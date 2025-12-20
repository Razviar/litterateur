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
        global $wp_version;

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

        return Texter_API_Response::success(array(
            'status' => 'ok',
            'version' => TEXTER_API_VERSION,
            'wordpress_version' => $wp_version,
            'php_version' => phpversion(),
            'site_id' => get_current_blog_id(),
            'site_url' => get_site_url(),
            'site_name' => get_bloginfo('name'),
            'site_logo' => $site_logo_url ? $site_logo_url : null,
            'is_multisite' => is_multisite(),
            'timestamp' => current_time('c'),
        ));
    }
}
