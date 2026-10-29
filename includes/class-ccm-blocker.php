<?php
defined( 'ABSPATH' ) || exit;

class CCM_Blocker {
    private $s;

    private $scripts = array(
        'googletagmanager.com'    => 'analytics',
        'google-analytics.com'    => 'analytics',
        'connect.facebook.net'    => 'advertisement',
        'facebook.com/tr'         => 'advertisement',
        'static.hotjar.com'       => 'analytics',
        'clarity.ms'              => 'analytics',
        'static.doubleclick.net'  => 'advertisement',
        'securepubads.g.doubleclick.net' => 'advertisement',
        'snap.licdn.com'          => 'advertisement',
    );

    private $iframes = array(
        'youtube.com'           => 'functional',
        'youtube-nocookie.com'  => 'functional',
        'player.vimeo.com'      => 'functional',
        'google.com/maps'       => 'functional',
        'facebook.com/plugins'  => 'advertisement',
    );

    public function __construct( $settings ) {
        $this->s = $settings;
        if ( empty( $settings['enabled'] ) || is_admin() ) return;
        add_action( 'template_redirect', array( $this, 'start_buffer' ), 1 );
    }

    public function start_buffer() {
        if ( wp_doing_ajax() || wp_doing_cron() || defined( 'REST_REQUEST' ) ) return;
        ob_start( array( $this, 'rewrite' ) );
    }

    private function map( array $base ) {
        foreach ( (array) $this->s['custom_rules'] as $rule ) {
            if ( ! empty( $rule['match'] ) && ! empty( $rule['category'] ) ) {
                $base[ $rule['match'] ] = $rule['category'];
            }
        }
        return $base;
    }

    public function rewrite( $html ) {
        if ( ! empty( $this->s['block_scripts'] ) ) {
            $map = $this->map( $this->scripts );
            $html = preg_replace_callback( '#<script\b([^>]*)>(.*?)</script>#is', function ( $m ) use ( $map ) {
                $attrs = $m[1];
                if ( false !== stripos( $attrs, 'application/ld+json' ) || false !== stripos( $attrs, 'data-ccm' ) ) {
                    return $m[0];
                }
                $cat = '';
                foreach ( $map as $needle => $c ) {
                    if ( false !== stripos( $attrs, $needle ) || false !== stripos( $m[2], $needle ) ) { $cat = $c; break; }
                }
                if ( ! $cat ) return $m[0];
                $attrs = preg_replace( '/\s+type\s*=\s*("|\')(?:(?!\1).)*\1/i', '', $attrs );
                return '<script type="text/plain" data-ccm-category="' . esc_attr( $cat ) . '"' . $attrs . '>' . $m[2] . '</script>';
            }, $html );
        }

        if ( ! empty( $this->s['block_iframes'] ) ) {
            $map = $this->map( $this->iframes );
            $html = preg_replace_callback( '#<iframe\b([^>]*)>#i', function ( $m ) use ( $map ) {
                $attrs = $m[1];
                foreach ( $map as $needle => $cat ) {
                    if ( false === stripos( $attrs, $needle ) ) continue;
                    if ( preg_match( '/\ssrc\s*=\s*("|\')([^"\']+)\1/i', $attrs, $src ) ) {
                        $attrs = str_replace( $src[0], ' data-ccm-src="' . esc_attr( $src[2] ) . '"', $attrs );
                    }
                    return '<iframe data-ccm-category="' . esc_attr( $cat ) . '"' . $attrs . '></iframe>';
                }
                return $m[0];
            }, $html );
        }
        return $html;
    }
}