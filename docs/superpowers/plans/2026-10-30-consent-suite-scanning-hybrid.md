# Consent Suite — Scanare hibridă Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make consent logging work, let the local headless scanner push results via an authenticated REST route, and improve the on-site scanner's dictionary — no central server required.

**Architecture:** Fix the existing `/consent` route to call its real `log()` handler; add a token-authenticated `/scan` route for the headless scanner; generate a per-site token lazily and surface it in the admin; expand the on-site cookie dictionary with prefix matching.

**Tech Stack:** WordPress / PHP 7.4+, WP REST API, `$wpdb`.

**Spec:** [2026-10-30-consent-suite-scanning-hybrid-design.md](../specs/2026-10-30-consent-suite-scanning-hybrid-design.md)

## Global Constraints

- Routes live under namespace `ccm/v1` in `includes/class-ccm-rest.php`.
- Scan auth: header `X-CCM-Token`, compared against the stored token with `hash_equals`; missing/empty token must deny.
- Token option: `ccm_scan_token`, generated lazily (covers already-installed sites) via a `ccm_get_scan_token()` helper.
- `/scan` response must contain `added` and `updated` integer counts (`scanner.js` reads `r.added`/`r.updated`).
- `/consent` route: `permission_callback` → `__return_true`, `callback` → the existing `log()` method.
- Dictionary: expand `CCM_Scanner::known()` from `scanner.js` `COOKIE_MAP`; support `*`-suffix prefix keys.
- Out of scope: unifying the dictionary into a shared JSON, storing the headless `tags`, IP-allowlist auth, nonce-protecting the consent log.

## Review Focus

1. **Existing installs have no token** (generated only on activation) → scan writes denied forever. → pinned Task 2: `ccm_get_scan_token()` generates on first read.
2. **`/scan` accepts unauthenticated writes** (token empty or wrong) → DB injection. → pinned Task 2: permission callback returns `false` on missing/mismatched token.
3. **`/consent` still fatals** (callback/permission point at missing methods) → consent logging broken. → pinned Task 1: route registers with `log()` + `__return_true`.
4. **`log()` reads keys that don't match the route `args`** → consent rows written with wrong values. → pinned Task 1: `log()` keys match the `args` schema.
5. **`_ga_*` never matches** (exact-key lookup only) → prefixed cookies stay unclassified. → pinned Task 4: `lookup()` prefix-matches `*` keys.

---

### Task 1: Fix the `/consent` consent-logging route

**Files:**
- Modify: `includes/class-ccm-rest.php:12-25`

**Interfaces:**
- Produces: a working `POST /ccm/v1/consent` route that logs consent via the existing `log()` method.

- [ ] **Step 1: Rewrite the route registration**

In `CCM_Rest::routes()`, replace the `permission_callback` and `callback` lines of the `/consent` route with:

```php
'permission_callback' => '__return_true',
'callback'            => array( $this, 'log' ),
```

Leave the `args` block unchanged (its `action`/`uuid`/`functional`/`analytics`/`advertisement`/`url` keys are exactly what `log()` reads).

- [ ] **Step 2: Verify no dangling references remain**

Run: `grep -n "scan_auth\|ingest_scan" includes/class-ccm-rest.php`
Expected: no output (both broken references gone).

- [ ] **Step 3: Lint**

Run: `php -l includes/class-ccm-rest.php` → Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add includes/class-ccm-rest.php
git commit -m "fix: wire /consent route to its real log handler"
```

---

### Task 2: Add the token-authenticated `/scan` route

**Files:**
- Modify: `consent-suite.php:13-15` (add helper after the `CCM_*` defines)
- Modify: `includes/class-ccm-rest.php` (add `/scan` route + `ingest_scan()` method)

**Interfaces:**
- Produces: function `ccm_get_scan_token(): string` (returns the stored token, generating + persisting one if missing); route `POST /ccm/v1/scan`; method `CCM_Rest::ingest_scan( WP_REST_Request $req )` returning `{ ok, added, updated }`.

- [ ] **Step 1: Add the token helper in `consent-suite.php`**

```php
function ccm_get_scan_token() {
    $token = get_option( 'ccm_scan_token', '' );
    if ( '' === $token ) {
        $token = wp_generate_password( 48, false );
        add_option( 'ccm_scan_token', $token );
    }
    return $token;
}
```

- [ ] **Step 2: Register the `/scan` route in `CCM_Rest::routes()`**

```php
register_rest_route( 'ccm/v1', '/scan', array(
    'methods'             => 'POST',
    'permission_callback' => function ( $req ) {
        $stored = ccm_get_scan_token();
        $sent   = $req->get_header( 'X-CCM-Token' );
        return ( '' !== $stored && is_string( $sent ) && hash_equals( $stored, $sent ) );
    },
    'callback'            => array( $this, 'ingest_scan' ),
) );
```

- [ ] **Step 3: Add `ingest_scan()` to `CCM_Rest`**

```php
public function ingest_scan( $req ) {
    global $wpdb;
    $t       = $wpdb->prefix . 'ccm_cookies';
    $cats    = array( 'necessary', 'functional', 'analytics', 'advertisement', 'unclassified' );
    $added   = 0;
    $updated = 0;

    foreach ( (array) $req->get_param( 'cookies' ) as $c ) {
        $name = sanitize_text_field( $c['name'] ?? '' );
        if ( '' === $name ) continue;
        $row = array(
            'name'     => $name,
            'provider' => sanitize_text_field( $c['provider'] ?? '' ),
            'category' => in_array( $c['category'] ?? '', $cats, true ) ? $c['category'] : 'unclassified',
            'ctype'    => 'http',
            'expiry'   => sanitize_text_field( $c['expiry'] ?? 'session' ),
            'pages'    => implode( "\n", array_map( 'sanitize_text_field', (array) ( $c['pages'] ?? array() ) ) ),
            'source'   => 'auto',
            'status'   => 'active',
        );
        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE name = %s", $name ) );
        if ( $exists ) {
            $wpdb->update( $t, $row, array( 'id' => $exists ) );
            $updated++;
        } else {
            $row['created_at'] = current_time( 'mysql' );
            $wpdb->insert( $t, $row );
            $added++;
        }
    }
    return rest_ensure_response( array( 'ok' => true, 'added' => $added, 'updated' => $updated ) );
}
```

- [ ] **Step 4: Verify the route and response shape**

Run: `grep -n "ccm/v1', '/scan'\|X-CCM-Token\|added'\|'updated'" includes/class-ccm-rest.php`
Expected: the `/scan` route, `X-CCM-Token` header check, and `added`/`updated` keys all present.

- [ ] **Step 5: Lint**

Run: `php -l consent-suite.php && php -l includes/class-ccm-rest.php` → Expected: two `No syntax errors detected`.

- [ ] **Step 6: Commit**

```bash
git add consent-suite.php includes/class-ccm-rest.php
git commit -m "feat: add token-authenticated /scan route for headless scanner"
```

---

### Task 3: Show and regenerate the scan token in the admin

**Files:**
- Modify: `includes/class-ccm-admin.php` (settings page + a regenerate handler)

**Interfaces:**
- Consumes: `ccm_get_scan_token()` from Task 2.
- Produces: a readonly token field on the Consent Suite settings page and a "Regenerate" button (admin-post `ccm_regen_token`).

- [ ] **Step 1: Add the regenerate handler to `CCM_Admin::__construct()`**

```php
add_action( 'admin_post_ccm_regen_token', array( $this, 'regen_token' ) );
```

- [ ] **Step 2: Add the `regen_token()` method**

```php
public function regen_token() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die();
    check_admin_referer( 'ccm_regen_token' );
    update_option( 'ccm_scan_token', wp_generate_password( 48, false ) );
    wp_safe_redirect( add_query_arg( array( 'page' => 'consent-suite', 'regenerated' => 1 ), admin_url( 'admin.php' ) ) );
    exit;
}
```

- [ ] **Step 3: Render the token field in `settings_page()`**

Inside the settings `<table class="form-table">`, before the closing `</table>`, add a row:

```php
<tr><th>Scan token</th><td>
    <input class="large-text code" type="text" readonly value="<?php echo esc_attr( ccm_get_scan_token() ); ?>" onfocus="this.select()">
    <p class="description">Paste into the headless scanner's <code>config.json</code> to push scan results here.</p>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
        <?php wp_nonce_field( 'ccm_regen_token' ); ?>
        <input type="hidden" name="action" value="ccm_regen_token">
        <button class="button">Regenerate</button>
    </form>
</td></tr>
```

- [ ] **Step 4: Verify the wiring**

Run: `grep -n "ccm_regen_token\|ccm_get_scan_token" includes/class-ccm-admin.php`
Expected: the handler is registered, the nonce field is emitted, and `ccm_get_scan_token()` is called.

- [ ] **Step 5: Lint**

Run: `php -l includes/class-ccm-admin.php` → Expected: `No syntax errors detected`.

- [ ] **Step 6: Commit**

```bash
git add includes/class-ccm-admin.php
git commit -m "feat: display and regenerate scan token in admin"
```

---

### Task 4: Expand the on-site dictionary + prefix matching

**Files:**
- Modify: `includes/class-ccm-scanner.php:57-98` (`store()` lookup + `known()`)

**Interfaces:**
- Produces: `public static function lookup( $name, $known )` returning the matching entry (exact first, then `*`-suffix prefix), or `null`.

- [ ] **Step 1: Write the failing check for prefix matching**

Run (from the repo root, no WP required):

```bash
php -r "define('ABSPATH','/'); require 'includes/class-ccm-scanner.php'; var_dump( CCM_Scanner::lookup( '_ga_123', array( '_ga_*' => array( 'Google', 'analytics', '2 years' ) ) ) );"
```

Expected: currently FAILS (method does not exist — fatal `Call to undefined method`).

- [ ] **Step 2: Add the static `lookup()` method to `CCM_Scanner`**

```php
public static function lookup( $name, $known ) {
    if ( isset( $known[ $name ] ) ) return $known[ $name ];
    foreach ( $known as $key => $entry ) {
        if ( '*' === substr( $key, -1 ) && 0 === strpos( $name, substr( $key, 0, -1 ) ) ) {
            return $entry;
        }
    }
    return null;
}
```

- [ ] **Step 3: Use `lookup()` in `store()`**

In `CCM_Scanner::store()`, change `$k = $known[ $name ] ?? null;` to `$k = self::lookup( $name, $known );`.

- [ ] **Step 4: Expand `known()`**

Add every cookie-name entry from `scanner.js` `COOKIE_MAP` (lines 65-100) that is not already present, as `name => array( provider, category, 'session' )` (keep existing entries and their expiries; use the `*`-suffix convention for the prefix keys already in `COOKIE_MAP`).

- [ ] **Step 5: Verify prefix matching passes**

Re-run the Step 1 command (with the `define('ABSPATH','/')` prefix) → Expected: `array(3) { [0]=> string(6) "Google" [1]=> string(9) "analytics" [2]=> string(8) "2 years" }`.

- [ ] **Step 6: Lint**

Run: `php -l includes/class-ccm-scanner.php` → Expected: `No syntax errors detected`.

- [ ] **Step 7: Commit**

```bash
git add includes/class-ccm-scanner.php
git commit -m "feat: expand cookie dictionary + prefix matching"
```

---

### Task 5: Manual end-to-end verification (documented, not automated)

- [ ] **Step 1:** On a scratch WP site, confirm the consent banner logs a row to `ccm_consent_logs` on Accept/Reject (validates Task 1).
- [ ] **Step 2:** Copy the admin token into a local `config.json` and run `node scanner.js --site <scratch>`; confirm it prints `✔ pushed to WP` with `added`/`updated` counts and the cookies appear on the Cookies admin page (validates Tasks 2-3).
- [ ] **Step 3:** Trigger the on-site WP-Cron scan and confirm `_ga_*`-style cookies classify as `analytics` (validates Task 4).
