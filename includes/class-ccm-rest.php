<?php
defined( 'ABSPATH' ) || exit;

class CCM_Rest {
    private $s;

    public function __construct( $settings ) {
        $this->s = $settings;
        add_action( 'rest_api_init', array( $this, 'routes' ) );
    }

    public function routes() {
        register_rest_route( 'ccm/v1', '/consent', array(
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'args'                => array(
                'action'        => array( 'required' => true, 'enum' => array( 'accept_all', 'reject_all', 'custom' ) ),
                'uuid'          => array( 'required' => true, 'type' => 'string' ),
                'functional'    => array( 'type' => 'boolean', 'default' => false ),
                'analytics'     => array( 'type' => 'boolean', 'default' => false ),
                'advertisement' => array( 'type' => 'boolean', 'default' => false ),
                'url'           => array( 'type' => 'string' ),
            ),
            'callback'            => array( $this, 'log' ),
        ) );

        register_rest_route( 'ccm/v1', '/scan', array(
            'methods'             => 'POST',
            'permission_callback' => function ( $req ) {
                $stored = ccm_get_scan_token();
                $sent   = $req->get_header( 'X-CCM-Token' );
                return ( '' !== $stored && is_string( $sent ) && hash_equals( $stored, $sent ) );
            },
            'callback'            => array( $this, 'ingest_scan' ),
        ) );
    }

    public function log( $req ) {
        if ( empty( $this->s['log_consents'] ) ) return rest_ensure_response( array( 'ok' => true ) );
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'ccm_consent_logs', array(
            'uuid'            => substr( sanitize_text_field( $req['uuid'] ), 0, 64 ),
            'ip_hash'         => hash( 'sha256', ( $_SERVER['REMOTE_ADDR'] ?? '' ) . wp_salt( 'nonce' ) ),
            'region'          => CCM_Geo::region(),
            'necessary'       => 1,
            'functional'      => $req['functional'] ? 1 : 0,
            'analytics'       => $req['analytics'] ? 1 : 0,
            'advertisement'   => $req['advertisement'] ? 1 : 0,
            'action'          => sanitize_key( $req['action'] ),
            'consent_version' => (int) $this->s['consent_version'],
            'url'             => esc_url_raw( $req['url'] ),
            'user_agent'      => substr( sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 500 ),
            'created_at'      => current_time( 'mysql' ),
        ) );

        return rest_ensure_response( array( 'ok' => true ) );
    }

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
}