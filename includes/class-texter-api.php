<?php

/**
 * Main plugin class for Texter API
 */

if (!defined('ABSPATH')) {
    exit;
}

class Texter_API
{
    /**
     * Endpoint instances
     */
    private $endpoints = array();

    /**
     * Cached menu slug
     */
    private $menu_slug;

    /**
     * Get the REST API namespace
     *
     * @return string The API namespace
     */
    public static function get_api_namespace() {
        return texter_brand('api_namespace', 'litterateur') . '/v1';
    }

    /**
     * For backwards compatibility - use get_api_namespace() instead
     */
    const API_NAMESPACE = 'litterateur/v1';

    /**
     * Initialize the plugin
     */
    public function init()
    {
        // Register REST API routes
        add_action('rest_api_init', array($this, 'register_routes'));

        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Enqueue admin styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));

        // Initialize endpoints
        $this->init_endpoints();

        // Initialize indexation admin features
        Texter_API_Indexation_Admin::init();
    }

    /**
     * Initialize endpoint classes
     */
    private function init_endpoints()
    {
        $this->endpoints = array(
            new Texter_API_Endpoint_Health(),
            new Texter_API_Endpoint_Websites(),
            new Texter_API_Endpoint_Keys(),
            new Texter_API_Endpoint_Categories(),
            new Texter_API_Endpoint_Tags(),
            new Texter_API_Endpoint_Topics(),
            new Texter_API_Endpoint_Authors(),
            new Texter_API_Endpoint_Structured(),
            new Texter_API_Endpoint_Data_Tables(),
            new Texter_API_Endpoint_Gallery(),
        );
    }

    /**
     * Register REST API routes
     */
    public function register_routes()
    {
        $namespace = self::get_api_namespace();
        foreach ($this->endpoints as $endpoint) {
            $endpoint->register_routes($namespace);
        }
    }

    /**
     * Get the menu slug prefix
     *
     * @return string The menu slug
     */
    private function get_menu_slug() {
        if (!$this->menu_slug) {
            $this->menu_slug = texter_brand('menu_slug', 'litterateur');
        }
        return $this->menu_slug;
    }

    /**
     * Add admin menu - top-level menu with subpages
     */
    public function add_admin_menu()
    {
        $brand_name = texter_brand('name', 'Litterateur');
        $menu_slug = $this->get_menu_slug();
        $main_slug = $menu_slug . '-api';

        // Main menu page
        add_menu_page(
            $brand_name,
            $brand_name,
            'manage_options',
            $main_slug,
            array($this, 'render_dashboard_page'),
            'dashicons-edit-page',
            30
        );

        // Dashboard submenu (same as main)
        add_submenu_page(
            $main_slug,
            'Dashboard',
            'Dashboard',
            'manage_options',
            $main_slug,
            array($this, 'render_dashboard_page')
        );

        // Storage submenu
        add_submenu_page(
            $main_slug,
            'Storage Settings',
            'Storage',
            'manage_options',
            $menu_slug . '-storage',
            array($this, 'render_storage_page')
        );

        // Gallery submenu
        add_submenu_page(
            $main_slug,
            'Gallery Settings',
            'Gallery',
            'manage_options',
            $menu_slug . '-gallery',
            array($this, 'render_gallery_page')
        );

        // Indexation submenu
        add_submenu_page(
            $main_slug,
            'Google Indexation',
            'Indexation',
            'manage_options',
            $menu_slug . '-indexation',
            array($this, 'render_indexation_page')
        );

        // Site Closure submenu
        add_submenu_page(
            $main_slug,
            'Close Website',
            'Close Website',
            'manage_options',
            $menu_slug . '-closure',
            array($this, 'render_closure_page')
        );
    }

    /**
     * Enqueue admin styles
     */
    public function enqueue_admin_styles($hook)
    {
        $menu_slug = $this->get_menu_slug();

        // Load on all plugin admin pages
        $plugin_pages = array(
            'toplevel_page_' . $menu_slug . '-api',
            $menu_slug . '_page_' . $menu_slug . '-storage',
            $menu_slug . '_page_' . $menu_slug . '-gallery',
            $menu_slug . '_page_' . $menu_slug . '-indexation',
            $menu_slug . '_page_' . $menu_slug . '-closure',
        );

        if (!in_array($hook, $plugin_pages)) {
            return;
        }

        wp_enqueue_style(
            $menu_slug . '-api-admin',
            TEXTER_API_PLUGIN_URL . 'assets/admin.css',
            array(),
            filemtime(TEXTER_API_PLUGIN_DIR . 'assets/admin.css')
        );
    }

    /**
     * Render Dashboard page (main page with API info)
     */
    public function render_dashboard_page()
    {
        Litterateur_Admin_Dashboard::render();
    }

    /**
     * Render Storage settings page (S3/R2)
     */
    public function render_storage_page()
    {
        Litterateur_Admin_Storage::render();
    }

    /**
     * Render Gallery settings page
     */
    public function render_gallery_page()
    {
        Litterateur_Admin_Gallery::render();
    }

    /**
     * Render Indexation settings page
     */
    public function render_indexation_page()
    {
        // Handle indexation settings
        $indexation_saved = Texter_API_Indexation_Admin::process_settings_form();
        if ($indexation_saved === true) {
            echo '<div class="notice notice-success"><p>Indexation settings have been saved.</p></div>';
        } elseif ($indexation_saved === false) {
            echo '<div class="notice notice-error"><p>Failed to save indexation settings.</p></div>';
        }

        // Handle header codes settings
        $header_codes_saved = Texter_API_Header_Codes::process_settings_form();
        if ($header_codes_saved === true) {
            echo '<div class="notice notice-success"><p>Header codes have been saved.</p></div>';
        } elseif ($header_codes_saved === false) {
            echo '<div class="notice notice-error"><p>Failed to save header codes.</p></div>';
        }

?>
        <div class="wrap litterateur-api-settings">
            <?php Litterateur_Admin_Header::render('Indexation'); ?>

            <div class="litterateur-api-cards-grid">
                <?php Texter_API_Header_Codes::render_settings_section(); ?>
                <?php Texter_API_Indexation_Admin::render_settings_section(); ?>
            </div>
        </div>
<?php
    }

    /**
     * Render Site Closure settings page
     */
    public function render_closure_page()
    {
        // Handle form submission
        $saved = Texter_Site_Closure::process_settings_form();
        if ($saved === true) {
            $is_enabled = Texter_Site_Closure::is_enabled();
            if ($is_enabled) {
                echo '<div class="notice notice-warning"><p><strong>Website is now CLOSED.</strong> Visitors will see the closure message. You can still access the site because you are logged in as an administrator.</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>Website is now open to all visitors.</p></div>';
            }
        } elseif ($saved === false) {
            echo '<div class="notice notice-error"><p>Failed to save settings.</p></div>';
        }

?>
        <div class="wrap litterateur-api-settings">
            <?php Litterateur_Admin_Header::render('Close Website'); ?>

            <div class="litterateur-api-cards-grid">
                <?php Texter_Site_Closure::render_settings_section(); ?>
            </div>
        </div>
<?php
    }
}
