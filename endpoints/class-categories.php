<?php
/**
 * Categories endpoint for Texter API
 */

if (!defined('ABSPATH')) {
    exit;
}

class Texter_API_Endpoint_Categories {
    
    /**
     * Register routes
     *
     * @param string $namespace
     */
    public function register_routes($namespace) {
        register_rest_route($namespace, '/categories', array(
            array(
                'methods' => 'GET',
                'callback' => array($this, 'get_categories'),
                'permission_callback' => array('Texter_API_Auth', 'check_permission'),
            ),
            array(
                'methods' => 'POST',
                'callback' => array($this, 'set_categories'),
                'permission_callback' => array('Texter_API_Auth', 'check_permission'),
            ),
        ));
        
        // Single category CRUD operations
        register_rest_route($namespace, '/categories/(?P<id>\d+)', array(
            array(
                'methods' => 'PUT',
                'callback' => array($this, 'update_category'),
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
                'callback' => array($this, 'delete_category'),
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
        
        // Create single category
        register_rest_route($namespace, '/categories/create', array(
            array(
                'methods' => 'POST',
                'callback' => array($this, 'create_category'),
                'permission_callback' => array('Texter_API_Auth', 'check_permission'),
            ),
        ));
    }
    
    /**
     * Get all categories
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_categories($request) {
        $args = array(
            'taxonomy' => 'category',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        );
        
        $categories = get_terms($args);
        
        if (is_wp_error($categories)) {
            return Texter_API_Response::error($categories->get_error_message());
        }
        
        $result = array();
        foreach ($categories as $category) {
            $result[] = array(
                'id' => $category->term_id,
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
                'parent_id' => $category->parent ?: null,
                'count' => $category->count,
            );
        }
        
        return Texter_API_Response::success(array(
            'categories' => $result,
        ));
    }
    
    /**
     * Create or update categories
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function set_categories($request) {
        $categories = $request->get_param('categories');
        
        if (empty($categories) || !is_array($categories)) {
            return Texter_API_Response::validation_error('Categories array is required');
        }
        
        $results = array(
            'created' => array(),
            'updated' => array(),
            'errors' => array(),
        );
        
        foreach ($categories as $cat_data) {
            $name = isset($cat_data['name']) ? sanitize_text_field($cat_data['name']) : '';
            
            if (empty($name)) {
                $results['errors'][] = array(
                    'data' => $cat_data,
                    'error' => 'Category name is required',
                );
                continue;
            }
            
            $args = array(
                'description' => isset($cat_data['description']) ? sanitize_textarea_field($cat_data['description']) : '',
                'slug' => isset($cat_data['slug']) ? sanitize_title($cat_data['slug']) : sanitize_title($name),
            );
            
            // Handle parent category
            if (!empty($cat_data['parent_id'])) {
                $args['parent'] = intval($cat_data['parent_id']);
            } elseif (!empty($cat_data['parent_slug'])) {
                $parent = get_term_by('slug', $cat_data['parent_slug'], 'category');
                if ($parent) {
                    $args['parent'] = $parent->term_id;
                }
            }
            
            // Check if category exists by ID or slug
            $existing = null;
            if (!empty($cat_data['id'])) {
                $existing = get_term($cat_data['id'], 'category');
            }
            if (!$existing && !empty($cat_data['slug'])) {
                $existing = get_term_by('slug', $cat_data['slug'], 'category');
            }
            if (!$existing) {
                $existing = get_term_by('name', $name, 'category');
            }
            
            if ($existing && !is_wp_error($existing)) {
                // Update existing category
                $result = wp_update_term($existing->term_id, 'category', array_merge($args, array('name' => $name)));
                
                if (is_wp_error($result)) {
                    $results['errors'][] = array(
                        'data' => $cat_data,
                        'error' => $result->get_error_message(),
                    );
                } else {
                    $term = get_term($result['term_id'], 'category');
                    $results['updated'][] = array(
                        'id' => $term->term_id,
                        'name' => $term->name,
                        'slug' => $term->slug,
                    );
                }
            } else {
                // Create new category
                $result = wp_insert_term($name, 'category', $args);
                
                if (is_wp_error($result)) {
                    $results['errors'][] = array(
                        'data' => $cat_data,
                        'error' => $result->get_error_message(),
                    );
                } else {
                    $term = get_term($result['term_id'], 'category');
                    $results['created'][] = array(
                        'id' => $term->term_id,
                        'name' => $term->name,
                        'slug' => $term->slug,
                    );
                }
            }
        }
        
        // Get updated list of all categories
        $args = array(
            'taxonomy' => 'category',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        );
        
        $all_categories = get_terms($args);
        $categories_list = array();
        
        if (!is_wp_error($all_categories)) {
            foreach ($all_categories as $category) {
                $categories_list[] = array(
                    'id' => $category->term_id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'description' => $category->description,
                    'parent_id' => $category->parent ?: null,
                    'count' => $category->count,
                );
            }
        }
        
        $results['categories'] = $categories_list;
        
        return Texter_API_Response::success($results);
    }
    
    /**
     * Create a single category
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function create_category($request) {
        $name = $request->get_param('name');
        
        if (empty($name)) {
            return Texter_API_Response::validation_error('Category name is required');
        }
        
        $name = sanitize_text_field($name);
        
        // Check if category already exists
        $existing = get_term_by('name', $name, 'category');
        if ($existing) {
            return Texter_API_Response::error('Category already exists', 'category_exists');
        }
        
        $args = array(
            'description' => $request->get_param('description') ? sanitize_textarea_field($request->get_param('description')) : '',
            'slug' => $request->get_param('slug') ? sanitize_title($request->get_param('slug')) : sanitize_title($name),
        );
        
        // Handle parent category
        $parent_id = $request->get_param('parent_id');
        if (!empty($parent_id)) {
            $args['parent'] = intval($parent_id);
        }
        
        $result = wp_insert_term($name, 'category', $args);
        
        if (is_wp_error($result)) {
            return Texter_API_Response::error($result->get_error_message());
        }
        
        $term = get_term($result['term_id'], 'category');
        
        $created = array(
            'id' => $term->term_id,
            'name' => $term->name,
            'slug' => $term->slug,
            'description' => $term->description,
            'parent_id' => $term->parent ?: null,
            'count' => $term->count,
        );
        
        return Texter_API_Response::success(array(
            'category' => $created,
            'categories' => $this->get_all_categories_list(),
        ));
    }
    
    /**
     * Update a single category
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function update_category($request) {
        $id = intval($request->get_param('id'));
        
        $term = get_term($id, 'category');
        if (!$term || is_wp_error($term)) {
            return Texter_API_Response::not_found('Category not found');
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
        
        // Check if parent_id was explicitly sent in the request (including null to remove parent)
        $json_params = $request->get_json_params();
        if (is_array($json_params) && array_key_exists('parent_id', $json_params)) {
            $parent_id = $json_params['parent_id'];
            // null or 0 means top-level (no parent), otherwise use the provided ID
            $args['parent'] = ($parent_id === null || $parent_id === 0) ? 0 : intval($parent_id);
        }
        
        if (empty($args)) {
            return Texter_API_Response::validation_error('No fields to update');
        }
        
        $result = wp_update_term($id, 'category', $args);
        
        if (is_wp_error($result)) {
            return Texter_API_Response::error($result->get_error_message());
        }
        
        $term = get_term($result['term_id'], 'category');
        
        $updated = array(
            'id' => $term->term_id,
            'name' => $term->name,
            'slug' => $term->slug,
            'description' => $term->description,
            'parent_id' => $term->parent ?: null,
            'count' => $term->count,
        );
        
        return Texter_API_Response::success(array(
            'category' => $updated,
            'categories' => $this->get_all_categories_list(),
        ));
    }
    
    /**
     * Delete a category
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function delete_category($request) {
        $id = intval($request->get_param('id'));
        
        $term = get_term($id, 'category');
        if (!$term || is_wp_error($term)) {
            return Texter_API_Response::not_found('Category not found');
        }
        
        // Check if category has posts
        if ($term->count > 0) {
            return Texter_API_Response::error(
                sprintf('Category deletion is not allowed - there are %d posts in this category', $term->count),
                'category_not_empty'
            );
        }
        
        // Check if category has children (subcategories)
        $children = get_term_children($id, 'category');
        if (!empty($children) && !is_wp_error($children)) {
            return Texter_API_Response::error(
                sprintf('Category deletion is not allowed - there are %d subcategories', count($children)),
                'category_has_children'
            );
        }
        
        // Prevent deleting the default category
        $default_category = get_option('default_category');
        if ($id == $default_category) {
            return Texter_API_Response::error(
                'Cannot delete the default category',
                'default_category'
            );
        }
        
        $result = wp_delete_term($id, 'category');
        
        if (is_wp_error($result)) {
            return Texter_API_Response::error($result->get_error_message());
        }
        
        return Texter_API_Response::success(array(
            'deleted' => true,
            'id' => $id,
            'categories' => $this->get_all_categories_list(),
        ));
    }
    
    /**
     * Helper: Get all categories as a list
     *
     * @return array
     */
    private function get_all_categories_list() {
        $args = array(
            'taxonomy' => 'category',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        );
        
        $all_categories = get_terms($args);
        $categories_list = array();
        
        if (!is_wp_error($all_categories)) {
            foreach ($all_categories as $category) {
                $categories_list[] = array(
                    'id' => $category->term_id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'description' => $category->description,
                    'parent_id' => $category->parent ?: null,
                    'count' => $category->count,
                );
            }
        }
        
        return $categories_list;
    }
}
