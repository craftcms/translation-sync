<?php

namespace craft\translationsync;

use RuntimeException;

final class Git
{
    public function __construct(public readonly string $dir)
    {
    }

    /**
     * Runs a command in the repository and returns its stdout. Throws when it fails.
     */
    public function run(string ...$args): string
    {
        $result = $this->tryRun(...$args);
        if ($result === null) {
            throw new RuntimeException(sprintf('Command failed: %s', implode(' ', $args)));
        }
        return $result;
    }

    /**
     * Runs a command in the repository and returns its stdout, or null when it fails.
     */
    public function tryRun(string ...$args): ?string
    {
        $process = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->dir);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            if (getenv('TRANSLATION_SYNC_DEBUG')) {
                fwrite(STDERR, $stderr);
            }
            return null;
        }
        return $stdout;
    }

    /**
     * Returns a file's contents at a revision, or null when it doesn't exist there.
     */
    public function show(string $rev, string $path): ?string
    {
        return $this->tryRun('git', 'show', "$rev:$path");
    }

    /**
     * @return string[] Names of the directories directly within $path at $rev.
     */
    public function dirs(string $rev, string $path): array
    {
        $out = $this->tryRun('git', 'ls-tree', '-d', '--name-only', "$rev:$path");
        return $out === null ? [] : array_values(array_filter(explode("\n", $out)));
    }
}
