# Consent Suite — GitHub Releases Distribution Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the `consent-suite` plugin updatable on every installed WordPress site from a single public GitHub repo via GitHub Releases.

**Architecture:** The plugin vendors the YahnisElsts `plugin-update-checker` library pointing at the repo; a GitHub Actions workflow builds a correctly-rooted zip and attaches it to a release on every `v*` tag; WordPress shows "Update available" and an admin installs manually (full auto-update is opt-in via a filter).

**Tech Stack:** WordPress / PHP 7.4+, YahnisElsts `plugin-update-checker` v5, GitHub Actions, bash + `zip`.

**Spec:** [2026-10-29-consent-suite-distribution-design.md](../specs/2026-10-29-consent-suite-distribution-design.md)

## Global Constraints

- Repo: `https://github.com/sorinciupitu/cookieswp` (public).
- Plugin slug: `consent-suite`; main file `consent-suite.php` at repo root; the release zip MUST have its files under a top-level `consent-suite/` folder.
- Version source of truth: the `Version:` header in `consent-suite.php`, the `CCM_VERSION` constant, and the git tag `vX.Y.Z` must all match.
- PHP ≥ 7.4 (plugin requirement; update library v5 also requires 7.4).
- No client-specific data in the repo: `config.json`, `scans/`, and `node_modules/` are git-ignored; only `config.example.json` is committed.
- Out of scope: fixing the broken REST scan route (`class-ccm-rest.php` references `scan_auth` / `ingest_scan` that don't exist; `scanner.js` posts to a different URL). Flag before first tag, but no task here changes it.

## Review Focus

The five failure modes most likely to bite a person using this, in order:

1. **Zip root folder is not `consent-suite/`** → WP shows "The package could not be installed. No valid plugins were found." → pinned in Task 3 (verify `unzip -l` shows `consent-suite/consent-suite.php`).
2. **Version mismatch** (header vs `CCM_VERSION` vs tag) → update never detected, or wrong version shown → pinned in Task 3 (build script asserts all three equal).
3. **Wrong update URL in code** (missing trailing slash, or repo name ≠ `cookieswp`) → sites never see the update → pinned in Task 2 (assert the exact URL string) and Task 4 (manual end-to-end check).
4. **Sensitive data committed** (`config.json` with tokens, scan output) → leak → pinned in Task 1 (`git check-ignore` + `git ls-files` show no such file).
5. **Auto-update filter miswired** (defaults to auto, or blocks manual) → pinned in Task 2 (assert default returns the original `$update`, and only `consent-suite` slug is flipped).

---

### Task 1: Initialize the repo with a no-sensitive-data guard

**Files:**
- Create: `.gitignore`
- Modify: `scanner.js:358` (scan output directory)
- Modify: (git metadata — `git init`, first commit, `gh repo create`)

**Interfaces:**
- Produces: a public GitHub repo `sorinciupitu/cookieswp` containing the current tree, with `config.json`, `scans/`, `node_modules/` excluded.

- [ ] **Step 1: Write `.gitignore`**

```
node_modules/
config.json
scans/
*.log
.DS_Store
.vscode/
```

- [ ] **Step 2: Route scanner output into the ignored `scans/` dir**

In `scanner.js`, at the `else` branch that saves to a file (around line 358), create `scans/` before writing and write there instead of the repo root: replace the `fs.writeFileSync(out, …)` line so `out` is `path.join('scans', <sanitized-url>.json)` and precede it with `fs.mkdirSync('scans', { recursive: true })`.

- [ ] **Step 3: Verify the guard**

Run: `git init && git add -A && git ls-files | grep -E 'config\.json$|^scans/|node_modules|\.vscode' && echo LEAK || echo CLEAN`
Expected: prints `CLEAN` (no sensitive/ignored path is tracked).

- [ ] **Step 4: Commit and create the remote**

```bash
git add -A
git commit -m "chore: initial consent-suite plugin + scanner"
gh repo create sorinciupitu/cookieswp --public --source=. --push
```

Expected: `gh` prints the new repo URL; `git remote -v` shows `github.com/sorinciupitu/cookieswp`.

- [ ] **Step 5: Commit note**

Include the `.gitignore` + `scanner.js` change in the initial commit (they land together in Step 4).

---

### Task 2: Vendor the update checker and wire the bootstrap

**Files:**
- Create: `includes/lib/plugin-update-checker.php` (vendored, downloaded)
- Modify: `consent-suite.php:13-15` (add bootstrap after the `CCM_*` defines)

**Interfaces:**
- Consumes: slug `consent-suite`, repo URL `https://github.com/sorinciupitu/cookieswp/` (trailing slash).
- Produces: `ccm_auto_update_plugin` filter (default `false`) that, when `true`, forces auto-update only for the `consent-suite` slug.

- [ ] **Step 1: Vendor the library**

Download the latest release of `YahnisElsts/plugin-update-checker` (asset `plugin-update-checker.zip`), extract the single `plugin-update-checker.php`, and place it at `includes/lib/plugin-update-checker.php`. Verify the file exists and is non-empty.

- [ ] **Step 2: Confirm the v5 factory class name**

Run: `grep -rn "class PucFactory\|buildUpdateChecker" includes/lib/plugin-update-checker.php | head`
Expected: a `YahnisElsts\PluginUpdateChecker\v5\PucFactory` (or `Puc_v*_Factory`) class providing `buildUpdateChecker`. Note the exact name used in Step 3.

- [ ] **Step 3: Add the bootstrap in `consent-suite.php`**

After the `define( 'CCM_URL', … )` line, add (adjusting the factory class name to Step 2's finding):

```php
if ( is_admin() && ! class_exists( 'YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' ) ) {
    require_once CCM_PATH . 'includes/lib/plugin-update-checker.php';
    \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/sorinciupitu/cookieswp/',
        __FILE__,
        'consent-suite'
    );
    add_filter( 'auto_update_plugin', function ( $update, $item ) {
        if ( ! apply_filters( 'ccm_auto_update_plugin', false ) ) {
            return $update;
        }
        return ( isset( $item->slug ) && 'consent-suite' === $item->slug ) ? true : $update;
    }, 10, 2 );
}
```

- [ ] **Step 4: Lint and URL-check**

Run: `php -l consent-suite.php` → Expected: `No syntax errors detected`.
Run: `grep -n "github.com/sorinciupitu/cookieswp/" consent-suite.php` → Expected: exactly one match, with trailing slash.

- [ ] **Step 5: Commit**

```bash
git add includes/lib/plugin-update-checker.php consent-suite.php
git commit -m "feat: add GitHub update checker + opt-in auto-update"
```

---

### Task 3: Release build script and GitHub Actions workflow

**Files:**
- Create: `bin/build-release.sh`
- Create: `.github/workflows/release.yml`

**Interfaces:**
- Produces: `release/consent-suite.zip` whose entries are all under `consent-suite/`, containing only `consent-suite.php`, `includes/`, `public/`, `uninstall.php`.
- Consumes: a version argument (no leading `v`) equal to the header + `CCM_VERSION`.

- [ ] **Step 1: Write `bin/build-release.sh`**

```bash
#!/usr/bin/env bash
set -euo pipefail
VERSION="${1:-$(git describe --tags --abbrev=0 2>/dev/null | sed 's/^v//')}"

HEADER_VERSION=$(sed -n 's/^ \* Version:[[:space:]]*\(.*\)/\1/p' consent-suite.php)
CONST_VERSION=$(grep -o "define( 'CCM_VERSION', '[^']*' )" consent-suite.php | sed "s/.*'\([^']*\)'.*/\1/")
[ "$HEADER_VERSION" = "$VERSION" ] || { echo "Header Version ($HEADER_VERSION) != $VERSION" >&2; exit 1; }
[ "$CONST_VERSION" = "$VERSION" ] || { echo "CCM_VERSION ($CONST_VERSION) != $VERSION" >&2; exit 1; }

rm -rf build release
mkdir -p build/consent-suite release
cp -R consent-suite.php includes public uninstall.php build/consent-suite/
find build -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
( cd build && zip -rq ../release/consent-suite.zip consent-suite )
echo "Built release/consent-suite.zip ($VERSION)"
```

Make it executable: `chmod +x bin/build-release.sh`.

- [ ] **Step 2: Write `.github/workflows/release.yml`**

```yaml
name: Release

on:
  push:
    tags: ['v*']

jobs:
  release:
    runs-on: ubuntu-latest
    permissions:
      contents: write
    steps:
      - uses: actions/checkout@v4
      - name: Build release zip
        run: bin/build-release.sh "${GITHUB_REF_NAME#v}"
      - name: Upload release
        uses: softprops/action-gh-release@v2
        with:
          files: release/consent-suite.zip
```

- [ ] **Step 3: Verify the zip structure locally**

Run: `bin/build-release.sh 1.0.0 && unzip -l release/consent-suite.zip`
Expected: `0.0.0`-free, and every entry starts with `consent-suite/`; the first file is `consent-suite/consent-suite.php`; `scanner.js`, `package.json`, `config.*`, and `bin/` are NOT present.

- [ ] **Step 4: Verify the version guard fires**

Run: `bin/build-release.sh 9.9.9` → Expected: exits non-zero printing `Header Version (1.0.0) != 9.9.9` (or the `CCM_VERSION` mismatch).

- [ ] **Step 5: Commit**

```bash
git add bin/build-release.sh .github/workflows/release.yml
git commit -m "ci: release workflow + build script"
```

---

### Task 4: README with install and release docs

**Files:**
- Create: `README.md`

**Interfaces:**
- Consumes: repo URL, release workflow from Tasks 1–3.

- [ ] **Step 1: Write `README.md`** covering three sections:

  - **Install** — manual first install per site (download a release zip and upload, or clone); the plugin registers `consent-suite/consent-suite.php`.
  - **Updates** — after a release, sites show "Update available"; admin installs manually; to enable full auto-update on a site add `add_filter( 'ccm_auto_update_plugin', '__return_true' );`.
  - **Releasing** — bump `Version:` header + `CCM_VERSION` together, `git commit`, `git tag vX.Y.Z`, `git push --tags`; the workflow builds the zip and attaches it to the release.

- [ ] **Step 2: Verify docs state the correct values**

Run: `grep -nE "sorinciupitu/cookieswp|ccm_auto_update_plugin|consent-suite/" README.md`
Expected: the repo URL, filter name, and slug each appear correctly.

- [ ] **Step 3: Manual end-to-end check (documented, not automated)**

On a scratch WP site with the plugin installed at `1.0.0`: after a `1.0.1` tag + release, the Plugins screen shows "Update available" and the update installs cleanly. Note: run this only after the out-of-scope REST fix is addressed, before the first public tag.

- [ ] **Step 4: Commit**

```bash
git add README.md
git commit -m "docs: install, update, and release instructions"
```
