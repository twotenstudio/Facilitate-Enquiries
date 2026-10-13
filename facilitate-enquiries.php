<?php
/**
 * Plugin Name: Facilitate Enquiries
 * Description: Sends booking enquiries from your Gravity Forms (e.g. the booking form on each property page) to your Facilitate back office, filed on the guest's profile with live availability and pricing.
 * Version: 1.1.0
 * Update URI: https://github.com/twotenstudio/Facilitate-Enquiries
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Two Ten Studio
 * Plugin URI: https://github.com/twotenstudio/Facilitate-Enquiries
 * License: GPL-2.0-or-later
 * Text Domain: facilitate-enquiries
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'FACILITATE_ENQUIRIES_VERSION', '1.1.0' );
define( 'FACILITATE_ENQUIRIES_PATH', plugin_dir_path( __FILE__ ) );

require_once FACILITATE_ENQUIRIES_PATH . 'includes/class-facilitate-enquiries-settings.php';
require_once FACILITATE_ENQUIRIES_PATH . 'includes/class-facilitate-enquiries-mapper.php';
require_once FACILITATE_ENQUIRIES_PATH . 'includes/class-facilitate-enquiries-sender.php';
require_once FACILITATE_ENQUIRIES_PATH . 'includes/class-facilitate-enquiries-updater.php';

add_action( 'plugins_loaded', function () {
    Facilitate_Enquiries_Settings::init();
    Facilitate_Enquiries_Sender::init();
} );

// Updates come from this plugin's GitHub releases (see the updater class).
Facilitate_Enquiries_Updater::init( plugin_basename( __FILE__ ) );

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
    array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=facilitate-enquiries' ) ) . '">Settings</a>' );
    return $links;
} );

register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( 'facilitate_enquiries_retry' );
} );
