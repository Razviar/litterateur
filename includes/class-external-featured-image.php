<?php

/**
 * External Featured Image functionality for Texter API
 */

if (!defined('ABSPATH')) {
    exit;
}

class Texter_External_Featured_Image
{

    /**
     * Special author ID to identify fake attachments
     */
    const FAKE_AUTHOR_ID = 77778;

    /**
     * Meta key for storing external image URL
     */
    const META_KEY_URL = 'texter_external_image_url';

    /**
     * Meta key for storing external image alt text
     */
    const META_KEY_ALT = 'texter_external_image_alt';

    /**
     * Initialize the class
     */
    public static function init()
    {
        // Admin meta box
        add_action('add_meta_boxes', array(__CLASS__, 'add_meta_box'));
        add_action('save_post', array(__CLASS__, 'save_meta_box'));

        // Filters for external images
        add_filter('get_attached_file', array(__CLASS__, 'filter_attached_file'), 10, 2);
        add_filter('wp_get_attachment_url', array(__CLASS__, 'filter_attachment_url'), 10, 2);
        add_filter('wp_get_attachment_image_src', array(__CLASS__, 'filter_attachment_image_src'), 10, 3);

        // Hide fake attachments from media library
        add_filter('posts_where', array(__CLASS__, 'filter_media_library'), 10, 2);

        // Delete fake attachment when post is deleted
        add_action('before_delete_post', array(__CLASS__, 'before_delete_post'));

        // Social meta tags / SEO support
        add_filter('post_thumbnail_html', array(__CLASS__, 'filter_thumbnail_html'), 10, 5);

        // Yoast SEO support
        if (defined('WPSEO_VERSION')) {
            add_action('wpseo_opengraph_image', array(__CLASS__, 'filter_yoast_og_image'));
            add_action('wpseo_twitter_image', array(__CLASS__, 'filter_yoast_twitter_image'));
        }

        // Rank Math SEO support
        add_filter('rank_math/opengraph/facebook/image', array(__CLASS__, 'filter_rankmath_image'));
        add_filter('rank_math/opengraph/twitter/image', array(__CLASS__, 'filter_rankmath_image'));

        // Add social meta tags if no SEO plugin is active
        add_action('wp_head', array(__CLASS__, 'add_social_meta_tags'), 5);
    }

    /**
     * Check if an attachment is a fake external attachment
     */
    public static function is_external_attachment($att_id)
    {
        if (!$att_id) {
            return false;
        }

        $att_post = get_post($att_id);
        if (!$att_post) {
            return false;
        }

        return (int) $att_post->post_author === self::FAKE_AUTHOR_ID;
    }

    /**
     * Get external image URL for a post
     */
    public static function get_external_url($post_id)
    {
        return get_post_meta($post_id, self::META_KEY_URL, true);
    }

    /**
     * Get external image alt text for a post
     */
    public static function get_external_alt($post_id)
    {
        return get_post_meta($post_id, self::META_KEY_ALT, true);
    }

    /**
     * Add meta box to post editor
     */
    public static function add_meta_box()
    {
        $post_types = get_post_types(array('public' => true), 'names');

        foreach ($post_types as $post_type) {
            if (post_type_supports($post_type, 'thumbnail')) {
                add_meta_box(
                    'texter_external_featured_image',
                    __('External Featured Image', 'litterateur-api'),
                    array(__CLASS__, 'render_meta_box'),
                    $post_type,
                    'side',
                    'low'
                );
            }
        }
    }

    /**
     * Render meta box content
     */
    public static function render_meta_box($post)
    {
        $url = self::get_external_url($post->ID);
        $alt = self::get_external_alt($post->ID);

        wp_nonce_field('texter_external_image_nonce', 'texter_external_image_nonce');
?>
        <div class="texter-external-image-box">
            <p>
                <label for="texter_external_image_url">
                    <strong><?php _e('Image URL', 'litterateur-api'); ?></strong>
                </label>
                <input type="url"
                    id="texter_external_image_url"
                    name="texter_external_image_url"
                    value="<?php echo esc_attr($url); ?>"
                    class="widefat"
                    placeholder="https://example.com/image.jpg" />
            </p>
            <p>
                <label for="texter_external_image_alt">
                    <strong><?php _e('Alt Text', 'litterateur-api'); ?></strong>
                </label>
                <input type="text"
                    id="texter_external_image_alt"
                    name="texter_external_image_alt"
                    value="<?php echo esc_attr($alt); ?>"
                    class="widefat"
                    placeholder="<?php esc_attr_e('Image description', 'litterateur-api'); ?>" />
            </p>
            <?php if ($url) : ?>
                <p>
                    <img src="<?php echo esc_url($url); ?>"
                        alt="<?php echo esc_attr($alt); ?>"
                        style="max-width:100%;height:auto;margin-top:10px;" />
                </p>
            <?php endif; ?>
            <p class="description">
                <?php _e('Enter an external URL to use as the featured image. This will override the standard featured image.', 'litterateur-api'); ?>
            </p>
        </div>
<?php
    }

    /**
     * Save meta box data
     */
    public static function save_meta_box($post_id)
    {
        // Check nonce
        if (
            !isset($_POST['texter_external_image_nonce']) ||
            !wp_verify_nonce($_POST['texter_external_image_nonce'], 'texter_external_image_nonce')
        ) {
            return;
        }

        // Check autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Get submitted URL and alt
        $url = isset($_POST['texter_external_image_url']) ? esc_url_raw(trim($_POST['texter_external_image_url'])) : '';
        $alt = isset($_POST['texter_external_image_alt']) ? sanitize_text_field($_POST['texter_external_image_alt']) : '';

        // Update or create fake attachment
        self::set_external_image($post_id, $url, $alt);
    }

    /**
     * Set external image for a post (can be called programmatically)
     */
    public static function set_external_image($post_id, $url, $alt = '')
    {
        $url = esc_url_raw(trim($url));
        $alt = sanitize_text_field($alt);

        // Get current attachment ID
        $current_att_id = get_post_thumbnail_id($post_id);
        $has_external = $current_att_id ? self::is_external_attachment($current_att_id) : false;

        if (empty($url)) {
            // Remove external image
            delete_post_meta($post_id, self::META_KEY_URL);
            delete_post_meta($post_id, self::META_KEY_ALT);

            if ($has_external) {
                wp_delete_attachment($current_att_id, true);
                delete_post_thumbnail($post_id);
            }
            return;
        }

        // Save meta values
        update_post_meta($post_id, self::META_KEY_URL, $url);
        if ($alt) {
            update_post_meta($post_id, self::META_KEY_ALT, $alt);
        } else {
            delete_post_meta($post_id, self::META_KEY_ALT);
        }

        if ($has_external) {
            // Update existing fake attachment
            update_post_meta($current_att_id, '_wp_attached_file', $url);
            if ($alt) {
                update_post_meta($current_att_id, '_wp_attachment_image_alt', $alt);
            } else {
                delete_post_meta($current_att_id, '_wp_attachment_image_alt');
            }
            // Update post title/excerpt for the attachment
            wp_update_post(array(
                'ID' => $current_att_id,
                'post_title' => $alt,
                'post_excerpt' => $alt,
                'post_content_filtered' => $url
            ));
        } else {
            // Delete any existing fake attachment without thumbnail
            self::cleanup_orphan_attachments($post_id);

            // Create new fake attachment
            $att_id = self::create_fake_attachment($post_id, $url, $alt);
            if ($att_id) {
                set_post_thumbnail($post_id, $att_id);
            }
        }
    }

    /**
     * Create a fake attachment post for external image
     */
    private static function create_fake_attachment($post_id, $url, $alt = '')
    {
        global $wpdb;

        $data = array(
            'post_author' => self::FAKE_AUTHOR_ID,
            'post_date' => current_time('mysql'),
            'post_date_gmt' => current_time('mysql', true),
            'post_modified' => current_time('mysql'),
            'post_modified_gmt' => current_time('mysql', true),
            'post_title' => $alt,
            'post_excerpt' => $alt,
            'post_content' => '',
            'post_status' => 'inherit',
            'post_type' => 'attachment',
            'post_mime_type' => 'image/jpeg',
            'post_parent' => $post_id,
            'post_content_filtered' => $url,
            'guid' => '',
            'to_ping' => '',
            'pinged' => ''
        );

        $wpdb->insert($wpdb->posts, $data);
        $att_id = $wpdb->insert_id;

        if ($att_id) {
            update_post_meta($att_id, '_wp_attached_file', $url);
            if ($alt) {
                update_post_meta($att_id, '_wp_attachment_image_alt', $alt);
            }
        }

        return $att_id;
    }

    /**
     * Cleanup orphan fake attachments for a post
     */
    private static function cleanup_orphan_attachments($post_id)
    {
        global $wpdb;

        $orphans = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_parent = %d
             AND post_author = %d
             AND post_type = 'attachment'
             AND ID NOT IN (
                 SELECT meta_value FROM {$wpdb->postmeta}
                 WHERE post_id = %d AND meta_key = '_thumbnail_id'
             )",
            $post_id,
            self::FAKE_AUTHOR_ID,
            $post_id
        ));

        foreach ($orphans as $orphan_id) {
            wp_delete_attachment($orphan_id, true);
        }
    }

    /**
     * Filter: get_attached_file
     */
    public static function filter_attached_file($file, $att_id)
    {
        if (!self::is_external_attachment($att_id)) {
            return $file;
        }

        $url = get_post_meta($att_id, '_wp_attached_file', true);
        return $url ? $url : $file;
    }

    /**
     * Filter: wp_get_attachment_url
     */
    public static function filter_attachment_url($url, $att_id)
    {
        if (!self::is_external_attachment($att_id)) {
            return $url;
        }

        $external_url = get_post_meta($att_id, '_wp_attached_file', true);
        return $external_url ? $external_url : $url;
    }

    /**
     * Filter: wp_get_attachment_image_src
     */
    public static function filter_attachment_image_src($image, $att_id, $size)
    {
        if (!$image || !self::is_external_attachment($att_id)) {
            return $image;
        }

        $url = get_post_meta($att_id, '_wp_attached_file', true);
        if ($url) {
            $image[0] = $url;
        }

        return $image;
    }

    /**
     * Filter: Hide fake attachments from media library
     */
    public static function filter_media_library($where, $query)
    {
        global $wpdb;

        // Only filter in admin media library
        if (!is_admin()) {
            return $where;
        }

        // Check if this is a media library query
        $action = isset($_POST['action']) ? $_POST['action'] : '';
        $is_media_query = in_array($action, array('query-attachments', 'get-attachment'), true);

        if ($is_media_query) {
            $where .= $wpdb->prepare(" AND {$wpdb->posts}.post_author <> %d ", self::FAKE_AUTHOR_ID);
        }

        return $where;
    }

    /**
     * Action: Delete fake attachment when post is deleted
     */
    public static function before_delete_post($post_id)
    {
        global $wpdb;

        // Find and delete any fake attachments for this post
        $attachments = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_parent = %d
             AND post_author = %d
             AND post_type = 'attachment'",
            $post_id,
            self::FAKE_AUTHOR_ID
        ));

        foreach ($attachments as $att_id) {
            wp_delete_attachment($att_id, true);
        }
    }

    /**
     * Filter: post_thumbnail_html - add alt text
     */
    public static function filter_thumbnail_html($html, $post_id, $post_thumbnail_id, $size, $attr)
    {
        if (!$html || !self::is_external_attachment($post_thumbnail_id)) {
            return $html;
        }

        $url = self::get_external_url($post_id);
        $alt = self::get_external_alt($post_id);

        if (!$alt) {
            $alt = get_the_title($post_id);
        }

        // Update alt attribute if missing
        if (strpos($html, 'alt=""') !== false) {
            $html = str_replace('alt=""', 'alt="' . esc_attr($alt) . '"', $html);
        }

        return $html;
    }

    /**
     * Filter: Yoast SEO og:image
     */
    public static function filter_yoast_og_image($image_url)
    {
        $post_id = get_the_ID();
        if (!$post_id) {
            return $image_url;
        }

        $external_url = self::get_external_url($post_id);
        return $external_url ? $external_url : $image_url;
    }

    /**
     * Filter: Yoast SEO twitter:image
     */
    public static function filter_yoast_twitter_image($image_url)
    {
        return self::filter_yoast_og_image($image_url);
    }

    /**
     * Filter: Rank Math SEO image
     */
    public static function filter_rankmath_image($image_url)
    {
        $post_id = get_the_ID();
        if (!$post_id) {
            return $image_url;
        }

        $external_url = self::get_external_url($post_id);
        return $external_url ? $external_url : $image_url;
    }

    /**
     * Action: Add social meta tags (when no SEO plugin is active)
     */
    public static function add_social_meta_tags()
    {
        // Skip if SEO plugin is active
        if (defined('WPSEO_VERSION') || class_exists('RankMath')) {
            return;
        }

        if (!is_singular()) {
            return;
        }

        $post_id = get_the_ID();
        if (!$post_id) {
            return;
        }

        $url = self::get_external_url($post_id);
        if (!$url) {
            return;
        }

        $title = esc_attr(get_the_title($post_id));
        $safe_url = esc_url($url);

        echo "\n<!-- Texter External Featured Image -->\n";
        echo '<meta property="og:image" content="' . $safe_url . '" />' . "\n";
        echo '<meta property="og:image:alt" content="' . $title . '" />' . "\n";
        echo '<meta name="twitter:image" content="' . $safe_url . '" />' . "\n";
        echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
        echo "<!-- /Texter External Featured Image -->\n";
    }
}
