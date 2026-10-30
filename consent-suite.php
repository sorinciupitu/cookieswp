<?php
/**
 * Plugin Name:  Consent Suite
 * Description:  Consimțământ cookie GDPR/CCPA, blocare scripturi, scaner de cookie-uri, jurnal de consimțământ.
 * Version:      1.0.4
 * Requires PHP: 7.4
 * License:      GPL-2.0-or-later
 * Text Domain:  consent-suite
 */

defined( 'ABSPATH' ) || exit;

define( 'CCM_VERSION', '1.0.4' );
define( 'CCM_PATH', plugin_dir_path( __FILE__ ) );
define( 'CCM_URL', plugin_dir_url( __FILE__ ) );

function ccm_get_scan_token() {
    $token = get_option( 'ccm_scan_token', '' );
    if ( '' === $token ) {
        $token = wp_generate_password( 48, false );
        add_option( 'ccm_scan_token', $token );
    }
    return $token;
}

if ( ! class_exists( 'YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' ) ) {
    require_once CCM_PATH . 'includes/lib/plugin-update-checker/plugin-update-checker.php';
}
$ccm_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://github.com/sorinciupitu/cookieswp/',
    __FILE__,
    'consent-suite'
);
$ccm_update_checker->getVcsApi()->enableReleaseAssets( '/consent-suite\.zip$/' );
add_filter( 'auto_update_plugin', function ( $update, $item ) {
    if ( ! apply_filters( 'ccm_auto_update_plugin', false ) ) {
        return $update;
    }
    return ( isset( $item->slug ) && 'consent-suite' === $item->slug ) ? true : $update;
}, 10, 2 );

function ccm_default_settings() {
    return array(
        'enabled'            => 1,
        'compliance_mode'    => 'gdpr',        // gdpr | ccpa | both
        'layout'             => 'box',         // box | bar
        'position'           => 'bottom-left',
        'consent_version'    => 1,             // bump to re-prompt everyone
        'cookie_expiry_days' => 365,
        'log_consents'       => 1,
        'block_scripts'      => 1,
        'block_iframes'      => 1,
        'gcm_enabled'        => 1,             // Google Consent Mode v2
        'gpc_enabled'        => 1,             // Global Privacy Control
        'geo_enabled'        => 0,
        'geo_regions'        => array( 'EU', 'GB' ),
        'colors'             => array(
            'bg'       => '#ffffff',
            'text'     => '#212121',
            'btn_bg'   => '#1863dc',
            'btn_text' => '#ffffff',
        ),
        'categories'         => array(
            'necessary'     => array( 'label' => 'Strict necesare', 'locked' => 1, 'default_on' => 1 ),
            'functional'    => array( 'label' => 'Funcționale',    'locked' => 0, 'default_on' => 0 ),
            'analytics'     => array( 'label' => 'Analiză',         'locked' => 0, 'default_on' => 0 ),
            'advertisement' => array( 'label' => 'Publicitate',     'locked' => 0, 'default_on' => 0 ),
        ),
        'texts'              => array(
            'title'       => 'Respectăm confidențialitatea ta',
            'message'     => 'Folosim cookie-uri pentru a îmbunătăți experiența de navigare și pentru a analiza traficul. Apasă „Acceptă toate" pentru a-ți da acordul.',
            'accept'      => 'Acceptă toate',
            'reject'      => 'Respinge toate',
            'prefs'       => 'Personalizează',
            'prefs_title' => 'Personalizează preferințele de consimțământ',
            'save'        => 'Salvează preferințele',
            'revisit'     => 'Setări cookie',
        ),
        'custom_rules'       => array(), // [ ['match'=>'domain.com', 'category'=>'analytics'], ... ]
    );
}

register_activation_hook( __FILE__, function () {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $c = $wpdb->get_charset_collate();

    dbDelta( "CREATE TABLE {$wpdb->prefix}ccm_cookies (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        name varchar(191) NOT NULL DEFAULT '',
        provider varchar(191) NOT NULL DEFAULT '',
        category varchar(50) NOT NULL DEFAULT 'unclassified',
        ctype varchar(20) NOT NULL DEFAULT 'http',
        expiry varchar(100) NOT NULL DEFAULT 'session',
        description text NULL,
        pages text NULL,
        source varchar(20) NOT NULL DEFAULT 'auto',
        status varchar(20) NOT NULL DEFAULT 'active',
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY name (name),
        KEY category (category)
    ) $c;" );

    dbDelta( "CREATE TABLE {$wpdb->prefix}ccm_consent_logs (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        uuid varchar(64) NOT NULL DEFAULT '',
        ip_hash varchar(64) NOT NULL DEFAULT '',
        region varchar(10) NOT NULL DEFAULT '',
        necessary tinyint(1) NOT NULL DEFAULT 1,
        functional tinyint(1) NOT NULL DEFAULT 0,
        analytics tinyint(1) NOT NULL DEFAULT 0,
        advertisement tinyint(1) NOT NULL DEFAULT 0,
        action varchar(20) NOT NULL DEFAULT '',
        consent_version int NOT NULL DEFAULT 1,
        url text NULL,
        user_agent text NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY uuid (uuid),
        KEY created_at (created_at)
    ) $c;" );

    add_option( 'ccm_settings', ccm_default_settings() );

    if ( ! wp_next_scheduled( 'ccm_scan_event' ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ccm_scan_event' );
    }
} );

foreach ( array( 'plugin', 'public', 'blocker', 'rest', 'scanner', 'geo', 'admin' ) as $f ) {
    require CCM_PATH . "includes/class-ccm-{$f}.php";
}
CCM_Plugin::instance();