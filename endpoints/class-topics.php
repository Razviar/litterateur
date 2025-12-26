<?php

/**
 * Topics endpoint for Texter API
 */

if (!defined('ABSPATH')) {
    exit;
}

class Texter_API_Endpoint_Topics
{

    /**
     * Register routes
     *
     * @param string $namespace
     */
    public function register_routes($namespace)
    {
        register_rest_route($namespace, '/topics', array(
            'methods' => 'POST',
            'callback' => array($this, 'publish_topic'),
            'permission_callback' => array('Texter_API_Auth', 'check_permission'),
        ));

        register_rest_route($namespace, '/topics/(?P<id>\d+)', array(
            array(
                'methods' => 'GET',
                'callback' => array($this, 'get_topic'),
                'permission_callback' => array('Texter_API_Auth', 'check_permission'),
            ),
            array(
                'methods' => 'PUT',
                'callback' => array($this, 'update_topic'),
                'permission_callback' => array('Texter_API_Auth', 'check_permission'),
            ),
            array(
                'methods' => 'DELETE',
                'callback' => array($this, 'delete_topic'),
                'permission_callback' => array('Texter_API_Auth', 'check_permission'),
            ),
        ));
    }

    /**
     * Publish a new topic (post)
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function publish_topic($request)
    {
        $title = $request->get_param('title');
        $content = $request->get_param('content');

        if (empty($title)) {
            return Texter_API_Response::validation_error('Title is required');
        }

        if (empty($content)) {
            return Texter_API_Response::validation_error('Content is required');
        }

        // Handle embed images - replace ##**META_IMAGE** placeholders with actual images
        $embed_images = $request->get_param('embed_images');
        if (!empty($embed_images) && is_array($embed_images)) {
            $content = $this->process_embed_images($content, $embed_images);
        } else {
            // Remove any remaining ##**META_IMAGE** placeholders if no embed images provided
            $content = preg_replace('/##\*\*META_IMAGE\*\*/m', '', $content);
        }

        // Convert markdown content to Gutenberg blocks
        $gutenberg_content = $this->markdown_to_gutenberg($content);

        // Prepare post data
        $post_data = array(
            'post_title' => sanitize_text_field($title),
            'post_content' => $gutenberg_content,
            'post_status' => $request->get_param('status') ?: 'publish',
            'post_type' => 'post',
        );

        // Handle excerpt (description)
        if ($request->get_param('excerpt')) {
            $post_data['post_excerpt'] = sanitize_textarea_field($request->get_param('excerpt'));
        }

        // Handle slug - use short_title if available, otherwise use explicit slug
        $short_title = $request->get_param('short_title');
        $slug = $request->get_param('slug');
        if (!empty($short_title)) {
            $post_data['post_name'] = sanitize_title($short_title);
        } elseif (!empty($slug)) {
            $post_data['post_name'] = sanitize_title($slug);
        }

        // Handle author - if not specified, pick a random author from the site
        $author_id = $request->get_param('author_id');
        if ($author_id) {
            $post_data['post_author'] = intval($author_id);
        } else {
            // Get users with 'author' or 'editor' role (use role__in for multiple roles)
            $authors = get_users(array(
                'role__in' => array('author', 'editor'),
                'number' => 50,
                'orderby' => 'rand',
            ));

            // Filter out super admins and users with manage_options capability
            $authors = array_filter($authors, function ($user) {
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

            if (empty($authors)) {
                // Fallback to any user who can publish posts but exclude admins
                $authors = get_users(array(
                    'capability' => 'publish_posts',
                    'number' => 50,
                    'orderby' => 'rand',
                ));

                // Filter out admins from fallback as well
                $authors = array_filter($authors, function ($user) {
                    if (is_multisite() && is_super_admin($user->ID)) {
                        return false;
                    }
                    if ($user->has_cap('manage_options')) {
                        return false;
                    }
                    return true;
                });
            }

            if (!empty($authors)) {
                $authors = array_values($authors); // Re-index after filtering
                $random_author = $authors[array_rand($authors)];
                $post_data['post_author'] = $random_author->ID;
            }
        }

        // Handle publish date
        if ($request->get_param('publish_date')) {
            $post_data['post_date'] = $request->get_param('publish_date');
        }

        // Insert the post
        $post_id = wp_insert_post($post_data, true);

        if (is_wp_error($post_id)) {
            return Texter_API_Response::error($post_id->get_error_message());
        }

        // Handle categories - priority: category_id > category name with hierarchy parsing
        $category_id_param = $request->get_param('category_id');
        $category = $request->get_param('category');

        if (!empty($category_id_param)) {
            // Try to use the provided category ID first
            $term = get_term(intval($category_id_param), 'category');
            if ($term && !is_wp_error($term)) {
                wp_set_post_categories($post_id, array($term->term_id));
            } elseif (!empty($category)) {
                // Category ID is invalid, fall back to name
                $resolved_category_id = $this->resolve_hierarchical_category($category);
                if ($resolved_category_id) {
                    wp_set_post_categories($post_id, array($resolved_category_id));
                }
            }
        } elseif (!empty($category)) {
            // No category_id provided, resolve by name (with hierarchy parsing)
            $resolved_category_id = $this->resolve_hierarchical_category($category);
            if ($resolved_category_id) {
                wp_set_post_categories($post_id, array($resolved_category_id));
            }
        } else {
            // Fallback to categories array if provided
            $categories = $request->get_param('categories');
            if (!empty($categories)) {
                $category_ids = $this->resolve_categories($categories);
                if (!empty($category_ids)) {
                    wp_set_post_categories($post_id, $category_ids);
                }
            }
        }

        // Handle tags
        $tags = $request->get_param('tags');
        if (!empty($tags)) {
            if (is_array($tags)) {
                wp_set_post_tags($post_id, $tags);
            } else {
                wp_set_post_tags($post_id, array_map('trim', explode(',', $tags)));
            }
        }

        // Handle featured image - support gallery ID, URL and base64
        $featured_image_id = $request->get_param('featured_image_id');
        $featured_image_url = $request->get_param('featured_image_url');
        $featured_image = $request->get_param('featured_image');
        $image_title = $request->get_param('image_title');

        if (!empty($featured_image_id)) {
            // Use existing gallery image by ID (format: gal-{id})
            $this->set_featured_image_from_gallery_id($post_id, $featured_image_id);
        } elseif (!empty($featured_image)) {
            // Base64 image
            $this->set_featured_image_from_base64($post_id, $featured_image, $image_title);
        } elseif (!empty($featured_image_url)) {
            // URL image
            $this->set_featured_image_from_url($post_id, $featured_image_url, $image_title);
        }

        // Handle custom fields (meta)
        $meta = $request->get_param('meta');
        if (!empty($meta) && is_array($meta)) {
            foreach ($meta as $key => $value) {
                update_post_meta($post_id, sanitize_key($key), $value);
            }
        }

        // Add Yoast SEO meta if Yoast is active
        if (defined('WPSEO_VERSION')) {
            $seo_title = $request->get_param('seo_title');
            $description = $request->get_param('description');
            $focuskw = $request->get_param('focuskw');

            if (!empty($seo_title)) {
                update_post_meta($post_id, '_yoast_wpseo_title', sanitize_text_field($seo_title));
            }
            if (!empty($description)) {
                update_post_meta($post_id, '_yoast_wpseo_metadesc', sanitize_text_field($description));
            }
            if (!empty($focuskw)) {
                update_post_meta($post_id, '_yoast_wpseo_focuskw', sanitize_text_field($focuskw));
            }
        }

        // Get the created post
        $post = get_post($post_id);

        return Texter_API_Response::success(array(
            'id' => $post->ID,
            'title' => $post->post_title,
            'slug' => $post->post_name,
            'url' => get_permalink($post->ID),
            'status' => $post->post_status,
            'created_at' => $post->post_date,
        ), 201);
    }

    /**
     * Get a topic by ID
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_topic($request)
    {
        $post_id = intval($request->get_param('id'));
        $post = get_post($post_id);

        if (!$post || $post->post_type !== 'post') {
            return Texter_API_Response::not_found('Topic not found');
        }

        return Texter_API_Response::success($this->format_post($post));
    }

    /**
     * Update a topic
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function update_topic($request)
    {
        $post_id = intval($request->get_param('id'));
        $post = get_post($post_id);

        if (!$post || $post->post_type !== 'post') {
            return Texter_API_Response::not_found('Topic not found');
        }

        $post_data = array('ID' => $post_id);

        if ($request->get_param('title')) {
            $post_data['post_title'] = sanitize_text_field($request->get_param('title'));
        }

        if ($request->get_param('content')) {
            $post_data['post_content'] = wp_kses_post($request->get_param('content'));
        }

        if ($request->get_param('excerpt')) {
            $post_data['post_excerpt'] = sanitize_textarea_field($request->get_param('excerpt'));
        }

        if ($request->get_param('status')) {
            $post_data['post_status'] = $request->get_param('status');
        }

        if ($request->get_param('slug')) {
            $post_data['post_name'] = sanitize_title($request->get_param('slug'));
        }

        $result = wp_update_post($post_data, true);

        if (is_wp_error($result)) {
            return Texter_API_Response::error($result->get_error_message());
        }

        // Handle categories
        $categories = $request->get_param('categories');
        if ($categories !== null) {
            $category_ids = $this->resolve_categories($categories);
            wp_set_post_categories($post_id, $category_ids);
        }

        // Handle tags
        $tags = $request->get_param('tags');
        if ($tags !== null) {
            if (is_array($tags)) {
                wp_set_post_tags($post_id, $tags);
            } else {
                wp_set_post_tags($post_id, array_map('trim', explode(',', $tags)));
            }
        }

        // Handle meta
        $meta = $request->get_param('meta');
        if (!empty($meta) && is_array($meta)) {
            foreach ($meta as $key => $value) {
                update_post_meta($post_id, sanitize_key($key), $value);
            }
        }

        $post = get_post($post_id);
        return Texter_API_Response::success($this->format_post($post));
    }

    /**
     * Delete a topic
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function delete_topic($request)
    {
        $post_id = intval($request->get_param('id'));
        $post = get_post($post_id);

        if (!$post || $post->post_type !== 'post') {
            return Texter_API_Response::not_found('Topic not found');
        }

        $force = $request->get_param('force') === true;
        $result = wp_delete_post($post_id, $force);

        if (!$result) {
            return Texter_API_Response::error('Failed to delete topic');
        }

        return Texter_API_Response::success(array(
            'id' => $post_id,
            'deleted' => true,
            'trashed' => !$force,
        ));
    }

    /**
     * Format post for response
     *
     * @param WP_Post $post
     * @return array
     */
    private function format_post($post)
    {
        $categories = wp_get_post_categories($post->ID, array('fields' => 'all'));
        $tags = wp_get_post_tags($post->ID, array('fields' => 'all'));

        return array(
            'id' => $post->ID,
            'title' => $post->post_title,
            'slug' => $post->post_name,
            'content' => $post->post_content,
            'excerpt' => $post->post_excerpt,
            'status' => $post->post_status,
            'author_id' => $post->post_author,
            'url' => get_permalink($post->ID),
            'featured_image' => get_the_post_thumbnail_url($post->ID, 'full'),
            'categories' => array_map(function ($cat) {
                return array(
                    'id' => $cat->term_id,
                    'name' => $cat->name,
                    'slug' => $cat->slug,
                );
            }, $categories),
            'tags' => array_map(function ($tag) {
                return array(
                    'id' => $tag->term_id,
                    'name' => $tag->name,
                    'slug' => $tag->slug,
                );
            }, $tags),
            'created_at' => $post->post_date,
            'updated_at' => $post->post_modified,
        );
    }

    /**
     * Resolve categories from mixed input (IDs, slugs, names)
     * Creates categories that don't exist
     *
     * @param array $categories
     * @return array Category IDs
     */
    private function resolve_categories($categories)
    {
        if (!is_array($categories)) {
            return array();
        }

        $ids = array();

        foreach ($categories as $cat) {
            if (is_numeric($cat)) {
                $ids[] = intval($cat);
            } elseif (is_string($cat)) {
                $category_id = $this->get_or_create_category($cat);
                if ($category_id) {
                    $ids[] = $category_id;
                }
            } elseif (is_array($cat) && isset($cat['id'])) {
                $ids[] = intval($cat['id']);
            } elseif (is_array($cat) && isset($cat['slug'])) {
                $term = get_term_by('slug', $cat['slug'], 'category');
                if ($term) {
                    $ids[] = $term->term_id;
                } elseif (isset($cat['name'])) {
                    $category_id = $this->get_or_create_category($cat['name']);
                    if ($category_id) {
                        $ids[] = $category_id;
                    }
                }
            }
        }

        return array_unique($ids);
    }

    /**
     * Get category by name/slug or create it if it doesn't exist
     *
     * @param string $category_name
     * @return int|false Category ID or false
     */
    private function get_or_create_category($category_name)
    {
        if (empty($category_name)) {
            return false;
        }

        $category_name = trim($category_name);

        // Try to find by slug first
        $term = get_term_by('slug', sanitize_title($category_name), 'category');
        if ($term) {
            return $term->term_id;
        }

        // Try to find by name
        $term = get_term_by('name', $category_name, 'category');
        if ($term) {
            return $term->term_id;
        }

        // Category doesn't exist, create it
        $result = wp_insert_term($category_name, 'category', array(
            'slug' => sanitize_title($category_name),
        ));

        if (is_wp_error($result)) {
            // Check if it's a duplicate slug error (term exists with different name)
            if ($result->get_error_code() === 'term_exists') {
                return $result->get_error_data();
            }
            return false;
        }

        return $result['term_id'];
    }

    /**
     * Resolve a category name with optional parent hierarchy (e.g., "Parent / Child")
     * If the category doesn't exist, creates it with proper parent relationship.
     *
     * @param string $category_name Category name, possibly with " / " hierarchy separator
     * @return int|false Category ID or false
     */
    private function resolve_hierarchical_category($category_name)
    {
        if (empty($category_name)) {
            return false;
        }

        $category_name = trim($category_name);

        // Check if this is a hierarchical name (contains " / ")
        if (strpos($category_name, ' / ') !== false) {
            $parts = array_map('trim', explode(' / ', $category_name));
            $parent_id = 0;
            $final_category_id = false;

            // Walk through the hierarchy
            foreach ($parts as $part_name) {
                if (empty($part_name)) continue;

                // Try to find this category with the current parent
                $args = array(
                    'taxonomy' => 'category',
                    'name' => $part_name,
                    'parent' => $parent_id,
                    'hide_empty' => false,
                );
                $terms = get_terms($args);

                if (!empty($terms) && !is_wp_error($terms)) {
                    $final_category_id = $terms[0]->term_id;
                    $parent_id = $final_category_id;
                } else {
                    // Also try finding by slug with this parent
                    $term = get_term_by('slug', sanitize_title($part_name), 'category');
                    if ($term && $term->parent == $parent_id) {
                        $final_category_id = $term->term_id;
                        $parent_id = $final_category_id;
                    } else {
                        // Category at this level doesn't exist, create it
                        $result = wp_insert_term($part_name, 'category', array(
                            'slug' => sanitize_title($part_name),
                            'parent' => $parent_id,
                        ));

                        if (is_wp_error($result)) {
                            if ($result->get_error_code() === 'term_exists') {
                                $final_category_id = $result->get_error_data();
                            } else {
                                return false;
                            }
                        } else {
                            $final_category_id = $result['term_id'];
                        }
                        $parent_id = $final_category_id;
                    }
                }
            }

            return $final_category_id;
        }

        // Not hierarchical, use the simple method
        return $this->get_or_create_category($category_name);
    }

    /**
     * Set featured image from URL
     *
     * @param int $post_id
     * @param string $url
     * @param string|null $image_title Optional title for the image
     * @return int|false Attachment ID or false
     */
    private function set_featured_image_from_url($post_id, $url, $image_title = null)
    {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Download the image
        $tmp = download_url($url);

        if (is_wp_error($tmp)) {
            return false;
        }

        // Generate filename from title or URL
        $filename = !empty($image_title)
            ? sanitize_title($image_title) . '.' . pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)
            : basename(parse_url($url, PHP_URL_PATH));

        // Get file info
        $file_array = array(
            'name' => $filename,
            'tmp_name' => $tmp,
        );

        // Upload and attach
        $attachment_id = media_handle_sideload($file_array, $post_id, $image_title ?: '');

        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            return false;
        }

        // Set as featured image
        set_post_thumbnail($post_id, $attachment_id);

        return $attachment_id;
    }

    /**
     * Set featured image from base64 data
     *
     * @param int $post_id
     * @param string $base64_data Base64 encoded image (with or without data URI prefix)
     * @param string|null $image_title Optional title for the image
     * @return int|false Attachment ID or false
     */
    private function set_featured_image_from_base64($post_id, $base64_data, $image_title = null)
    {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Parse base64 data - handle both data URI format and plain base64
        $image_type = 'jpeg'; // default

        if (preg_match('/^data:image\/(\w+);base64,/', $base64_data, $matches)) {
            $image_type = $matches[1];
            $base64_data = substr($base64_data, strpos($base64_data, ',') + 1);
        }

        // Decode base64
        $image_data = base64_decode($base64_data);
        if ($image_data === false) {
            return false;
        }

        // Try to detect actual image type from data
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected_type = $finfo->buffer($image_data);
        if ($detected_type && strpos($detected_type, 'image/') === 0) {
            $image_type = str_replace('image/', '', $detected_type);
        }

        // Generate filename from title
        $slug = !empty($image_title) ? sanitize_title($image_title) : 'featured-image-' . time();
        $filename = $slug . '.' . $image_type;

        // Create temp file
        $upload_dir = wp_upload_dir();
        $tmp_file = $upload_dir['path'] . '/' . wp_unique_filename($upload_dir['path'], $filename);

        // Write image data
        if (file_put_contents($tmp_file, $image_data) === false) {
            return false;
        }

        // Prepare file array for upload
        $file_array = array(
            'name' => $filename,
            'tmp_name' => $tmp_file,
            'type' => 'image/' . $image_type,
            'error' => 0,
            'size' => strlen($image_data),
        );

        // Upload the file
        $attachment_id = media_handle_sideload($file_array, $post_id, $image_title ?: '');

        // Clean up temp file if sideload failed
        if (is_wp_error($attachment_id)) {
            @unlink($tmp_file);
            return false;
        }

        // Set alt text if image_title is provided
        if (!empty($image_title)) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($image_title));
        }

        // Set as featured image
        set_post_thumbnail($post_id, $attachment_id);

        return $attachment_id;
    }

    /**
     * Set featured image from existing gallery image ID
     *
     * @param int $post_id
     * @param string $gallery_id Gallery image ID (format: 'gal-{attachment_id}' or 's3-{id}')
     * @return int|false Attachment ID or false
     */
    private function set_featured_image_from_gallery_id($post_id, $gallery_id)
    {
        // Check if this is an S3 image
        if (strpos($gallery_id, 's3-') === 0) {
            return $this->set_featured_image_from_s3($post_id, $gallery_id);
        }

        // Extract attachment ID from gallery format (gal-123)
        $attachment_id = null;

        if (strpos($gallery_id, 'gal-') === 0) {
            $attachment_id = intval(substr($gallery_id, 4));
        } else {
            // If no prefix, try to use as-is
            $attachment_id = intval($gallery_id);
        }

        if (!$attachment_id) {
            return false;
        }

        // Verify the attachment exists and is an image
        $attachment = get_post($attachment_id);
        if (!$attachment || $attachment->post_type !== 'attachment') {
            return false;
        }

        // Check if it's an image
        if (!wp_attachment_is_image($attachment_id)) {
            return false;
        }

        // Set as featured image
        set_post_thumbnail($post_id, $attachment_id);

        return $attachment_id;
    }

    /**
     * Set featured image from S3 image (uses external featured image)
     *
     * @param int $post_id
     * @param string $s3_id S3 image ID (format: 's3-{id}')
     * @return bool Success
     */
    private function set_featured_image_from_s3($post_id, $s3_id)
    {
        // Get S3 image URL and alt text
        $public_url = Texter_API_Endpoint_Gallery::get_image_public_url($s3_id);
        $alt_text = Texter_API_Endpoint_Gallery::get_image_alt_text($s3_id);

        if (!$public_url) {
            return false;
        }

        // Use External Featured Image to set as featured
        Texter_External_Featured_Image::set_external_image($post_id, $public_url, $alt_text);

        return true;
    }

    /**
     * Process embed images - replace ##**META_IMAGE** placeholders with image markdown
     *
     * @param string $content Content with placeholders
     * @param array $embed_images Array of embed images with wp_id and url
     * @return string Content with placeholders replaced
     */
    private function process_embed_images($content, $embed_images)
    {
        // Count placeholders in content
        $placeholder_count = preg_match_all('/##\*\*META_IMAGE\*\*/', $content);
        $images_count = count($embed_images);

        // Remove excess placeholders if there are more placeholders than images
        if ($placeholder_count > $images_count) {
            // Replace extra placeholders (from end) with empty string
            $excess = $placeholder_count - $images_count;
            for ($i = 0; $i < $excess; $i++) {
                // Find last occurrence and remove it
                $pos = strrpos($content, '##**META_IMAGE**');
                if ($pos !== false) {
                    // Remove the placeholder and any surrounding empty lines
                    $content = substr_replace($content, '', $pos, strlen('##**META_IMAGE**'));
                    // Clean up any resulting double line breaks
                    $content = preg_replace('/\n{3,}/', "\n\n", $content);
                }
            }
        }

        // Replace placeholders with image markdown for gallery images
        $image_index = 0;
        $content = preg_replace_callback('/##\*\*META_IMAGE\*\*/', function ($matches) use (&$image_index, $embed_images) {
            if ($image_index >= count($embed_images)) {
                return ''; // No more images, remove placeholder
            }

            $image = $embed_images[$image_index];
            $image_index++;

            if (empty($image) || empty($image['wp_id'])) {
                return ''; // No image data, remove placeholder
            }

            // Get attachment ID from gallery format (gal-123)
            $attachment_id = null;
            $wp_id = $image['wp_id'];

            if (strpos($wp_id, 'gal-') === 0) {
                $attachment_id = intval(substr($wp_id, 4));
            } else {
                $attachment_id = intval($wp_id);
            }

            if (!$attachment_id) {
                return ''; // Invalid ID
            }

            // Verify attachment exists
            $attachment = get_post($attachment_id);
            if (!$attachment || $attachment->post_type !== 'attachment') {
                return ''; // Attachment not found
            }

            // Get attachment URL (prefer provided URL, fallback to WordPress)
            $url = !empty($image['url']) ? $image['url'] : wp_get_attachment_url($attachment_id);
            $alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
            if (empty($alt)) {
                $alt = $attachment->post_title ?: 'Image';
            }

            // Return markdown image syntax that will be converted to image block
            return sprintf('![%s](%s)', $alt, $url);
        }, $content);

        return $content;
    }

    /**
     * Convert Markdown content to Gutenberg blocks
     *
     * @param string $markdown
     * @return string Gutenberg block content
     */
    private function markdown_to_gutenberg($markdown)
    {
        $blocks = array();

        // Normalize line endings
        $markdown = str_replace(array("\r\n", "\r"), "\n", $markdown);

        // Split into lines for processing
        $lines = explode("\n", $markdown);
        $current_block = array();
        $in_code_block = false;
        $code_language = '';
        $in_list = false;
        $list_items = array();
        $list_type = 'ul';

        foreach ($lines as $line) {
            // Handle fenced code blocks
            if (preg_match('/^```(\w*)$/', $line, $matches)) {
                if (!$in_code_block) {
                    // Flush any pending paragraph
                    if (!empty($current_block)) {
                        $blocks[] = $this->create_paragraph_block(implode("\n", $current_block));
                        $current_block = array();
                    }
                    // Flush any pending list
                    if ($in_list) {
                        $blocks[] = $this->create_list_block($list_items, $list_type);
                        $list_items = array();
                        $in_list = false;
                    }
                    $in_code_block = true;
                    $code_language = $matches[1];
                } else {
                    // End code block
                    $blocks[] = $this->create_code_block(implode("\n", $current_block), $code_language);
                    $current_block = array();
                    $in_code_block = false;
                    $code_language = '';
                }
                continue;
            }

            if ($in_code_block) {
                $current_block[] = $line;
                continue;
            }

            // Handle standalone images ![alt](url) - creates image block
            if (preg_match('/^!\[([^\]]*)\]\(([^)]+)\)$/', trim($line), $matches)) {
                // Flush pending content
                if (!empty($current_block)) {
                    $blocks[] = $this->create_paragraph_block(implode("\n", $current_block));
                    $current_block = array();
                }
                if ($in_list) {
                    $blocks[] = $this->create_list_block($list_items, $list_type);
                    $list_items = array();
                    $in_list = false;
                }
                $blocks[] = $this->create_image_block($matches[2], $matches[1]);
                continue;
            }

            // Handle headings (## Heading)
            if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $matches)) {
                // Flush pending content
                if (!empty($current_block)) {
                    $blocks[] = $this->create_paragraph_block(implode("\n", $current_block));
                    $current_block = array();
                }
                if ($in_list) {
                    $blocks[] = $this->create_list_block($list_items, $list_type);
                    $list_items = array();
                    $in_list = false;
                }
                $level = strlen($matches[1]);
                $blocks[] = $this->create_heading_block($matches[2], $level);
                continue;
            }

            // Handle unordered list items (- item or * item)
            if (preg_match('/^[\-\*]\s+(.+)$/', $line, $matches)) {
                // Flush pending paragraph
                if (!empty($current_block)) {
                    $blocks[] = $this->create_paragraph_block(implode("\n", $current_block));
                    $current_block = array();
                }
                // If switching from ordered to unordered, flush
                if ($in_list && $list_type === 'ol') {
                    $blocks[] = $this->create_list_block($list_items, $list_type);
                    $list_items = array();
                }
                $in_list = true;
                $list_type = 'ul';
                $list_items[] = $this->convert_inline_markdown($matches[1]);
                continue;
            }

            // Handle ordered list items (1. item)
            if (preg_match('/^\d+\.\s+(.+)$/', $line, $matches)) {
                // Flush pending paragraph
                if (!empty($current_block)) {
                    $blocks[] = $this->create_paragraph_block(implode("\n", $current_block));
                    $current_block = array();
                }
                // If switching from unordered to ordered, flush
                if ($in_list && $list_type === 'ul') {
                    $blocks[] = $this->create_list_block($list_items, $list_type);
                    $list_items = array();
                }
                $in_list = true;
                $list_type = 'ol';
                $list_items[] = $this->convert_inline_markdown($matches[1]);
                continue;
            }

            // Handle blockquotes (> quote)
            if (preg_match('/^>\s*(.*)$/', $line, $matches)) {
                // Flush pending content
                if (!empty($current_block)) {
                    $blocks[] = $this->create_paragraph_block(implode("\n", $current_block));
                    $current_block = array();
                }
                if ($in_list) {
                    $blocks[] = $this->create_list_block($list_items, $list_type);
                    $list_items = array();
                    $in_list = false;
                }
                $blocks[] = $this->create_quote_block($matches[1]);
                continue;
            }

            // Handle horizontal rule (--- or ***)
            if (preg_match('/^(-{3,}|\*{3,})$/', $line)) {
                // Flush pending content
                if (!empty($current_block)) {
                    $blocks[] = $this->create_paragraph_block(implode("\n", $current_block));
                    $current_block = array();
                }
                if ($in_list) {
                    $blocks[] = $this->create_list_block($list_items, $list_type);
                    $list_items = array();
                    $in_list = false;
                }
                $blocks[] = $this->create_separator_block();
                continue;
            }

            // Empty line - flush paragraph and list
            if (trim($line) === '') {
                if (!empty($current_block)) {
                    $blocks[] = $this->create_paragraph_block(implode("\n", $current_block));
                    $current_block = array();
                }
                if ($in_list) {
                    $blocks[] = $this->create_list_block($list_items, $list_type);
                    $list_items = array();
                    $in_list = false;
                }
                continue;
            }

            // Regular paragraph line
            if ($in_list) {
                // End the list if we hit regular text
                $blocks[] = $this->create_list_block($list_items, $list_type);
                $list_items = array();
                $in_list = false;
            }
            $current_block[] = $line;
        }

        // Flush remaining content
        if (!empty($current_block)) {
            if ($in_code_block) {
                $blocks[] = $this->create_code_block(implode("\n", $current_block), $code_language);
            } else {
                $blocks[] = $this->create_paragraph_block(implode("\n", $current_block));
            }
        }
        if ($in_list && !empty($list_items)) {
            $blocks[] = $this->create_list_block($list_items, $list_type);
        }

        return implode("\n\n", $blocks);
    }

    /**
     * Convert inline markdown (bold, italic, links, code) to HTML
     *
     * @param string $text
     * @return string
     */
    private function convert_inline_markdown($text)
    {
        // Convert bold (**text** or __text__)
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/__(.+?)__/', '<strong>$1</strong>', $text);

        // Convert italic (*text* or _text_) - be careful not to match bold
        $text = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/', '<em>$1</em>', $text);
        $text = preg_replace('/(?<!_)_(?!_)(.+?)(?<!_)_(?!_)/', '<em>$1</em>', $text);

        // Convert inline code (`code`)
        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);

        // Convert links [text](url)
        $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $text);

        // Convert images ![alt](url)
        $text = preg_replace('/!\[([^\]]*)\]\(([^)]+)\)/', '<img src="$2" alt="$1" />', $text);

        return $text;
    }

    /**
     * Create a Gutenberg paragraph block
     */
    private function create_paragraph_block($text)
    {
        $text = $this->convert_inline_markdown(trim($text));
        $html = '<p>' . nl2br(esc_html($text)) . '</p>';
        // Re-process since we escaped - need to preserve our HTML
        $html = '<p>' . $this->convert_inline_markdown(trim($text)) . '</p>';
        return "<!-- wp:paragraph -->\n{$html}\n<!-- /wp:paragraph -->";
    }

    /**
     * Create a Gutenberg heading block
     */
    private function create_heading_block($text, $level)
    {
        $text = $this->convert_inline_markdown(trim($text));
        $tag = 'h' . $level;
        return "<!-- wp:heading {\"level\":{$level}} -->\n<{$tag}>{$text}</{$tag}>\n<!-- /wp:heading -->";
    }

    /**
     * Create a Gutenberg list block
     */
    private function create_list_block($items, $type = 'ul')
    {
        $tag = $type === 'ol' ? 'ol' : 'ul';
        $ordered_attr = $type === 'ol' ? '{"ordered":true}' : '';
        $list_html = "<{$tag}>\n";
        foreach ($items as $item) {
            $list_html .= "<li>{$item}</li>\n";
        }
        $list_html .= "</{$tag}>";
        return "<!-- wp:list {$ordered_attr} -->\n{$list_html}\n<!-- /wp:list -->";
    }

    /**
     * Create a Gutenberg code block
     */
    private function create_code_block($code, $language = '')
    {
        $code = esc_html($code);
        $lang_attr = $language ? "{\"language\":\"{$language}\"}" : '';
        return "<!-- wp:code {$lang_attr} -->\n<pre class=\"wp-block-code\"><code>{$code}</code></pre>\n<!-- /wp:code -->";
    }

    /**
     * Create a Gutenberg quote block
     */
    private function create_quote_block($text)
    {
        $text = $this->convert_inline_markdown(trim($text));
        return "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><p>{$text}</p></blockquote>\n<!-- /wp:quote -->";
    }

    /**
     * Create a Gutenberg separator block
     */
    private function create_separator_block()
    {
        return "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";
    }

    /**
     * Create a Gutenberg image block
     */
    private function create_image_block($url, $alt = '')
    {
        $url = esc_url($url);
        $alt = esc_attr($alt);
        return "<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"{$url}\" alt=\"{$alt}\"/></figure>\n<!-- /wp:image -->";
    }
}
