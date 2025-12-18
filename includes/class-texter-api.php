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
     * REST API namespace
     */
    const API_NAMESPACE = 'litterateur/v1';

    /**
     * Endpoint instances
     */
    private $endpoints = array();

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
        foreach ($this->endpoints as $endpoint) {
            $endpoint->register_routes(self::API_NAMESPACE);
        }
    }

    /**
     * Add admin menu - top-level Litterateur menu with subpages
     */
    public function add_admin_menu()
    {
        // Main menu page
        add_menu_page(
            'Litterateur',
            'Litterateur',
            'manage_options',
            'litterateur-api',
            array($this, 'render_dashboard_page'),
            'dashicons-edit-page',
            30
        );

        // Dashboard submenu (same as main)
        add_submenu_page(
            'litterateur-api',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'litterateur-api',
            array($this, 'render_dashboard_page')
        );

        // Storage submenu
        add_submenu_page(
            'litterateur-api',
            'Storage Settings',
            'Storage',
            'manage_options',
            'litterateur-storage',
            array($this, 'render_storage_page')
        );

        // Gallery submenu
        add_submenu_page(
            'litterateur-api',
            'Gallery Settings',
            'Gallery',
            'manage_options',
            'litterateur-gallery',
            array($this, 'render_gallery_page')
        );

        // Indexation submenu
        add_submenu_page(
            'litterateur-api',
            'Google Indexation',
            'Indexation',
            'manage_options',
            'litterateur-indexation',
            array($this, 'render_indexation_page')
        );
    }

    /**
     * Enqueue admin styles
     */
    public function enqueue_admin_styles($hook)
    {
        // Load on all Litterateur admin pages
        $litterateur_pages = array(
            'toplevel_page_litterateur-api',
            'litterateur_page_litterateur-storage',
            'litterateur_page_litterateur-gallery',
            'litterateur_page_litterateur-indexation',
        );

        if (!in_array($hook, $litterateur_pages)) {
            return;
        }

        wp_enqueue_style(
            'litterateur-api-admin',
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

?>
        <div class="wrap litterateur-api-settings">
            <?php Litterateur_Admin_Header::render('Indexation'); ?>

            <div class="litterateur-api-cards-grid">
                <?php Texter_API_Indexation_Admin::render_settings_section(); ?>
            </div>
        </div>
<?php
    }
}
