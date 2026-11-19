# Translation Sync

Keeps the PHP translation files on several release branches in sync with one catalog branch. You fix a translation once on the catalog branch, and each release branch that uses the string gets a pull request with the change.

It reads and writes Yii-style message files:

```php
<?php

return [
    'Save' => 'Speichern',
];
```

## How it works

A repository keeps its catalog on a dedicated branch, `translations` by default. It holds one file per language with that language's translations, in the same format as the branch files:

```
translation-sync.yml
de/app.php              German translations
ja/app.php
```

To translate a string, add its key and translation to the language file. `status` shows how many strings each language is missing.

The `sync` command runs on a schedule and after each push to the catalog branch. It does three things:

1. It copies translations that were edited on a branch into the catalog (see below).
2. It reads each branch's source file (`en/app.php`) and removes translations of strings that no branch uses anymore. It commits these catalog changes to the catalog branch.
3. For each branch, it builds every language file from that branch's source strings and the catalog's translations. If any file differs from the branch, it force-pushes a `t9n/<branch>` branch and opens or updates a pull request. A branch's files include only the keys in that branch's source file, and leave out untranslated strings so Yii falls back to the source text. Message IDs such as `account_activation_body` are the exception: their untranslated entries keep the source text, because the key isn't readable text.

### One translation per key

A translation applies to its key on every branch. The catalog holds one translation per key and language. When `bootstrap` finds branches that translate a key differently, it keeps the newest branch's translation.

### Edits on branches

The sync runs both ways, and the latest commit wins. If someone changes a translation directly on a branch, for example in a pull request to `5.x`, the sync copies it into the catalog, and from there it reaches the other branches. If the catalog's translation of that key changed more recently, the catalog's version wins and the branch's sync pull request changes it back.

The sync dates each translation with `git blame`, using the commit time of the newest commit that changed its line in the catalog or on a branch. Some lines never count as edits:

- Lines last changed by the sync's own git identity. Merged sync pull requests carry that identity, even when they're squash-merged, as long as the identity is the account that opens the pull requests (`git-user-email` in the action).
- Lines older than the 90 days of history the sync fetches. The daily sync has copied those already.
- Removed translations. Deleting a line on a branch doesn't delete it from the catalog, so the next sync pull request adds it back.

When you run the CLI yourself, it uses your git identity, so your own edits on branches don't count as edits for that run.

## Setting up a repository

1. Create the catalog branch with only a config file:

   ```sh
   git switch --orphan translations
   ```

   ```yaml
   # translation-sync.yml
   # Branches whose translation files are kept in sync, oldest first. Remove a
   # branch once it stops getting releases; the sync then leaves it alone.
   # When branches translate a string differently, the newest branch's translation wins.
   branches:
     5.x: src/translations
     6.x: resources/translations
   files:
     - app.php
   source_language: en
   # Languages that stay out of the catalog. The sync leaves their files on each branch alone.
   exclude_languages: [cy, hr, id, ky]
   commit_message: Update translations
   ```

2. Build the catalog from the translation files already on each branch:

   ```sh
   git fetch origin 5.x 6.x
   php path/to/translation-sync/bin/translation-sync bootstrap
   ```

   When branches translate the same string differently, the newest branch wins. `bootstrap` lists each of these conflicts with every branch's version, so you can fix the catalog where an older branch had the better translation.

3. Preview the first sync, then commit and push the catalog:

   ```sh
   php path/to/translation-sync/bin/translation-sync sync --dry-run
   git add -A && git commit -m "Add translation catalog" && git push origin translations
   ```

4. Add [`examples/translation-sync.yml`](examples/translation-sync.yml) as `.github/workflows/translation-sync.yml` on the default branch (the schedule runs from there) and on the catalog branch (so edits there trigger a sync).

## Commands

Run these from a checkout of the catalog branch.

| Command | What it does |
| --- | --- |
| `bootstrap` | Builds the catalog from the translation files on each branch. |
| `sync` | Updates the catalog and the pull request for each branch. Use `--dry-run` to preview, `--no-pr` to push branches without opening pull requests, and `--fetch` to fetch the last 90 days of the catalog and configured branches first, which the two-way sync needs for `git blame`. |
| `status` | Shows how many strings each language has translated. |

The sync parses translation files with [nikic/php-parser](https://github.com/nikic/PHP-Parser) instead of including them, so a file from a contributor's branch never runs. Files may contain only string keys and string values.

## Action inputs

| Input | Default | Description |
| --- | --- | --- |
| `catalog-branch` | `translations` | Branch that holds the catalog. |
| `token` | `github.token` | Token used to push and open pull requests. Pull requests opened with the default `GITHUB_TOKEN` don't trigger other workflows, so pass a GitHub App or bot token if the pull requests need CI. |
| `git-user-name` | `github-actions[bot]` | Name for the sync's commits. |
| `git-user-email` | the `github-actions[bot]` noreply email | Email for the sync's commits. If `token` belongs to a GitHub App or bot account, use that account's noreply email. |
| `dry-run` | `false` | Report what would change without pushing. |
