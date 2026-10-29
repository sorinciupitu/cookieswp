<?php
defined( 'ABSPATH' ) || exit;

class CCM_Geo {
    private static $eu = array( 'AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IE','IT',
        'LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE','IS','LI','NO' );

    public static function region() {
        // Prefer free edge headers (Cloudflare, CloudFront, Apache mod_geoip) — no latency, cache-safe.
        foreach ( array( 'HTTP_CF_IPCOUNTRY', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY', 'GEOIP_COUNTRY_CODE' ) as $k ) {
            if ( ! empty( $_SERVER[ $k ] ) ) return sanitize_text_field( $_SERVER[ $k ] );
        }
        // Fallback: cached IP lookup (avoid on high-traffic sites — use a CDN header instead).
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if ( ! $ip ) return '';
        $key = 'ccm_geo_' . md5( $ip );
        $cc  = get_transient( $key );
        if ( false === $cc ) {
            $res = wp_remote_get( 'https://ipapi.co/' . rawurlencode( $ip ) . '/country/', array( 'timeout' => 3 ) );
            $cc  = is_wp_error( $res ) ? '' : strtoupper( trim( wp_remote_retrieve_body( $res ) ) );
            set_transient( $key, $cc, DAY_IN_SECONDS );
        }
        return $cc;
    }

    public static function matches( array $regions ) {
        $cc = self::region();
        if ( ! $cc ) return false;
        if ( in_array( $cc, $regions, true ) ) return true;
        if ( in_array( 'EU', $regions, true ) && in_array( $cc, self::$eu, true ) ) return true;
        return false;
    }
}