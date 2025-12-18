<?php
/**
 * Authentication class for Texter API
 */

if (!defined('ABSPATH')) {
    exit;
}

class Texter_API_Auth {
    
    /**
     * Validate the API key from request
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public static function validate_request($request) {
        $api_key = $request->get_header('X-API-Key');
        
        if (empty($api_key)) {
            return new WP_Error(
                'missing_api_key',
                'API key is required',
                array('status' => 401)
            );
        }
        
        // Handle multisite - switch to requested site before validating key
        self::maybe_switch_to_site($request);
        
        $stored_key = get_option('texter_api_key');
        
        if (empty($stored_key)) {
            return new WP_Error(
                'api_not_configured',
                'API key has not been configured',
                array('status' => 500)
            );
        }
        
        if (!hash_equals($stored_key, $api_key)) {
            return new WP_Error(
                'invalid_api_key',
                'Invalid API key',
                array('status' => 401)
            );
        }
        
        return true;
    }
    
    /**
     * Permission callback for protected endpoints
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public static function check_permission($request) {
        return self::validate_request($request);
    }
    
    /**
     * Switch to the requested site in multisite installations
     *
     * @param WP_REST_Request $request
     * @return bool True if switched, false otherwise
     */
    public static function maybe_switch_to_site($request) {
        if (!is_multisite()) {
            return false;
        }
        
        $site_id = $request->get_header('X-Site-ID');
        
        if (empty($site_id)) {
            return false;
        }
        
        // Try to resolve site ID (can be blog ID, slug, or domain)
        $blog_id = self::resolve_site_id($site_id);
        
        if ($blog_id && $blog_id !== get_current_blog_id()) {
            switch_to_blog($blog_id);
            return true;
        }
        
        return false;
    }
    
    /**
     * Resolve site identifier to blog ID
     * Accepts: numeric blog ID, site slug, or domain
     *
     * @param string|int $site_id
     * @return int|false Blog ID or false if not found
     */
    public static function resolve_site_id($site_id) {
        // If numeric, use directly
        if (is_numeric($site_id)) {
            $blog_id = intval($site_id);
            if (get_blog_details($blog_id)) {
                return $blog_id;
            }
            return false;
        }
        
        // Try to find by path/slug
        $site = get_site_by_path(network_home_url(), '/' . ltrim($site_id, '/') . '/');
        if ($site) {
            return $site->blog_id;
        }
        
        // Try to find by domain
        $sites = get_sites(array(
            'domain' => $site_id,
            'number' => 1,
        ));
        if (!empty($sites)) {
            return $sites[0]->blog_id;
        }
        
        // Try to find by searching all sites for matching slug in path
        $sites = get_sites(array(
            'path__like' => $site_id,
            'number' => 1,
        ));
        if (!empty($sites)) {
            return $sites[0]->blog_id;
        }
        
        return false;
    }
    
    /**
     * Get the current site ID from request
     *
     * @param WP_REST_Request $request
     * @return int Current blog ID
     */
    public static function get_current_site_id($request) {
        if (is_multisite()) {
            $site_id = $request->get_header('X-Site-ID');
            if (!empty($site_id)) {
                $blog_id = self::resolve_site_id($site_id);
                if ($blog_id) {
                    return $blog_id;
                }
            }
        }
        return get_current_blog_id();
    }
    
    /**
     * Generate a new API key
     *
     * @return string
     */
    public static function generate_api_key() {
        return 'txtr_' . bin2hex(random_bytes(32));
    }
    
    /**
     * Rotate the API key
     *
     * @return string New API key
     */
    public static function rotate_api_key() {
        $new_key = self::generate_api_key();
        update_option('texter_api_key', $new_key);
        return $new_key;
    }
    
    /**
     * Get the current API key
     *
     * @return string|false
     */
    public static function get_api_key() {
        return get_option('texter_api_key');
    }
}
