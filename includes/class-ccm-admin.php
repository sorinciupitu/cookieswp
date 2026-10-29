<?php
defined( 'ABSPATH' ) || exit;

class CCM_Admin {
    private $s;

    public function __construct( $settings ) {
        $this->s = $settings;
        add_action( 'admin_menu', array( $this, 'menu' ) );
        add_action( 'admin_post_ccm_save',    array( $this, 'save' ) );
        add_action( 'admin_post_ccm_scan',    array( $this, 'queue_scan' ) );
        add_action( 'admin_post_ccm_export',  array( $this, 'export_csv' ) );
    }

    public function menu() {
        add_menu_page( 'Consent Suite', 'Consent Suite', 'manage_options', 'consent-suite', array( $this, 'settings_page' ), 'dashicons-privacy' );
        add_submenu_page( 'consent-suite', 'Cookies', 'Cookies', 'manage_options', 'ccm-cookies', array( $this, 'cookies_page' ) );
        add_submenu_page( 'consent-suite', 'Consent Log', 'Consent Log', 'manage_options', 'ccm-log', array( $this, 'log_page' ) );
    }

    public function settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $s = $this->s; ?>
        <div class="wrap"><h1>Consent Suite</h1>
        <?php if ( isset( $_GET['saved'] ) ) echo '<div class="notice notice-success"><p>Settings saved.</p></div>'; ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'ccm_save' ); ?><input type="hidden" name="action" value="ccm_save">
            <table class="form-table">
                <tr><th>Enabled</th><td><label><input type="checkbox" name="s[enabled]" value="1" <?php checked( $s['enabled'] ); ?>> Show consent banner</label></td></tr>
                <tr><th>Compliance mode</th><td><select name="s[compliance_mode]">
                    <?php foreach ( array( 'gdpr' => 'GDPR / ePrivacy', 'ccpa' => 'CCPA', 'both' => 'GDPR + CCPA' ) as $v => $l ) : ?>
                    <option value="<?php echo $v; ?>" <?php selected( $s['compliance_mode'], $v ); ?>><?php echo $l; ?></option>
                    <?php endforeach; ?></select></td></tr>
                <tr><th>Layout / position</th><td>
                    <select name="s[layout]"><option value="box" <?php selected( $s['layout'], 'box' ); ?>>Box</option><option value="bar" <?php selected( $s['layout'], 'bar' ); ?>>Full-width bar</option></select>
                    <select name="s[position]"><?php foreach ( array( 'bottom-left','bottom-center','bottom-right','top-left','top-right' ) as $p ) : ?>
                    <option value="<?php echo $p; ?>" <?php selected( $s['position'], $p ); ?>><?php echo $p; ?></option><?php endforeach; ?></select></td></tr>
                <tr><th>Colors</th><td><?php foreach ( array( 'bg' => 'Background', 'text' => 'Text', 'btn_bg' => 'Button', 'btn_text' => 'Button text' ) as $k => $l ) : ?>
                    <label><?php echo $l; ?> <input type="color" name="s[colors][<?php echo $k; ?>]" value="<?php echo esc_attr( $s['colors'][ $k ] ); ?>"></label> <?php endforeach; ?></td></tr>
                <tr><th>Banner texts</th><td>
                    <input class="regular-text" name="s[texts][title]"   value="<?php echo esc_attr( $s['texts']['title'] ); ?>"><br>
                    <textarea class="large-text" rows="3" name="s[texts][message]"><?php echo esc_textarea( $s['texts']['message'] ); ?></textarea><br>
                    <input class="regular-text" name="s[texts][accept]"  value="<?php echo esc_attr( $s['texts']['accept'] ); ?>">
                    <input class="regular-text" name="s[texts][reject]"  value="<?php echo esc_attr( $s['texts']['reject'] ); ?>">
                    <input class="regular-text" name="s[texts][prefs]"   value="<?php echo esc_attr( $s['texts']['prefs'] ); ?>">
                    <input class="regular-text" name="s[texts][revisit]" value="<?php echo esc_attr( $s['texts']['revisit'] ); ?>"></td></tr>
                <tr><th>Behaviour</th><td>
                    <label><input type="checkbox" name="s[block_scripts]" value="1" <?php checked( $s['block_scripts'] ); ?>> Block scripts before consent</label><br>
                    <label><input type="checkbox" name="s[block_iframes]" value="1" <?php checked( $s['block_iframes'] ); ?>> Block third-party iframes</label><br>
                    <label><input type="checkbox" name="s[log_consents]"  value="1" <?php checked( $s['log_consents'] ); ?>> Log consent records</label><br>
                    <label><input type="checkbox" name="s[gcm_enabled]"   value="1" <?php checked( $s['gcm_enabled'] ); ?>> Google Consent Mode v2</label><br>
                    <label><input type="checkbox" name="s[gpc_enabled]"   value="1" <?php checked( $s['gpc_enabled'] ); ?>> Respect Global Privacy Control (GPC)</label></td></tr>
                <tr><th>Geo-targeting</th><td>
                    <label><input type="checkbox" name="s[geo_enabled]" value="1" <?php checked( $s['geo_enabled'] ); ?>> Only show banner in these regions</label>
                    <input class="regular-text" name="s[geo_regions]" value="<?php echo esc_attr( implode( ',', (array) $s['geo_regions'] ) ); ?>"> <em>(e.g. EU,GB,CA — "EU" matches all EU members)</em></td></tr>
                <tr><th>Custom blocking rules</th><td>
                    <textarea class="large-text code" rows="4" name="s[custom_rules_raw]" placeholder="cdn.example.com|analytics"><?php
                    echo esc_textarea( implode( "\n", array_map( function ( $r ) { return $r['match'] . '|' . $r['category']; }, (array) $s['custom_rules'] ) ) ); ?></textarea>
                    <p class="description">One per line: <code>needle|category</code>. Matches script src, inline script body, or iframe src.</p></td></tr>
                <tr><th>Consent version</th><td>
                    <input type="number" name="s[consent_version]" value="<?php echo (int) $s['consent_version']; ?>" min="1">
                    <p class="description">Increase to re-prompt every visitor (e.g. after policy changes).</p></td></tr>
            </table>
            <p><button class="button button-primary">Save Settings</button></p>
        </form>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'ccm_scan' ); ?><input type="hidden" name="action" value="ccm_scan">
            <p><button class="button">Scan site for cookies</button></p>
        </form></div>
        <?php
    }

    public function save() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die();
        check_admin_referer( 'ccm_save' );
        $in = wp_unslash( $_POST['s'] ?? array() );
        $d  = ccm_default_settings();
        $out = $this->s;

        $out['enabled']         = empty( $in['enabled'] ) ? 0 : 1;
        $out['compliance_mode'] = in_array( $in['compliance_mode'] ?? '', array( 'gdpr', 'ccpa', 'both' ), true ) ? $in['compliance_mode'] : 'gdpr';
        $out['layout']          = 'bar' === ( $in['layout'] ?? '' ) ? 'bar' : 'box';
        $out['position']        = sanitize_key( $in['position'] ?? 'bottom-left' );
        foreach ( array( 'bg', 'text', 'btn_bg', 'btn_text' ) as $k )
            $out['colors'][ $k ] = sanitize_hex_color( $in['colors'][ $k ] ?? '' ) ?: $d['colors'][ $k ];
        foreach ( $d['texts'] as $k => $v )
            $out['texts'][ $k ] = sanitize_text_field( $in['texts'][ $k ] ?? $v );
        foreach ( array( 'block_scripts', 'block_iframes', 'log_consents', 'gcm_enabled', 'gpc_enabled', 'geo_enabled' ) as $k )
            $out[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
        $out['geo_regions']        = array_filter( array_map( 'strtoupper', array_map( 'trim', explode( ',', $in['geo_regions'] ?? '' ) ) ) );
        $out['consent_version']    = max( (int) $this->s['consent_version'], absint( $in['consent_version'] ?? 1 ) );
        $out['cookie_expiry_days'] = max( 30, absint( $in['cookie_expiry_days'] ?? 365 ) );

        $out['custom_rules'] = array();
        foreach ( preg_split( '/\r\n|\r|\n/', $in['custom_rules_raw'] ?? '' ) as $line ) {
            $p = array_map( 'trim', explode( '|', $line ) );
            if ( 2 === count( $p ) && '' !== $p[0] && in_array( $p[1], array( 'necessary', 'functional', 'analytics', 'advertisement' ), true ) )
                $out['custom_rules'][] = array( 'match' => $p[0], 'category' => $p[1] );
        }

        CCM_Plugin::instance()->update_settings( $out );
        wp_safe_redirect( add_query_arg( array( 'page' => 'consent-suite', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public function cookies_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        global $wpdb; $t = $wpdb->prefix . 'ccm_cookies';

        if ( isset( $_POST['ccm_nonce'] ) && check_admin_referer( 'ccm_cookies', 'ccm_nonce' ) ) {
            foreach ( (array) ( $_POST['cat'] ?? array() ) as $id => $cat ) {
                if ( in_array( $cat, array( 'necessary','functional','analytics','advertisement','unclassified' ), true ) )
                    $wpdb->update( $t, array( 'category' => $cat ), array( 'id' => absint( $id ) ) );
            }
            echo '<div class="notice notice-success"><p>Categories updated.</p></div>';
        }
        $rows = $wpdb->get_results( "SELECT * FROM $t ORDER BY category, name LIMIT 500" ); ?>
        <div class="wrap"><h1>Cookies</h1>
        <p>Scanner runs daily via WP-Cron. JS-set cookies from third parties may require the headless scanner (see docs).</p>
        <form method="post"><?php wp_nonce_field( 'ccm_cookies', 'ccm_nonce' ); ?>
        <table class="widefat striped"><thead><tr><th>Name</th><th>Provider</th><th>Type</th><th>Expiry</th><th>Category</th></tr></thead><tbody>
        <?php foreach ( $rows as $r ) : ?><tr>
            <td><strong><?php echo esc_html( $r->name ); ?></strong><br><small><?php echo esc_html( wp_trim_words( $r->pages, 8 ) ); ?></small></td>
            <td><?php echo esc_html( $r->provider ); ?></td>
            <td><?php echo esc_html( $r->ctype ); ?></td>
            <td><?php echo esc_html( $r->expiry ); ?></td>
            <td><select name="cat[<?php echo (int) $r->id; ?>]"><?php
                foreach ( array( 'necessary','functional','analytics','advertisement','unclassified' ) as $c )
                    printf( '<option value="%1$s" %2$s>%1$s</option>', $c, selected( $r->category, $c, false ) ); ?></select></td>
        </tr><?php endforeach; ?></tbody></table>
        <p><button class="button button-primary">Save categories</button></p></form></div>
        <?php
    }

    public function log_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        global $wpdb; $t = $wpdb->prefix . 'ccm_consent_logs';
        $paged = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $per   = 50;
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" );
        $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t ORDER BY id DESC LIMIT %d OFFSET %d", $per, ( $paged - 1 ) * $per ) );
        $export = wp_nonce_url( admin_url( 'admin-post.php?action=ccm_export' ), 'ccm_export' ); ?>
        <div class="wrap"><h1>Consent Log
            <a href="<?php echo esc_url( $export ); ?>" class="button">Export CSV</a></h1>
        <table class="widefat striped"><thead><tr><th>Date</th><th>Action</th><th>Categories (F/A/D)</th><th>Region</th><th>User ID</th><th>Consent v</th></tr></thead><tbody>
        <?php foreach ( $rows as $r ) : ?><tr>
            <td><?php echo esc_html( $r->created_at ); ?></td>
            <td><?php echo esc_html( $r->action ); ?></td>
            <td><?php echo (int) $r->functional . ' / ' . (int) $r->analytics . ' / ' . (int) $r->advertisement; ?></td>
            <td><?php echo esc_html( $r->region ); ?></td>
            <td><code><?php echo esc_html( substr( $r->uuid, 0, 8 ) ); ?></code></td>
            <td><?php echo (int) $r->consent_version; ?></td>
        </tr><?php endforeach; ?></tbody></table>
        <p><?php
        $pages = (int) ceil( $total / $per );
        for ( $i = 1; $i <= min( $pages, 20 ); $i++ )
            printf( ' <a class="%s" href="%s">%d</a>', $i === $paged ? 'button button-primary' : 'button',
                esc_url( add_query_arg( array( 'page' => 'ccm-log', 'paged' => $i ), admin_url( 'admin.php' ) ) ), $i ); ?></p>
        </div><?php
    }

    public function queue_scan() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die();
        check_admin_referer( 'ccm_scan' );
        CCM_Plugin::instance()->scanner->queue();
        wp_safe_redirect( add_query_arg( array( 'page' => 'ccm-cookies', 'scan' => 1 ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public function export_csv() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die();
        check_admin_referer( 'ccm_export' );
        global $wpdb;
        $rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}ccm_consent_logs ORDER BY id ASC", ARRAY_A );
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=consent-log-' . gmdate( 'Y-m-d' ) . '.csv' );
        $out = fopen( 'php://output', 'w' );
        if ( $rows ) fputcsv( $out, array_keys( $rows[0] ) );
        foreach ( $rows as $r ) fputcsv( $out, $r );
        fclose( $out ); exit;
    }
}