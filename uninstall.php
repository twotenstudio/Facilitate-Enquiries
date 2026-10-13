<?php
/**
 * Removes the plugin's settings. Entry notes and the per-entry "sent" marks
 * stay with the Gravity Forms entries they describe.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'facilitate_enquiries_settings' );
