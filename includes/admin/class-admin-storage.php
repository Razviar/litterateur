<?php

/**
 * Storage (S3/R2) admin page for Litterateur API
 */

if (!defined('ABSPATH')) {
    exit;
}

class Litterateur_Admin_Storage
{
    /**
     * Process form submissions
     */
    public static function process_form()
    {
        if (isset($_POST['texter_save_s3_settings']) && check_admin_referer('texter_api_settings')) {
            $s3_enabled = isset($_POST['texter_s3_enabled']) ? 'yes' : 'no';
            update_option('texter_s3_enabled', $s3_enabled);
            update_option('texter_s3_endpoint', sanitize_text_field($_POST['texter_s3_endpoint'] ?? ''));
            update_option('texter_s3_bucket', sanitize_text_field($_POST['texter_s3_bucket'] ?? ''));
            update_option('texter_s3_access_key', sanitize_text_field($_POST['texter_s3_access_key'] ?? ''));
            update_option('texter_s3_secret_key', sanitize_text_field($_POST['texter_s3_secret_key'] ?? ''));
            update_option('texter_s3_region', sanitize_text_field($_POST['texter_s3_region'] ?? 'auto'));
            update_option('texter_s3_public_url', esc_url_raw($_POST['texter_s3_public_url'] ?? ''));
            update_option('texter_s3_path_prefix', sanitize_text_field($_POST['texter_s3_path_prefix'] ?? ''));
            update_option('texter_s3_preferred_storage', sanitize_text_field($_POST['texter_s3_preferred_storage'] ?? 'gallery'));
            return true;
        }
        return null;
    }

    /**
     * Render the storage settings page
     */
    public static function render()
    {
        $result = self::process_form();
        if ($result === true) {
            echo '<div class="notice notice-success"><p>S3 storage settings have been saved.</p></div>';
        }

?>
        <div class="wrap litterateur-api-settings">
            <?php Litterateur_Admin_Header::render('Storage'); ?>

            <div class="litterateur-api-cards-grid">
                <?php self::render_settings_section(); ?>
            </div>
        </div>
    <?php
    }

    /**
     * Render S3/R2 storage settings section
     */
    public static function render_settings_section()
    {
        $s3_enabled = get_option('texter_s3_enabled', 'no') === 'yes';
        $s3_endpoint = get_option('texter_s3_endpoint', '');
        $s3_bucket = get_option('texter_s3_bucket', '');
        $s3_access_key = get_option('texter_s3_access_key', '');
        $s3_secret_key = get_option('texter_s3_secret_key', '');
        $s3_region = get_option('texter_s3_region', 'auto');
        $s3_public_url = get_option('texter_s3_public_url', '');
        $s3_path_prefix = get_option('texter_s3_path_prefix', '');
        $s3_preferred = get_option('texter_s3_preferred_storage', 'gallery');
        $s3_image_count = Texter_S3_Storage::get_instance()->get_image_count();
    ?>
        <div class="litterateur-api-card">
            <h2>S3 / Cloudflare R2 Storage</h2>
            <p>Configure external S3-compatible storage (like Cloudflare R2) for images. When enabled, images can be stored externally and served from a CDN.</p>

            <form method="post">
                <?php wp_nonce_field('texter_api_settings'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">Enable S3 Storage</th>
                        <td>
                            <label>
                                <input type="checkbox" name="texter_s3_enabled" value="1" <?php checked($s3_enabled); ?> />
                                Enable S3-compatible external storage
                            </label>
                            <p class="description">When enabled, images from S3 will appear in the gallery alongside WordPress media library images.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">S3 Endpoint URL <span class="required">*</span></th>
                        <td>
                            <input type="url" name="texter_s3_endpoint" value="<?php echo esc_attr($s3_endpoint); ?>" class="regular-text" placeholder="https://account-id.r2.cloudflarestorage.com" />
                            <p class="description">For Cloudflare R2: <code>https://&lt;account-id&gt;.r2.cloudflarestorage.com</code></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Bucket Name <span class="required">*</span></th>
                        <td>
                            <input type="text" name="texter_s3_bucket" value="<?php echo esc_attr($s3_bucket); ?>" class="regular-text" placeholder="my-bucket" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Access Key ID <span class="required">*</span></th>
                        <td>
                            <input type="text" name="texter_s3_access_key" value="<?php echo esc_attr($s3_access_key); ?>" class="regular-text" autocomplete="off" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Secret Access Key <span class="required">*</span></th>
                        <td>
                            <input type="password" name="texter_s3_secret_key" value="<?php echo esc_attr($s3_secret_key); ?>" class="regular-text" autocomplete="off" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Region</th>
                        <td>
                            <input type="text" name="texter_s3_region" value="<?php echo esc_attr($s3_region); ?>" class="regular-text" placeholder="auto" />
                            <p class="description">For Cloudflare R2, use <code>auto</code>. For AWS S3, use the region code (e.g., <code>us-east-1</code>).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Public URL Prefix <span class="required">*</span></th>
                        <td>
                            <input type="url" name="texter_s3_public_url" value="<?php echo esc_attr($s3_public_url); ?>" class="regular-text" placeholder="https://images.example.com" />
                            <p class="description">The public URL where images will be accessible. For R2, this is your custom domain or R2 public URL.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Path Prefix</th>
                        <td>
                            <input type="text" name="texter_s3_path_prefix" value="<?php echo esc_attr($s3_path_prefix); ?>" class="regular-text" placeholder="texter/images" />
                            <p class="description">Optional folder path within the bucket. Leave empty to use the bucket root.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Preferred Storage for New Uploads</th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="radio" name="texter_s3_preferred_storage" value="gallery" <?php checked($s3_preferred, 'gallery'); ?> />
                                    WordPress Media Library (Gallery)
                                </label>
                                <br />
                                <label>
                                    <input type="radio" name="texter_s3_preferred_storage" value="s3" <?php checked($s3_preferred, 's3'); ?> />
                                    S3 / Cloudflare R2
                                </label>
                            </fieldset>
                            <p class="description">Where new images uploaded via Litterateur API should be stored.</p>
                        </td>
                    </tr>
                </table>

                <p>
                    <input type="submit" name="texter_save_s3_settings" class="button button-primary" value="Save S3 Settings" />
                    <button type="button" id="texter-s3-test" class="button button-secondary" <?php echo !$s3_enabled ? 'disabled' : ''; ?>>Test Connection</button>
                    <button type="button" id="texter-s3-sync" class="button button-secondary" <?php echo !$s3_enabled ? 'disabled' : ''; ?>>Sync Images from R2</button>
                    <span id="texter-s3-status"></span>
                </p>

                <?php if ($s3_enabled && $s3_image_count > 0) : ?>
                    <p class="description">
                        <strong>S3 Images in database:</strong> <?php echo esc_html($s3_image_count); ?> images synced
                    </p>
                <?php endif; ?>
            </form>
        </div>

        <script>
            jQuery(document).ready(function($) {
                var nonce = '<?php echo wp_create_nonce('texter_s3_nonce'); ?>';

                $('#texter-s3-test').on('click', function() {
                    var $btn = $(this);
                    var $status = $('#texter-s3-status');

                    $btn.prop('disabled', true);
                    $status.html('<span style="color:#666;">Testing connection...</span>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'texter_s3_test_connection',
                            nonce: nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                $status.html('<span style="color:green;">✓ ' + response.data + '</span>');
                            } else {
                                $status.html('<span style="color:red;">✗ ' + response.data + '</span>');
                            }
                        },
                        error: function() {
                            $status.html('<span style="color:red;">✗ Request failed</span>');
                        },
                        complete: function() {
                            $btn.prop('disabled', false);
                        }
                    });
                });

                $('#texter-s3-sync').on('click', function() {
                    var $btn = $(this);
                    var $status = $('#texter-s3-status');

                    $btn.prop('disabled', true);
                    $status.html('<span style="color:#666;">Syncing images from R2... This may take a while.</span>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'texter_s3_sync',
                            nonce: nonce
                        },
                        timeout: 300000, // 5 minute timeout
                        success: function(response) {
                            if (response.success) {
                                $status.html('<span style="color:green;">✓ ' + response.data.message + '</span>');
                            } else {
                                var msg = response.data.message || response.data;
                                if (response.data.added || response.data.updated) {
                                    msg += ' (Added: ' + response.data.added + ', Updated: ' + response.data.updated + ')';
                                }
                                $status.html('<span style="color:orange;">⚠ ' + msg + '</span>');
                            }
                        },
                        error: function() {
                            $status.html('<span style="color:red;">✗ Request failed or timed out</span>');
                        },
                        complete: function() {
                            $btn.prop('disabled', false);
                        }
                    });
                });
            });
        </script>
<?php
    }
}
