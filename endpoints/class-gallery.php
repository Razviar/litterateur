<?php
/**
 * Gallery Images REST API Endpoint
 * 
 * Returns images from WordPress media library for gallery feature
 */

if (!defined('ABSPATH')) {
    exit;
}

class Texter_API_Endpoint_Gallery {
    
    /**
     * Source prefix for WordPress gallery images
     */
    const SOURCE_PREFIX = 'gal-';
    
    /**
     * Source prefix for S3 images
     */
    const S3_PREFIX = 's3-';
    
    /**
     * Default minimum image size in pixels
     */
    const DEFAULT_MIN_IMAGE_SIZE = 200;
    
    /**
     * Register REST API routes
     *
     * @param string $namespace API namespace
     */
    public function register_routes($namespace) {
        // List all gallery images
        register_rest_route($namespace, '/gallery', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_images'],
            'permission_callback' => ['Texter_API_Auth', 'check_permission'],
            'args' => [
                'modified_after' => [
                    'required' => false,
                    'type' => 'string',
                    'description' => 'Only return images modified after this date (ISO 8601)',
                ],
            ],
        ]);
        
        // Get single image details
        register_rest_route($namespace, '/gallery/(?P<id>[a-z0-9-]+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_image'],
            'permission_callback' => ['Texter_API_Auth', 'check_permission'],
            'args' => [
                'id' => [
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Gallery image ID (e.g., gal-123)',
                ],
            ],
        ]);
        
        // Upload image to gallery
        register_rest_route($namespace, '/gallery/upload', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'upload_image'],
            'permission_callback' => ['Texter_API_Auth', 'check_permission'],
            'args' => [
                'image' => [
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Base64-encoded image data',
                ],
                'title' => [
                    'required' => false,
                    'type' => 'string',
                    'description' => 'Image title (used for filename and alt text)',
                ],
            ],
        ]);
        
        // Delete image from gallery
        register_rest_route($namespace, '/gallery/(?P<id>[a-z0-9-]+)', [
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => [$this, 'delete_image'],
            'permission_callback' => ['Texter_API_Auth', 'check_permission'],
            'args' => [
                'id' => [
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Gallery image ID (e.g., gal-123)',
                ],
            ],
        ]);
    }
    
    /**
     * Check if minimum size filtering is enabled
     *
     * @return bool
     */
    public static function is_min_size_filter_enabled() {
        $enabled = get_option('texter_gallery_min_size_enabled', false);
        // Handle both boolean true and string '1' or 'true'
        return $enabled === true || $enabled === '1' || $enabled === 1;
    }
    
    /**
     * Get minimum image size setting
     *
     * @return int
     */
    public static function get_min_image_size() {
        return (int) get_option('texter_gallery_min_image_size', self::DEFAULT_MIN_IMAGE_SIZE);
    }
    
    /**
     * Check if image meets minimum size requirements
     *
     * @param array $metadata Image metadata
     * @return bool
     */
    private function meets_size_requirements($metadata) {
        if (!self::is_min_size_filter_enabled()) {
            return true;
        }
        
        $min_size = self::get_min_image_size();
        $width = $metadata['width'] ?? 0;
        $height = $metadata['height'] ?? 0;
        
        return $width >= $min_size && $height >= $min_size;
    }
    
    /**
     * Get list of gallery images including S3 images
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_images($request) {
        $modified_after = $request->get_param('modified_after');
        $filter_by_size = self::is_min_size_filter_enabled();
        $min_size = $filter_by_size ? self::get_min_image_size() : 0;
        
        $images = [];
        
        // Get WordPress gallery images
        $images = array_merge($images, $this->get_wordpress_images($modified_after, $filter_by_size, $min_size));
        
        // Get S3 images if enabled
        if (Texter_S3_Storage::is_enabled()) {
            $images = array_merge($images, $this->get_s3_images($modified_after, $filter_by_size, $min_size));
        }
        
        // Sort all images by upload_date descending
        usort($images, function($a, $b) {
            return strtotime($b['upload_date']) - strtotime($a['upload_date']);
        });
        
        return Texter_API_Response::success([
            'images' => $images,
            'total' => count($images),
        ]);
    }
    
    /**
     * Get WordPress media library images
     *
     * @param string|null $modified_after
     * @param bool $filter_by_size
     * @param int $min_size
     * @return array
     */
    private function get_wordpress_images($modified_after, $filter_by_size, $min_size) {
        $images = [];
        $wp_page = 1;
        $batch_size = 500;
        
        // Fetch all images in batches
        while (true) {
            $args = [
                'post_type' => 'attachment',
                'post_mime_type' => 'image',
                'post_status' => 'inherit',
                'posts_per_page' => $batch_size,
                'paged' => $wp_page,
                'orderby' => 'date',
                'order' => 'DESC',
            ];
            
            if ($modified_after) {
                $args['date_query'] = [
                    [
                        'after' => $modified_after,
                        'column' => 'post_modified',
                    ],
                ];
            }
            
            $query = new WP_Query($args);
            
            if (empty($query->posts)) {
                break;
            }
            
            foreach ($query->posts as $attachment) {
                $metadata = wp_get_attachment_metadata($attachment->ID);
                
                // Apply size filter if enabled
                if ($filter_by_size) {
                    $width = $metadata['width'] ?? 0;
                    $height = $metadata['height'] ?? 0;
                    if ($width < $min_size || $height < $min_size) {
                        continue;
                    }
                }
                
                $images[] = $this->format_image($attachment, $metadata);
            }
            
            if ($wp_page >= $query->max_num_pages) {
                break;
            }
            
            $wp_page++;
        }
        
        return $images;
    }
    
    /**
     * Get S3 images from local database cache
     *
     * @param string|null $modified_after
     * @param bool $filter_by_size
     * @param int $min_size
     * @return array
     */
    private function get_s3_images($modified_after, $filter_by_size, $min_size) {
        $s3 = Texter_S3_Storage::get_instance();
        $args = array(
            'limit' => 10000, // Get all images
            'offset' => 0,
        );
        
        if ($modified_after) {
            $args['modified_after'] = $modified_after;
        }
        
        $s3_images = $s3->get_images($args);
        $images = [];
        
        foreach ($s3_images as $s3_image) {
            // Apply size filter if enabled
            if ($filter_by_size) {
                $width = $s3_image->width ?? 0;
                $height = $s3_image->height ?? 0;
                if ($width < $min_size || $height < $min_size) {
                    continue;
                }
            }
            
            $images[] = $this->format_s3_image($s3_image);
        }
        
        return $images;
    }
    
    /**
     * Format S3 image for API response
     *
     * @param object $s3_image Database row
     * @return array
     */
    private function format_s3_image($s3_image) {
        $s3 = Texter_S3_Storage::get_instance();
        $public_url = $s3->get_public_url($s3_image->s3_key);
        
        return [
            'id' => self::S3_PREFIX . $s3_image->id,
            'public_url' => $public_url,
            'thumbnail_url' => $public_url, // S3 images don't have thumbnails
            'title' => $s3_image->title,
            'description' => $s3_image->description,
            'alt_text' => $s3_image->alt_text,
            'caption' => '',
            'mime_type' => $s3_image->mime_type,
            'width' => (int) $s3_image->width,
            'height' => (int) $s3_image->height,
            'filesize' => (int) $s3_image->filesize,
            'upload_date' => $s3_image->upload_date,
            'modified_date' => $s3_image->modified_date,
            'hash' => $s3_image->hash,
            'used_in_posts' => (int) $s3_image->used_in_posts ?: 0,
        ];
    }
    
    /**
     * Get single image details
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_image($request) {
        $id = $request->get_param('id');
        
        // Check if this is an S3 image
        if (strpos($id, self::S3_PREFIX) === 0) {
            return $this->get_s3_image_details($id);
        }
        
        // WordPress gallery image
        // Strip prefix if present (e.g., gal-123 -> 123)
        if (strpos($id, self::SOURCE_PREFIX) === 0) {
            $id = substr($id, strlen(self::SOURCE_PREFIX));
        }
        $id = intval($id);
        
        if (!$id) {
            return Texter_API_Response::error(
                'Invalid image ID',
                'invalid_id',
                400
            );
        }
        
        $attachment = get_post($id);
        
        if (!$attachment || $attachment->post_type !== 'attachment') {
            return Texter_API_Response::error(
                'Image not found',
                'image_not_found',
                404
            );
        }
        
        if (strpos($attachment->post_mime_type, 'image/') !== 0) {
            return Texter_API_Response::error(
                'Attachment is not an image',
                'not_an_image',
                400
            );
        }
        
        return Texter_API_Response::success([
            'image' => $this->format_image($attachment),
        ]);
    }
    
    /**
     * Get S3 image details
     *
     * @param string $id S3 image ID (s3-123)
     * @return WP_REST_Response|WP_Error
     */
    private function get_s3_image_details($id) {
        // Strip prefix (s3-123 -> 123)
        $numeric_id = intval(substr($id, strlen(self::S3_PREFIX)));
        
        if (!$numeric_id) {
            return Texter_API_Response::error(
                'Invalid S3 image ID',
                'invalid_id',
                400
            );
        }
        
        $s3 = Texter_S3_Storage::get_instance();
        $s3_image = $s3->get_image_by_id($numeric_id);
        
        if (!$s3_image) {
            return Texter_API_Response::error(
                'S3 image not found',
                'image_not_found',
                404
            );
        }
        
        return Texter_API_Response::success([
            'image' => $this->format_s3_image($s3_image),
        ]);
    }
    
    /**
     * Format image attachment for API response
     *
     * @param WP_Post $attachment
     * @param array|null $metadata Optional pre-fetched metadata
     * @return array
     */
    private function format_image($attachment, $metadata = null) {
        $id = $attachment->ID;
        if ($metadata === null) {
            $metadata = wp_get_attachment_metadata($id);
        }
        
        // Get image URLs
        $full_url = wp_get_attachment_url($id);
        $thumbnail_url = null;
        
        // Try to get thumbnail size
        $thumbnail = wp_get_attachment_image_src($id, 'thumbnail');
        if ($thumbnail) {
            $thumbnail_url = $thumbnail[0];
        }
        
        // Get medium size as alternative
        if (!$thumbnail_url) {
            $medium = wp_get_attachment_image_src($id, 'medium');
            if ($medium) {
                $thumbnail_url = $medium[0];
            }
        }
        
        // Generate hash from metadata for change detection
        $hash_data = [
            'id' => $id,
            'file' => $metadata['file'] ?? '',
            'width' => $metadata['width'] ?? 0,
            'height' => $metadata['height'] ?? 0,
            'filesize' => $metadata['filesize'] ?? filesize(get_attached_file($id)),
            'modified' => $attachment->post_modified,
        ];
        $hash = md5(json_encode($hash_data));
        
        // Get alt text and caption
        $alt_text = get_post_meta($id, '_wp_attachment_image_alt', true);
        $caption = $attachment->post_excerpt;
        $description = $attachment->post_content;
        
        // Build description from available text
        $text_description = '';
        if (!empty($alt_text)) {
            $text_description = $alt_text;
        } elseif (!empty($caption)) {
            $text_description = $caption;
        } elseif (!empty($description)) {
            $text_description = wp_trim_words($description, 50);
        }
        
        // Count how many posts use this image
        $used_in_posts = $this->count_image_usage($id);
        
        return [
            'id' => self::SOURCE_PREFIX . $id,
            'public_url' => $full_url,
            'thumbnail_url' => $thumbnail_url,
            'title' => $attachment->post_title,
            'description' => $text_description,
            'alt_text' => $alt_text,
            'caption' => $caption,
            'mime_type' => $attachment->post_mime_type,
            'width' => $metadata['width'] ?? null,
            'height' => $metadata['height'] ?? null,
            'filesize' => $metadata['filesize'] ?? null,
            'upload_date' => $attachment->post_date,
            'modified_date' => $attachment->post_modified,
            'hash' => $hash,
            'used_in_posts' => $used_in_posts,
        ];
    }
    
    /**
     * Count how many posts use this image
     *
     * @param int $attachment_id
     * @return int
     */
    private function count_image_usage($attachment_id) {
        global $wpdb;
        
        $count = 0;
        
        // Check featured images
        $featured_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} 
             WHERE meta_key = '_thumbnail_id' AND meta_value = %d",
            $attachment_id
        ));
        $count += (int) $featured_count;
        
        // Check content for image references (by URL or attachment ID)
        $attachment_url = wp_get_attachment_url($attachment_id);
        if ($attachment_url) {
            // Search for image URL in post content
            $content_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT ID) FROM {$wpdb->posts} 
                 WHERE post_type IN ('post', 'page') 
                 AND post_status = 'publish'
                 AND (post_content LIKE %s OR post_content LIKE %s)",
                '%' . $wpdb->esc_like($attachment_url) . '%',
                '%wp-image-' . $attachment_id . '%'
            ));
            $count += (int) $content_count;
        }
        
        return $count;
    }
    
    /**
     * Upload image to gallery
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function upload_image($request) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        
        $base64_data = $request->get_param('image');
        $title = $request->get_param('title') ?: 'Uploaded Image';
        
        if (empty($base64_data)) {
            return new WP_Error('missing_image', 'Image data is required', ['status' => 400]);
        }
        
        // Parse base64 data - handle both data URI format and plain base64
        $image_type = 'webp'; // default for our AI-generated images
        
        if (preg_match('/^data:image\/(\w+);base64,/', $base64_data, $matches)) {
            $image_type = $matches[1];
            $base64_data = substr($base64_data, strpos($base64_data, ',') + 1);
        }
        
        // Decode base64
        $image_data = base64_decode($base64_data);
        if ($image_data === false) {
            return new WP_Error('invalid_image', 'Invalid base64 image data', ['status' => 400]);
        }
        
        // Try to detect actual image type from data
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected_type = $finfo->buffer($image_data);
        $mime_type = $detected_type ?: 'image/' . $image_type;
        if ($detected_type && strpos($detected_type, 'image/') === 0) {
            $image_type = str_replace('image/', '', $detected_type);
        }
        
        // Generate filename from title
        $slug = sanitize_title($title);
        if (empty($slug)) {
            $slug = 'image-' . time();
        }
        // Add timestamp to make filename unique
        $filename = $slug . '-' . time() . '.' . $image_type;
        
        // Check if S3 is preferred storage
        if (Texter_S3_Storage::is_preferred_storage()) {
            return $this->upload_to_s3($image_data, $filename, $mime_type, $title);
        }
        
        // Upload to WordPress media library
        return $this->upload_to_wordpress($image_data, $filename, $image_type, $title);
    }
    
    /**
     * Upload image to S3 storage
     *
     * @param string $image_data Binary image data
     * @param string $filename Filename
     * @param string $mime_type MIME type
     * @param string $title Image title
     * @return WP_REST_Response|WP_Error
     */
    private function upload_to_s3($image_data, $filename, $mime_type, $title) {
        $s3 = Texter_S3_Storage::get_instance();
        
        // Upload to S3
        $result = $s3->upload($image_data, $filename, $mime_type);
        
        if (is_wp_error($result)) {
            return new WP_Error(
                's3_upload_failed',
                'Failed to upload to S3: ' . $result->get_error_message(),
                ['status' => 500]
            );
        }
        
        // Extract image dimensions
        $temp_file = wp_tempnam('s3_');
        file_put_contents($temp_file, $image_data);
        $image_info = @getimagesize($temp_file);
        @unlink($temp_file);
        
        $width = $image_info ? $image_info[0] : 0;
        $height = $image_info ? $image_info[1] : 0;
        
        // Save to database
        $metadata = array(
            'mime_type' => $mime_type,
            'width' => $width,
            'height' => $height,
            'title' => $title,
            'alt_text' => $title,
            'hash' => md5($image_data),
        );
        
        $db_id = $s3->save_image_to_db($result, $metadata);
        
        if (is_wp_error($db_id)) {
            // S3 upload succeeded but DB failed - try to delete from S3
            $s3->delete($result['key']);
            return new WP_Error(
                'db_save_failed',
                'Failed to save image metadata: ' . $db_id->get_error_message(),
                ['status' => 500]
            );
        }
        
        // Get the saved image for response
        $s3_image = $s3->get_image_by_id($db_id);
        
        return Texter_API_Response::success($this->format_s3_image($s3_image));
    }
    
    /**
     * Upload image to WordPress media library
     *
     * @param string $image_data Binary image data
     * @param string $filename Filename
     * @param string $image_type Image type (extension)
     * @param string $title Image title
     * @return WP_REST_Response|WP_Error
     */
    private function upload_to_wordpress($image_data, $filename, $image_type, $title) {
        // Create temp file
        $upload_dir = wp_upload_dir();
        $tmp_file = $upload_dir['path'] . '/' . wp_unique_filename($upload_dir['path'], $filename);
        
        // Write image data
        if (file_put_contents($tmp_file, $image_data) === false) {
            return new WP_Error('upload_failed', 'Failed to write image file', ['status' => 500]);
        }
        
        // Prepare file array for upload
        $file_array = array(
            'name' => $filename,
            'tmp_name' => $tmp_file,
            'type' => 'image/' . $image_type,
            'error' => 0,
            'size' => strlen($image_data),
        );
        
        // Upload the file (0 = no post parent)
        $attachment_id = media_handle_sideload($file_array, 0, $title);
        
        // Clean up temp file if sideload failed
        if (is_wp_error($attachment_id)) {
            @unlink($tmp_file);
            return new WP_Error(
                'media_upload_failed',
                'Failed to upload image: ' . $attachment_id->get_error_message(),
                ['status' => 500]
            );
        }
        
        // Set alt text
        update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($title));
        
        // Get the attachment post object for format_image
        $attachment = get_post($attachment_id);
        if (!$attachment) {
            return new WP_Error('format_failed', 'Image uploaded but failed to retrieve attachment', ['status' => 500]);
        }
        
        // Return formatted image data using existing format method
        $formatted_image = $this->format_image($attachment);
        
        if (!$formatted_image) {
            return new WP_Error('format_failed', 'Image uploaded but failed to retrieve details', ['status' => 500]);
        }
        
        return Texter_API_Response::success($formatted_image);
    }
    
    /**
     * Delete image from gallery
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function delete_image($request) {
        global $wpdb;
        
        $id = $request->get_param('id');
        
        // Check if this is an S3 image
        if (strpos($id, self::S3_PREFIX) === 0) {
            return $this->delete_s3_image($id);
        }
        
        // WordPress gallery image
        // Strip prefix if present (e.g., gal-123 -> 123)
        if (strpos($id, self::SOURCE_PREFIX) === 0) {
            $id = substr($id, strlen(self::SOURCE_PREFIX));
        }
        $id = intval($id);
        
        if (!$id) {
            return Texter_API_Response::error(
                'Invalid image ID',
                'invalid_id',
                400
            );
        }
        
        $attachment = get_post($id);
        
        if (!$attachment || $attachment->post_type !== 'attachment') {
            return Texter_API_Response::error(
                'Image not found',
                'image_not_found',
                404
            );
        }
        
        // Check if it's an image
        if (strpos($attachment->post_mime_type, 'image/') !== 0) {
            return Texter_API_Response::error(
                'Attachment is not an image',
                'not_an_image',
                400
            );
        }
        
        // Get image URL before deletion for content cleanup
        $attachment_url = wp_get_attachment_url($id);
        $posts_cleaned = 0;
        
        // Remove featured image references
        $featured_posts = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
             WHERE meta_key = '_thumbnail_id' AND meta_value = %d",
            $id
        ));
        
        foreach ($featured_posts as $post_id) {
            delete_post_meta($post_id, '_thumbnail_id');
            $posts_cleaned++;
        }
        
        // Remove image from post content
        if ($attachment_url) {
            // Find posts containing this image
            $posts_with_image = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_content FROM {$wpdb->posts} 
                 WHERE post_type IN ('post', 'page') 
                 AND (post_content LIKE %s OR post_content LIKE %s)",
                '%' . $wpdb->esc_like($attachment_url) . '%',
                '%wp-image-' . $id . '%'
            ));
            
            foreach ($posts_with_image as $post) {
                $content = $post->post_content;
                $original_content = $content;
                
                // Remove image tags containing this image
                // Pattern for img tags with this URL
                $content = preg_replace(
                    '/<img[^>]*src=["\'][^"\']*' . preg_quote(basename($attachment_url), '/') . '[^"\']*["\'][^>]*>/i',
                    '',
                    $content
                );
                
                // Remove figure/div blocks containing wp-image-{id}
                $content = preg_replace(
                    '/<figure[^>]*class="[^"]*wp-image-' . $id . '[^"]*"[^>]*>.*?<\/figure>/is',
                    '',
                    $content
                );
                
                // Remove wp-block-image containing this image
                $content = preg_replace(
                    '/<figure[^>]*class="[^"]*wp-block-image[^"]*"[^>]*>.*?<img[^>]*wp-image-' . $id . '[^>]*>.*?<\/figure>/is',
                    '',
                    $content
                );
                
                // Clean up empty paragraphs that might be left
                $content = preg_replace('/<p>\s*<\/p>/', '', $content);
                
                // Only update if content changed
                if ($content !== $original_content) {
                    wp_update_post([
                        'ID' => $post->ID,
                        'post_content' => $content,
                    ]);
                    $posts_cleaned++;
                }
            }
        }
        
        // Delete the attachment (this also deletes the file)
        $result = wp_delete_attachment($id, true);
        
        if (!$result) {
            return Texter_API_Response::error(
                'Failed to delete image',
                'delete_failed',
                500
            );
        }
        
        return Texter_API_Response::success([
            'deleted' => true,
            'id' => self::SOURCE_PREFIX . $id,
            'posts_cleaned' => $posts_cleaned,
        ]);
    }
    
    /**
     * Delete S3 image
     *
     * @param string $id S3 image ID (s3-123)
     * @return WP_REST_Response|WP_Error
     */
    private function delete_s3_image($id) {
        // Strip prefix (s3-123 -> 123)
        $numeric_id = intval(substr($id, strlen(self::S3_PREFIX)));
        
        if (!$numeric_id) {
            return Texter_API_Response::error(
                'Invalid S3 image ID',
                'invalid_id',
                400
            );
        }
        
        $s3 = Texter_S3_Storage::get_instance();
        $result = $s3->delete_image($numeric_id);
        
        if (is_wp_error($result)) {
            return Texter_API_Response::error(
                $result->get_error_message(),
                $result->get_error_code(),
                500
            );
        }
        
        return Texter_API_Response::success([
            'deleted' => true,
            'id' => $id,
            'posts_cleaned' => 0, // S3 images aren't embedded in content
        ]);
    }
    
    /**
     * Get public URL for an image ID (handles both gal- and s3- prefixes)
     *
     * @param string $image_id Image ID with prefix
     * @return string|null Public URL or null if not found
     */
    public static function get_image_public_url($image_id) {
        if (strpos($image_id, self::S3_PREFIX) === 0) {
            // S3 image
            $numeric_id = intval(substr($image_id, strlen(self::S3_PREFIX)));
            if (!$numeric_id) {
                return null;
            }
            
            $s3 = Texter_S3_Storage::get_instance();
            $s3_image = $s3->get_image_by_id($numeric_id);
            
            if (!$s3_image) {
                return null;
            }
            
            return $s3->get_public_url($s3_image->s3_key);
        }
        
        // WordPress gallery image
        if (strpos($image_id, self::SOURCE_PREFIX) === 0) {
            $numeric_id = intval(substr($image_id, strlen(self::SOURCE_PREFIX)));
        } else {
            $numeric_id = intval($image_id);
        }
        
        if (!$numeric_id) {
            return null;
        }
        
        return wp_get_attachment_url($numeric_id);
    }
    
    /**
     * Get alt text for an image ID (handles both gal- and s3- prefixes)
     *
     * @param string $image_id Image ID with prefix
     * @return string Alt text or empty string
     */
    public static function get_image_alt_text($image_id) {
        if (strpos($image_id, self::S3_PREFIX) === 0) {
            // S3 image
            $numeric_id = intval(substr($image_id, strlen(self::S3_PREFIX)));
            if (!$numeric_id) {
                return '';
            }
            
            $s3 = Texter_S3_Storage::get_instance();
            $s3_image = $s3->get_image_by_id($numeric_id);
            
            return $s3_image ? ($s3_image->alt_text ?: $s3_image->title) : '';
        }
        
        // WordPress gallery image
        if (strpos($image_id, self::SOURCE_PREFIX) === 0) {
            $numeric_id = intval(substr($image_id, strlen(self::SOURCE_PREFIX)));
        } else {
            $numeric_id = intval($image_id);
        }
        
        if (!$numeric_id) {
            return '';
        }
        
        $alt = get_post_meta($numeric_id, '_wp_attachment_image_alt', true);
        if ($alt) {
            return $alt;
        }
        
        // Fall back to attachment title
        $attachment = get_post($numeric_id);
        return $attachment ? $attachment->post_title : '';
    }
}
