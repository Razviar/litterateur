<?php

/**
 * Gallery settings admin page for Litterateur API
 */

if (!defined('ABSPATH')) {
    exit;
}

class Litterateur_Admin_Gallery
{
    /**
     * Process form submissions
     */
    public static function process_form()
    {
        if (isset($_POST['texter_save_gallery_settings']) && check_admin_referer('texter_api_settings')) {
            $min_size_enabled = isset($_POST['texter_gallery_min_size_enabled']);
            $min_image_size = isset($_POST['texter_gallery_min_image_size'])
                ? max(1, intval($_POST['texter_gallery_min_image_size']))
                : Texter_API_Endpoint_Gallery::DEFAULT_MIN_IMAGE_SIZE;

            update_option('texter_gallery_min_size_enabled', $min_size_enabled);
            update_option('texter_gallery_min_image_size', $min_image_size);
            return true;
        }
        return null;
    }

    /**
     * Render the gallery settings page
     */
    public static function render()
    {
        $result = self::process_form();
        if ($result === true) {
            echo '<div class="notice notice-success"><p>Gallery settings have been saved.</p></div>';
        }

?>
        <div class="wrap litterateur-api-settings">
            <?php Litterateur_Admin_Header::render('Gallery'); ?>

            <div class="litterateur-api-cards-grid">
                <?php self::render_settings_section(); ?>
            </div>
        </div>
    <?php
    }

    /**
     * Render gallery settings section
     */
    public static function render_settings_section()
    {
        $min_size_enabled = Texter_API_Endpoint_Gallery::is_min_size_filter_enabled();
        $min_image_size = Texter_API_Endpoint_Gallery::get_min_image_size();
    ?>
        <div class="litterateur-api-card">
            <h2>Gallery Settings</h2>
            <p>Configure how images are synced from the media library to Litterateur.</p>

            <form method="post">
                <?php wp_nonce_field('texter_api_settings'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">Filter Small Images</th>
                        <td>
                            <label>
                                <input type="checkbox" name="texter_gallery_min_size_enabled" value="1" <?php checked($min_size_enabled); ?> />
                                Enable minimum image size filter
                            </label>
                            <p class="description">When enabled, images smaller than the minimum size will not be sent to Litterateur.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Minimum Image Size</th>
                        <td>
                            <input type="number" name="texter_gallery_min_image_size" value="<?php echo esc_attr($min_image_size); ?>" min="1" max="2000" class="small-text" /> pixels
                            <p class="description">Both width and height must be at least this size. Images smaller than this will be ignored.</p>
                        </td>
                    </tr>
                </table>

                <p>
                    <input type="submit" name="texter_save_gallery_settings" class="button button-primary" value="Save Gallery Settings" />
                </p>
            </form>
        </div>
<?php
    }
}
