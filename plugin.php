<?php

/**
 * Plugin Name: Litterateur API
 * Plugin URI: https://litterateur.pro
 * Description: REST API integration for Litterateur content management service
 * Version: 1.0.23
 * Author: Litterateur
 * Author URI: https://litterateur.pro
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: litterateur-api
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('TEXTER_API_VERSION', '1.0.23');
define('TEXTER_API_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TEXTER_API_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include plugin configuration (must be loaded first)
require_once TEXTER_API_PLUGIN_DIR . 'includes/config.php';

// Include site closure early (needs to block before anything else loads)
require_once TEXTER_API_PLUGIN_DIR . 'includes/class-site-closure.php';

// Include required files
require_once TEXTER_API_PLUGIN_DIR . 'includes/class-response.php';
require_once TEXTER_API_PLUGIN_DIR . 'includes/class-auth.php';
require_once TEXTER_API_PLUGIN_DIR . 'includes/class-texter-api.php';
require_once TEXTER_API_PLUGIN_DIR . 'includes/class-google-indexation.php';
require_once TEXTER_API_PLUGIN_DIR . 'includes/class-indexation-admin.php';
require_once TEXTER_API_PLUGIN_DIR . 'includes/class-header-codes.php';
require_once TEXTER_API_PLUGIN_DIR . 'includes/class-external-featured-image.php';
require_once TEXTER_API_PLUGIN_DIR . 'includes/class-s3-storage.php';
require_once TEXTER_API_PLUGIN_DIR . 'includes/class-author-settings.php';

// Include admin page modules
require_once TEXTER_API_PLUGIN_DIR . 'includes/admin/class-admin-header.php';
require_once TEXTER_API_PLUGIN_DIR . 'includes/admin/class-admin-plugins.php';
require_once TEXTER_API_PLUGIN_DIR . 'includes/admin/class-admin-dashboard.php';
require_once TEXTER_API_PLUGIN_DIR . 'includes/admin/class-admin-storage.php';
require_once TEXTER_API_PLUGIN_DIR . 'includes/admin/class-admin-gallery.php';

// Include endpoints
require_once TEXTER_API_PLUGIN_DIR . 'endpoints/class-health.php';
require_once TEXTER_API_PLUGIN_DIR . 'endpoints/class-websites.php';
require_once TEXTER_API_PLUGIN_DIR . 'endpoints/class-keys.php';
require_once TEXTER_API_PLUGIN_DIR . 'endpoints/class-categories.php';
require_once TEXTER_API_PLUGIN_DIR . 'endpoints/class-tags.php';
require_once TEXTER_API_PLUGIN_DIR . 'endpoints/class-topics.php';
require_once TEXTER_API_PLUGIN_DIR . 'endpoints/class-authors.php';
require_once TEXTER_API_PLUGIN_DIR . 'endpoints/class-structured.php';
require_once TEXTER_API_PLUGIN_DIR . 'endpoints/class-data-tables.php';
require_once TEXTER_API_PLUGIN_DIR . 'endpoints/class-gallery.php';

/**
 * Initialize site closure check very early
 * Must run before most plugins to block site access properly
 */
function texter_site_closure_init()
{
    Texter_Site_Closure::init();
}
add_action('init', 'texter_site_closure_init', 1);

/**
 * Initialize the plugin
 */
function texter_api_init()
{
    $plugin = new Texter_API();
    $plugin->init();

    // Initialize indexation cron
    Texter_API_Google_Indexation::init_cron();

    // Initialize header codes
    Texter_API_Header_Codes::init();

    // Initialize external featured image functionality
    Texter_External_Featured_Image::init();

    // Initialize S3 storage AJAX handlers
    Texter_S3_Storage::init_ajax();
}
add_action('plugins_loaded', 'texter_api_init');

/**
 * Activation hook - generate API key on first install
 */
function texter_api_activate()
{
    if (!get_option('texter_api_key')) {
        update_option('texter_api_key', Texter_API_Auth::generate_api_key());
    }

    // Schedule indexation cron if enabled
    if (class_exists('Texter_API_Google_Indexation') && Texter_API_Google_Indexation::is_enabled()) {
        Texter_API_Google_Indexation::schedule_cron();
    }

    // Create S3 images table
    Texter_S3_Storage::create_table();
}
register_activation_hook(__FILE__, 'texter_api_activate');

/**
 * Deactivation hook
 */
function texter_api_deactivate()
{
    // Unschedule indexation cron
    if (class_exists('Texter_API_Google_Indexation')) {
        Texter_API_Google_Indexation::unschedule_cron();
    }
}
register_deactivation_hook(__FILE__, 'texter_api_deactivate');

/**
 * Add Settings link to plugins page
 */
function texter_api_plugin_action_links($links)
{
    $menu_slug = texter_brand('menu_slug', 'litterateur');
    $settings_link = '<a href="' . admin_url('admin.php?page=' . $menu_slug . '-api') . '">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'texter_api_plugin_action_links');
