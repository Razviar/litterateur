<?php

/**
 * Shared admin header component for Litterateur API
 */

if (!defined('ABSPATH')) {
    exit;
}

class Litterateur_Admin_Header
{
    /**
     * Render the branded header with logo and panel link
     * 
     * @param string $page_title The page title to display
     */
    public static function render($page_title = '')
    {
        // Generate control panel URL based on site domain
        $site_url = get_site_url();
        $parsed = parse_url($site_url);
        $domain = $parsed['host'] ?? '';
        $panel_url = 'https://litterateur.pro/panel/websites/' . str_replace('.', '-', $domain);
?>
        <div class="litterateur-header">
            <div class="litterateur-logo">
                <svg width="140" height="40" viewBox="0 0 180 56" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <!-- L letter from animated lines -->
                    <rect x="8" y="10" width="4" rx="1.25" height="2.5" fill="white" opacity="0.6" />
                    <rect x="13" y="10" width="5" rx="1.25" height="2.5" fill="white" opacity="0.6" />
                    <rect x="8" y="15" width="3" rx="1.25" height="2.5" fill="white" opacity="0.7" />
                    <rect x="12" y="15" width="6" rx="1.25" height="2.5" fill="white" opacity="0.7" />
                    <rect x="8" y="20" width="10" rx="1.25" height="2.5" fill="white" opacity="1" />
                    <rect x="8" y="25" width="6" rx="1.25" height="2.5" fill="white" opacity="0.9" />
                    <rect x="15" y="25" width="3" rx="1.25" height="2.5" fill="white" opacity="0.9" />
                    <rect x="8" y="30" width="4" rx="1.25" height="2.5" fill="white" opacity="0.8" />
                    <rect x="13" y="30" width="5" rx="1.25" height="2.5" fill="white" opacity="0.8" />
                    <!-- Horizontal rows -->
                    <rect x="8" y="35" width="3" rx="1.25" height="2.5" fill="white" opacity="1" />
                    <rect x="14" y="35" width="6" rx="1.25" height="2.5" fill="white" opacity="1" />
                    <rect x="23" y="35" width="5" rx="1.25" height="2.5" fill="white" opacity="1" />
                    <rect x="31" y="35" width="7" rx="1.25" height="2.5" fill="white" opacity="1" />
                    <rect x="8" y="39" width="4" rx="1.25" height="2.5" fill="white" opacity="0.65" />
                    <rect x="15" y="39" width="8" rx="1.25" height="2.5" fill="white" opacity="0.65" />
                    <rect x="26" y="39" width="6" rx="1.25" height="2.5" fill="white" opacity="0.65" />
                    <rect x="35" y="39" width="5" rx="1.25" height="2.5" fill="white" opacity="0.65" />
                    <rect x="8" y="43" width="3" rx="1.25" height="2.5" fill="white" opacity="0.85" />
                    <rect x="14" y="43" width="7" rx="1.25" height="2.5" fill="white" opacity="0.85" />
                    <rect x="24" y="43" width="5" rx="1.25" height="2.5" fill="white" opacity="0.85" />
                    <rect x="32" y="43" width="6" rx="1.25" height="2.5" fill="white" opacity="0.85" />
                    <!-- Brand text -->
                    <text x="50" y="34" font-family="Inter, -apple-system, sans-serif" font-size="18" font-weight="700" fill="white" letter-spacing="-0.02em">Litterateur</text>
                    <!-- Blinking dot -->
                    <circle cx="146" cy="29" r="4" fill="#22c55e" />
                </svg>
                <?php if ($page_title): ?>
                    <span class="litterateur-page-title"><?php echo esc_html($page_title); ?></span>
                <?php endif; ?>
            </div>
            <a href="<?php echo esc_url($panel_url); ?>" target="_blank" class="litterateur-panel-link">
                Open Control Panel →
            </a>
        </div>
<?php
    }
}
