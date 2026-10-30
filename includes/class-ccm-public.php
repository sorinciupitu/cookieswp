<?php
defined( 'ABSPATH' ) || exit;

class CCM_Public {
    private $s;

    public function __construct( $settings ) {
        $this->s = $settings;
        add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
        add_action( 'wp_head', array( $this, 'consent_mode_defaults' ), 1 );
    }

    private function should_show() {
        if ( empty( $this->s['enabled'] ) ) return false;
        if ( ! empty( $this->s['geo_enabled'] ) ) {
            return CCM_Geo::matches( (array) $this->s['geo_regions'] );
        }
        return true;
    }

    public function assets() {
        wp_register_script( 'ccm-consent', CCM_URL . 'public/js/consent.js', array(), CCM_VERSION, true );
        wp_localize_script( 'ccm-consent', 'CCM_CONFIG', array(
            'enabled'        => (bool) $this->should_show(),
            'restUrl'        => esc_url_raw( rest_url( 'ccm/v1/consent' ) ),
            'consentVersion' => (int) $this->s['consent_version'],
            'expiry'         => (int) $this->s['cookie_expiry_days'],
            'gcm'            => (bool) $this->s['gcm_enabled'],
            'gpc'            => (bool) $this->s['gpc_enabled'],
            'log'            => (bool) $this->s['log_consents'],
            'layout'         => $this->s['layout'],
            'position'       => $this->s['position'],
            'showReject'     => in_array( $this->s['compliance_mode'], array( 'ccpa', 'both' ), true ),
            'colors'         => $this->s['colors'],
            'categories'     => $this->s['categories'],
            'texts'          => $this->s['texts'],
        ) );
        wp_enqueue_script( 'ccm-consent' );
        wp_enqueue_style( 'ccm-consent', CCM_URL . 'public/css/consent.css', array(), CCM_VERSION );
    }

    /** Google Consent Mode v2 — denied defaults, before GTM/gtag loads */
    public function consent_mode_defaults() {
        if ( empty( $this->s['gcm_enabled'] ) ) return;
        ?>
        <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('consent','default',{
            ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',
            analytics_storage:'denied',functionality_storage:'denied',
            personalization_storage:'denied',security_storage:'granted',
            wait_for_update:500
        });
        </script>
        <?php
    }
}