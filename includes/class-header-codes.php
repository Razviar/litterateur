<?php

/**
 * Header Codes - Insert custom HTML into the <head> tag
 */

if (!defined('ABSPATH')) {
    exit;
}

class Texter_API_Header_Codes
{
    const OPTION_KEY = 'texter_header_codes';

    /**
     * Initialize hooks
     */
    public static function init()
    {
        // Output header codes on frontend
        add_action('wp_head', [__CLASS__, 'output_header_codes'], 1);
    }

    /**
     * Get saved header codes
     *
     * @return string
     */
    public static function get_codes()
    {
        return get_option(self::OPTION_KEY, '');
    }

    /**
     * Save header codes
     *
     * @param string $codes
     * @return bool
     */
    public static function save_codes($codes)
    {
        return update_option(self::OPTION_KEY, $codes);
    }

    /**
     * Output header codes in <head> tag
     */
    public static function output_header_codes()
    {
        $codes = self::get_codes();
        if (!empty($codes)) {
            echo "\n<!-- Texter Header Codes -->\n";
            echo $codes;
            echo "\n<!-- /Texter Header Codes -->\n";
        }
    }

    /**
     * Render the header codes settings section
     */
    public static function render_settings_section()
    {
        $codes = self::get_codes();
?>
        <div class="litterateur-api-card texter-header-codes-settings">
            <h2>Header Codes</h2>
            <p>Add custom HTML code to be inserted in the <code>&lt;head&gt;</code> tag of every page. Useful for verification tags, analytics scripts, etc.</p>

            <form method="post" id="texter-header-codes-form">
                <?php wp_nonce_field('texter_header_codes_settings'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">Custom Header Code</th>
                        <td>
                            <textarea name="texter_header_codes" class="litterateur-api-key-textarea" placeholder="Paste your HTML code here (e.g., Google Search Console verification, analytics scripts, etc.)"><?php echo esc_textarea($codes); ?></textarea>
                            <p class="description">This code will be inserted into the <code>&lt;head&gt;</code> section of every page on your site.</p>
                        </td>
                    </tr>
                </table>

                <p>
                    <input type="submit" name="texter_save_header_codes" class="button button-primary" value="Save Header Codes" />
                </p>
            </form>
        </div>
<?php
    }

    /**
     * Process header codes form submission
     *
     * @return bool|null True if saved, null if no submission
     */
    public static function process_settings_form()
    {
        if (!isset($_POST['texter_save_header_codes'])) {
            return null;
        }

        if (!check_admin_referer('texter_header_codes_settings')) {
            return false;
        }

        $codes = isset($_POST['texter_header_codes']) 
            ? wp_unslash($_POST['texter_header_codes']) 
            : '';

        self::save_codes($codes);

        return true;
    }
}
