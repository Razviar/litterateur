<?php

/**
 * Health endpoint for Texter API
 */

if (!defined('ABSPATH')) {
    exit;
}

class Texter_API_Endpoint_Health
{

    /**
     * Register routes
     *
     * @param string $namespace
     */
    public function register_routes($namespace)
    {
        register_rest_route($namespace, '/health', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_health'),
            'permission_callback' => '__return_true', // Health check is public
        ));
    }

    /**
     * Get API health status
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_health($request)
    {
        global $wp_version, $wpdb;

        // Handle multisite - switch to requested site if specified
        Texter_API_Auth::maybe_switch_to_site($request);

        // Get site icon (favicon) - prioritize this over custom logo
        $site_logo_url = '';
        $site_icon_id = get_option('site_icon');
        if ($site_icon_id) {
            $site_logo_url = wp_get_attachment_image_url($site_icon_id, 'medium');
        }
        // Fallback to custom logo if no site icon
        if (empty($site_logo_url)) {
            $custom_logo_id = get_theme_mod('custom_logo');
            if ($custom_logo_id) {
                $site_logo_url = wp_get_attachment_image_url($custom_logo_id, 'medium');
            }
        }

        // Build system info object
        $system = array(
            'type' => 'wordpress',
            'wordpress_version' => $wp_version,
            'php_version' => phpversion(),
            'is_multisite' => is_multisite(),
        );

        // Add database version
        if (method_exists($wpdb, 'db_version')) {
            $system['db_version'] = $wpdb->db_version();
        }

        // Add active theme
        $theme = wp_get_theme();
        if ($theme) {
            $system['theme'] = $theme->get('Name');
        }

        // Add server software
        if (!empty($_SERVER['SERVER_SOFTWARE'])) {
            $system['server'] = sanitize_text_field($_SERVER['SERVER_SOFTWARE']);
        }

        // Add max upload size
        $system['max_upload_size'] = wp_max_upload_size();

        // Add memory limit
        $memory_limit = ini_get('memory_limit');
        if ($memory_limit) {
            $system['memory_limit'] = wp_convert_hr_to_bytes($memory_limit);
        }

        // Add debug mode status
        $system['debug_mode'] = defined('WP_DEBUG') && WP_DEBUG;

        return Texter_API_Response::success(array(
            'status' => 'ok',
            'version' => TEXTER_API_VERSION,
            'system' => $system,
            'site_id' => get_current_blog_id(),
            'site_url' => get_site_url(),
            'site_name' => get_bloginfo('name'),
            'site_logo' => $site_logo_url ? $site_logo_url : null,
            'timestamp' => current_time('c'),
        ));
    }
}
