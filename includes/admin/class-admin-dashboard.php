<?php

/**
 * Dashboard admin page for Litterateur API
 */

if (!defined('ABSPATH')) {
    exit;
}

class Litterateur_Admin_Dashboard
{
    /**
     * Process form submissions
     */
    public static function process_form()
    {
        if (isset($_POST['texter_regenerate_key']) && check_admin_referer('texter_api_settings')) {
            Texter_API_Auth::rotate_api_key();
            return true;
        }
        return null;
    }

    /**
     * Render the dashboard page
     */
    public static function render()
    {
        $result = self::process_form();
        if ($result === true) {
            echo '<div class="notice notice-success"><p>API key has been regenerated.</p></div>';
        }

        $api_key = Texter_API_Auth::get_api_key();
        $api_url = rest_url(Texter_API::API_NAMESPACE);

        // Generate control panel URL based on site domain
        $site_url = get_site_url();
        $parsed = parse_url($site_url);
        $domain = $parsed['host'] ?? '';
        $panel_url = 'https://litterateur.pro/panel/websites/' . str_replace('.', '-', $domain);

?>
        <div class="wrap litterateur-api-settings">
            <?php Litterateur_Admin_Header::render('Dashboard'); ?>

            <div class="litterateur-api-cards-grid">

                <div class="litterateur-api-card litterateur-card-primary">
                    <h2>API Connection</h2>

                    <table class="form-table">
                        <tr>
                            <th scope="row">API URL</th>
                            <td>
                                <input type="text" id="litterateur-api-url" class="regular-text code" value="<?php echo esc_url($api_url); ?>" readonly />
                                <button type="button" class="button litterateur-btn" onclick="copyApiUrl()">Copy</button>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">API Key</th>
                            <td>
                                <input type="text" id="litterateur-api-key" class="regular-text code" value="<?php echo esc_attr($api_key); ?>" readonly />
                                <button type="button" class="button litterateur-btn" onclick="copyApiKey()">Copy</button>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Plugin Version</th>
                            <td><span class="litterateur-version"><?php echo esc_html(TEXTER_API_VERSION); ?></span></td>
                        </tr>
                        <tr>
                            <th scope="row">Control Panel</th>
                            <td>
                                <a href="<?php echo esc_url($panel_url); ?>" target="_blank" class="litterateur-link"><?php echo esc_html($panel_url); ?></a>
                            </td>
                        </tr>
                    </table>

                    <form method="post" class="litterateur-regenerate-form">
                        <?php wp_nonce_field('texter_api_settings'); ?>
                        <input type="submit" name="texter_regenerate_key" class="button litterateur-btn-danger" value="Regenerate API Key" onclick="return confirm('Are you sure? The old key will stop working immediately.');" />
                    </form>
                </div>

                <div class="litterateur-api-card full-width">
                    <h2>Available Endpoints</h2>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th>Method</th>
                                <th>Endpoint</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>GET</code></td>
                                <td><code>/health</code></td>
                                <td>Check API availability</td>
                            </tr>
                            <tr>
                                <td><code>GET</code></td>
                                <td><code>/websites</code></td>
                                <td>List websites (multisite support)</td>
                            </tr>
                            <tr>
                                <td><code>POST</code></td>
                                <td><code>/keys/rotate</code></td>
                                <td>Rotate API key</td>
                            </tr>
                            <tr>
                                <td><code>GET</code></td>
                                <td><code>/categories</code></td>
                                <td>Get blog categories</td>
                            </tr>
                            <tr>
                                <td><code>POST</code></td>
                                <td><code>/categories</code></td>
                                <td>Create/update categories</td>
                            </tr>
                            <tr>
                                <td><code>POST</code></td>
                                <td><code>/topics</code></td>
                                <td>Publish new post</td>
                            </tr>
                            <tr>
                                <td><code>POST</code></td>
                                <td><code>/authors</code></td>
                                <td>Create/update author</td>
                            </tr>
                            <tr>
                                <td><code>POST</code></td>
                                <td><code>/structured</code></td>
                                <td>Publish structured data</td>
                            </tr>
                            <tr>
                                <td><code>POST</code></td>
                                <td><code>/data-tables</code></td>
                                <td>Sync data to custom database table</td>
                            </tr>
                            <tr>
                                <td><code>GET</code></td>
                                <td><code>/data-tables</code></td>
                                <td>List custom data tables</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            </div><!-- .litterateur-api-cards-grid -->
        </div><!-- .wrap -->

        <script>
            function copyApiUrl() {
                var input = document.getElementById('litterateur-api-url');
                input.select();
                document.execCommand('copy');
                alert('API URL copied to clipboard');
            }

            function copyApiKey() {
                var input = document.getElementById('litterateur-api-key');
                input.select();
                document.execCommand('copy');
                alert('API key copied to clipboard');
            }
        </script>
<?php
    }
}
