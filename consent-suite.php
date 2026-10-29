<?php
/**
 * Plugin Name:  Consent Suite
 * Description:  GDPR/CCPA cookie consent, script blocking, cookie scanner, consent log.
 * Version:      1.0.0
 * Requires PHP: 7.4
 * License:      GPL-2.0-or-later
 * Text Domain:  consent-suite
 */

defined( 'ABSPATH' ) || exit;

define( 'CCM_VERSION', '1.0.0' );
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
        'hide_for_logged_in' => 1,
        'colors'             => array(
            'bg'       => '#ffffff',
            'text'     => '#212121',
            'btn_bg'   => '#1863dc',
            'btn_text' => '#ffffff',
        ),
        'categories'         => array(
            'necessary'     => array( 'label' => 'Strictly necessary', 'locked' => 1, 'default_on' => 1 ),
            'functional'    => array( 'label' => 'Functional',    'locked' => 0, 'default_on' => 0 ),
            'analytics'     => array( 'label' => 'Analytics',     'locked' => 0, 'default_on' => 0 ),
            'advertisement' => array( 'label' => 'Advertisement', 'locked' => 0, 'default_on' => 0 ),
        ),
        'texts'              => array(
            'title'       => 'We value your privacy',
            'message'     => 'We use cookies to enhance your browsing experience and analyse our traffic. Click "Accept All" to consent to our use of cookies.',
            'accept'      => 'Accept All',
            'reject'      => 'Reject All',
            'prefs'       => 'Customize',
            'prefs_title' => 'Customize Consent Preferences',
            'save'        => 'Save My Preferences',
            'revisit'     => 'Cookie Settings',
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