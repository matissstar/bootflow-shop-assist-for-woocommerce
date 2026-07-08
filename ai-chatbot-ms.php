<?php
/**
 * Plugin Name: Bootflow Shop Assist for WooCommerce
 * Description: Product search assistant for WooCommerce with local search, voice input, comparison, and custom responses.
 * Version: 2.0.2
 * Author: Bootflow.io
 * License: GPL v2 or later
 * Text Domain: bootflow-shop-assist-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.0
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) exit;

define('BOOTFLOW_SHOP_ASSIST_VERSION', '2.0.2');
define('BOOTFLOW_SHOP_ASSIST_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BOOTFLOW_SHOP_ASSIST_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BOOTFLOW_SHOP_ASSIST_PLUGIN_FILE', __FILE__);

// Declare WooCommerce HPOS and Blocks compatibility
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

// Helper: check if current page is frontend
function bootflow_shop_assist_is_frontend() {
    if (is_admin()) return false;
    if (isset($GLOBALS['pagenow']) && in_array($GLOBALS['pagenow'], ['wp-login.php', 'wp-register.php'], true)) return false;
    return true;
}
// Helper: generate CSS variable overrides from admin palette/custom color settings
function bootflow_shop_assist_get_theme_css() {
    $palettes = [
        'indigo'  => ['primary' => '#6366f1', 'hover' => '#4f46e5', 'light' => 'rgba(99,102,241,0.12)',  'grad_end' => '#8b5cf6'],
        'blue'    => ['primary' => '#3b82f6', 'hover' => '#2563eb', 'light' => 'rgba(59,130,246,0.12)',  'grad_end' => '#60a5fa'],
        'emerald' => ['primary' => '#10b981', 'hover' => '#059669', 'light' => 'rgba(16,185,129,0.12)',  'grad_end' => '#34d399'],
        'rose'    => ['primary' => '#f43f5e', 'hover' => '#e11d48', 'light' => 'rgba(244,63,94,0.12)',   'grad_end' => '#fb7185'],
        'amber'   => ['primary' => '#f59e0b', 'hover' => '#d97706', 'light' => 'rgba(245,158,11,0.12)',  'grad_end' => '#fbbf24'],
        'slate'   => ['primary' => '#475569', 'hover' => '#334155', 'light' => 'rgba(71,85,105,0.12)',   'grad_end' => '#64748b'],
    ];

    $palette = get_option('bootshas_color_palette', 'indigo');
    $font    = get_option('bootshas_font', 'Inter');
    $vars    = [];

    if ($palette === 'custom') {
        $primary = sanitize_hex_color(get_option('bootshas_custom_primary', ''));
        $text    = sanitize_hex_color(get_option('bootshas_custom_text', ''));
        $bg      = sanitize_hex_color(get_option('bootshas_custom_bg', ''));
        if ($primary) {
            $r = hexdec(substr($primary, 1, 2));
            $g = hexdec(substr($primary, 3, 2));
            $b = hexdec(substr($primary, 5, 2));
            $hover = sprintf('#%02x%02x%02x', max(0, $r - 20), max(0, $g - 20), max(0, $b - 20));
            $grad_end = sprintf('#%02x%02x%02x', min(255, $r + 30), min(255, $g + 20), max(0, $b - 10));
            $vars[] = "--msai-primary: {$primary}";
            $vars[] = "--msai-primary-hover: {$hover}";
            $vars[] = "--msai-primary-light: rgba({$r},{$g},{$b},0.12)";
            $vars[] = "--msai-gradient-user: linear-gradient(135deg, {$primary}, {$grad_end})";
            $vars[] = "--msai-gradient-fab: linear-gradient(135deg, {$primary}, {$grad_end})";
        }
        if ($text) {
            $vars[] = "--msai-text: {$text}";
        }
        if ($bg) {
            $r = hexdec(substr($bg, 1, 2));
            $g = hexdec(substr($bg, 3, 2));
            $b = hexdec(substr($bg, 5, 2));
            $vars[] = "--msai-glass-bg: rgba({$r},{$g},{$b},0.72)";
            $vars[] = "--msai-surface: rgba({$r},{$g},{$b},0.85)";
            $vars[] = "--msai-surface-hover: rgba({$r},{$g},{$b},0.95)";
        }
    } elseif ($palette !== 'indigo' && isset($palettes[$palette])) {
        $p = $palettes[$palette];
        $r = hexdec(substr($p['primary'], 1, 2));
        $g = hexdec(substr($p['primary'], 3, 2));
        $b = hexdec(substr($p['primary'], 5, 2));
        $vars[] = "--msai-primary: {$p['primary']}";
        $vars[] = "--msai-primary-hover: {$p['hover']}";
        $vars[] = "--msai-primary-light: {$p['light']}";
        $vars[] = "--msai-gradient-user: linear-gradient(135deg, {$p['primary']}, {$p['grad_end']})";
        $vars[] = "--msai-gradient-fab: linear-gradient(135deg, {$p['primary']}, {$p['grad_end']})";
    }

    // Font: use system font stack; add-ons may override via filter.
    $font_family = apply_filters('bootflow_shop_assist_font_family', "'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif");
    $font_safe = preg_replace('/[^a-zA-Z0-9\s\-]/', '', $font);
    if ($font !== 'Inter') {
        $vars[] = "--msai-font: '{$font_safe}', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif";
    }

    $font_size = absint(get_option('bootshas_font_size', '14'));
    if ($font_size && $font_size !== 14) {
        $vars[] = "--msai-font-size: {$font_size}px";
    }

    $font_style = get_option('bootshas_font_style', 'normal');
    if ($font_style === 'bold') {
        $vars[] = "--msai-font-weight: 700";
    } elseif ($font_style === 'italic') {
        $vars[] = "--msai-font-style-override: italic";
    }

    // Button colors
    $btn_details = sanitize_hex_color(get_option('bootshas_color_details', ''));
    $btn_compare = sanitize_hex_color(get_option('bootshas_color_compare', ''));
    $btn_cart    = sanitize_hex_color(get_option('bootshas_color_cart', ''));
    if ($btn_details) {
        $r = hexdec(substr($btn_details, 1, 2)); $g = hexdec(substr($btn_details, 3, 2)); $b = hexdec(substr($btn_details, 5, 2));
        $vars[] = "--msai-btn-details-bg: rgba({$r},{$g},{$b},0.10)";
        $vars[] = "--msai-btn-details-text: {$btn_details}";
    }
    if ($btn_compare) {
        $r = hexdec(substr($btn_compare, 1, 2)); $g = hexdec(substr($btn_compare, 3, 2)); $b = hexdec(substr($btn_compare, 5, 2));
        $vars[] = "--msai-btn-compare-bg: rgba({$r},{$g},{$b},0.10)";
        $vars[] = "--msai-btn-compare-text: {$btn_compare}";
    }
    if ($btn_cart) {
        $vars[] = "--msai-btn-cart-bg: {$btn_cart}";
        $vars[] = "--msai-btn-cart-text: #ffffff";
    }

    if (empty($vars)) return '';
    return ':root { ' . implode('; ', $vars) . '; }';
}
// Include classes
require_once BOOTFLOW_SHOP_ASSIST_PLUGIN_DIR . 'includes/translations.php';
require_once BOOTFLOW_SHOP_ASSIST_PLUGIN_DIR . 'includes/class-chatbot.php';
require_once BOOTFLOW_SHOP_ASSIST_PLUGIN_DIR . 'includes/class-admin.php';

// Set redirect transient on activation
register_activation_hook(__FILE__, function() {
    set_transient('bootshas_activated', true, 30);
});

// Schedule export/update cron
register_activation_hook(__FILE__, function() {
    if (!wp_next_scheduled('bootshas_export_products')) {
        wp_schedule_single_event(time() + 10, 'bootshas_export_products');
    }
    if (!wp_next_scheduled('bootshas_check_export')) {
        wp_schedule_event(time() + 300, 'bootshas_5min', 'bootshas_check_export');
    }
});

// Register custom 5-minute interval
add_filter('cron_schedules', function($schedules) {
    $schedules['bootshas_5min'] = [
        'interval' => 300,
        'display'  => 'Every 5 minutes (Shop Assistant)',
    ];
    return $schedules;
});

// Debounced export check
add_action('bootshas_check_export', function() {
    global $bootflow_shop_assist_chatbot;

    // Self-heal if plugin was updated without reactivation and schedule is missing.
    if (!wp_next_scheduled('bootshas_check_export')) {
        wp_schedule_event(time() + 300, 'bootshas_5min', 'bootshas_check_export');
    }

    if (get_transient('bootshas_needs_export')) {
        delete_transient('bootshas_needs_export');
        if ($bootflow_shop_assist_chatbot && method_exists($bootflow_shop_assist_chatbot, 'export_products_to_json')) {
            $bootflow_shop_assist_chatbot->export_products_to_json();
        }
    }
});

// Remove cron on deactivation
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('bootshas_check_export');
    wp_clear_scheduled_hook('bootshas_export_products');
});

// Initialize the plugin
add_action('plugins_loaded', function() {
    global $bootflow_shop_assist_chatbot;
    $bootflow_shop_assist_chatbot = new Bootflow_Shop_Assist_Chatbot();
    if (is_admin()) {
        new Bootflow_Shop_Assist_Admin();
    }

    /**
     * Hook: bootflow_shop_assist_loaded
    * Fires after the plugin is fully initialized.
        * Add-ons can hook here to extend functionality.
     */
    do_action('bootflow_shop_assist_loaded');
});

// Enqueue scripts and styles — NO external CDN calls
add_action('wp_enqueue_scripts', function() {
    if (bootflow_shop_assist_is_frontend()) {
        $css_deps = [];

        // Use non-minified CSS so UI fixes are always in sync with runtime markup.
        wp_enqueue_style('bootflow-shop-assist-style', BOOTFLOW_SHOP_ASSIST_PLUGIN_URL . 'assets/css/chatbot.css', $css_deps, BOOTFLOW_SHOP_ASSIST_VERSION);

        $inline_css = bootflow_shop_assist_get_theme_css();
        if ($inline_css) {
            wp_add_inline_style('bootflow-shop-assist-style', $inline_css);
        }

        // Use non-minified JS so add-on runtime hooks and latest speech fallback logic are always in sync.
        wp_enqueue_script('bootflow-shop-assist-script', BOOTFLOW_SHOP_ASSIST_PLUGIN_URL . 'assets/js/chatbot.js', ['jquery'], BOOTFLOW_SHOP_ASSIST_VERSION, false);

        // Build localize data — add-ons can extend via filter
        $localize_data = [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bootshas_nonce'),
            'i18n' => bootshas_get_strings(),
            'checkout_url' => function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : '/checkout/',
            'starter_questions' => bootshas_get_starter_questions(),
            'voice_mode' => get_option('bootshas_voice_mode', 'delayed'),
            'voice_silence' => intval(get_option('bootshas_voice_silence', 4)),
            'show_default_starters' => get_option('bootshas_show_default_starters', '1'),
            'gdpr_notice' => get_option('bootshas_gdpr_notice', ''),
            'wl_icon' => get_option('bootshas_wl_icon', ''),
            'wl_welcome' => get_option('bootshas_wl_welcome', ''),
            'wl_powered_by' => get_option('bootshas_wl_powered_by', '0'),
        ];

        /**
         * Filter: bootflow_shop_assist_localize_data
         * Add-ons can extend the localized frontend data.
         */
        $localize_data = apply_filters('bootflow_shop_assist_localize_data', $localize_data);

        wp_localize_script('bootflow-shop-assist-script', 'bootshas_ajax', $localize_data);
    }
}, 5);

// Floating button and modal injection
add_action('wp_footer', function() {
    if (bootflow_shop_assist_is_frontend()) {
        bootflow_shop_assist_inject_html();
    }
}, 999);

add_action('wp_body_open', function() {
    if (bootflow_shop_assist_is_frontend()) {
        bootflow_shop_assist_inject_html();
    }
}, 999);

add_action('wp_enqueue_scripts', function() {
    if (bootflow_shop_assist_is_frontend()) {
        $wl_icon = get_option('bootshas_wl_icon', '');
        $btn_icon = !empty($wl_icon) ? esc_js($wl_icon) : '💬';
        $inline_js = 'document.addEventListener("DOMContentLoaded", function() {
            if (!document.getElementById("bootflow-shop-assist-floating-btn")) {
                var btn = document.createElement("div");
                btn.id = "bootflow-shop-assist-floating-btn";
                btn.innerHTML = "' . $btn_icon . '";
                btn.style.cssText = "position:fixed;bottom:20px;right:20px;width:60px;height:60px;background:#0366d6;color:white;border-radius:50%;align-items:center;justify-content:center;cursor:pointer;z-index:999999!important;font-size:24px;box-shadow:0 4px 8px rgba(0,0,0,0.2);transition:all 0.3s ease";
                document.body.appendChild(btn);
            }
        });';
        wp_add_inline_script('bootflow-shop-assist-script', $inline_js, 'before');
    }
}, 6);

function bootflow_shop_assist_inject_html() {
    static $injected = false;
    if ($injected) return;
    $injected = true;
    
    $wl_icon = get_option('bootshas_wl_icon', '');
    $btn_icon = !empty($wl_icon) ? $wl_icon : '💬';

    ob_start();
    include BOOTFLOW_SHOP_ASSIST_PLUGIN_DIR . 'templates/chatbot-modal.php';
    $modal_html = ob_get_clean();
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template handles its own escaping
    echo $modal_html;
    echo '<div id="bootflow-shop-assist-floating-btn" style="position:fixed;bottom:20px;right:20px;width:60px;height:60px;background:#0366d6;color:white;border-radius:50%;align-items:center;justify-content:center;cursor:pointer;z-index:999999!important;font-size:24px;box-shadow:0 4px 8px rgba(0,0,0,0.2)">' . esc_html($btn_icon) . '</div>';
}
