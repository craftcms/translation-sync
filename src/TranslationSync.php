<?php

namespace craft\translationsync;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Keeps translation files on several release branches in sync with one catalog branch.
 *
 * The catalog branch holds `translation-sync.yml` and one `<language>/<file>` per language.
 * Each catalog file holds that language's translations, keyed like the source files.
 * A translation applies to its key on every branch.
 *
 * The sync runs both ways. When a translation was changed on a branch more recently than in
 * the catalog, the branch's version is copied into the catalog first and then reaches the other
 * branches. `git blame` dates each key's line: the newest commit wins. Lines last changed by the
 * sync's own git identity, such as merged sync pull requests, don't count as edits.
 */
final class TranslationSync
{
    private const PR_BRANCH_PREFIX = 't9n/';
    /** How much history `--fetch` gets. Edits older than this, which the daily sync has already copied, no longer count. */
    private const HISTORY_DAYS = 90;

    private Git $git;
    private string $sourceLanguage;
    /** @var string[] */
    private array $files;
    /** @var string[] */
    private array $excludeLanguages;
    /** @var array<string,string> Branch name => translations path, oldest branch first */
    private array $branches;
    private string $commitMessage;

    public function __construct(private readonly string $dir, private readonly string $remote = 'origin')
    {
        $configPath = "$dir/translation-sync.yml";
        if (!is_file($configPath)) {
            throw new RuntimeException("$configPath doesn't exist. Run this from a checkout of the catalog branch.");
        }
        $config = Yaml::parseFile($configPath);
        $this->sourceLanguage = $config['source_language'] ?? 'en';
        $this->files = $config['files'] ?? throw new RuntimeException('translation-sync.yml must list `files`.');
        $this->excludeLanguages = $config['exclude_languages'] ?? [];
        $this->branches = $config['branches'] ?? throw new RuntimeException('translation-sync.yml must list `branches`.');
        $this->commitMessage = $config['commit_message'] ?? 'Update translations';
        $this->git = new Git($dir);
    }

    /**
     * Builds the catalog from the translation files currently on each branch. When branches
     * translate a string differently, the newest branch wins, and each conflict is listed
     * for review.
     */
    public function bootstrap(): void
    {
        $sources = $this->readSources();
        $catalog = [];
        $found = [];

        foreach ($this->branches as $branch => $path) {
            foreach ($this->git->dirs($this->ref($branch), $path) as $language) {
                if (!$this->isTargetLanguage($language)) {
                    continue;
                }
                foreach ($this->files as $file) {
                    $catalog[$language][$file] ??= [];
                    $code = $this->git->show($this->ref($branch), "$path/$language/$file");
                    if ($code === null) {
                        continue;
                    }
                    foreach (PhpArrayFile::parse($code, "$branch:$path/$language/$file") as $key => $translation) {
                        $source = $sources[$branch][$file][$key] ?? null;
                        // Strings that still show the source text are untranslated.
                        if ($source === null || $translation === $source || $translation === '') {
                            continue;
                        }
                        $catalog[$language][$file][$key] = $translation;
                        $found[$language][$file][$key][$branch] = $translation;
                    }
                }
            }
        }

        $union = $this->unionSources($sources);
        $this->writeCatalog($catalog, $union, false);
        $this->printStatus($catalog, $union);

        foreach ($found as $language => $files) {
            foreach ($files as $file => $keys) {
                foreach ($keys as $key => $byBranch) {
                    if (count(array_unique($byBranch)) > 1) {
                        printf("\nConflict in %s/%s: %s\n", $language, $file, self::oneLine($key));
                        foreach ($byBranch as $branch => $translation) {
                            printf("  %s: %s\n", $branch, self::oneLine($translation));
                        }
                    }
                }
            }
        }
    }

    /**
     * Drops catalog translations of strings that no branch uses anymore, commits and pushes the
     * catalog when that changed it, and then updates a pull request for each branch whose translation files
     * are out of date.
     */
    public function sync(bool $dryRun, bool $openPullRequests, bool $fetch): void
    {
        $catalogBranch = trim($this->git->run('git', 'rev-parse', '--abbrev-ref', 'HEAD'));
        if ($fetch) {
            // Blame needs recent history. A branch with no commits in that window gets its latest commit.
            foreach ([$catalogBranch, ...array_keys($this->branches)] as $branch) {
                $refspec = "+refs/heads/$branch:refs/remotes/$this->remote/$branch";
                $fetched = $this->git->tryRun('git', 'fetch', '--no-tags', '--shallow-since=' . self::HISTORY_DAYS . ' days ago', $this->remote, $refspec)
                    ?? $this->git->tryRun('git', 'fetch', '--no-tags', '--depth=1', $this->remote, $refspec);
                // The catalog branch may not be pushed yet; it's already checked out.
                if ($fetched === null && $branch !== $catalogBranch) {
                    throw new RuntimeException("Couldn't fetch $branch from $this->remote.");
                }
            }
        }
        $sources = $this->readSources();
        $catalog = $this->readCatalog();

        $copied = $this->copyBranchEdits($sources, $catalog);
        $changedPaths = $this->writeCatalog($catalog, $this->unionSources($sources), $dryRun);
        if ($changedPaths) {
            printf("%s %d catalog files\n", $dryRun ? 'Would update' : 'Updating', count($changedPaths));
            if (!$dryRun) {
                $message = $copied
                    ? 'Copy translation edits from ' . implode(', ', array_unique(array_column($copied, 3)))
                    : 'Remove unused translations';
                $this->git->run('git', 'add', '--', ...$changedPaths);
                $this->git->run('git', 'commit', '-m', $message);
                $this->git->run('git', 'push', $this->remote, "HEAD:refs/heads/$catalogBranch");
            }
        }

        foreach ($this->branches as $branch => $path) {
            $this->syncBranch($branch, $path, $sources[$branch], $catalog, $catalogBranch, $dryRun, $openPullRequests);
        }
    }

    /**
     * Copies translations that were changed on a branch more recently than in the catalog into
     * the catalog. When several branches changed a key, the newest change wins.
     *
     * @return array<array{0:string,1:string,2:string,3:string}> [language, file, key, branch] for each copied translation
     */
    private function copyBranchEdits(array $sources, array &$catalog): array
    {
        $syncEmail = trim($this->git->tryRun('git', 'config', 'user.email') ?? '');
        $copied = [];
        foreach ($catalog as $language => $files) {
            foreach ($this->files as $file) {
                [, $catalogTimes] = $this->datedEntries('HEAD', "$language/$file", null, true);
                $winners = [];
                foreach ($this->branches as $branch => $path) {
                    [$entries, $times] = $this->datedEntries($this->ref($branch), "$path/$language/$file", $syncEmail, false);
                    foreach ($entries as $key => $translation) {
                        $source = $sources[$branch][$file][$key] ?? null;
                        // Entries that still show the source text are untranslated, not edits.
                        if ($source === null || $translation === $source || $translation === '') {
                            continue;
                        }
                        $catalogTimes[$key] ??= $times[$key] ? $this->removalTime("$language/$file", (string)$key) : 0;
                        if ($times[$key] > ($winners[$key][0] ?? $catalogTimes[$key])) {
                            $winners[$key] = [$times[$key], $translation, $branch];
                        }
                    }
                }
                foreach ($winners as $key => [, $translation, $branch]) {
                    if ($translation !== ($files[$file][$key] ?? null)) {
                        $catalog[$language][$file][$key] = $translation;
                        $copied[] = [$language, $file, (string)$key, $branch];
                        printf("Copying %s/%s from %s: %s\n", $language, $file, $branch, self::oneLine((string)$key));
                    }
                }
            }
        }
        return $copied;
    }

    /**
     * Returns since when a catalog file has been missing a key: the commit that last removed it,
     * or else the commit that added the file. Run only for keys the catalog doesn't have.
     */
    private function removalTime(string $path, string $key): int
    {
        $removed = $this->git->run('git', 'log', '-1', '--format=%ct', '-S', PhpArrayFile::quote($key) . ' => ', 'HEAD', '--', $path);
        // In a shallow history, the oldest fetched commit counts as adding every file.
        return (int)trim($removed ?: $this->git->run('git', 'log', '-1', '--format=%ct', '--diff-filter=A', 'HEAD', '--', $path));
    }

    /**
     * Reads a translation file at a revision, and dates each entry by the newest commit that
     * changed one of its lines. Lines from commits by $ignoreEmail get time 0. So do lines from
     * root commits and commits at the edge of a shallow history, unless $dateRoot is set: the
     * catalog's lines keep those dates, so a newly created catalog isn't older than every edit.
     *
     * @return array{0:array<string,string>,1:array<string,int>} Entries and their commit times
     */
    private function datedEntries(string $rev, string $path, ?string $ignoreEmail, bool $dateRoot): array
    {
        $code = $this->git->show($rev, $path);
        if ($code === null) {
            return [[], []];
        }
        $entries = PhpArrayFile::parse($code, "$rev:$path", $lines);

        $lineTimes = [];
        $commits = [];
        $sha = null;
        foreach (explode("\n", $this->git->run(...['git', 'blame', '--porcelain', ...($dateRoot ? ['--root'] : []), $rev, '--', $path])) as $row) {
            if (preg_match('/^([0-9a-f]{40,64}) \d+ (\d+)/', $row, $m)) {
                [, $sha, $lineNumber] = $m;
                $commits[$sha] ??= ['time' => 0, 'ignore' => false];
            } elseif (str_starts_with($row, "\t")) {
                $lineTimes[(int)$lineNumber] = $commits[$sha]['ignore'] ? 0 : $commits[$sha]['time'];
            } elseif (str_starts_with($row, 'committer-time ')) {
                $commits[$sha]['time'] = (int)substr($row, 15);
            } elseif ($row === 'boundary' || ($ignoreEmail !== null && $row === "author-mail <$ignoreEmail>")) {
                $commits[$sha]['ignore'] = true;
            }
        }

        $times = [];
        foreach ($lines as $key => [$start, $end]) {
            $times[$key] = max(array_map(fn($n) => $lineTimes[$n] ?? 0, range($start, $end)));
        }
        return [$entries, $times];
    }

    /**
     * Prints how many strings each language has translated.
     */
    public function status(): void
    {
        $this->printStatus($this->readCatalog(), $this->unionSources($this->readSources()));
    }

    /**
     * @param array<string,array<string,string>> $sources File => [key => source text] for this branch
     * @param array<string,array<string,array<string,string>>> $catalog
     */
    private function syncBranch(string $branch, string $path, array $sources, array $catalog, string $catalogBranch, bool $dryRun, bool $openPullRequests): void
    {
        $prBranch = self::PR_BRANCH_PREFIX . $branch;
        $worktree = sys_get_temp_dir() . '/translation-sync-' . getmypid() . '-' . preg_replace('/[^\w.-]/', '_', $branch);
        $this->git->run('git', 'worktree', 'add', '--detach', $worktree, $this->ref($branch));

        try {
            $git = new Git($worktree);
            $changes = [];
            foreach ($catalog as $language => $files) {
                foreach ($this->files as $file) {
                    if (!$sources[$file]) {
                        continue;
                    }
                    $new = [];
                    foreach ($sources[$file] as $key => $text) {
                        // Untranslated strings are left out, so Yii falls back to the source text.
                        // A message ID such as `account_activation_body` isn't its own source text,
                        // so it falls back to the source text explicitly.
                        $translation = $files[$file][$key] ?? ((string)$key !== $text ? $text : null);
                        if ($translation !== null) {
                            $new[$key] = $translation;
                        }
                    }
                    $target = "$worktree/$path/$language/$file";
                    $old = is_file($target) ? file_get_contents($target) : null;
                    $content = PhpArrayFile::render(
                        array_map(null, array_keys($new), $new),
                        $old ?? file_get_contents("$worktree/$path/$this->sourceLanguage/$file"),
                    );
                    if ($old === $content) {
                        continue;
                    }
                    $oldEntries = $old === null ? [] : PhpArrayFile::parse($old, "$branch:$path/$language/$file");
                    $changes[] = [
                        $language,
                        $file,
                        count(array_diff_key($new, $oldEntries)),
                        count(array_intersect_key(array_diff_assoc($new, $oldEntries), $oldEntries)),
                        count(array_diff_key($oldEntries, $new)),
                    ];
                    if (!is_dir(dirname($target))) {
                        mkdir(dirname($target), 0777, true);
                    }
                    file_put_contents($target, $content);
                }
            }

            if (!$changes) {
                echo "$branch: up to date\n";
                if ($openPullRequests && !$dryRun && ($number = $this->findPullRequest($branch, $prBranch))) {
                    $git->run('gh', 'pr', 'close', (string)$number, '--delete-branch', '--comment', "Translations on `$branch` are already up to date.");
                }
                return;
            }

            $body = "Updates translations on `$branch` from the `$catalogBranch` branch.\n\n| Language | File | Added | Changed | Removed |\n| --- | --- | --- | --- | --- |\n";
            foreach ($changes as $row) {
                $body .= '| ' . implode(' | ', $row) . " |\n";
            }
            $body .= "\nTo change a translation, edit it on the `$catalogBranch` branch. The sync rebuilds this pull request each time it runs, so commits pushed here get overwritten.\n";
            echo "$branch: " . count($changes) . " files out of date\n" . $git->run('git', 'diff', '--stat');

            if ($dryRun) {
                return;
            }

            $git->run('git', 'add', '-A', '--', $path);
            $git->run('git', 'commit', '-m', $this->commitMessage);
            $git->tryRun('git', 'fetch', '--no-tags', '--depth=1', $this->remote, "+refs/heads/$prBranch:refs/remotes/$this->remote/$prBranch");
            $remoteTree = $git->tryRun('git', 'rev-parse', '--verify', '-q', "$this->remote/$prBranch^{tree}");
            if (trim((string)$remoteTree) !== trim($git->run('git', 'rev-parse', 'HEAD^{tree}'))) {
                $git->run('git', 'push', '--force', $this->remote, "HEAD:refs/heads/$prBranch");
            }

            if ($openPullRequests) {
                $number = $this->findPullRequest($branch, $prBranch);
                if ($number) {
                    $git->run('gh', 'pr', 'edit', (string)$number, '--body', $body);
                } else {
                    echo $git->run('gh', 'pr', 'create', '--base', $branch, '--head', $prBranch, '--title', "Update translations for $branch", '--body', $body);
                }
            }
        } finally {
            $this->git->run('git', 'worktree', 'remove', '--force', $worktree);
        }
    }

    private function findPullRequest(string $branch, string $prBranch): ?int
    {
        $json = $this->git->run('gh', 'pr', 'list', '--base', $branch, '--head', $prBranch, '--state', 'open', '--json', 'number');
        return json_decode($json, true)[0]['number'] ?? null;
    }

    /**
     * @return array<string,array<string,array<string,string>>> Branch => file => [key => source text]
     */
    private function readSources(): array
    {
        $sources = [];
        foreach ($this->branches as $branch => $path) {
            if ($this->git->tryRun('git', 'rev-parse', '--verify', '-q', $this->ref($branch)) === null) {
                throw new RuntimeException("{$this->ref($branch)} doesn't exist. Fetch all branches first.");
            }
            foreach ($this->files as $file) {
                $code = $this->git->show($this->ref($branch), "$path/$this->sourceLanguage/$file");
                $sources[$branch][$file] = $code === null ? [] : PhpArrayFile::parse($code, "$branch:$path/$this->sourceLanguage/$file");
            }
        }
        return $sources;
    }

    /**
     * Merges each file's source strings across branches. Each key takes its source text from
     * the newest branch that has it. Keys follow the newest branch's order, followed by keys
     * that only older branches have.
     *
     * @return array<string,array<string,string>> File => [key => source text]
     */
    private function unionSources(array $sources): array
    {
        $union = [];
        foreach (array_reverse(array_keys($this->branches)) as $branch) {
            foreach ($this->files as $file) {
                $union[$file] = ($union[$file] ?? []) + $sources[$branch][$file];
            }
        }
        return $union;
    }

    /**
     * @return array<string,array<string,array<string,string>>> Language => file => [key => translation]
     */
    private function readCatalog(): array
    {
        $catalog = [];
        foreach (scandir($this->dir) as $language) {
            if ($language[0] === '.' || !is_dir("$this->dir/$language") || !$this->isTargetLanguage($language)) {
                continue;
            }
            foreach ($this->files as $file) {
                $path = "$this->dir/$language/$file";
                $translations = is_file($path) ? PhpArrayFile::parse(file_get_contents($path), "$language/$file") : [];
                $catalog[$language][$file] = array_filter($translations, fn($t) => $t !== '');
            }
        }
        return $catalog;
    }

    /**
     * Writes each catalog file in source-string order. Translations of strings that no branch
     * uses anymore are dropped.
     *
     * @return string[] Paths that changed, relative to the catalog directory.
     */
    private function writeCatalog(array $catalog, array $union, bool $dryRun): array
    {
        ksort($catalog);
        $changed = [];
        foreach ($catalog as $language => $files) {
            foreach ($this->files as $file) {
                $rows = [];
                foreach (array_intersect_key($union[$file], $files[$file] ?? []) as $key => $source) {
                    $rows[] = [$key, $files[$file][$key]];
                }
                $content = PhpArrayFile::render($rows);
                $path = "$this->dir/$language/$file";
                if (!is_file($path) || file_get_contents($path) !== $content) {
                    $changed[] = "$language/$file";
                    if (!$dryRun) {
                        if (!is_dir(dirname($path))) {
                            mkdir(dirname($path), 0777, true);
                        }
                        file_put_contents($path, $content);
                    }
                }
            }
        }
        return $changed;
    }

    private function printStatus(array $catalog, array $union): void
    {
        ksort($catalog);
        foreach ($this->files as $file) {
            $total = count($union[$file]);
            echo "$file ($total strings)\n";
            foreach ($catalog as $language => $files) {
                $translated = count(array_intersect_key($files[$file] ?? [], $union[$file]));
                printf("  %-6s %5d translated, %5d missing\n", $language, $translated, $total - $translated);
            }
        }
    }

    private function isTargetLanguage(string $language): bool
    {
        return $language !== $this->sourceLanguage && !in_array($language, $this->excludeLanguages, true);
    }

    private function ref(string $branch): string
    {
        return "$this->remote/$branch";
    }

    private static function oneLine(string $value): string
    {
        $value = str_replace("\n", '\n', $value);
        return mb_strlen($value) > 120 ? mb_substr($value, 0, 120) . '…' : $value;
    }
}
