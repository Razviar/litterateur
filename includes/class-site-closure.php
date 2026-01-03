<?php

/**
 * Site Closure - Block visitors from accessing the website
 *
 * When enabled, visitors see a maintenance message while
 * logged-in admins, authors, and editors can still access the site.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Texter_Site_Closure
{
    const OPTION_ENABLED = 'texter_site_closure_enabled';
    const OPTION_MESSAGE_TYPE = 'texter_site_closure_message_type';
    const OPTION_CUSTOM_MESSAGE = 'texter_site_closure_custom_message';

    /**
     * Predefined message options
     */
    public static function get_predefined_messages()
    {
        return array(
            'maintenance' => array(
                'title' => 'Under Maintenance',
                'message' => 'We are currently performing scheduled maintenance. We will be back online shortly. Thank you for your patience.',
            ),
            'coming_soon' => array(
                'title' => 'Coming Soon',
                'message' => 'We are working on something exciting! Our website will be launching soon. Stay tuned!',
            ),
            'temporarily_closed' => array(
                'title' => 'Temporarily Closed',
                'message' => 'Our website is temporarily unavailable. Please check back later.',
            ),
            'renovating' => array(
                'title' => 'Site Renovation',
                'message' => 'We are renovating our website to serve you better. Please visit us again soon!',
            ),
            'technical_issues' => array(
                'title' => 'Technical Difficulties',
                'message' => 'We are experiencing technical difficulties. Our team is working to resolve the issue. Please try again later.',
            ),
            'custom' => array(
                'title' => 'Custom Message',
                'message' => '',
            ),
        );
    }

    /**
     * Initialize - must be called very early (before template loads)
     */
    public static function init()
    {
        // Check if closure is enabled and block if needed
        // Using 'template_redirect' ensures we're after login but before output
        add_action('template_redirect', [__CLASS__, 'maybe_block_site'], 1);
    }

    /**
     * Check if site closure is enabled
     *
     * @return bool
     */
    public static function is_enabled()
    {
        return (bool) get_option(self::OPTION_ENABLED, false);
    }

    /**
     * Get current message type
     *
     * @return string
     */
    public static function get_message_type()
    {
        return get_option(self::OPTION_MESSAGE_TYPE, 'maintenance');
    }

    /**
     * Get custom message content
     *
     * @return string
     */
    public static function get_custom_message()
    {
        return get_option(self::OPTION_CUSTOM_MESSAGE, '');
    }

    /**
     * Get the current message to display
     *
     * @return array Array with 'title' and 'message' keys
     */
    public static function get_current_message()
    {
        $type = self::get_message_type();
        $messages = self::get_predefined_messages();

        if ($type === 'custom') {
            $custom = self::get_custom_message();
            return array(
                'title' => 'Site Closed',
                'message' => !empty($custom) ? $custom : 'This website is currently unavailable.',
            );
        }

        if (isset($messages[$type])) {
            return $messages[$type];
        }

        return $messages['maintenance'];
    }

    /**
     * Check if current user should be allowed through
     *
     * @return bool True if user can bypass closure
     */
    public static function user_can_bypass()
    {
        // Not logged in - cannot bypass
        if (!is_user_logged_in()) {
            return false;
        }

        // Check for admin, editor, or author capabilities
        $current_user = wp_get_current_user();

        // Administrators can always bypass
        if (current_user_can('manage_options')) {
            return true;
        }

        // Editors can bypass
        if (current_user_can('edit_others_posts')) {
            return true;
        }

        // Authors can bypass
        if (current_user_can('publish_posts')) {
            return true;
        }

        return false;
    }

    /**
     * Maybe block the site if closure is enabled
     */
    public static function maybe_block_site()
    {
        // Not enabled - do nothing
        if (!self::is_enabled()) {
            return;
        }

        // Allow login page
        if (self::is_login_page()) {
            return;
        }

        // Allow admin area
        if (is_admin()) {
            return;
        }

        // Allow AJAX requests
        if (wp_doing_ajax()) {
            return;
        }

        // Allow REST API (for plugin functionality)
        if (self::is_rest_request()) {
            return;
        }

        // Allow cron
        if (wp_doing_cron()) {
            return;
        }

        // Check if user can bypass
        if (self::user_can_bypass()) {
            return;
        }

        // Block the site
        self::display_closure_page();
        exit;
    }

    /**
     * Check if current request is to login page
     *
     * @return bool
     */
    private static function is_login_page()
    {
        $script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
        return (
            strpos($script, 'wp-login.php') !== false ||
            strpos($script, 'wp-register.php') !== false
        );
    }

    /**
     * Check if current request is a REST API request
     *
     * @return bool
     */
    private static function is_rest_request()
    {
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }

        // Check URL path
        $rest_prefix = rest_get_url_prefix();
        $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';

        return strpos($request_uri, '/' . $rest_prefix . '/') !== false;
    }

    /**
     * Display the closure page and stop execution
     */
    public static function display_closure_page()
    {
        $message_data = self::get_current_message();
        $title = esc_html($message_data['title']);
        $message = nl2br(esc_html($message_data['message']));
        $site_name = esc_html(get_bloginfo('name'));

        // Send 503 Service Unavailable status
        status_header(503);
        header('Retry-After: 3600');
        header('Content-Type: text/html; charset=utf-8');

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>{$title} - {$site_name}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #1e1e2e 0%, #2d2d44 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .closure-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 16px;
            padding: 60px 50px;
            max-width: 600px;
            width: 100%;
            text-align: center;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
        }

        .closure-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 30px;
        }

        .closure-icon svg {
            width: 40px;
            height: 40px;
            fill: white;
        }

        h1 {
            color: #1e1e2e;
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 20px;
        }

        .message {
            color: #4a4a68;
            font-size: 1.1rem;
            line-height: 1.7;
            margin-bottom: 30px;
        }

        .site-name {
            color: #6366f1;
            font-size: 0.9rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 2px;
        }

        @media (max-width: 480px) {
            .closure-container {
                padding: 40px 30px;
            }

            h1 {
                font-size: 1.5rem;
            }

            .message {
                font-size: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="closure-container">
        <div class="closure-icon">
            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
            </svg>
        </div>
        <h1>{$title}</h1>
        <p class="message">{$message}</p>
        <p class="site-name">{$site_name}</p>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Save closure settings
     *
     * @param bool $enabled
     * @param string $message_type
     * @param string $custom_message
     * @return bool
     */
    public static function save_settings($enabled, $message_type, $custom_message)
    {
        $result1 = update_option(self::OPTION_ENABLED, (bool) $enabled);
        $result2 = update_option(self::OPTION_MESSAGE_TYPE, sanitize_key($message_type));
        $result3 = update_option(self::OPTION_CUSTOM_MESSAGE, wp_kses_post($custom_message));

        return $result1 !== false || $result2 !== false || $result3 !== false;
    }

    /**
     * Render the admin settings section
     */
    public static function render_settings_section()
    {
        $enabled = self::is_enabled();
        $message_type = self::get_message_type();
        $custom_message = self::get_custom_message();
        $predefined = self::get_predefined_messages();
?>
        <div class="litterateur-api-card texter-site-closure-settings">
            <h2>Close Website</h2>
            <p>Block visitors from accessing your website. Logged-in administrators, editors, and authors can still view the site and access the admin panel.</p>

            <form method="post" id="texter-site-closure-form">
                <?php wp_nonce_field('texter_site_closure_settings'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">Site Blocking</th>
                        <td>
                            <label class="texter-toggle">
                                <input type="checkbox" name="texter_site_closure_enabled" value="1" <?php checked($enabled); ?> />
                                <span class="texter-toggle-slider"></span>
                                <span class="texter-toggle-label"><?php echo $enabled ? 'Website is CLOSED to visitors' : 'Website is open'; ?></span>
                            </label>
                            <p class="description">When enabled, visitors will see a closure message instead of your website content.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Message Template</th>
                        <td>
                            <select name="texter_site_closure_message_type" id="texter-closure-message-type" class="regular-text">
                                <?php foreach ($predefined as $key => $msg): ?>
                                    <option value="<?php echo esc_attr($key); ?>" <?php selected($message_type, $key); ?>>
                                        <?php echo esc_html($msg['title']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div id="texter-closure-preview" class="texter-closure-preview" style="margin-top: 10px;">
                                <?php
                                $current = isset($predefined[$message_type]) ? $predefined[$message_type] : $predefined['maintenance'];
                                if ($message_type !== 'custom'):
                                ?>
                                    <em><?php echo esc_html($current['message']); ?></em>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <tr id="texter-custom-message-row" style="<?php echo $message_type === 'custom' ? '' : 'display:none;'; ?>">
                        <th scope="row">Custom Message</th>
                        <td>
                            <textarea name="texter_site_closure_custom_message" class="litterateur-api-key-textarea" placeholder="Enter your custom closure message here..."><?php echo esc_textarea($custom_message); ?></textarea>
                            <p class="description">Write your own message to display when the site is closed.</p>
                        </td>
                    </tr>
                </table>

                <p>
                    <input type="submit" name="texter_save_site_closure" class="button button-primary" value="Save Settings" />
                </p>
            </form>
        </div>

        <style>
            .texter-toggle {
                display: flex;
                align-items: center;
                gap: 12px;
                cursor: pointer;
            }
            .texter-toggle input {
                display: none;
            }
            .texter-toggle-slider {
                position: relative;
                width: 50px;
                height: 26px;
                background: #ccc;
                border-radius: 26px;
                transition: background 0.3s;
            }
            .texter-toggle-slider::before {
                content: '';
                position: absolute;
                width: 20px;
                height: 20px;
                background: white;
                border-radius: 50%;
                top: 3px;
                left: 3px;
                transition: transform 0.3s;
            }
            .texter-toggle input:checked + .texter-toggle-slider {
                background: #dc3545;
            }
            .texter-toggle input:checked + .texter-toggle-slider::before {
                transform: translateX(24px);
            }
            .texter-toggle-label {
                font-weight: 500;
            }
            .texter-toggle input:checked ~ .texter-toggle-label {
                color: #dc3545;
            }
            .texter-closure-preview {
                background: #f8f9fa;
                padding: 12px 15px;
                border-radius: 6px;
                border-left: 3px solid #6366f1;
                color: #666;
            }
        </style>

        <script>
            (function() {
                var messageType = document.getElementById('texter-closure-message-type');
                var customRow = document.getElementById('texter-custom-message-row');
                var preview = document.getElementById('texter-closure-preview');

                var messages = <?php echo json_encode($predefined); ?>;

                messageType.addEventListener('change', function() {
                    var selected = this.value;

                    if (selected === 'custom') {
                        customRow.style.display = '';
                        preview.innerHTML = '<em>Your custom message will be displayed.</em>';
                    } else {
                        customRow.style.display = 'none';
                        if (messages[selected]) {
                            preview.innerHTML = '<em>' + messages[selected].message + '</em>';
                        }
                    }
                });
            })();
        </script>
<?php
    }

    /**
     * Process settings form submission
     *
     * @return bool|null True if saved, null if no submission
     */
    public static function process_settings_form()
    {
        if (!isset($_POST['texter_save_site_closure'])) {
            return null;
        }

        if (!check_admin_referer('texter_site_closure_settings')) {
            return false;
        }

        $enabled = isset($_POST['texter_site_closure_enabled']) ? true : false;
        $message_type = isset($_POST['texter_site_closure_message_type'])
            ? sanitize_key($_POST['texter_site_closure_message_type'])
            : 'maintenance';
        $custom_message = isset($_POST['texter_site_closure_custom_message'])
            ? wp_unslash($_POST['texter_site_closure_custom_message'])
            : '';

        self::save_settings($enabled, $message_type, $custom_message);

        return true;
    }
}
