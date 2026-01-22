<?php

/**
 * Google Indexation Admin UI Components
 * 
 * Provides admin settings page sections and posts list column
 */

if (!defined('ABSPATH')) {
    exit;
}

class Texter_API_Indexation_Admin
{

    /**
     * Initialize admin hooks
     */
    public static function init()
    {
        // Add posts list column (only if enabled)
        add_filter('manage_post_posts_columns', [__CLASS__, 'add_posts_column']);
        add_action('manage_post_posts_custom_column', [__CLASS__, 'render_posts_column'], 10, 2);

        // Add row action for manual check
        add_filter('post_row_actions', [__CLASS__, 'add_row_actions'], 10, 2);

        // Handle admin AJAX actions
        add_action('wp_ajax_texter_check_indexation', [__CLASS__, 'handle_check_indexation']);

        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_scripts']);
    }

    /**
     * Add Google Index column to posts list
     *
     * @param array $columns Current columns
     * @return array Modified columns
     */
    public static function add_posts_column($columns)
    {
        if (!Texter_API_Google_Indexation::is_enabled()) {
            return $columns;
        }

        $columns['texter_google_index'] = '<span class="dashicons dashicons-google" title="Google Index Status"></span>';
        return $columns;
    }

    /**
     * Render Google Index column content
     *
     * @param string $column Column name
     * @param int $post_id Post ID
     */
    public static function render_posts_column($column, $post_id)
    {
        if ($column !== 'texter_google_index') {
            return;
        }

        $status = Texter_API_Google_Indexation::get_post_status($post_id);

        if ($status === null) {
            echo '<span class="texter-gi-status texter-gi-unknown" title="Not checked">—</span>';
            return;
        }

        $checked_date = $status['checked_at'] > 0
            ? date('m/d/Y H:i', $status['checked_at'])
            : '';

        switch ($status['status']) {
            case 'indexed':
                echo '<span class="texter-gi-status texter-gi-indexed" title="Indexed ' . esc_attr($checked_date) . '">●</span>';
                break;
            case 'not_indexed':
                echo '<span class="texter-gi-status texter-gi-not-indexed" title="Not indexed ' . esc_attr($checked_date) . '">●</span>';
                break;
            case 'error':
                echo '<span class="texter-gi-status texter-gi-error" title="Error ' . esc_attr($checked_date) . '">●</span>';
                break;
            default:
                echo '<span class="texter-gi-status texter-gi-unknown" title="Unknown status">?</span>';
        }
    }

    /**
     * Add row actions for indexation
     *
     * @param array $actions Current actions
     * @param WP_Post $post Post object
     * @return array Modified actions
     */
    public static function add_row_actions($actions, $post)
    {
        if ($post->post_type !== 'post' || !Texter_API_Google_Indexation::is_enabled()) {
            return $actions;
        }

        $check_url = admin_url('admin.php?action=texter_check_indexation&post_id=' . $post->ID);
        $check_url = wp_nonce_url($check_url, 'texter_check_indexation_' . $post->ID);

        $actions['texter_check_index'] = sprintf(
            '<a href="#" onclick="texterCheckIndexation(%d, this); return false;">Check Google Index</a>',
            $post->ID
        );

        return $actions;
    }

    /**
     * Handle manual indexation check via admin action
     */
    public static function handle_check_indexation()
    {
        // Clean any output buffers that might interfere
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Verify user is logged in
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Not logged in']);
            wp_die();
        }

        $post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;

        if (!$post_id) {
            wp_send_json_error(['message' => 'Invalid post ID']);
            wp_die();
        }

        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => 'Permission denied']);
            wp_die();
        }

        if (!Texter_API_Google_Indexation::is_enabled()) {
            wp_send_json_error(['message' => 'Indexation checking is disabled']);
            wp_die();
        }

        $url = get_permalink($post_id);
        $result = Texter_API_Google_Indexation::inspect_url($url);

        if ($result['status'] === 'error') {
            wp_send_json_error([
                'message' => $result['error'],
                'status' => 'error',
            ]);
            wp_die();
        }

        $status = $result['indexed'] ? 'indexed' : 'not_indexed';
        Texter_API_Google_Indexation::update_post_status($post_id, $status);

        wp_send_json_success([
            'status' => $status,
            'indexed' => $result['indexed'],
            'message' => $result['indexed'] ? 'Page is indexed' : 'Page is not indexed',
        ]);
        wp_die();
    }

    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook Current admin page
     */
    public static function enqueue_scripts($hook)
    {
        // Load on posts list and Litterateur admin pages
        $allowed_hooks = [
            'edit.php',
            'toplevel_page_litterateur-api',
            'litterateur_page_litterateur-indexation',
        ];

        if (!in_array($hook, $allowed_hooks)) {
            return;
        }

        // Inline styles for indexation status icons
        $css = '
            .column-texter_google_index {
                width: 36px !important;
                text-align: center;
            }
            .column-texter_google_index .dashicons {
                color: #787c82;
                font-size: 16px;
                width: 16px;
                height: 16px;
            }
            .texter-gi-status {
                display: inline-block;
                font-size: 28px;
                line-height: 1;
                cursor: default;
            }
            .texter-gi-indexed { color: #00a32a; }
            .texter-gi-not-indexed { color: #d63638; }
            .texter-gi-error { color: #dba617; }
            .texter-gi-unknown { color: #a7aaad; }
            
            .texter-indexation-settings {
                background: #fff;
                border: 1px solid #c3c4c7;
                padding: 15px;
                margin-bottom: 20px;
            }
            .texter-indexation-settings h3 {
                margin-top: 0;
            }
            .texter-indexation-settings .form-table th {
                width: 200px;
            }
            .litterateur-api-key-textarea {
                width: 100%;
                height: 200px;
                font-family: monospace;
                font-size: 12px;
            }
        ';

        wp_add_inline_style('wp-admin', $css);

        // Inline script for manual indexation check
        $js = "
            function texterCheckIndexation(postId, element) {
                element.textContent = 'Checking...';
                element.style.pointerEvents = 'none';
                
                fetch(ajaxurl + '?action=texter_check_indexation&post_id=' + postId, {
                    method: 'GET',
                    credentials: 'same-origin'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        element.textContent = data.data.message;
                        // Reload to update status column
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        element.textContent = 'Error: ' + (data.data?.message || 'Unknown error 7');
                        element.style.pointerEvents = 'auto';
                    }
                })
                .catch(error => {
                    element.textContent = 'Error: ' + error.message;
                    element.style.pointerEvents = 'auto';
                });
            }
        ";

        wp_add_inline_script('jquery', $js);
    }

    /**
     * Render indexation settings section for the settings page
     */
    public static function render_settings_section()
    {
        $enabled = Texter_API_Google_Indexation::is_enabled();
        $has_api_key = Texter_API_Google_Indexation::get_api_credentials() !== null;
        $intervals = Texter_API_Google_Indexation::get_check_intervals();
        $cron_frequency = Texter_API_Google_Indexation::get_cron_frequency();
        $batch_size = Texter_API_Google_Indexation::get_batch_size();
        $next_run = Texter_API_Google_Indexation::get_next_cron_run();

?>
        <div class="litterateur-api-card texter-indexation-settings">
            <p>Check if your posts are indexed by Google using the Search Console API.</p>

            <form method="post" id="texter-indexation-settings-form">
                <?php wp_nonce_field('texter_indexation_settings'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">Enable Indexation Checking</th>
                        <td>
                            <label>
                                <input type="checkbox" name="texter_indexation_enabled" value="1" <?php checked($enabled); ?> />
                                Show indexation status in posts list
                            </label>
                            <p class="description">When enabled, a Google Index column will appear in the posts list.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Google API Credentials</th>
                        <td>
                            <textarea name="texter_google_api_key" class="litterateur-api-key-textarea" placeholder="Paste your Google API service account JSON here..."><?php
                                                                                                                                                                            if ($has_api_key) {
                                                                                                                                                                                echo '{"type": "service_account", "...": "credentials hidden for security"}';
                                                                                                                                                                            }
                                                                                                                                                                            ?></textarea>
                            <p class="description">
                                <a href="https://developers.google.com/webmaster-tools/v1/how-tos/authorizing" target="_blank">How to get Google API credentials</a>.
                                Requires <code>webmasters.readonly</code> scope.
                            </p>
                            <?php if ($has_api_key): ?>
                                <p class="description" style="color: #00a32a;">✓ API credentials are configured</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Automatic Checking</th>
                        <td>
                            <label>
                                Check frequency:
                                <select name="texter_cron_frequency">
                                    <option value="hourly" <?php selected($cron_frequency, 'hourly'); ?>>Hourly</option>
                                    <option value="twicedaily" <?php selected($cron_frequency, 'twicedaily'); ?>>Twice Daily</option>
                                    <option value="daily" <?php selected($cron_frequency, 'daily'); ?>>Daily</option>
                                </select>
                            </label>
                            <br><br>
                            <label>
                                Posts per check:
                                <input type="number" name="texter_batch_size" value="<?php echo esc_attr($batch_size); ?>" min="1" max="50" style="width: 60px;" />
                            </label>
                            <p class="description">How often to run automatic checks and how many posts to check each time.</p>
                            <?php if ($enabled && $next_run): ?>
                                <p class="description">Next scheduled check: <strong><?php echo date('Y-m-d H:i:s', $next_run); ?></strong></p>
                            <?php elseif ($enabled): ?>
                                <p class="description" style="color: #dba617;">⚠ Cron not scheduled. Save settings to activate.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Re-check Intervals</th>
                        <td>
                            <label>
                                Indexed pages:
                                <input type="number" name="texter_interval_indexed" value="<?php echo esc_attr($intervals['indexed']); ?>" min="1" max="30" style="width: 60px;" />
                                days
                            </label>
                            <br><br>
                            <label>
                                Non-indexed pages:
                                <input type="number" name="texter_interval_not_indexed" value="<?php echo esc_attr($intervals['not_indexed']); ?>" min="1" max="30" style="width: 60px;" />
                                days
                            </label>
                            <p class="description">How often to re-check pages based on their current status.</p>
                        </td>
                    </tr>
                </table>

                <p>
                    <input type="submit" name="texter_save_indexation" class="button button-primary" value="Save Indexation Settings" />
                </p>
            </form>
        </div>
<?php
    }

    /**
     * Process indexation settings form submission
     *
     * @return bool|null True if saved, null if no submission
     */
    public static function process_settings_form()
    {
        if (!isset($_POST['texter_save_indexation'])) {
            return null;
        }

        if (!check_admin_referer('texter_indexation_settings')) {
            return false;
        }

        $enabled = isset($_POST['texter_indexation_enabled']);
        $was_enabled = Texter_API_Google_Indexation::is_enabled();

        // Only update API key if a new one was provided (not the placeholder)
        $api_key = null;
        if (isset($_POST['texter_google_api_key'])) {
            $raw_key = stripslashes($_POST['texter_google_api_key']);
            // Don't save if it's the hidden placeholder
            if (strpos($raw_key, '"...": "credentials hidden') === false && !empty($raw_key)) {
                // Validate JSON
                $decoded = json_decode($raw_key, true);
                if ($decoded !== null) {
                    $api_key = $raw_key;
                }
            }
        }

        $intervals = [
            'indexed' => isset($_POST['texter_interval_indexed'])
                ? max(1, intval($_POST['texter_interval_indexed']))
                : Texter_API_Google_Indexation::DEFAULT_INDEXED_INTERVAL,
            'not_indexed' => isset($_POST['texter_interval_not_indexed'])
                ? max(1, intval($_POST['texter_interval_not_indexed']))
                : Texter_API_Google_Indexation::DEFAULT_NOT_INDEXED_INTERVAL,
        ];

        Texter_API_Google_Indexation::save_settings($enabled, $api_key, $intervals);

        // Handle cron settings
        $cron_frequency = isset($_POST['texter_cron_frequency'])
            ? sanitize_text_field($_POST['texter_cron_frequency'])
            : Texter_API_Google_Indexation::DEFAULT_CRON_FREQUENCY;
        $batch_size = isset($_POST['texter_batch_size'])
            ? intval($_POST['texter_batch_size'])
            : Texter_API_Google_Indexation::DEFAULT_BATCH_SIZE;

        Texter_API_Google_Indexation::save_cron_settings($cron_frequency, $batch_size);

        // Manage cron scheduling based on enabled state
        if ($enabled && !$was_enabled) {
            // Just enabled - schedule cron
            Texter_API_Google_Indexation::schedule_cron();
        } elseif (!$enabled && $was_enabled) {
            // Just disabled - unschedule cron
            Texter_API_Google_Indexation::unschedule_cron();
        }

        return true;
    }
}
