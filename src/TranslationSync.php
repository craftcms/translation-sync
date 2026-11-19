<?php

namespace craft\translationsync;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Keeps translation files on several release branches in sync with one catalog branch.
 *
 * The catalog branch holds `translation-sync.yml` and one `<language>/<file>` per language.
 * Each catalog file lists every source string used by any configured branch, with the
 * untranslated ones commented out. A translation applies to its key on every branch.
 */
final class TranslationSync
{
    private const PR_BRANCH_PREFIX = 'translation-sync/';

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
     * Brings the catalog up to date with each branch's source strings, commits and pushes it
     * when it changed, and then updates a pull request for each branch whose translation files
     * are out of date.
     */
    public function sync(bool $dryRun, bool $openPullRequests, bool $fetch): void
    {
        if ($fetch) {
            $refspecs = array_map(fn($branch) => "+refs/heads/$branch:refs/remotes/$this->remote/$branch", array_keys($this->branches));
            $this->git->run('git', 'fetch', '--no-tags', '--depth=1', $this->remote, ...$refspecs);
        }
        $sources = $this->readSources();
        $catalog = $this->readCatalog();
        $catalogBranch = trim($this->git->run('git', 'rev-parse', '--abbrev-ref', 'HEAD'));

        $changedPaths = $this->writeCatalog($catalog, $this->unionSources($sources), $dryRun);
        if ($changedPaths) {
            printf("%s %d catalog files\n", $dryRun ? 'Would update' : 'Updating', count($changedPaths));
            if (!$dryRun) {
                $this->git->run('git', 'add', '--', ...$changedPaths);
                $this->git->run('git', 'commit', '-m', 'Update source strings');
                $this->git->run('git', 'push', $this->remote, "HEAD:refs/heads/$catalogBranch");
            }
        }

        foreach ($this->branches as $branch => $path) {
            $this->syncBranch($branch, $path, $sources[$branch], $catalog, $catalogBranch, $dryRun, $openPullRequests);
        }
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
     * Writes each catalog file with every source string, commenting out the untranslated ones.
     * Translations of strings that no branch uses anymore are dropped.
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
                foreach ($union[$file] as $key => $source) {
                    $translation = $files[$file][$key] ?? null;
                    $rows[] = $translation !== null ? [$key, $translation] : [$key, $source, true];
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
