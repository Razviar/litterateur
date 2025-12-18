<?php
/**
 * S3-compatible storage (Cloudflare R2) functionality for Texter API
 * Uses raw HTTP requests with AWS Signature v4 - no SDK required
 */

if (!defined('ABSPATH')) {
    exit;
}

class Texter_S3_Storage {

    /**
     * Table name for S3 images metadata
     */
    const TABLE_NAME = 'texter_s3_images';

    /**
     * S3 configuration
     */
    private $endpoint;
    private $bucket;
    private $access_key;
    private $secret_key;
    private $region;
    private $public_url;
    private $path_prefix;

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - load settings
     */
    private function __construct() {
        $this->load_settings();
    }

    /**
     * Load S3 settings from WordPress options
     */
    private function load_settings() {
        $this->endpoint = get_option('texter_s3_endpoint', '');
        $this->bucket = get_option('texter_s3_bucket', '');
        $this->access_key = get_option('texter_s3_access_key', '');
        $this->secret_key = get_option('texter_s3_secret_key', '');
        $this->region = get_option('texter_s3_region', 'auto');
        $this->public_url = rtrim(get_option('texter_s3_public_url', ''), '/');
        $this->path_prefix = trim(get_option('texter_s3_path_prefix', ''), '/');
    }

    /**
     * Check if S3 storage is enabled and configured
     */
    public static function is_enabled() {
        return get_option('texter_s3_enabled', false) === 'yes';
    }

    /**
     * Check if S3 is preferred storage for new uploads
     */
    public static function is_preferred_storage() {
        return self::is_enabled() && get_option('texter_s3_preferred_storage', 'gallery') === 's3';
    }

    /**
     * Get the database table name with prefix
     */
    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * Create database table on plugin activation
     */
    public static function create_table() {
        global $wpdb;
        
        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            s3_key varchar(500) NOT NULL,
            filename varchar(255) NOT NULL,
            mime_type varchar(100) DEFAULT 'image/jpeg',
            width int(10) unsigned DEFAULT 0,
            height int(10) unsigned DEFAULT 0,
            filesize bigint(20) unsigned DEFAULT 0,
            title varchar(255) DEFAULT '',
            description text,
            alt_text varchar(255) DEFAULT '',
            upload_date datetime DEFAULT CURRENT_TIMESTAMP,
            modified_date datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            hash varchar(64) DEFAULT '',
            used_in_posts text,
            last_synced datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY s3_key (s3_key(191)),
            KEY upload_date (upload_date),
            KEY modified_date (modified_date)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Get public URL for an S3 key
     */
    public function get_public_url($key) {
        if (empty($this->public_url)) {
            // Fallback to endpoint URL
            return rtrim($this->endpoint, '/') . '/' . $this->bucket . '/' . $key;
        }
        return $this->public_url . '/' . $key;
    }

    /**
     * Get full S3 key with path prefix
     */
    private function get_full_key($filename) {
        if (!empty($this->path_prefix)) {
            return $this->path_prefix . '/' . $filename;
        }
        return $filename;
    }

    /**
     * Test connection to S3/R2
     */
    public function test_connection() {
        if (empty($this->endpoint) || empty($this->bucket) || empty($this->access_key) || empty($this->secret_key)) {
            return array(
                'success' => false,
                'message' => 'Missing required configuration'
            );
        }

        // Try to list objects (max 1) to test connection
        $result = $this->list_objects('', 1);
        
        if (is_wp_error($result)) {
            return array(
                'success' => false,
                'message' => $result->get_error_message()
            );
        }

        return array(
            'success' => true,
            'message' => 'Connection successful'
        );
    }

    /**
     * Upload file to S3
     * 
     * @param string $file_data Binary file data
     * @param string $filename Desired filename
     * @param string $content_type MIME type
     * @return array|WP_Error Upload result with key and URL
     */
    public function upload($file_data, $filename, $content_type = 'image/jpeg') {
        $key = $this->get_full_key($filename);
        
        $headers = array(
            'Content-Type' => $content_type,
            'Content-Length' => strlen($file_data),
        );

        $response = $this->make_request('PUT', $key, $file_data, $headers);

        if (is_wp_error($response)) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code !== 200 && $response_code !== 201) {
            $body = wp_remote_retrieve_body($response);
            return new WP_Error('s3_upload_failed', 'Upload failed: ' . $body);
        }

        return array(
            'key' => $key,
            'url' => $this->get_public_url($key),
            'size' => strlen($file_data)
        );
    }

    /**
     * Delete object from S3
     * 
     * @param string $key S3 object key
     * @return bool|WP_Error
     */
    public function delete($key) {
        $response = $this->make_request('DELETE', $key);

        if (is_wp_error($response)) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        
        // 204 No Content is success for DELETE
        if ($response_code !== 204 && $response_code !== 200) {
            $body = wp_remote_retrieve_body($response);
            return new WP_Error('s3_delete_failed', 'Delete failed: ' . $body);
        }

        return true;
    }

    /**
     * Get object metadata (HEAD request)
     * 
     * @param string $key S3 object key
     * @return array|WP_Error Object metadata
     */
    public function head_object($key) {
        $response = $this->make_request('HEAD', $key);

        if (is_wp_error($response)) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code !== 200) {
            return new WP_Error('s3_head_failed', 'Object not found');
        }

        $headers = wp_remote_retrieve_headers($response);
        
        return array(
            'content_type' => isset($headers['content-type']) ? $headers['content-type'] : 'application/octet-stream',
            'content_length' => isset($headers['content-length']) ? (int) $headers['content-length'] : 0,
            'last_modified' => isset($headers['last-modified']) ? $headers['last-modified'] : null,
            'etag' => isset($headers['etag']) ? trim($headers['etag'], '"') : null,
        );
    }

    /**
     * Get object content
     * 
     * @param string $key S3 object key
     * @return string|WP_Error Object content
     */
    public function get_object($key) {
        $response = $this->make_request('GET', $key);

        if (is_wp_error($response)) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code !== 200) {
            return new WP_Error('s3_get_failed', 'Failed to get object');
        }

        return wp_remote_retrieve_body($response);
    }

    /**
     * List objects in bucket
     * 
     * @param string $prefix Filter by prefix
     * @param int $max_keys Maximum number of keys to return
     * @param string $continuation_token For pagination
     * @return array|WP_Error List of objects
     */
    public function list_objects($prefix = '', $max_keys = 1000, $continuation_token = '') {
        $query_params = array(
            'list-type' => '2',
            'max-keys' => $max_keys,
        );

        // Add path prefix to search prefix
        $full_prefix = $this->path_prefix;
        if (!empty($prefix)) {
            $full_prefix = !empty($full_prefix) ? $full_prefix . '/' . $prefix : $prefix;
        }
        
        if (!empty($full_prefix)) {
            $query_params['prefix'] = $full_prefix;
        }

        if (!empty($continuation_token)) {
            $query_params['continuation-token'] = $continuation_token;
        }

        $response = $this->make_request('GET', '', null, array(), $query_params);

        if (is_wp_error($response)) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code !== 200) {
            $body = wp_remote_retrieve_body($response);
            return new WP_Error('s3_list_failed', 'List failed: ' . $body);
        }

        $body = wp_remote_retrieve_body($response);
        return $this->parse_list_response($body);
    }

    /**
     * Parse ListObjectsV2 XML response
     */
    private function parse_list_response($xml_body) {
        $xml = simplexml_load_string($xml_body);
        
        if ($xml === false) {
            return new WP_Error('s3_parse_error', 'Failed to parse response');
        }

        // Handle namespace
        $namespaces = $xml->getNamespaces(true);
        if (!empty($namespaces)) {
            $xml->registerXPathNamespace('s3', reset($namespaces));
            $contents = $xml->xpath('//s3:Contents');
            $is_truncated = (string) $xml->xpath('//s3:IsTruncated')[0] ?? 'false';
            $continuation = $xml->xpath('//s3:NextContinuationToken');
        } else {
            $contents = $xml->Contents ?? array();
            $is_truncated = (string) ($xml->IsTruncated ?? 'false');
            $continuation = isset($xml->NextContinuationToken) ? array($xml->NextContinuationToken) : array();
        }

        $objects = array();
        foreach ($contents as $content) {
            $objects[] = array(
                'key' => (string) $content->Key,
                'size' => (int) $content->Size,
                'last_modified' => (string) $content->LastModified,
                'etag' => trim((string) $content->ETag, '"'),
            );
        }

        return array(
            'objects' => $objects,
            'is_truncated' => strtolower($is_truncated) === 'true',
            'continuation_token' => !empty($continuation) ? (string) $continuation[0] : null,
        );
    }

    /**
     * Sync images from S3 bucket to local database
     * Discovers externally uploaded images
     * 
     * @return array Sync results
     */
    public function sync_from_bucket() {
        global $wpdb;
        
        $table_name = self::get_table_name();
        $results = array(
            'added' => 0,
            'updated' => 0,
            'errors' => array(),
        );

        $continuation_token = '';
        $image_extensions = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg');

        do {
            $list_result = $this->list_objects('', 1000, $continuation_token);
            
            if (is_wp_error($list_result)) {
                $results['errors'][] = $list_result->get_error_message();
                break;
            }

            foreach ($list_result['objects'] as $object) {
                $key = $object['key'];
                $extension = strtolower(pathinfo($key, PATHINFO_EXTENSION));
                
                // Skip non-image files
                if (!in_array($extension, $image_extensions)) {
                    continue;
                }

                // Check if already in database
                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, hash FROM $table_name WHERE s3_key = %s",
                    $key
                ));

                $etag = $object['etag'];
                
                if ($existing) {
                    // Update if hash changed
                    if ($existing->hash !== $etag) {
                        $metadata = $this->extract_image_metadata($key);
                        
                        $wpdb->update(
                            $table_name,
                            array(
                                'filesize' => $object['size'],
                                'width' => $metadata['width'],
                                'height' => $metadata['height'],
                                'mime_type' => $metadata['mime_type'],
                                'hash' => $etag,
                                'last_synced' => current_time('mysql'),
                            ),
                            array('id' => $existing->id),
                            array('%d', '%d', '%d', '%s', '%s', '%s'),
                            array('%d')
                        );
                        $results['updated']++;
                    }
                } else {
                    // New image - extract metadata
                    $metadata = $this->extract_image_metadata($key);
                    $filename = basename($key);
                    
                    $wpdb->insert(
                        $table_name,
                        array(
                            's3_key' => $key,
                            'filename' => $filename,
                            'mime_type' => $metadata['mime_type'],
                            'width' => $metadata['width'],
                            'height' => $metadata['height'],
                            'filesize' => $object['size'],
                            'title' => pathinfo($filename, PATHINFO_FILENAME),
                            'hash' => $etag,
                            'upload_date' => date('Y-m-d H:i:s', strtotime($object['last_modified'])),
                            'last_synced' => current_time('mysql'),
                        ),
                        array('%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s')
                    );
                    $results['added']++;
                }
            }

            $continuation_token = $list_result['continuation_token'];
            
        } while ($list_result['is_truncated'] && !empty($continuation_token));

        return $results;
    }

    /**
     * Extract image metadata (dimensions, mime type)
     * Downloads image temporarily to extract info
     * 
     * @param string $key S3 object key
     * @return array Image metadata
     */
    private function extract_image_metadata($key) {
        $metadata = array(
            'width' => 0,
            'height' => 0,
            'mime_type' => 'image/jpeg',
        );

        // Get the image content
        $content = $this->get_object($key);
        
        if (is_wp_error($content)) {
            return $metadata;
        }

        // Write to temp file
        $temp_file = wp_tempnam('s3_');
        if (!$temp_file) {
            return $metadata;
        }

        file_put_contents($temp_file, $content);

        // Get image info
        $image_info = @getimagesize($temp_file);
        
        if ($image_info !== false) {
            $metadata['width'] = $image_info[0];
            $metadata['height'] = $image_info[1];
            $metadata['mime_type'] = $image_info['mime'];
        }

        // Clean up
        @unlink($temp_file);

        return $metadata;
    }

    /**
     * Get image from local database by ID
     * 
     * @param int $id Database row ID
     * @return object|null Image data
     */
    public function get_image_by_id($id) {
        global $wpdb;
        $table_name = self::get_table_name();
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $id
        ));
    }

    /**
     * Get all images from local database
     * 
     * @param array $args Query arguments
     * @return array Images
     */
    public function get_images($args = array()) {
        global $wpdb;
        $table_name = self::get_table_name();
        
        $defaults = array(
            'orderby' => 'upload_date',
            'order' => 'DESC',
            'limit' => 100,
            'offset' => 0,
            'modified_after' => null,
        );
        
        $args = wp_parse_args($args, $defaults);
        
        $sql = "SELECT * FROM $table_name WHERE 1=1";
        $params = array();
        
        if (!empty($args['modified_after'])) {
            $sql .= " AND modified_date > %s";
            $params[] = $args['modified_after'];
        }
        
        $sql .= " ORDER BY {$args['orderby']} {$args['order']}";
        $sql .= " LIMIT %d OFFSET %d";
        $params[] = $args['limit'];
        $params[] = $args['offset'];
        
        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }
        
        return $wpdb->get_results($sql);
    }

    /**
     * Get image count
     */
    public function get_image_count() {
        global $wpdb;
        $table_name = self::get_table_name();
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    }

    /**
     * Update image metadata in database
     * 
     * @param int $id Database row ID
     * @param array $data Data to update
     * @return bool Success
     */
    public function update_image($id, $data) {
        global $wpdb;
        $table_name = self::get_table_name();
        
        $allowed_fields = array('title', 'description', 'alt_text', 'used_in_posts');
        $update_data = array_intersect_key($data, array_flip($allowed_fields));
        
        if (empty($update_data)) {
            return false;
        }
        
        return $wpdb->update($table_name, $update_data, array('id' => $id)) !== false;
    }

    /**
     * Delete image from S3 and database
     * 
     * @param int $id Database row ID
     * @return bool|WP_Error Success or error
     */
    public function delete_image($id) {
        global $wpdb;
        $table_name = self::get_table_name();
        
        $image = $this->get_image_by_id($id);
        if (!$image) {
            return new WP_Error('not_found', 'Image not found');
        }
        
        // Delete from S3
        $result = $this->delete($image->s3_key);
        if (is_wp_error($result)) {
            return $result;
        }
        
        // Delete from database
        $wpdb->delete($table_name, array('id' => $id), array('%d'));
        
        return true;
    }

    /**
     * Save uploaded image to database
     * 
     * @param array $upload_result Result from upload()
     * @param array $metadata Additional metadata
     * @return int|WP_Error Inserted row ID
     */
    public function save_image_to_db($upload_result, $metadata = array()) {
        global $wpdb;
        $table_name = self::get_table_name();
        
        $data = array(
            's3_key' => $upload_result['key'],
            'filename' => basename($upload_result['key']),
            'mime_type' => isset($metadata['mime_type']) ? $metadata['mime_type'] : 'image/jpeg',
            'width' => isset($metadata['width']) ? $metadata['width'] : 0,
            'height' => isset($metadata['height']) ? $metadata['height'] : 0,
            'filesize' => $upload_result['size'],
            'title' => isset($metadata['title']) ? $metadata['title'] : '',
            'description' => isset($metadata['description']) ? $metadata['description'] : '',
            'alt_text' => isset($metadata['alt_text']) ? $metadata['alt_text'] : '',
            'hash' => isset($metadata['hash']) ? $metadata['hash'] : md5($upload_result['key']),
            'upload_date' => current_time('mysql'),
            'last_synced' => current_time('mysql'),
        );
        
        $result = $wpdb->insert($table_name, $data);
        
        if ($result === false) {
            return new WP_Error('db_error', 'Failed to save image to database');
        }
        
        return $wpdb->insert_id;
    }

    /**
     * Make signed request to S3
     * 
     * @param string $method HTTP method
     * @param string $key Object key (empty for bucket operations)
     * @param string $body Request body
     * @param array $headers Additional headers
     * @param array $query_params Query parameters
     * @return array|WP_Error Response
     */
    private function make_request($method, $key, $body = null, $headers = array(), $query_params = array()) {
        $service = 's3';
        $region = $this->region ?: 'auto';
        
        // Parse endpoint to get host
        $parsed = parse_url($this->endpoint);
        $host = $parsed['host'];
        $scheme = isset($parsed['scheme']) ? $parsed['scheme'] : 'https';
        
        // Build URI
        $uri = '/' . $this->bucket;
        if (!empty($key)) {
            $uri .= '/' . $key;
        }
        
        // Build query string
        $query_string = '';
        if (!empty($query_params)) {
            ksort($query_params);
            $query_string = http_build_query($query_params, '', '&', PHP_QUERY_RFC3986);
        }
        
        // Current time
        $amz_date = gmdate('Ymd\THis\Z');
        $date_stamp = gmdate('Ymd');
        
        // Create canonical request
        $canonical_uri = $uri;
        $canonical_querystring = $query_string;
        
        // Hash payload
        $payload_hash = hash('sha256', $body ?? '');
        
        // Set required headers
        $headers['Host'] = $host;
        $headers['x-amz-date'] = $amz_date;
        $headers['x-amz-content-sha256'] = $payload_hash;
        
        // Create signed headers string
        $signed_headers_list = array_keys($headers);
        $signed_headers_list = array_map('strtolower', $signed_headers_list);
        sort($signed_headers_list);
        $signed_headers = implode(';', $signed_headers_list);
        
        // Create canonical headers string
        $canonical_headers = '';
        $headers_lower = array();
        foreach ($headers as $k => $v) {
            $headers_lower[strtolower($k)] = $v;
        }
        ksort($headers_lower);
        foreach ($headers_lower as $k => $v) {
            $canonical_headers .= $k . ':' . trim($v) . "\n";
        }
        
        // Create canonical request
        $canonical_request = $method . "\n" .
            $canonical_uri . "\n" .
            $canonical_querystring . "\n" .
            $canonical_headers . "\n" .
            $signed_headers . "\n" .
            $payload_hash;
        
        // Create string to sign
        $algorithm = 'AWS4-HMAC-SHA256';
        $credential_scope = $date_stamp . '/' . $region . '/' . $service . '/aws4_request';
        $string_to_sign = $algorithm . "\n" .
            $amz_date . "\n" .
            $credential_scope . "\n" .
            hash('sha256', $canonical_request);
        
        // Create signing key
        $signing_key = $this->get_signature_key($this->secret_key, $date_stamp, $region, $service);
        
        // Create signature
        $signature = hash_hmac('sha256', $string_to_sign, $signing_key);
        
        // Create authorization header
        $authorization = $algorithm . ' ' .
            'Credential=' . $this->access_key . '/' . $credential_scope . ', ' .
            'SignedHeaders=' . $signed_headers . ', ' .
            'Signature=' . $signature;
        
        $headers['Authorization'] = $authorization;
        
        // Build full URL
        $url = $scheme . '://' . $host . $uri;
        if (!empty($query_string)) {
            $url .= '?' . $query_string;
        }
        
        // Make request
        $args = array(
            'method' => $method,
            'headers' => $headers,
            'timeout' => 60,
            'sslverify' => true,
        );
        
        if ($body !== null) {
            $args['body'] = $body;
        }
        
        return wp_remote_request($url, $args);
    }

    /**
     * Generate AWS v4 signature key
     */
    private function get_signature_key($key, $date_stamp, $region, $service) {
        $k_date = hash_hmac('sha256', $date_stamp, 'AWS4' . $key, true);
        $k_region = hash_hmac('sha256', $region, $k_date, true);
        $k_service = hash_hmac('sha256', $service, $k_region, true);
        $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
        return $k_signing;
    }

    /**
     * Initialize AJAX handlers
     */
    public static function init_ajax() {
        add_action('wp_ajax_texter_s3_test_connection', array(__CLASS__, 'ajax_test_connection'));
        add_action('wp_ajax_texter_s3_sync', array(__CLASS__, 'ajax_sync'));
    }

    /**
     * AJAX handler for testing S3 connection
     */
    public static function ajax_test_connection() {
        check_ajax_referer('texter_s3_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $instance = self::get_instance();
        $result = $instance->test_connection();
        
        if ($result['success']) {
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    /**
     * AJAX handler for syncing images from S3
     */
    public static function ajax_sync() {
        check_ajax_referer('texter_s3_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $instance = self::get_instance();
        $result = $instance->sync_from_bucket();
        
        if (!empty($result['errors'])) {
            wp_send_json_error(array(
                'message' => implode(', ', $result['errors']),
                'added' => $result['added'],
                'updated' => $result['updated'],
            ));
        }
        
        wp_send_json_success(array(
            'message' => sprintf('Sync complete. Added: %d, Updated: %d', $result['added'], $result['updated']),
            'added' => $result['added'],
            'updated' => $result['updated'],
        ));
    }
}
