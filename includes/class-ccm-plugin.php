<?php
defined( 'ABSPATH' ) || exit;

final class CCM_Plugin {
    private static $instance = null;
    public $settings;
    public $scanner;

    public static function instance() {
        return null === self::$instance ? self::$instance = new self() : self::$instance;
    }

    private function __construct() {
        $this->settings = wp_parse_args( get_option( 'ccm_settings', array() ), ccm_default_settings() );

        add_action( 'init', function () {
            load_plugin_textdomain( 'consent-suite', false, dirname( plugin_basename( CCM_PATH ) ) . '/languages' );
        } );

        if ( is_admin() ) {
            new CCM_Admin( $this->settings );
        } else {
            new CCM_Public( $this->settings );
        }

        new CCM_Blocker( $this->settings );
        new CCM_Rest( $this->settings );
        $this->scanner = new CCM_Scanner( $this->settings );

        add_shortcode( 'ccm_cookie_list', array( $this, 'cookie_list_shortcode' ) );
        add_shortcode( 'ccm_revisit_consent', function () {
            return '<button type="button" class="ccm-revisit-link" onclick="window.CCM && CCM.showPreferences()">Cookie Settings</button>';
        } );
    }

    public function update_settings( $settings ) {
        $this->settings = $settings;
        update_option( 'ccm_settings', $settings );
    }

    /** [ccm_cookie_list] — public cookie declaration */
    public function cookie_list_shortcode() {
        global $wpdb;
        $rows = $wpdb->get_results( "SELECT name, provider, category, ctype, expiry, description
            FROM {$wpdb->prefix}ccm_cookies WHERE status='active' ORDER BY category, name" );
        if ( ! $rows ) return '';

        $out = '';
        foreach ( $this->settings['categories'] as $key => $cat ) {
            $group = array_filter( $rows, function ( $r ) use ( $key ) { return $r->category === $key; } );
            if ( ! $group ) continue;
            $out .= '<h3>' . esc_html( $cat['label'] ) . '</h3>';
            $out .= '<table class="ccm-cookie-table"><thead><tr><th>Cookie</th><th>Provider</th><th>Type</th><th>Expiry</th><th>Description</th></tr></thead><tbody>';
            foreach ( $group as $r ) {
                $out .= sprintf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                    esc_html( $r->name ), esc_html( $r->provider ), esc_html( $r->ctype ),
                    esc_html( $r->expiry ), esc_html( $r->description ) );
            }
            $out .= '</tbody></table>';
        }
        return $out;
    }
}