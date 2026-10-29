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
            'permission_callback' => array( $this, 'scan_auth' ),
            'args'                => array(
                'action'        => array( 'required' => true, 'enum' => array( 'accept_all', 'reject_all', 'custom' ) ),
                'uuid'          => array( 'required' => true, 'type' => 'string' ),
                'functional'    => array( 'type' => 'boolean', 'default' => false ),
                'analytics'     => array( 'type' => 'boolean', 'default' => false ),
                'advertisement' => array( 'type' => 'boolean', 'default' => false ),
                'url'           => array( 'type' => 'string' ),
            ),
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
}