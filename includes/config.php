<?php

/**
 * Plugin Configuration
 *
 * Edit this file to customize branding and other plugin settings.
 * This centralizes configuration to make white-labeling easy.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get branding configuration
 *
 * @return array Branding configuration values
 */
function texter_get_branding() {
    return array(
        // Brand name displayed in menus and UI
        'name'           => 'Litterateur',

        // Plugin description
        'description'    => 'REST API integration for Litterateur content management service',

        // Control panel base URL (without trailing slash)
        'panel_url'      => 'https://litterateur.pro/panel',

        // Website URL for the brand
        'website_url'    => 'https://litterateur.pro',

        // REST API namespace (used in API routes like /wp-json/{namespace}/v1/)
        'api_namespace'  => 'litterateur',

        // Menu slug prefix (used in WordPress admin URLs)
        'menu_slug'      => 'litterateur',

        // Text domain for translations
        'text_domain'    => 'litterateur-api',
    );
}

/**
 * Helper function to get a specific branding value
 *
 * @param string $key The branding key to retrieve
 * @param string $default Default value if key doesn't exist
 * @return string The branding value
 */
function texter_brand($key, $default = '') {
    $branding = texter_get_branding();
    return isset($branding[$key]) ? $branding[$key] : $default;
}

/**
 * Get the control panel URL for the current site
 *
 * @return string The full control panel URL
 */
function texter_get_panel_url() {
    $site_url = get_site_url();
    $parsed = parse_url($site_url);
    $domain = $parsed['host'] ?? '';
    return texter_brand('panel_url') . '/websites/' . str_replace('.', '-', $domain);
}
