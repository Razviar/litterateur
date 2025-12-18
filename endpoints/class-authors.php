<?php
/**
 * Authors endpoint for Texter API
 */

if (!defined('ABSPATH')) {
    exit;
}

class Texter_API_Endpoint_Authors {
    
    /**
     * Register routes
     *
     * @param string $namespace
     */
    public function register_routes($namespace) {
        register_rest_route($namespace, '/authors', array(
            array(
                'methods' => 'GET',
                'callback' => array($this, 'get_authors'),
                'permission_callback' => array('Texter_API_Auth', 'check_permission'),
            ),
            array(
                'methods' => 'POST',
                'callback' => array($this, 'create_author'),
                'permission_callback' => array('Texter_API_Auth', 'check_permission'),
            ),
        ));
        
        register_rest_route($namespace, '/authors/(?P<id>\d+)', array(
            array(
                'methods' => 'GET',
                'callback' => array($this, 'get_author'),
                'permission_callback' => array('Texter_API_Auth', 'check_permission'),
            ),
            array(
                'methods' => 'PUT',
                'callback' => array($this, 'update_author'),
                'permission_callback' => array('Texter_API_Auth', 'check_permission'),
            ),
        ));
    }
    
    /**
     * Get all authors
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_authors($request) {
        $args = array(
            'role__in' => array('author', 'editor'),
            'orderby' => 'display_name',
            'order' => 'ASC',
        );
        
        $users = get_users($args);
        
        // Filter out super admins (multisite) and users with manage_options capability
        $filtered_users = array_filter($users, function($user) {
            // Check if user is super admin (multisite)
            if (is_multisite() && is_super_admin($user->ID)) {
                return false;
            }
            // Check if user has manage_options capability (typically admin-level)
            if ($user->has_cap('manage_options')) {
                return false;
            }
            return true;
        });
        
        $authors = array_map(function($user) {
            return $this->format_author($user);
        }, $filtered_users);
        
        // Re-index array after filtering
        $authors = array_values($authors);
        
        return Texter_API_Response::success(array(
            'authors' => $authors,
        ));
    }
    
    /**
     * Get single author
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_author($request) {
        $user_id = intval($request->get_param('id'));
        $user = get_user_by('ID', $user_id);
        
        if (!$user) {
            return Texter_API_Response::not_found('Author not found');
        }
        
        return Texter_API_Response::success($this->format_author($user, true));
    }
    
    /**
     * Create a new author
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function create_author($request) {
        $username = $request->get_param('username');
        $email = $request->get_param('email');
        $display_name = $request->get_param('display_name');
        
        // Validate required fields
        if (empty($username)) {
            return Texter_API_Response::validation_error('Username is required');
        }
        
        if (empty($email)) {
            return Texter_API_Response::validation_error('Email is required');
        }
        
        if (!is_email($email)) {
            return Texter_API_Response::validation_error('Invalid email address');
        }
        
        // Check if username exists
        if (username_exists($username)) {
            return Texter_API_Response::validation_error('Username already exists');
        }
        
        // Check if email exists
        if (email_exists($email)) {
            return Texter_API_Response::validation_error('Email already exists');
        }
        
        // Generate random password
        $password = wp_generate_password(16, true, true);
        
        // Create user
        $user_data = array(
            'user_login' => sanitize_user($username),
            'user_email' => sanitize_email($email),
            'user_pass' => $password,
            'display_name' => $display_name ? sanitize_text_field($display_name) : $username,
            'role' => 'author',
        );
        
        // Optional fields
        if ($request->get_param('first_name')) {
            $user_data['first_name'] = sanitize_text_field($request->get_param('first_name'));
        }
        
        if ($request->get_param('last_name')) {
            $user_data['last_name'] = sanitize_text_field($request->get_param('last_name'));
        }
        
        if ($request->get_param('description')) {
            $user_data['description'] = sanitize_textarea_field($request->get_param('description'));
        }
        
        if ($request->get_param('url')) {
            $user_data['user_url'] = esc_url_raw($request->get_param('url'));
        }
        
        $user_id = wp_insert_user($user_data);
        
        if (is_wp_error($user_id)) {
            return Texter_API_Response::error($user_id->get_error_message());
        }
        
        // Handle avatar upload from base64
        $avatar = $request->get_param('avatar');
        if (!empty($avatar)) {
            $avatar_result = $this->set_user_avatar_from_base64($user_id, $avatar);
            if (is_wp_error($avatar_result)) {
                // Log error but don't fail the user creation
                error_log('Texter API: Failed to set avatar for user ' . $user_id . ': ' . $avatar_result->get_error_message());
            }
        }
        
        // Handle custom meta
        $meta = $request->get_param('meta');
        if (!empty($meta) && is_array($meta)) {
            foreach ($meta as $key => $value) {
                update_user_meta($user_id, sanitize_key($key), $value);
            }
        }
        
        $user = get_user_by('ID', $user_id);
        
        return Texter_API_Response::success($this->format_author($user, true), 201);
    }
    
    /**
     * Update an author
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function update_author($request) {
        $user_id = intval($request->get_param('id'));
        $user = get_user_by('ID', $user_id);
        
        if (!$user) {
            return Texter_API_Response::not_found('Author not found');
        }
        
        $user_data = array('ID' => $user_id);
        
        // Update allowed fields
        if ($request->get_param('display_name') !== null) {
            $user_data['display_name'] = sanitize_text_field($request->get_param('display_name'));
        }
        
        if ($request->get_param('first_name') !== null) {
            $user_data['first_name'] = sanitize_text_field($request->get_param('first_name'));
        }
        
        if ($request->get_param('last_name') !== null) {
            $user_data['last_name'] = sanitize_text_field($request->get_param('last_name'));
        }
        
        if ($request->get_param('description') !== null) {
            $user_data['description'] = sanitize_textarea_field($request->get_param('description'));
        }
        
        if ($request->get_param('url') !== null) {
            $user_data['user_url'] = esc_url_raw($request->get_param('url'));
        }
        
        // Update email if changed
        $new_email = $request->get_param('email');
        if ($new_email && $new_email !== $user->user_email) {
            if (!is_email($new_email)) {
                return Texter_API_Response::validation_error('Invalid email address');
            }
            if (email_exists($new_email)) {
                return Texter_API_Response::validation_error('Email already exists');
            }
            $user_data['user_email'] = sanitize_email($new_email);
        }
        
        $result = wp_update_user($user_data);
        
        if (is_wp_error($result)) {
            return Texter_API_Response::error($result->get_error_message());
        }
        
        // Handle avatar upload from base64
        $avatar = $request->get_param('avatar');
        if (!empty($avatar)) {
            $avatar_result = $this->set_user_avatar_from_base64($user_id, $avatar);
            if (is_wp_error($avatar_result)) {
                // Log error but don't fail the user update
                error_log('Texter API: Failed to set avatar for user ' . $user_id . ': ' . $avatar_result->get_error_message());
            }
        }
        
        // Handle custom meta
        $meta = $request->get_param('meta');
        if (!empty($meta) && is_array($meta)) {
            foreach ($meta as $key => $value) {
                update_user_meta($user_id, sanitize_key($key), $value);
            }
        }
        
        $user = get_user_by('ID', $user_id);
        
        return Texter_API_Response::success($this->format_author($user, true));
    }
    
    /**
     * Set user avatar from base64 encoded image
     * Compatible with basic-user-avatars plugin
     *
     * @param int $user_id
     * @param string $base64_data Base64 encoded image (with or without data URI prefix)
     * @return true|WP_Error
     */
    private function set_user_avatar_from_base64($user_id, $base64_data) {
        // Parse base64 data - handle both 'data:image/jpeg;base64,xxxx' and plain base64
        $extension = 'jpg'; // default
        $image_data = null;
        
        if (preg_match('/^data:image\/([a-zA-Z]+);base64,(.+)$/', $base64_data, $matches)) {
            // Data URI format
            $mime_type = strtolower($matches[1]);
            $base64_content = $matches[2];
            
            // Map mime type to extension
            $mime_to_ext = array(
                'jpeg' => 'jpg',
                'jpg' => 'jpg',
                'png' => 'png',
                'gif' => 'gif',
                'webp' => 'webp',
            );
            
            if (!isset($mime_to_ext[$mime_type])) {
                return new WP_Error('invalid_type', 'Invalid image type. Allowed: jpg, png, gif, webp');
            }
            
            $extension = $mime_to_ext[$mime_type];
            $image_data = base64_decode($base64_content);
        } else {
            // Plain base64 - try to decode and detect type
            $image_data = base64_decode($base64_data);
            
            if ($image_data === false) {
                return new WP_Error('invalid_base64', 'Invalid base64 data');
            }
            
            // Detect image type from magic bytes
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $detected_type = $finfo->buffer($image_data);
            
            $type_to_ext = array(
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
            );
            
            if (!isset($type_to_ext[$detected_type])) {
                return new WP_Error('invalid_type', 'Invalid or unsupported image type: ' . $detected_type);
            }
            
            $extension = $type_to_ext[$detected_type];
        }
        
        if (empty($image_data)) {
            return new WP_Error('empty_image', 'Image data is empty');
        }
        
        // Get user for filename
        $user = get_user_by('ID', $user_id);
        if (!$user) {
            return new WP_Error('user_not_found', 'User not found');
        }
        
        // Generate filename
        $filename = sanitize_file_name(strtolower($user->display_name) . '_avatar.' . $extension);
        
        // Get upload directory
        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['error'])) {
            return new WP_Error('upload_dir_error', $upload_dir['error']);
        }
        
        // Delete old avatar if exists (compatible with basic-user-avatars)
        $old_avatars = get_user_meta($user_id, 'basic_user_avatar', true);
        if (is_array($old_avatars)) {
            foreach ($old_avatars as $old_avatar) {
                $old_avatar_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $old_avatar);
                if (file_exists($old_avatar_path)) {
                    @unlink($old_avatar_path);
                }
            }
        }
        
        // Ensure unique filename
        $file_path = $upload_dir['path'] . '/' . $filename;
        $file_url = $upload_dir['url'] . '/' . $filename;
        
        $counter = 1;
        $base_filename = pathinfo($filename, PATHINFO_FILENAME);
        while (file_exists($file_path)) {
            $filename = $base_filename . '_' . $counter . '.' . $extension;
            $file_path = $upload_dir['path'] . '/' . $filename;
            $file_url = $upload_dir['url'] . '/' . $filename;
            $counter++;
        }
        
        // Save the image
        $saved = file_put_contents($file_path, $image_data);
        if ($saved === false) {
            return new WP_Error('save_failed', 'Failed to save image file');
        }
        
        // Set correct permissions
        chmod($file_path, 0644);
        
        // Update user meta for basic-user-avatars compatibility
        update_user_meta($user_id, 'basic_user_avatar', array('full' => $file_url));
        
        return true;
    }
    
    /**
     * Format author for response
     *
     * @param WP_User $user
     * @param bool $include_details
     * @return array
     */
    private function format_author($user, $include_details = false) {
        $data = array(
            'id' => $user->ID,
            'username' => $user->user_login,
            'email' => $user->user_email,
            'display_name' => $user->display_name,
            'posts_count' => count_user_posts($user->ID, 'post', true),
            'avatar_url' => get_avatar_url($user->ID, array('size' => 96)),
            'description' => $user->description,
        );
        
        if ($include_details) {
            $data['first_name'] = $user->first_name;
            $data['last_name'] = $user->last_name;
            $data['url'] = $user->user_url;
            $data['registered'] = $user->user_registered;
            $data['roles'] = $user->roles;
        }
        
        return $data;
    }
}
