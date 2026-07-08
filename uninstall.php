<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Resolve products JSON paths before deleting options.
$bootflow_shop_assist_upload_dir = wp_upload_dir();
$bootflow_shop_assist_json_paths = [
    $bootflow_shop_assist_upload_dir['basedir'] . '/bootflow-shop-assist-for-woocommerce/ai-chatboot-products.json',
];
$bootflow_shop_assist_saved_json_path = get_option('bootshas_products_json_path');
if (!empty($bootflow_shop_assist_saved_json_path)) {
    $bootflow_shop_assist_json_paths[] = $bootflow_shop_assist_saved_json_path;
}
$bootflow_shop_assist_json_paths = array_values(array_unique(array_filter($bootflow_shop_assist_json_paths)));

// --- General settings ---
delete_option('bootshas_language');
delete_option('bootshas_excluded_tags');
delete_option('bootshas_show_default_starters');
delete_option('bootshas_auto_contact');
delete_option('bootshas_gdpr_notice');

// --- Colors & font ---
delete_option('bootshas_color_palette');
delete_option('bootshas_custom_primary');
delete_option('bootshas_custom_text');
delete_option('bootshas_custom_bg');
delete_option('bootshas_color_details');
delete_option('bootshas_color_compare');
delete_option('bootshas_color_cart');
delete_option('bootshas_font');
delete_option('bootshas_font_size');
delete_option('bootshas_font_style');

// --- Voice settings ---
delete_option('bootshas_voice_mode');
delete_option('bootshas_voice_silence');

// --- Handoff settings ---
delete_option('bootshas_handoff_enabled');
delete_option('bootshas_handoff_context');
delete_option('bootshas_handoff_methods');

// --- White-label ---
delete_option('bootshas_wl_name');
delete_option('bootshas_wl_icon');
delete_option('bootshas_wl_welcome');
delete_option('bootshas_wl_admin_name');
delete_option('bootshas_wl_powered_by');

// --- Products export ---
delete_option('bootshas_products_json_path');
delete_option('bootshas_products_json_url');
delete_option('bootshas_products_export_time');

// --- Custom responses & starter questions ---
delete_option('bootshas_custom_responses');
delete_option('bootshas_starter_questions');

// --- Transients ---
delete_transient('bootshas_needs_export');
delete_transient('bootshas_activated');

// --- Delete products JSON file(s) ---
foreach ($bootflow_shop_assist_json_paths as $bootflow_shop_assist_json_path) {
    if ($bootflow_shop_assist_json_path && file_exists($bootflow_shop_assist_json_path)) {
        wp_delete_file($bootflow_shop_assist_json_path);
    }
}

// --- Clear all scheduled cron hooks ---
wp_clear_scheduled_hook('bootshas_check_export');
wp_clear_scheduled_hook('bootshas_export_products');

/**
 * Hook: bootflow_shop_assist_uninstall
 * Add-ons can clean up their own options and data.
 */
do_action('bootflow_shop_assist_uninstall');
?>
