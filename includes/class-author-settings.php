<?php

/**
 * Author Settings - Configure how post authors are selected
 *
 * Controls whether to auto-select a random author or set author ID to zero
 * when publishing posts via the API.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Texter_Author_Settings
{
    const OPTION_AUTHOR_MODE = 'texter_author_mode';

    /**
     * Get current author mode
     *
     * @return string 'auto' or 'zero'
     */
    public static function get_author_mode()
    {
        return get_option(self::OPTION_AUTHOR_MODE, 'auto');
    }

    /**
     * Check if auto-select mode is enabled
     *
     * @return bool
     */
    public static function is_auto_select()
    {
        return self::get_author_mode() === 'auto';
    }

    /**
     * Save author mode setting
     *
     * @param string $mode 'auto' or 'zero'
     * @return bool
     */
    public static function save_settings($mode)
    {
        $valid_modes = array('auto', 'zero');
        if (!in_array($mode, $valid_modes, true)) {
            $mode = 'auto';
        }
        return update_option(self::OPTION_AUTHOR_MODE, $mode) !== false;
    }

    /**
     * Render the admin settings section
     */
    public static function render_settings_section()
    {
        $current_mode = self::get_author_mode();
?>
        <div class="litterateur-api-card texter-author-settings">
            <h2>Author Selection</h2>
            <p>Configure how the post author is selected when publishing content via the API. This setting only applies when a specific author is not provided in the API request.</p>

            <form method="post" id="texter-author-settings-form">
                <?php wp_nonce_field('texter_author_settings'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">Author Mode</th>
                        <td>
                            <fieldset>
                                <label style="display: block; margin-bottom: 12px;">
                                    <input type="radio" name="texter_author_mode" value="auto" <?php checked($current_mode, 'auto'); ?> />
                                    <strong>Auto-select author</strong>
                                    <p class="description" style="margin-left: 24px; margin-top: 4px;">
                                        Automatically select a random author or editor from your site. This helps distribute content across multiple authors and avoids assigning posts to administrators.
                                    </p>
                                </label>
                                <label style="display: block;">
                                    <input type="radio" name="texter_author_mode" value="zero" <?php checked($current_mode, 'zero'); ?> />
                                    <strong>Set author ID to zero</strong>
                                    <p class="description" style="margin-left: 24px; margin-top: 4px;">
                                        Do not assign an author to new posts. The author ID will be set to 0 (no author). Some themes may display "Anonymous" or hide the author field for these posts.
                                    </p>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                </table>

                <p>
                    <input type="submit" name="texter_save_author_settings" class="button button-primary" value="Save Settings" />
                </p>
            </form>
        </div>
<?php
    }

    /**
     * Process settings form submission
     *
     * @return bool|null True if saved, false if error, null if no submission
     */
    public static function process_settings_form()
    {
        if (!isset($_POST['texter_save_author_settings'])) {
            return null;
        }

        if (!check_admin_referer('texter_author_settings')) {
            return false;
        }

        $mode = isset($_POST['texter_author_mode']) ? sanitize_key($_POST['texter_author_mode']) : 'auto';

        return self::save_settings($mode);
    }
}
