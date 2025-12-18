<?php
/**
 * Websites endpoint for Texter API
 */

if (!defined('ABSPATH')) {
    exit;
}

class Texter_API_Endpoint_Websites {
    
    /**
     * Register routes
     *
     * @param string $namespace
     */
    public function register_routes($namespace) {
        register_rest_route($namespace, '/websites', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_websites'),
            'permission_callback' => array('Texter_API_Auth', 'check_permission'),
        ));
    }
    
    /**
     * Get websites list
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_websites($request) {
        $websites = array();
        
        if (is_multisite()) {
            // Get all sites in multisite network
            $sites = get_sites(array(
                'number' => 0, // All sites
                'public' => 1,
            ));
            
            foreach ($sites as $site) {
                switch_to_blog($site->blog_id);
                
                $websites[] = array(
                    'id' => $site->blog_id,
                    'url' => get_site_url(),
                    'name' => get_bloginfo('name'),
                    'description' => get_bloginfo('description'),
                    'language' => get_bloginfo('language'),
                    'is_main' => is_main_site($site->blog_id),
                );
                
                restore_current_blog();
            }
        } else {
            // Single site installation
            $websites[] = array(
                'id' => get_current_blog_id(),
                'url' => get_site_url(),
                'name' => get_bloginfo('name'),
                'description' => get_bloginfo('description'),
                'language' => get_bloginfo('language'),
                'is_main' => true,
            );
        }
        
        return Texter_API_Response::success(array(
            'websites' => $websites,
            'is_multisite' => is_multisite(),
        ));
    }
}
