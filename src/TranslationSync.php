<?php

namespace craft\translationsync;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Keeps translation files on several release branches in sync with one catalog branch.
 *
 * The catalog is made of layers. Each layer has a source snapshot (`en/<file>`, the source
 * text its translations were made for) and one `<language>/<file>` per language, which lists
 * every source string and comments out the untranslated ones.
 *
 * - The root layer holds the newest source text of every string used by any branch.
 * - `overrides/<branch>/` holds the strings whose source text on that branch differs from
 *   the root layer's, such as an email body that changed on a newer branch.
 *
 * A translation always belongs to a key and the exact source text it was made for. When a
 * string's source text changes, the old translation stays with the branches that still use
 * the old text, and the new text starts out untranslated.
 */
final class TranslationSync
{
    private const PR_BRANCH_PREFIX = 'translation-sync/';
    private const OVERRIDES_DIR = 'overrides';

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
     * translate the same source text differently, the newest branch wins, and each conflict
     * is listed for review.
     */
    public function bootstrap(): void
    {
        $sources = $this->readSources();
        $union = $this->unionSources($sources);
        $root = ['source' => $union, 'translations' => []];
        $overrides = [];
        $found = [];
        $languages = [];

        foreach ($this->branches as $branch => $path) {
            foreach ($this->git->dirs($this->ref($branch), $path) as $language) {
                if (!$this->isTargetLanguage($language)) {
                    continue;
                }
                $languages[$language] = true;
                foreach ($this->files as $file) {
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
                        if ($source === $union[$file][$key]) {
                            $root['translations'][$language][$file][$key] = $translation;
                            $found[$language][$file][$key][$branch] = $translation;
                        } else {
                            $overrides[$branch]['source'][$file][$key] = $source;
                            $overrides[$branch]['translations'][$language][$file][$key] = $translation;
                        }
                    }
                }
            }
        }

        // Every string with branch-specific source text gets an override, translated or not.
        foreach ($this->divergentSources($sources, $union) as $branch => $files) {
            $overrides[$branch]['source'] = $files;
            $overrides[$branch]['translations'] ??= [];
        }

        $languages = array_keys($languages);
        sort($languages);
        $this->writeCatalog($root, $overrides, $languages, false);
        $this->printStatus($root, $overrides, $languages);

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
        $union = $this->unionSources($sources);
        $languages = $this->catalogLanguages();
        [$root, $overrides] = $this->harvest($sources, $union, $languages);
        $catalogBranch = trim($this->git->run('git', 'rev-parse', '--abbrev-ref', 'HEAD'));

        $changedPaths = $this->writeCatalog($root, $overrides, $languages, $dryRun);
        if ($changedPaths) {
            printf("%s %d catalog files\n", $dryRun ? 'Would update' : 'Updating', count($changedPaths));
            if (!$dryRun) {
                $this->git->run('git', 'add', '-A', '--', ...$changedPaths);
                $this->git->run('git', 'commit', '-m', 'Update source strings');
                $this->git->run('git', 'push', $this->remote, "HEAD:refs/heads/$catalogBranch");
            }
        }

        foreach ($this->branches as $branch => $path) {
            $this->syncBranch($branch, $path, $sources[$branch], $union, $root, $overrides[$branch] ?? null, $languages, $catalogBranch, $dryRun, $openPullRequests);
        }
    }

    /**
     * Prints how many strings each language has translated.
     */
    public function status(): void
    {
        $sources = $this->readSources();
        $languages = $this->catalogLanguages();
        [$root, $overrides] = $this->harvest($sources, $this->unionSources($sources), $languages);
        $this->printStatus($root, $overrides, $languages);
    }

    /**
     * Rebuilds the catalog's layers from the branches' current source strings, carrying over
     * every translation whose key and source text still match.
     *
     * @return array{0:array,1:array<string,array>} The root layer and the override layers by branch.
     */
    private function harvest(array $sources, array $union, array $languages): array
    {
        $oldRoot = $this->readLayer($this->dir, $languages);
        $oldOverrides = [];
        foreach (array_keys($this->branches) as $branch) {
            $oldOverrides[$branch] = $this->readLayer("$this->dir/" . self::OVERRIDES_DIR . "/$branch", $languages);
        }

        $root = ['source' => $union, 'translations' => []];
        $this->fillTranslations($root, $languages, [$oldRoot, ...array_values($oldOverrides)]);

        $overrides = [];
        foreach ($this->divergentSources($sources, $union) as $branch => $files) {
            $overrides[$branch] = ['source' => $files, 'translations' => []];
            $others = array_diff_key($oldOverrides, [$branch => true]);
            $this->fillTranslations($overrides[$branch], $languages, [$oldOverrides[$branch], $oldRoot, ...array_values($others)]);
        }

        return [$root, $overrides];
    }

    /**
     * Fills a layer's translations from the first of the given layers that translated the same
     * key from the same source text.
     */
    private function fillTranslations(array &$layer, array $languages, array $from): void
    {
        foreach ($languages as $language) {
            foreach ($layer['source'] as $file => $strings) {
                foreach ($strings as $key => $text) {
                    foreach ($from as $old) {
                        if (($old['source'][$file][$key] ?? null) === $text && isset($old['translations'][$language][$file][$key])) {
                            $layer['translations'][$language][$file][$key] = $old['translations'][$language][$file][$key];
                            break;
                        }
                    }
                }
            }
        }
    }

    private function syncBranch(string $branch, string $path, array $sources, array $union, array $root, ?array $override, array $languages, string $catalogBranch, bool $dryRun, bool $openPullRequests): void
    {
        $prBranch = self::PR_BRANCH_PREFIX . $branch;
        $worktree = sys_get_temp_dir() . '/translation-sync-' . getmypid() . '-' . preg_replace('/[^\w.-]/', '_', $branch);
        $this->git->run('git', 'worktree', 'add', '--detach', $worktree, $this->ref($branch));

        try {
            $git = new Git($worktree);
            $changes = [];
            foreach ($languages as $language) {
                foreach ($this->files as $file) {
                    if (!$sources[$file]) {
                        continue;
                    }
                    $rows = [];
                    $new = [];
                    foreach ($sources[$file] as $key => $text) {
                        $layer = $text === $union[$file][$key] ? $root : $override;
                        $new[$key] = $layer['translations'][$language][$file][$key] ?? $text;
                        $rows[] = [$key, $new[$key]];
                    }
                    $target = "$worktree/$path/$language/$file";
                    $old = is_file($target) ? file_get_contents($target) : null;
                    $content = PhpArrayFile::render($rows, $old ?? file_get_contents("$worktree/$path/$this->sourceLanguage/$file"));
                    if ($old === $content) {
                        continue;
                    }
                    $oldEntries = $old === null ? [] : PhpArrayFile::parse($old, "$branch:$path/$language/$file");
                    $changed = count(array_diff_assoc($new, $oldEntries)) + count(array_diff_key($oldEntries, $new));
                    $changes[] = [$language, $file, $changed];
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

            $body = "Updates translations on `$branch` from the `$catalogBranch` branch.\n\n| Language | File | Strings changed |\n| --- | --- | --- |\n";
            foreach ($changes as [$language, $file, $changed]) {
                $body .= "| $language | $file | $changed |\n";
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
     * @return array<string,array<string,array<string,string>>> Branch => file => [key => source text]
     * for the strings whose source text on that branch differs from the union's.
     */
    private function divergentSources(array $sources, array $union): array
    {
        $divergent = [];
        foreach ($sources as $branch => $files) {
            foreach ($files as $file => $strings) {
                foreach ($strings as $key => $text) {
                    if ($text !== $union[$file][$key]) {
                        $divergent[$branch][$file][$key] = $text;
                    }
                }
            }
        }
        return $divergent;
    }

    /**
     * @return string[] The languages in the catalog's root layer.
     */
    private function catalogLanguages(): array
    {
        $languages = [];
        foreach (scandir($this->dir) as $name) {
            if ($name[0] !== '.' && $name !== self::OVERRIDES_DIR && is_dir("$this->dir/$name") && $this->isTargetLanguage($name)) {
                $languages[] = $name;
            }
        }
        return $languages;
    }

    /**
     * @return array{source:array<string,array<string,string>>,translations:array<string,array<string,array<string,string>>>}
     */
    private function readLayer(string $dir, array $languages): array
    {
        $layer = ['source' => [], 'translations' => []];
        foreach ($this->files as $file) {
            $path = "$dir/$this->sourceLanguage/$file";
            if (!is_file($path)) {
                continue;
            }
            $layer['source'][$file] = PhpArrayFile::parse(file_get_contents($path), $path);
            foreach ($languages as $language) {
                $path = "$dir/$language/$file";
                if (is_file($path)) {
                    $translations = array_filter(PhpArrayFile::parse(file_get_contents($path), $path), fn($t) => $t !== '');
                    $layer['translations'][$language][$file] = $translations;
                }
            }
        }
        return $layer;
    }

    /**
     * Writes the catalog's layers and deletes override files that are no longer needed.
     *
     * @return string[] Paths that changed, relative to the catalog directory.
     */
    private function writeCatalog(array $root, array $overrides, array $languages, bool $dryRun): array
    {
        $files = $this->renderLayer('', $root, $languages);
        foreach ($overrides as $branch => $layer) {
            $files += $this->renderLayer(self::OVERRIDES_DIR . "/$branch/", $layer, $languages);
        }

        $existing = [];
        if (is_dir("$this->dir/" . self::OVERRIDES_DIR)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$this->dir/" . self::OVERRIDES_DIR, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $item) {
                $existing[] = substr($item->getPathname(), strlen($this->dir) + 1);
            }
        }

        $changed = [];
        foreach ($files as $path => $content) {
            $absolute = "$this->dir/$path";
            if (!is_file($absolute) || file_get_contents($absolute) !== $content) {
                $changed[] = $path;
                if (!$dryRun) {
                    if (!is_dir(dirname($absolute))) {
                        mkdir(dirname($absolute), 0777, true);
                    }
                    file_put_contents($absolute, $content);
                }
            }
        }
        foreach (array_diff($existing, array_keys($files)) as $path) {
            $changed[] = $path;
            if (!$dryRun) {
                unlink("$this->dir/$path");
            }
        }
        return $changed;
    }

    /**
     * @return array<string,string> Path => content
     */
    private function renderLayer(string $prefix, array $layer, array $languages): array
    {
        $files = [];
        foreach ($layer['source'] as $file => $strings) {
            $files["$prefix$this->sourceLanguage/$file"] = PhpArrayFile::render(array_map(null, array_keys($strings), $strings));
            foreach ($languages as $language) {
                $translations = $layer['translations'][$language][$file] ?? [];
                $rows = [];
                foreach ($strings as $key => $text) {
                    $rows[] = isset($translations[$key]) ? [$key, $translations[$key]] : [$key, $text, true];
                }
                $files["$prefix$language/$file"] = PhpArrayFile::render($rows);
            }
        }
        return $files;
    }

    private function printStatus(array $root, array $overrides, array $languages): void
    {
        foreach (['' => $root] + $overrides as $branch => $layer) {
            foreach ($layer['source'] as $file => $strings) {
                printf("%s%s (%d strings)\n", $branch === '' ? '' : self::OVERRIDES_DIR . "/$branch/", $file, count($strings));
                foreach ($languages as $language) {
                    $translated = count($layer['translations'][$language][$file] ?? []);
                    printf("  %-6s %5d translated, %5d missing\n", $language, $translated, count($strings) - $translated);
                }
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
