<?php
defined( 'ABSPATH' ) || exit;

class CCM_Scanner {
    private $s;

    public function __construct( $settings ) {
        $this->s = $settings;
        add_action( 'ccm_scan_event', array( $this, 'run_scan' ) );
    }

    public function queue() {
        wp_schedule_single_event( time(), 'ccm_scan_event' );
        spawn_cron();
    }

    public function run_scan() {
        $known = $this->known();               // extend this dictionary over time
        $found = array();

        foreach ( $this->urls( 25 ) as $url ) {
            $res = wp_remote_get( $url, array( 'timeout' => 20, 'redirection' => 3 ) );
            if ( is_wp_error( $res ) ) continue;
            $body = wp_remote_retrieve_body( $res );

            // a) First-party Set-Cookie response headers
            foreach ( (array) ( wp_remote_retrieve_headers( $res )['set-cookie'] ?? array() ) as $sc ) {
                if ( ! preg_match( '/^\s*([^=;\s]+)=/', $sc, $m ) ) continue;
                $found[ $m[1] ]['pages'][] = $url;
                $found[ $m[1] ]['ctype']   = 'http';
            }

            // b) JS-set cookies in inline scripts
            preg_match_all( '/document\.cookie\s*=\s*[\'"]([^=\s;\'"]+)=/', $body, $m );
            foreach ( $m[1] as $name ) {
                $found[ $name ]['pages'][] = $url;
                $found[ $name ]['ctype']   = 'js';
            }
        }

        $this->store( $found, $known );
    }

    private function urls( $limit ) {
        $urls = array( home_url( '/' ) );
        $sitemap = wp_remote_get( home_url( '/wp-sitemap.xml' ), array( 'timeout' => 10 ) );
        if ( ! is_wp_error( $sitemap ) ) {
            preg_match_all( '#<loc>([^<]+)</loc>#', wp_remote_retrieve_body( $sitemap ), $m );
            foreach ( $m[1] as $loc ) {
                if ( count( $urls ) >= $limit || preg_match( '#\.xml$#', $loc ) ) continue;
                $urls[] = $loc;
            }
        }
        return array_slice( array_unique( $urls ), 0, $limit );
    }

    private function store( $found, $known ) {
        global $wpdb;
        $t = $wpdb->prefix . 'ccm_cookies';
        foreach ( $found as $name => $info ) {
            $k      = $known[ $name ] ?? null;
            $row    = array(
                'name'     => $name,
                'provider' => $k['provider'] ?? '',
                'category' => $k['category'] ?? 'unclassified',
                'ctype'    => $info['ctype'] ?? 'http',
                'expiry'   => $k['expiry'] ?? 'session',
                'pages'    => implode( "\n", array_unique( $info['pages'] ) ),
                'source'   => 'auto',
                'status'   => 'active',
            );
            $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE name = %s", $name ) );
            if ( $exists ) {
                $wpdb->update( $t, $row, array( 'id' => $exists ) );
            } else {
                $row['created_at'] = current_time( 'mysql' );
                $wpdb->insert( $t, $row );
            }
        }
    }

    /** Known-cookie dictionary — grow this (public lists exist) or fetch remotely. */
    private function known() {
        return array(
            '_ga'        => array( 'provider' => 'Google',          'category' => 'analytics',     'expiry' => '2 years' ),
            '_gid'       => array( 'provider' => 'Google',          'category' => 'analytics',     'expiry' => '1 day' ),
            '_ga_*'      => array( 'provider' => 'Google',          'category' => 'analytics',     'expiry' => '2 years' ),
            '_gcl_au'    => array( 'provider' => 'Google Ads',      'category' => 'advertisement', 'expiry' => '3 months' ),
            'IDE'        => array( 'provider' => 'Google',          'category' => 'advertisement', 'expiry' => '2 years' ),
            '_fbp'       => array( 'provider' => 'Meta',            'category' => 'advertisement', 'expiry' => '3 months' ),
            'fr'         => array( 'provider' => 'Meta',            'category' => 'advertisement', 'expiry' => '3 months' ),
            '_hjSessionUser' => array( 'provider' => 'Hotjar',      'category' => 'analytics',     'expiry' => '1 year' ),
            'hubspotutk' => array( 'provider' => 'HubSpot',         'category' => 'analytics',     'expiry' => '13 months' ),
            'NID'        => array( 'provider' => 'Google',          'category' => 'advertisement', 'expiry' => '6 months' ),
            'VISITOR_INFO1_LIVE' => array( 'provider' => 'YouTube', 'category' => 'functional',    'expiry' => '6 months' ),
            'YSC'        => array( 'provider' => 'YouTube',         'category' => 'functional',    'expiry' => 'session' ),
        );
    }
}