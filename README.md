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

A repository keeps its catalog on a dedicated branch, `translations` by default. The catalog lists every source string used by any configured branch, once per language:

```
translation-sync.yml
en/app.php              source text the translations were made for (written by the sync)
de/app.php              German translations
ja/app.php
overrides/5.x/en/app.php
overrides/5.x/de/app.php
```

In each language file, untranslated strings are commented out and show the source text. To translate one, uncomment the line and replace the value:

```php
    // 'New entry' => 'New entry',
    'New entry' => 'Neuer Eintrag',
```

The `sync` command runs on a schedule and after each push to the catalog branch. It does two things:

1. It reads each branch's source file (`en/app.php`) and updates the catalog. New strings appear as commented-out lines, and strings that no branch uses anymore are removed. It commits these changes to the catalog branch.
2. For each branch, it builds every language file from that branch's source strings and the catalog's translations. If any file differs from the branch, it force-pushes a `translation-sync/<branch>` branch and opens or updates a pull request. Strings without a translation keep their source text, matching what Crowdin exported.

### Strings whose source text differs between branches

A translation belongs to a key and the exact source text it was made for. Most keys are the source text, so this only matters for message IDs such as `account_activation_body`, whose text can change between branches.

When a branch's source text for a key differs from the newest branch's, that branch's translations for the key live in `overrides/<branch>/`. When a key's source text changes on one branch, the other branches keep the old translation, and the new text starts out untranslated.

## Setting up a repository

1. Create the catalog branch with only a config file:

   ```sh
   git switch --orphan translations
   ```

   ```yaml
   # translation-sync.yml
   # Branches whose translation files are kept in sync, oldest first.
   # When bootstrapping, the newest branch's translation wins.
   branches:
     4.x: src/translations
     5.x: src/translations
     6.x: resources/translations
   files:
     - app.php
   source_language: en
   # Languages that stay out of the catalog, such as ones maintained by hand.
   # The sync leaves their files on each branch alone.
   exclude_languages: [cy, hr, id, ky, nn]
   commit_message: Update translations
   ```

2. Build the catalog from the translation files already on each branch:

   ```sh
   git fetch origin 4.x 5.x 6.x
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
| `sync` | Updates the catalog and the pull request for each branch. Use `--dry-run` to preview, `--no-pr` to push branches without opening pull requests, and `--fetch` to fetch the configured branches first. |
| `status` | Shows how many strings each language has translated. |

The sync parses translation files with [nikic/php-parser](https://github.com/nikic/PHP-Parser) instead of including them, so a file from a contributor's branch never runs. Files may contain only string keys and string values.

## Action inputs

| Input | Default | Description |
| --- | --- | --- |
| `catalog-branch` | `translations` | Branch that holds the catalog. |
| `token` | `github.token` | Token used to push and open pull requests. Pull requests opened with the default `GITHUB_TOKEN` don't trigger other workflows, so pass a GitHub App or bot token if the pull requests need CI. |
| `dry-run` | `false` | Report what would change without pushing. |
