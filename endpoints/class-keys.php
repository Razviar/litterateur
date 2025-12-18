<?php
/**
 * Keys endpoint for Texter API
 */

if (!defined('ABSPATH')) {
    exit;
}

class Texter_API_Endpoint_Keys {
    
    /**
     * Register routes
     *
     * @param string $namespace
     */
    public function register_routes($namespace) {
        register_rest_route($namespace, '/keys/rotate', array(
            'methods' => 'POST',
            'callback' => array($this, 'rotate_key'),
            'permission_callback' => array('Texter_API_Auth', 'check_permission'),
        ));
    }
    
    /**
     * Rotate API key
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function rotate_key($request) {
        $new_key = Texter_API_Auth::rotate_api_key();
        
        return Texter_API_Response::success(array(
            'key' => $new_key,
            'message' => 'API key rotated successfully. Please update your configuration with the new key.',
        ));
    }
}
