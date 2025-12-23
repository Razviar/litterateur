<?php

/**
 * Plugin dependencies checker for Litterateur API
 */

if (!defined('ABSPATH')) {
    exit;
}

class Litterateur_Admin_Plugins
{
    /**
     * List of required/recommended plugins
     *
     * @return array
     */
    public static function get_required_plugins()
    {
        return array(
            array(
                'name' => 'Basic User Avatars',
                'slug' => 'basic-user-avatars',
                'file' => 'basic-user-avatars/init.php',
                'description' => 'Required for author avatar support when publishing articles.',
                'required' => true,
                'wp_url' => 'https://wordpress.org/plugins/basic-user-avatars/',
            ),
            array(
                'name' => 'Yoast SEO',
                'slug' => 'wordpress-seo',
                'file' => 'wordpress-seo/wp-seo.php',
                'description' => 'Required for SEO meta fields (title, description) when publishing articles.',
                'required' => true,
                'wp_url' => 'https://wordpress.org/plugins/wordpress-seo/',
            ),
        );
    }

    /**
     * Check if a plugin is installed
     *
     * @param string $plugin_file Plugin file path relative to plugins directory
     * @return bool
     */
    public static function is_plugin_installed($plugin_file)
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();
        return isset($plugins[$plugin_file]);
    }

    /**
     * Check if a plugin is active
     *
     * @param string $plugin_file Plugin file path relative to plugins directory
     * @return bool
     */
    public static function is_plugin_active($plugin_file)
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active($plugin_file);
    }

    /**
     * Get plugin status
     *
     * @param array $plugin Plugin info array
     * @return array Status info with 'status' and 'label' keys
     */
    public static function get_plugin_status($plugin)
    {
        if (self::is_plugin_active($plugin['file'])) {
            return array(
                'status' => 'active',
                'label' => 'Active',
                'class' => 'litterateur-status-success',
            );
        }

        if (self::is_plugin_installed($plugin['file'])) {
            return array(
                'status' => 'inactive',
                'label' => 'Installed but not active',
                'class' => 'litterateur-status-warning',
            );
        }

        return array(
            'status' => 'missing',
            'label' => 'Not installed',
            'class' => 'litterateur-status-error',
        );
    }

    /**
     * Get install URL for a plugin
     *
     * @param string $slug Plugin slug
     * @return string
     */
    public static function get_install_url($slug)
    {
        return wp_nonce_url(
            admin_url('update.php?action=install-plugin&plugin=' . $slug),
            'install-plugin_' . $slug
        );
    }

    /**
     * Get activate URL for a plugin
     *
     * @param string $file Plugin file path
     * @return string
     */
    public static function get_activate_url($file)
    {
        return wp_nonce_url(
            admin_url('plugins.php?action=activate&plugin=' . urlencode($file)),
            'activate-plugin_' . $file
        );
    }

    /**
     * Check if all required plugins are active
     *
     * @return bool
     */
    public static function all_required_active()
    {
        $plugins = self::get_required_plugins();

        foreach ($plugins as $plugin) {
            if ($plugin['required'] && !self::is_plugin_active($plugin['file'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Render the required plugins section
     */
    public static function render()
    {
        $plugins = self::get_required_plugins();
        $all_active = self::all_required_active();
?>
        <div class="litterateur-api-card <?php echo $all_active ? '' : 'litterateur-card-warning'; ?>">
            <h2>
                Required Plugins
                <?php if ($all_active): ?>
                    <span class="litterateur-badge litterateur-badge-success">All Active</span>
                <?php else: ?>
                    <span class="litterateur-badge litterateur-badge-warning">Action Required</span>
                <?php endif; ?>
            </h2>

            <p class="litterateur-card-description">
                These plugins are required for full functionality of Litterateur API.
            </p>

            <table class="widefat litterateur-plugins-table">
                <thead>
                    <tr>
                        <th>Plugin</th>
                        <th>Description</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($plugins as $plugin):
                        $status = self::get_plugin_status($plugin);
                    ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($plugin['name']); ?></strong>
                                <?php if ($plugin['required']): ?>
                                    <span class="litterateur-required">*</span>
                                <?php endif; ?>
                            </td>
                            <td class="litterateur-plugin-desc">
                                <?php echo esc_html($plugin['description']); ?>
                            </td>
                            <td>
                                <span class="litterateur-status <?php echo esc_attr($status['class']); ?>">
                                    <?php echo esc_html($status['label']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($status['status'] === 'active'): ?>
                                    <span class="litterateur-check-icon">&#10003;</span>
                                <?php elseif ($status['status'] === 'inactive'): ?>
                                    <a href="<?php echo esc_url(self::get_activate_url($plugin['file'])); ?>" class="button litterateur-btn-small">
                                        Activate
                                    </a>
                                <?php else: ?>
                                    <a href="<?php echo esc_url(self::get_install_url($plugin['slug'])); ?>" class="button litterateur-btn-small">
                                        Install
                                    </a>
                                    <a href="<?php echo esc_url($plugin['wp_url']); ?>" target="_blank" class="litterateur-link-small">
                                        View on WP.org
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p class="litterateur-footnote">
                <span class="litterateur-required">*</span> Required for full functionality
            </p>
        </div>
<?php
    }
}
