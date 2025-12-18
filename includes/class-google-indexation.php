<?php
/**
 * Google Indexation Checker for Texter API
 * 
 * Uses Google Search Console API to check URL indexation status
 */

if (!defined('ABSPATH')) {
    exit;
}

class Texter_API_Google_Indexation {
    
    /**
     * Option keys for settings
     */
    const OPTION_ENABLED = 'texter_google_index_enabled';
    const OPTION_API_KEY = 'texter_google_api_key';
    const OPTION_CHECK_INTERVAL = 'texter_google_check_interval';
    const OPTION_CRON_FREQUENCY = 'texter_google_cron_frequency';
    const OPTION_BATCH_SIZE = 'texter_google_batch_size';
    
    /**
     * Cron hook name
     */
    const CRON_HOOK = 'texter_indexation_cron';
    
    /**
     * Default check intervals in days
     */
    const DEFAULT_INDEXED_INTERVAL = 7;    // Re-check indexed pages after 7 days
    const DEFAULT_NOT_INDEXED_INTERVAL = 1; // Re-check non-indexed pages after 1 day
    const DEFAULT_CRON_FREQUENCY = 'hourly'; // How often cron runs
    const DEFAULT_BATCH_SIZE = 5;           // Posts to check per cron run
    
    /**
     * HTTP client instance
     */
    private static $http_client = null;
    
    /**
     * Cached access token
     */
    private static $access_token = null;
    private static $token_expires = 0;
    
    /**
     * Check if indexation checking is enabled
     *
     * @return bool
     */
    public static function is_enabled() {
        return get_option(self::OPTION_ENABLED, 'no') === 'yes';
    }
    
    /**
     * Get Google API credentials
     *
     * @return array|null Decoded JSON credentials or null if not set
     */
    public static function get_api_credentials() {
        $credentials = get_option(self::OPTION_API_KEY, '');
        if (empty($credentials)) {
            return null;
        }
        
        $decoded = json_decode($credentials, true);
        return is_array($decoded) ? $decoded : null;
    }
    
    /**
     * Get check interval settings
     *
     * @return array ['indexed' => days, 'not_indexed' => days]
     */
    public static function get_check_intervals() {
        $interval = get_option(self::OPTION_CHECK_INTERVAL, '');
        if (empty($interval)) {
            return [
                'indexed' => self::DEFAULT_INDEXED_INTERVAL,
                'not_indexed' => self::DEFAULT_NOT_INDEXED_INTERVAL,
            ];
        }
        
        $decoded = json_decode($interval, true);
        return is_array($decoded) ? $decoded : [
            'indexed' => self::DEFAULT_INDEXED_INTERVAL,
            'not_indexed' => self::DEFAULT_NOT_INDEXED_INTERVAL,
        ];
    }
    
    /**
     * Save settings
     *
     * @param bool $enabled Whether indexation checking is enabled
     * @param string $api_key JSON credentials string
     * @param array $intervals Check intervals
     * @return bool Success
     */
    public static function save_settings($enabled, $api_key = null, $intervals = null) {
        update_option(self::OPTION_ENABLED, $enabled ? 'yes' : 'no');
        
        if ($api_key !== null) {
            update_option(self::OPTION_API_KEY, $api_key);
        }
        
        if ($intervals !== null) {
            update_option(self::OPTION_CHECK_INTERVAL, json_encode($intervals));
        }
        
        return true;
    }
    
    /**
     * Initialize Google API client
     *
     * @return string|WP_Error Access token or error
     */
    private static function get_access_token() {
        // Return cached token if still valid
        if (self::$access_token !== null && time() < self::$token_expires - 60) {
            return self::$access_token;
        }
        
        $credentials = self::get_api_credentials();
        if ($credentials === null) {
            return new WP_Error(
                'api_not_configured',
                'Google API credentials are not configured',
                ['status' => 500]
            );
        }
        
        // Validate required credential fields
        if (!isset($credentials['client_email']) || !isset($credentials['private_key'])) {
            return new WP_Error(
                'invalid_credentials',
                'Google API credentials missing client_email or private_key',
                ['status' => 500]
            );
        }
        
        // Create JWT for service account authentication
        $jwt = self::create_jwt($credentials);
        if (is_wp_error($jwt)) {
            return $jwt;
        }
        
        // Exchange JWT for access token
        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'body' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ],
            'timeout' => 30,
        ]);
        
        if (is_wp_error($response)) {
            return new WP_Error(
                'token_request_failed',
                'Failed to get access token: ' . $response->get_error_message(),
                ['status' => 500]
            );
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!isset($body['access_token'])) {
            $error_msg = $body['error_description'] ?? $body['error'] ?? 'Unknown error';
            return new WP_Error(
                'token_error',
                'Failed to get access token: ' . $error_msg,
                ['status' => 500]
            );
        }
        
        self::$access_token = $body['access_token'];
        self::$token_expires = time() + ($body['expires_in'] ?? 3600);
        
        return self::$access_token;
    }
    
    /**
     * Create JWT for Google service account authentication
     *
     * @param array $credentials Service account credentials
     * @return string|WP_Error JWT string or error
     */
    private static function create_jwt($credentials) {
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
        ];
        
        $now = time();
        $payload = [
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ];
        
        $header_encoded = self::base64url_encode(json_encode($header));
        $payload_encoded = self::base64url_encode(json_encode($payload));
        
        $signature_input = $header_encoded . '.' . $payload_encoded;
        
        // Sign with private key
        $private_key = openssl_pkey_get_private($credentials['private_key']);
        if ($private_key === false) {
            return new WP_Error(
                'invalid_private_key',
                'Failed to parse private key: ' . openssl_error_string(),
                ['status' => 500]
            );
        }
        
        $signature = '';
        $sign_result = openssl_sign($signature_input, $signature, $private_key, OPENSSL_ALGO_SHA256);
        
        if (!$sign_result) {
            return new WP_Error(
                'signing_failed',
                'Failed to sign JWT: ' . openssl_error_string(),
                ['status' => 500]
            );
        }
        
        return $signature_input . '.' . self::base64url_encode($signature);
    }
    
    /**
     * Base64 URL encode (JWT-safe)
     *
     * @param string $data Data to encode
     * @return string Encoded string
     */
    private static function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    /**
     * Inspect URL indexation status
     *
     * @param string $url The URL to check
     * @return array Result with 'status', 'indexed', 'raw', 'error' keys
     */
    public static function inspect_url($url) {
        $access_token = self::get_access_token();
        
        if (is_wp_error($access_token)) {
            return [
                'status' => 'error',
                'indexed' => null,
                'error' => $access_token->get_error_message(),
                'raw' => null,
            ];
        }
        
        $site = parse_url($url, PHP_URL_HOST);
        
        $response = wp_remote_post(
            'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'inspectionUrl' => $url,
                    'siteUrl' => 'sc-domain:' . $site,
                    'languageCode' => 'en-US',
                ]),
                'timeout' => 30,
            ]
        );
        
        if (is_wp_error($response)) {
            return [
                'status' => 'error',
                'indexed' => null,
                'error' => $response->get_error_message(),
                'raw' => null,
            ];
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status_code !== 200) {
            $error_data = json_decode($body, true);
            $error_msg = $error_data['error']['message'] ?? 'HTTP error: ' . $status_code;
            return [
                'status' => 'error',
                'indexed' => null,
                'error' => $error_msg,
                'raw' => $body,
            ];
        }
        
        $data = json_decode($body, true);
        if ($data === null) {
            return [
                'status' => 'error',
                'indexed' => null,
                'error' => 'Failed to decode JSON response',
                'raw' => $body,
            ];
        }
        
        // Parse indexation status from response
        $index_result = $data['inspectionResult']['indexStatusResult'] ?? [];
        $is_indexed = (
            ($index_result['pageFetchState'] ?? '') === 'SUCCESSFUL' &&
            ($index_result['verdict'] ?? '') === 'PASS' &&
            ($index_result['coverageState'] ?? '') === 'Submitted and indexed'
        );
        
        return [
            'status' => 'success',
            'indexed' => $is_indexed,
            'error' => null,
            'raw' => $data,
        ];
    }
    
    /**
     * Get indexation status for a post
     *
     * @param int $post_id Post ID
     * @return array|null Status array or null if not checked
     */
    public static function get_post_status($post_id) {
        $status = get_post_meta($post_id, 'texter_google_index_status', true);
        
        if (empty($status) || !is_string($status)) {
            return null;
        }
        
        $parts = explode('|', $status);
        return [
            'status' => $parts[0] ?? 'unknown',
            'checked_at' => isset($parts[1]) ? (int)$parts[1] : 0,
        ];
    }
    
    /**
     * Update indexation status for a post
     *
     * @param int $post_id Post ID
     * @param string $status Status: 'indexed', 'not_indexed', 'error'
     * @return bool Success
     */
    public static function update_post_status($post_id, $status) {
        $value = $status . '|' . time();
        return update_post_meta($post_id, 'texter_google_index_status', $value);
    }
    
    /**
     * Check if a post needs indexation checking
     *
     * @param int $post_id Post ID
     * @return bool True if check is needed
     */
    public static function needs_check($post_id) {
        $status = self::get_post_status($post_id);
        
        // Never checked
        if ($status === null) {
            return true;
        }
        
        $intervals = self::get_check_intervals();
        $checked_at = $status['checked_at'];
        $current_status = $status['status'];
        
        // Calculate interval based on current status
        $interval_days = match($current_status) {
            'indexed' => $intervals['indexed'],
            'not_indexed', 'error' => $intervals['not_indexed'],
            default => $intervals['not_indexed'],
        };
        
        $interval_seconds = $interval_days * 86400;
        return (time() - $checked_at) > $interval_seconds;
    }
    
    /**
     * Get posts that need indexation checking
     *
     * @param int $limit Maximum number of posts to return
     * @return array Array of post objects
     */
    public static function get_posts_to_check($limit = 50) {
        if (!self::is_enabled()) {
            return [];
        }
        
        $intervals = self::get_check_intervals();
        
        $args = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $limit * 2, // Get more to filter
            'orderby' => 'date',
            'order' => 'DESC',
        ];
        
        $posts = get_posts($args);
        $result = [];
        
        foreach ($posts as $post) {
            if (count($result) >= $limit) {
                break;
            }
            
            if (self::needs_check($post->ID)) {
                $result[] = $post;
            }
        }
        
        return $result;
    }
    
    /**
     * Get cron frequency setting
     *
     * @return string WordPress cron schedule name
     */
    public static function get_cron_frequency() {
        return get_option(self::OPTION_CRON_FREQUENCY, self::DEFAULT_CRON_FREQUENCY);
    }
    
    /**
     * Get batch size setting
     *
     * @return int Number of posts to check per cron run
     */
    public static function get_batch_size() {
        return (int) get_option(self::OPTION_BATCH_SIZE, self::DEFAULT_BATCH_SIZE);
    }
    
    /**
     * Save cron settings
     *
     * @param string $frequency Cron frequency
     * @param int $batch_size Batch size
     */
    public static function save_cron_settings($frequency, $batch_size) {
        $old_frequency = self::get_cron_frequency();
        
        update_option(self::OPTION_CRON_FREQUENCY, $frequency);
        update_option(self::OPTION_BATCH_SIZE, max(1, min(50, $batch_size)));
        
        // Reschedule if frequency changed
        if ($old_frequency !== $frequency) {
            self::unschedule_cron();
            if (self::is_enabled()) {
                self::schedule_cron();
            }
        }
    }
    
    /**
     * Schedule the cron event
     */
    public static function schedule_cron() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), self::get_cron_frequency(), self::CRON_HOOK);
        }
    }
    
    /**
     * Unschedule the cron event
     */
    public static function unschedule_cron() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }
    
    /**
     * Initialize cron hooks
     */
    public static function init_cron() {
        // Register the cron handler
        add_action(self::CRON_HOOK, [__CLASS__, 'run_cron_check']);
        
        // Schedule if enabled and not already scheduled
        if (self::is_enabled() && !wp_next_scheduled(self::CRON_HOOK)) {
            self::schedule_cron();
        }
    }
    
    /**
     * Cron job: Check indexation for pending posts
     */
    public static function run_cron_check() {
        if (!self::is_enabled()) {
            return;
        }
        
        $credentials = self::get_api_credentials();
        if ($credentials === null) {
            return; // No API credentials configured
        }
        
        $batch_size = self::get_batch_size();
        $posts = self::get_posts_to_check($batch_size);
        
        foreach ($posts as $post) {
            $url = get_permalink($post->ID);
            $result = self::inspect_url($url);
            
            if ($result['status'] === 'error') {
                self::update_post_status($post->ID, 'error');
            } else {
                $status = $result['indexed'] ? 'indexed' : 'not_indexed';
                self::update_post_status($post->ID, $status);
            }
            
            // Small delay between API calls to avoid rate limiting
            usleep(500000); // 500ms
        }
    }
    
    /**
     * Get next scheduled cron run time
     *
     * @return int|false Timestamp or false if not scheduled
     */
    public static function get_next_cron_run() {
        return wp_next_scheduled(self::CRON_HOOK);
    }
}
