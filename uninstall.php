<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;
global $wpdb;
 $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ccm_cookies" );
 $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ccm_consent_logs" );
delete_option( 'ccm_settings' );
wp_clear_scheduled_hook( 'ccm_scan_event' );