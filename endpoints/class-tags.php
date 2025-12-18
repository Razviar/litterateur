<?php
/**
 * Tags endpoint for Texter API
 */

if (!defined('ABSPATH')) {
    exit;
}

class Texter_API_Endpoint_Tags {
    
    /**
     * Register routes
     *
     * @param string $namespace
     */
    public function register_routes($namespace) {
        register_rest_route($namespace, '/tags', array(
            array(
                'methods' => 'GET',
                'callback' => array($this, 'get_tags'),
                'permission_callback' => array('Texter_API_Auth', 'check_permission'),
            ),
            array(
                'methods' => 'POST',
                'callback' => array($this, 'set_tags'),
                'permission_callback' => array('Texter_API_Auth', 'check_permission'),
            ),
        ));
        
        // Single tag CRUD operations
        register_rest_route($namespace, '/tags/(?P<id>\d+)', array(
            array(
                'methods' => 'PUT',
                'callback' => array($this, 'update_tag'),
                'permission_callback' => array('Texter_API_Auth', 'check_permission'),
                'args' => array(
                    'id' => array(
                        'required' => true,
                        'validate_callback' => function($param) {
                            return is_numeric($param);
                        }
                    ),
                ),
            ),
            array(
                'methods' => 'DELETE',
                'callback' => array($this, 'delete_tag'),
                'permission_callback' => array('Texter_API_Auth', 'check_permission'),
                'args' => array(
                    'id' => array(
                        'required' => true,
                        'validate_callback' => function($param) {
                            return is_numeric($param);
                        }
                    ),
                ),
            ),
        ));
        
        // Create single tag
        register_rest_route($namespace, '/tags/create', array(
            array(
                'methods' => 'POST',
                'callback' => array($this, 'create_tag'),
                'permission_callback' => array('Texter_API_Auth', 'check_permission'),
            ),
        ));
    }
    
    /**
     * Get all tags
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_tags($request) {
        $args = array(
            'taxonomy' => 'post_tag',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        );
        
        $tags = get_terms($args);
        
        if (is_wp_error($tags)) {
            return Texter_API_Response::error($tags->get_error_message());
        }
        
        $result = array();
        foreach ($tags as $tag) {
            $result[] = array(
                'id' => $tag->term_id,
                'name' => $tag->name,
                'slug' => $tag->slug,
                'description' => $tag->description,
                'count' => $tag->count,
            );
        }
        
        return Texter_API_Response::success(array(
            'tags' => $result,
        ));
    }
    
    /**
     * Create or update tags
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function set_tags($request) {
        $tags = $request->get_param('tags');
        
        if (empty($tags) || !is_array($tags)) {
            return Texter_API_Response::validation_error('Tags array is required');
        }
        
        $results = array(
            'created' => array(),
            'updated' => array(),
            'errors' => array(),
        );
        
        foreach ($tags as $tag_data) {
            $name = isset($tag_data['name']) ? sanitize_text_field($tag_data['name']) : '';
            
            if (empty($name)) {
                $results['errors'][] = array(
                    'data' => $tag_data,
                    'error' => 'Tag name is required',
                );
                continue;
            }
            
            $args = array(
                'description' => isset($tag_data['description']) ? sanitize_textarea_field($tag_data['description']) : '',
                'slug' => isset($tag_data['slug']) ? sanitize_title($tag_data['slug']) : sanitize_title($name),
            );
            
            // Check if tag exists by ID or slug
            $existing = null;
            if (!empty($tag_data['id'])) {
                $existing = get_term($tag_data['id'], 'post_tag');
            }
            if (!$existing && !empty($tag_data['slug'])) {
                $existing = get_term_by('slug', $tag_data['slug'], 'post_tag');
            }
            if (!$existing) {
                $existing = get_term_by('name', $name, 'post_tag');
            }
            
            if ($existing && !is_wp_error($existing)) {
                // Update existing tag
                $result = wp_update_term($existing->term_id, 'post_tag', array_merge($args, array('name' => $name)));
                
                if (is_wp_error($result)) {
                    $results['errors'][] = array(
                        'data' => $tag_data,
                        'error' => $result->get_error_message(),
                    );
                } else {
                    $term = get_term($result['term_id'], 'post_tag');
                    $results['updated'][] = array(
                        'id' => $term->term_id,
                        'name' => $term->name,
                        'slug' => $term->slug,
                    );
                }
            } else {
                // Create new tag
                $result = wp_insert_term($name, 'post_tag', $args);
                
                if (is_wp_error($result)) {
                    $results['errors'][] = array(
                        'data' => $tag_data,
                        'error' => $result->get_error_message(),
                    );
                } else {
                    $term = get_term($result['term_id'], 'post_tag');
                    $results['created'][] = array(
                        'id' => $term->term_id,
                        'name' => $term->name,
                        'slug' => $term->slug,
                    );
                }
            }
        }
        
        $results['tags'] = $this->get_all_tags_list();
        
        return Texter_API_Response::success($results);
    }
    
    /**
     * Create a single tag
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function create_tag($request) {
        $name = $request->get_param('name');
        
        if (empty($name)) {
            return Texter_API_Response::validation_error('Tag name is required');
        }
        
        $name = sanitize_text_field($name);
        
        // Check if tag already exists
        $existing = get_term_by('name', $name, 'post_tag');
        if ($existing) {
            return Texter_API_Response::error('Tag already exists', 'tag_exists');
        }
        
        $args = array(
            'description' => $request->get_param('description') ? sanitize_textarea_field($request->get_param('description')) : '',
            'slug' => $request->get_param('slug') ? sanitize_title($request->get_param('slug')) : sanitize_title($name),
        );
        
        $result = wp_insert_term($name, 'post_tag', $args);
        
        if (is_wp_error($result)) {
            return Texter_API_Response::error($result->get_error_message());
        }
        
        $term = get_term($result['term_id'], 'post_tag');
        
        $created = array(
            'id' => $term->term_id,
            'name' => $term->name,
            'slug' => $term->slug,
            'description' => $term->description,
            'count' => $term->count,
        );
        
        return Texter_API_Response::success(array(
            'tag' => $created,
            'tags' => $this->get_all_tags_list(),
        ));
    }
    
    /**
     * Update a single tag
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function update_tag($request) {
        $id = intval($request->get_param('id'));
        
        $term = get_term($id, 'post_tag');
        if (!$term || is_wp_error($term)) {
            return Texter_API_Response::not_found('Tag not found');
        }
        
        $args = array();
        
        $name = $request->get_param('name');
        if (!empty($name)) {
            $args['name'] = sanitize_text_field($name);
        }
        
        $description = $request->get_param('description');
        if ($description !== null) {
            $args['description'] = sanitize_textarea_field($description);
        }
        
        $slug = $request->get_param('slug');
        if (!empty($slug)) {
            $args['slug'] = sanitize_title($slug);
        }
        
        if (empty($args)) {
            return Texter_API_Response::validation_error('No fields to update');
        }
        
        $result = wp_update_term($id, 'post_tag', $args);
        
        if (is_wp_error($result)) {
            return Texter_API_Response::error($result->get_error_message());
        }
        
        $term = get_term($result['term_id'], 'post_tag');
        
        $updated = array(
            'id' => $term->term_id,
            'name' => $term->name,
            'slug' => $term->slug,
            'description' => $term->description,
            'count' => $term->count,
        );
        
        return Texter_API_Response::success(array(
            'tag' => $updated,
            'tags' => $this->get_all_tags_list(),
        ));
    }
    
    /**
     * Delete a tag
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function delete_tag($request) {
        $id = intval($request->get_param('id'));
        
        $term = get_term($id, 'post_tag');
        if (!$term || is_wp_error($term)) {
            return Texter_API_Response::not_found('Tag not found');
        }
        
        $result = wp_delete_term($id, 'post_tag');
        
        if (is_wp_error($result)) {
            return Texter_API_Response::error($result->get_error_message());
        }
        
        return Texter_API_Response::success(array(
            'deleted' => true,
            'id' => $id,
            'tags' => $this->get_all_tags_list(),
        ));
    }
    
    /**
     * Helper: Get all tags as a list
     *
     * @return array
     */
    private function get_all_tags_list() {
        $args = array(
            'taxonomy' => 'post_tag',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        );
        
        $all_tags = get_terms($args);
        $tags_list = array();
        
        if (!is_wp_error($all_tags)) {
            foreach ($all_tags as $tag) {
                $tags_list[] = array(
                    'id' => $tag->term_id,
                    'name' => $tag->name,
                    'slug' => $tag->slug,
                    'description' => $tag->description,
                    'count' => $tag->count,
                );
            }
        }
        
        return $tags_list;
    }
}
