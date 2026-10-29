# Consent Suite

GDPR/CCPA cookie-consent plugin for WordPress: consent banner, script/iframe
blocking, Google Consent Mode v2, Global Privacy Control, cookie scanner, and a
consent log. Ships with a headless (browser-based) cookie scanner for accurate
third-party cookie detection.

## Install

Install manually on each site:

1. Download the latest `consent-suite.zip` from
   [Releases](https://github.com/sorinciupitu/cookieswp/releases).
2. In WordPress admin, go to Plugins → Add New → Upload Plugin and upload the zip.
3. Activate "Consent Suite".

The plugin registers as `consent-suite/consent-suite.php`. On activation it
schedules a daily on-site cookie scan (WP-Cron) and stores its settings in the
site's database — nothing client-specific lives in this repo.

## Updates

Each installed site checks this repo for a newer release and shows
"Update available" in the Plugins screen; an admin installs it manually.

Full auto-update is opt-in per site. To enable it on a site, add to that site's
theme or a mu-plugin:

```php
add_filter( 'ccm_auto_update_plugin', '__return_true' );
```

## Releasing

1. Bump the `Version:` header in `consent-suite.php` **and** the `CCM_VERSION`
   constant to the same value.
2. Commit.
3. Tag and push: `git tag vX.Y.Z && git push --tags`.
4. The GitHub Actions workflow builds `consent-suite.zip` (plugin files only, at
   the zip root under `consent-suite/`) and attaches it to a new GitHub Release.

The build script enforces that the header version, `CCM_VERSION`, and the tag all
match. Build locally with `bin/build-release.sh X.Y.Z`.

## Cookie scanner

Two scanners share one classification dictionary:

- **On-site (automatic)** — `CCM_Scanner` runs via WP-Cron daily, with no external
  dependency. It finds first-party/HTTP cookies and inline `document.cookie`
  assignments.
- **Headless (on demand)** — `scanner.js` opens a real browser (Puppeteer) to
  capture third-party and JS-set cookies, then pushes results to the site via the
  authenticated REST endpoint.

To run the headless scanner against a site:

1. In the site's admin (Consent Suite → settings), copy the **Scan token**.
2. Create a local `config.json` (git-ignored):

```json
{
  "maxPages": 25,
  "sites": [
    {
      "url": "https://client-a.com",
      "endpoint": "https://client-a.com/wp-json/ccm/v1/scan",
      "token": "PASTE_SCAN_TOKEN",
      "acceptSelector": "#ccm-accept"
    }
  ]
}
```

3. Run `npm install && npm run scan -- --site client-a.com`.

## License

GPL-2.0-or-later.
