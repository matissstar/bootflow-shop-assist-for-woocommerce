<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Bootflow Shop Assist — Admin Panel
 *
 * @package Bootflow_Shop_Assist
 * @license GPL v2 or later
 */
class Bootflow_Shop_Assist_Admin {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'maybe_redirect_legacy_settings_slug']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_post_export_products', [$this, 'handle_export_products']);
        add_action('admin_post_save_custom_responses', [$this, 'handle_save_custom_responses']);
        add_action('admin_post_save_starter_questions', [$this, 'handle_save_starter_questions']);
        add_action('wp_ajax_bootshas_admin_product_search', [$this, 'ajax_admin_product_search']);
        add_action('admin_post_bootshas_export_settings', [$this, 'handle_export_settings']);
        add_action('admin_post_bootshas_import_settings', [$this, 'handle_import_settings']);
        add_action('admin_init', [$this, 'maybe_redirect_after_activation']);

        /**
         * Hook: bootflow_shop_assist_admin_init
         * Add-ons can register additional AJAX handlers.
         */
        do_action('bootflow_shop_assist_admin_init', $this);
    }

    public function maybe_redirect_legacy_settings_slug() {
        if (!is_admin()) {
            return;
        }

        if (!isset($_GET['page'])) {
            return;
        }

        $page = sanitize_key(wp_unslash($_GET['page']));
        if ($page !== 'bootflow-shop-assist-settings') {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        wp_safe_redirect(admin_url('admin.php?page=bootflow-shop-assist'));
        exit;
    }

    public function enqueue_admin_assets($hook_suffix) {
        if (strpos((string) $hook_suffix, 'bootflow-shop-assist') === false) {
            return;
        }

        wp_register_script(
            'bootflow-shop-assist-admin',
            BOOTFLOW_SHOP_ASSIST_PLUGIN_URL . 'assets/js/admin.js',
            [],
            BOOTFLOW_SHOP_ASSIST_VERSION,
            true
        );
        wp_enqueue_script('bootflow-shop-assist-admin');

        wp_register_style(
            'bootflow-shop-assist-admin-css',
            BOOTFLOW_SHOP_ASSIST_PLUGIN_URL . 'assets/css/admin.css',
            [],
            BOOTFLOW_SHOP_ASSIST_VERSION
        );
        wp_enqueue_style('bootflow-shop-assist-admin-css');
    }

    public function sanitize_language_option($value) {
        $lang = sanitize_key((string) $value);
        $allowed = ['auto', 'lv', 'en', 'de', 'ru', 'lt', 'et', 'es', 'fr'];
        return in_array($lang, $allowed, true) ? $lang : 'en';
    }

    public function add_menu() {
        $menu_name = get_option('bootshas_wl_admin_name', '');
        if (empty($menu_name)) $menu_name = 'Bootflow Shop Assist';

        add_menu_page(
            $menu_name,
            $menu_name,
            'manage_options',
            'bootflow-shop-assist',
            [$this, 'page_settings'],
            'dashicons-format-chat',
            56.5
        );

        // Make the default first submenu item the Settings page with icon.
        global $submenu;
        if (isset($submenu['bootflow-shop-assist'][0])) {
            $submenu['bootflow-shop-assist'][0][0] = '⚙️ ' . bootshas_t('admin_tab_settings');
        }

        add_submenu_page(
            'bootflow-shop-assist',
            bootshas_t('admin_tab_responses'),
            bootshas_t('admin_tab_responses'),
            'manage_options',
            'bootflow-shop-assist-responses',
            [$this, 'page_responses'],
            30
        );

        // Show Get PRO only when PRO add-on is not installed/active.
        if (!defined('BOOTFLOW_SHOP_ASSIST_PRO_ADDON_VERSION')) {
            add_submenu_page(
                'bootflow-shop-assist',
                'Get PRO',
                'Get PRO',
                'manage_options',
                'bootflow-shop-assist-get-pro',
                [$this, 'page_get_pro'],
                40
            );
        }
    }

    public function page_get_pro() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html(bootshas_t('admin_no_permission')));
        }

        $pro_url = 'https://bootflow.io/ai-chatbot-for-woocommerce/';
        ?>
        <div class="wrap">
            <h1>Bootflow Shop Assist PRO</h1>
            <p><?php echo esc_html__('Need advanced functionality and premium support?', 'bootflow-shop-assist-for-woocommerce'); ?></p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url($pro_url); ?>" target="_blank" rel="noopener noreferrer">
                    <?php echo esc_html__('View PRO details', 'bootflow-shop-assist-for-woocommerce'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    public function register_settings() {
        register_setting('bootshas_settings', 'bootshas_language', [
            'type' => 'string',
            'default' => 'en',
            'sanitize_callback' => [$this, 'sanitize_language_option'],
        ]);

        /**
         * Hook: bootflow_shop_assist_register_settings
         * Add-ons can register additional settings.
         */
        do_action('bootflow_shop_assist_register_settings');
        register_setting('bootshas_settings', 'bootshas_excluded_tags', [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'sanitize_text_field',
        ]);

        // Appearance settings
        register_setting('bootshas_settings', 'bootshas_color_palette', [
            'type' => 'string',
            'default' => 'indigo',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('bootshas_settings', 'bootshas_custom_primary', [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'sanitize_hex_color',
        ]);
        register_setting('bootshas_settings', 'bootshas_custom_text', [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'sanitize_hex_color',
        ]);
        register_setting('bootshas_settings', 'bootshas_custom_bg', [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'sanitize_hex_color',
        ]);
        // Button color settings
        register_setting('bootshas_settings', 'bootshas_color_details', [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'sanitize_hex_color',
        ]);
        register_setting('bootshas_settings', 'bootshas_color_compare', [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'sanitize_hex_color',
        ]);
        register_setting('bootshas_settings', 'bootshas_color_cart', [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'sanitize_hex_color',
        ]);
        register_setting('bootshas_settings', 'bootshas_font', [
            'type' => 'string',
            'default' => 'Inter',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('bootshas_settings', 'bootshas_font_size', [
            'type' => 'string',
            'default' => '14',
            'sanitize_callback' => function($val) {
                $allowed = ['12','13','14','15','16','17','18'];
                return in_array($val, $allowed, true) ? $val : '14';
            },
        ]);
        register_setting('bootshas_settings', 'bootshas_font_style', [
            'type' => 'string',
            'default' => 'normal',
            'sanitize_callback' => function($val) {
                $allowed = ['normal','bold','italic'];
                return in_array($val, $allowed, true) ? $val : 'normal';
            },
        ]);

        register_setting('bootshas_settings', 'bootshas_voice_mode', [
            'type' => 'string',
            'default' => 'delayed',
            'sanitize_callback' => function($val) {
                $allowed = ['manual','delayed','instant'];
                return in_array($val, $allowed, true) ? $val : 'delayed';
            },
        ]);
        register_setting('bootshas_settings', 'bootshas_voice_silence', [
            'type' => 'integer',
            'default' => 4,
            'sanitize_callback' => function($val) {
                $val = intval($val);
                return max(2, min(15, $val));
            },
        ]);
        register_setting('bootshas_settings', 'bootshas_show_default_starters', [
            'type' => 'string',
            'default' => '1',
            'sanitize_callback' => function($val) {
                return $val ? '1' : '0';
            },
        ]);
        register_setting('bootshas_settings', 'bootshas_gdpr_notice', [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'wp_kses_post',
        ]);
        register_setting('bootshas_settings', 'bootshas_auto_contact', [
            'type' => 'string',
            'default' => '1',
            'sanitize_callback' => function($val) {
                return $val ? '1' : '0';
            },
        ]);

        // White-label settings
        register_setting('bootshas_settings', 'bootshas_wl_name', [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('bootshas_settings', 'bootshas_wl_icon', [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('bootshas_settings', 'bootshas_wl_welcome', [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('bootshas_settings', 'bootshas_wl_admin_name', [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('bootshas_settings', 'bootshas_wl_powered_by', [
            'type' => 'string',
            'default' => '1',
            'sanitize_callback' => function($val) {
                return $val ? '1' : '0';
            },
        ]);

        // Handoff settings
        register_setting('bootshas_settings', 'bootshas_handoff_enabled', [
            'type' => 'string',
            'default' => '0',
            'sanitize_callback' => function($val) {
                return $val ? '1' : '0';
            },
        ]);
        register_setting('bootshas_settings', 'bootshas_handoff_context', [
            'type' => 'string',
            'default' => '1',
            'sanitize_callback' => function($val) {
                return $val ? '1' : '0';
            },
        ]);
        register_setting('bootshas_settings', 'bootshas_handoff_methods', [
            'type' => 'string',
            'default' => '[]',
            'sanitize_callback' => function($val) {
                if (is_string($val)) {
                    $val = json_decode(stripslashes($val), true);
                }
                if (!is_array($val)) return '[]';
                $clean = [];
                foreach ($val as $m) {
                    if (empty($m['type']) || empty($m['value'])) continue;
                    $allowed_types = ['email','whatsapp','telegram','facebook','instagram','tiktok','custom'];
                    if (!in_array($m['type'], $allowed_types, true)) continue;
                    $clean[] = [
                        'type'  => sanitize_text_field($m['type']),
                        'value' => sanitize_text_field($m['value']),
                        'label' => sanitize_text_field($m['label'] ?? ''),
                    ];
                }
                return wp_json_encode($clean);
            },
        ]);
    }

    public function handle_export_products() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html(bootshas_t('admin_no_permission')));
        }

        check_admin_referer('export_products');

        global $bootflow_shop_assist_chatbot;
        if ($bootflow_shop_assist_chatbot && method_exists($bootflow_shop_assist_chatbot, 'export_products_to_json')) {
            $result = $bootflow_shop_assist_chatbot->export_products_to_json();

            if ($result) {
                add_settings_error('bootshas_messages', 'export_success', bootshas_t('admin_export_success'), 'updated');
            } else {
                add_settings_error('bootshas_messages', 'export_error', bootshas_t('admin_export_error'), 'error');
            }
        }

        wp_safe_redirect(add_query_arg('page', 'bootflow-shop-assist', admin_url('admin.php')));
        exit;
    }

    public function page_analytics() {
        $this->page_settings();
    }

    public function page_responses() {
        $wl_admin_name = get_option('bootshas_wl_admin_name', '');
        if (empty($wl_admin_name)) $wl_admin_name = 'Bootflow Shop Assist';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html($wl_admin_name); ?> — <?php echo esc_html(bootshas_t('admin_tab_responses')); ?></h1>
            <?php $this->render_custom_responses_tab(); ?>
        </div>
        <?php
    }

    public function page_settings() {
        $wl_admin_name = get_option('bootshas_wl_admin_name', '');
        if (empty($wl_admin_name)) $wl_admin_name = 'Bootflow Shop Assist';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html($wl_admin_name); ?> — <?php echo esc_html(bootshas_t('admin_tab_settings')); ?></h1>
            <?php $this->render_settings_tab(); ?>
        </div>
        <?php
    }

    private function render_settings_tab() {
        $products_export_time = get_option('bootshas_products_export_time');
        $products_json_url = get_option('bootshas_products_json_url');
        $current_lang = get_option('bootshas_language', 'en');
        $active_lang = bootshas_get_language();

        $available_languages = [
            'en'   => 'English',
            'lv'   => 'Latviešu',
            'de'   => 'Deutsch',
            'auto' => 'Auto (WordPress: ' . get_locale() . ' → ' . $active_lang . ')',
            'ru'   => 'Русский',
            'lt'   => 'Lietuvių',
            'et'   => 'Eesti',
            'es'   => 'Español',
            'fr'   => 'Français',
        ];

        ?>

            <form method="post" action="options.php">
                <?php settings_fields('bootshas_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th><?php echo esc_html(bootshas_t('admin_interface_lang')); ?></th>
                        <td>
                            <select name="bootshas_language">
                                <?php foreach ($available_languages as $code => $label): ?>
                                    <option value="<?php echo esc_attr($code); ?>" <?php selected($current_lang, $code); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php echo esc_html(bootshas_t('admin_lang_desc')); ?></p>
                            <p class="description"><?php echo esc_html(bootshas_t('admin_active_label')); ?> <strong><?php echo esc_html($active_lang); ?></strong></p>
                        </td>
                    </tr>

                    <?php
                    /**
                     * Hook: bootflow_shop_assist_settings_after_language
                     * Add-ons can inject extra settings fields.
                     */
                    do_action('bootflow_shop_assist_settings_after_language');
                    ?>

                    <tr>
                        <th><?php echo esc_html(bootshas_t('admin_excluded_tags')); ?></th>
                        <td>
                            <input type="text" name="bootshas_excluded_tags" value="<?php echo esc_attr(get_option('bootshas_excluded_tags', '')); ?>" class="regular-text" />
                            <p class="description"><?php echo esc_html(bootshas_t('admin_excluded_tags_desc')); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html(bootshas_t('admin_show_default_starters')); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="bootshas_show_default_starters" value="1" <?php checked(get_option('bootshas_show_default_starters', '1'), '1'); ?> />
                                <?php echo esc_html(bootshas_t('admin_show_default_starters_desc')); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html(bootshas_t('admin_auto_contact')); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="bootshas_auto_contact" value="1" <?php checked(get_option('bootshas_auto_contact', '1'), '1'); ?> />
                                <?php echo esc_html(bootshas_t('admin_auto_contact_desc')); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <hr>
                <h2>🎨 <?php echo esc_html(bootshas_t('admin_appearance')); ?></h2>

                <?php
                $palettes = [
                    'indigo'  => ['label' => 'Indigo',      'primary' => '#6366f1', 'hover' => '#4f46e5', 'gradient_end' => '#8b5cf6'],
                    'blue'    => ['label' => 'Ocean Blue',  'primary' => '#3b82f6', 'hover' => '#2563eb', 'gradient_end' => '#60a5fa'],
                    'emerald' => ['label' => 'Emerald',     'primary' => '#10b981', 'hover' => '#059669', 'gradient_end' => '#34d399'],
                    'rose'    => ['label' => 'Rose',        'primary' => '#f43f5e', 'hover' => '#e11d48', 'gradient_end' => '#fb7185'],
                    'amber'   => ['label' => 'Amber',       'primary' => '#f59e0b', 'hover' => '#d97706', 'gradient_end' => '#fbbf24'],
                    'slate'   => ['label' => 'Slate',       'primary' => '#475569', 'hover' => '#334155', 'gradient_end' => '#64748b'],
                ];
                $current_palette = get_option('bootshas_color_palette', 'indigo');
                $custom_primary = get_option('bootshas_custom_primary', '');
                $custom_text = get_option('bootshas_custom_text', '');
                $custom_bg = get_option('bootshas_custom_bg', '');
                $color_details = get_option('bootshas_color_details', '');
                $color_compare = get_option('bootshas_color_compare', '');
                $color_cart = get_option('bootshas_color_cart', '');
                $current_font = get_option('bootshas_font', 'Inter');
                $current_font_size = get_option('bootshas_font_size', '14');
                $current_font_style = get_option('bootshas_font_style', 'normal');
                ?>

                <table class="form-table">
                    <tr>
                        <th><?php echo esc_html(bootshas_t('admin_color_palette')); ?></th>
                        <td>
                            <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:10px">
                                <?php foreach ($palettes as $key => $pal): ?>
                                <label style="cursor:pointer;text-align:center">
                                    <input type="radio" name="bootshas_color_palette" value="<?php echo esc_attr($key); ?>" <?php checked($current_palette, $key); ?> style="display:none" class="msai-palette-radio">
                                    <div class="msai-palette-swatch" data-palette="<?php echo esc_attr($key); ?>" style="width:64px;height:64px;border-radius:14px;background:linear-gradient(135deg,<?php echo esc_attr($pal['primary']); ?>,<?php echo esc_attr($pal['gradient_end']); ?>);border:3px solid <?php echo esc_attr($current_palette === $key ? '#1e293b' : 'transparent'); ?>;transition:border .2s,transform .2s;box-shadow:0 2px 8px rgba(0,0,0,0.12)">
                                    </div>
                                    <div style="font-size:12px;margin-top:4px;font-weight:<?php echo esc_attr($current_palette === $key ? '700' : '400'); ?>"><?php echo esc_html($pal['label']); ?></div>
                                </label>
                                <?php endforeach; ?>
                                <label style="cursor:pointer;text-align:center">
                                    <input type="radio" name="bootshas_color_palette" value="custom" <?php checked($current_palette, 'custom'); ?> style="display:none" class="msai-palette-radio">
                                    <div class="msai-palette-swatch" data-palette="custom" style="width:64px;height:64px;border-radius:14px;background:conic-gradient(red,yellow,lime,aqua,blue,magenta,red);border:3px solid <?php echo esc_attr($current_palette === 'custom' ? '#1e293b' : 'transparent'); ?>;transition:border .2s,transform .2s;box-shadow:0 2px 8px rgba(0,0,0,0.12)">
                                    </div>
                                    <div style="font-size:12px;margin-top:4px;font-weight:<?php echo esc_attr($current_palette === 'custom' ? '700' : '400'); ?>">Custom</div>
                                </label>
                            </div>
                        </td>
                    </tr>
                    <tr id="msai-custom-colors" style="<?php echo esc_attr($current_palette !== 'custom' ? 'display:none' : ''); ?>">
                        <th><?php echo esc_html(bootshas_t('admin_custom_colors')); ?></th>
                        <td>
                            <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:center">
                                <label>
                                    <span style="display:block;font-size:13px;margin-bottom:4px"><?php echo esc_html(bootshas_t('admin_color_primary')); ?></span>
                                    <input type="color" name="bootshas_custom_primary" value="<?php echo esc_attr($custom_primary ?: '#6366f1'); ?>" style="width:50px;height:36px;cursor:pointer;border:1px solid #ccc;border-radius:6px">
                                </label>
                                <label>
                                    <span style="display:block;font-size:13px;margin-bottom:4px"><?php echo esc_html(bootshas_t('admin_color_text')); ?></span>
                                    <input type="color" name="bootshas_custom_text" value="<?php echo esc_attr($custom_text ?: '#1e293b'); ?>" style="width:50px;height:36px;cursor:pointer;border:1px solid #ccc;border-radius:6px">
                                </label>
                                <label>
                                    <span style="display:block;font-size:13px;margin-bottom:4px"><?php echo esc_html(bootshas_t('admin_color_bg')); ?></span>
                                    <input type="color" name="bootshas_custom_bg" value="<?php echo esc_attr($custom_bg ?: '#ffffff'); ?>" style="width:50px;height:36px;cursor:pointer;border:1px solid #ccc;border-radius:6px">
                                </label>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html(bootshas_t('admin_color_details')); ?></th>
                        <td>
                            <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-end">
                                <label>
                                    <span style="display:block;font-size:13px;margin-bottom:4px"><?php echo esc_html(bootshas_t('admin_color_details')); ?></span>
                                    <input type="color" name="bootshas_color_details" value="<?php echo esc_attr($color_details ?: '#64748b'); ?>" style="width:50px;height:36px;cursor:pointer;border:1px solid #ccc;border-radius:6px">
                                </label>
                                <label>
                                    <span style="display:block;font-size:13px;margin-bottom:4px"><?php echo esc_html(bootshas_t('admin_color_compare')); ?></span>
                                    <input type="color" name="bootshas_color_compare" value="<?php echo esc_attr($color_compare ?: '#f59e0b'); ?>" style="width:50px;height:36px;cursor:pointer;border:1px solid #ccc;border-radius:6px">
                                </label>
                                <label>
                                    <span style="display:block;font-size:13px;margin-bottom:4px"><?php echo esc_html(bootshas_t('admin_color_cart')); ?></span>
                                    <input type="color" name="bootshas_color_cart" value="<?php echo esc_attr($color_cart ?: '#059669'); ?>" style="width:50px;height:36px;cursor:pointer;border:1px solid #ccc;border-radius:6px">
                                </label>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html(bootshas_t('admin_font')); ?></th>
                        <td>
                            <?php
                            $fonts = [
                                'Inter'          => 'Inter',
                                'Roboto'         => 'Roboto',
                                'Open Sans'      => 'Open Sans',
                                'Lato'           => 'Lato',
                                'Nunito'         => 'Nunito',
                                'Poppins'        => 'Poppins',
                                'Source Sans 3'  => 'Source Sans 3',
                                'Montserrat'     => 'Montserrat',
                                'Raleway'        => 'Raleway',
                                'PT Sans'        => 'PT Sans',
                            ];
                            ?>
                            <select name="bootshas_font" id="msai_font_select">
                                <?php foreach ($fonts as $fval => $flabel): ?>
                                    <option value="<?php echo esc_attr($fval); ?>" <?php selected($current_font, $fval); ?>><?php echo esc_html($flabel); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span id="msai-font-preview" style="margin-left:16px;font-size:16px;"><?php echo esc_html(bootshas_t('admin_font_preview')); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html(bootshas_t('admin_font_size')); ?></th>
                        <td>
                            <select name="bootshas_font_size" id="msai_font_size_select">
                                <?php foreach (['12','13','14','15','16','17','18'] as $sz): ?>
                                    <option value="<?php echo esc_attr($sz); ?>" <?php selected($current_font_size, $sz); ?>><?php echo esc_html($sz . 'px'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html(bootshas_t('admin_font_style')); ?></th>
                        <td>
                            <select name="bootshas_font_style" id="msai_font_style_select">
                                <option value="normal" <?php selected($current_font_style, 'normal'); ?>><?php echo esc_html(bootshas_t('admin_font_style_normal')); ?></option>
                                <option value="bold" <?php selected($current_font_style, 'bold'); ?>><?php echo esc_html(bootshas_t('admin_font_style_bold')); ?></option>
                                <option value="italic" <?php selected($current_font_style, 'italic'); ?>><?php echo esc_html(bootshas_t('admin_font_style_italic')); ?></option>
                            </select>
                        </td>
                    </tr>
                    <?php $current_voice_mode = get_option('bootshas_voice_mode', 'delayed'); ?>
                    <tr>
                        <th><?php echo esc_html(bootshas_t('admin_voice_mode')); ?></th>
                        <td>
                            <p class="description" style="margin:0 0 8px;">
                                <?php echo esc_html__('Voice recognition depends on browser support (works best in Chrome/Edge).', 'bootflow-shop-assist-for-woocommerce'); ?>
                            </p>
                            <select name="bootshas_voice_mode">
                                <option value="manual" <?php selected($current_voice_mode, 'manual'); ?>><?php echo esc_html(bootshas_t('admin_voice_mode_manual')); ?></option>
                                <option value="delayed" <?php selected($current_voice_mode, 'delayed'); ?>><?php echo esc_html(bootshas_t('admin_voice_mode_delayed')); ?></option>
                                <option value="instant" <?php selected($current_voice_mode, 'instant'); ?>><?php echo esc_html(bootshas_t('admin_voice_mode_instant')); ?></option>
                            </select>
                            <p class="description"><?php echo esc_html(bootshas_t('admin_voice_mode_desc')); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html(bootshas_t('admin_voice_silence')); ?></th>
                        <td>
                            <input type="number" name="bootshas_voice_silence" value="<?php echo esc_attr(get_option('bootshas_voice_silence', 4)); ?>" min="2" max="15" step="1" style="width:80px" />
                            <span>s</span>
                            <p class="description"><?php echo esc_html(bootshas_t('admin_voice_silence_desc')); ?></p>
                        </td>
                    </tr>
                </table>

                <!-- Handoff — Sazināšanās ar operatoru -->
                <h2 style="margin-top:30px">✋ <?php echo esc_html(bootshas_t('admin_handoff_title')); ?></h2>
                <p class="description" style="margin-bottom:12px"><?php echo esc_html(bootshas_t('admin_handoff_desc')); ?></p>
                <table class="form-table">
                    <tr>
                        <th><?php echo esc_html(bootshas_t('admin_handoff_enabled')); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="bootshas_handoff_enabled" value="1" <?php checked(get_option('bootshas_handoff_enabled', '0'), '1'); ?> />
                                <?php echo esc_html(bootshas_t('admin_handoff_enabled_desc')); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html(bootshas_t('admin_handoff_context')); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="bootshas_handoff_context" value="1" <?php checked(get_option('bootshas_handoff_context', '1'), '1'); ?> />
                                <?php echo esc_html(bootshas_t('admin_handoff_context_desc')); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html(bootshas_t('admin_handoff_methods')); ?></th>
                        <td>
                            <?php
                            $handoff_methods = json_decode(get_option('bootshas_handoff_methods', '[]'), true);
                            if (!is_array($handoff_methods)) $handoff_methods = [];
                            $method_types = [
                                'email'     => bootshas_t('admin_handoff_email'),
                                'whatsapp'  => bootshas_t('admin_handoff_whatsapp'),
                                'telegram'  => bootshas_t('admin_handoff_telegram'),
                                'facebook'  => bootshas_t('admin_handoff_facebook'),
                                'instagram' => bootshas_t('admin_handoff_instagram'),
                                'tiktok'    => bootshas_t('admin_handoff_tiktok'),
                                'custom'    => bootshas_t('admin_handoff_custom'),
                            ];
                            ?>
                            <div id="msai-handoff-methods">
                                <?php foreach ($handoff_methods as $idx => $m): ?>
                                <div class="msai-handoff-row" style="display:flex;gap:8px;align-items:center;margin-bottom:6px">
                                    <select class="msai-ho-type" style="width:160px">
                                        <?php foreach ($method_types as $k => $v): ?>
                                            <option value="<?php echo esc_attr($k); ?>" <?php selected($m['type'] ?? '', $k); ?>><?php echo esc_html($v); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="text" class="msai-ho-value" value="<?php echo esc_attr($m['value'] ?? ''); ?>" placeholder="<?php echo esc_attr(bootshas_t('admin_handoff_value_ph')); ?>" style="width:220px" />
                                    <input type="text" class="msai-ho-label" value="<?php echo esc_attr($m['label'] ?? ''); ?>" placeholder="<?php echo esc_attr(bootshas_t('admin_handoff_label_ph')); ?>" style="width:200px" />
                                    <button type="button" class="button msai-ho-remove" title="<?php echo esc_attr(bootshas_t('admin_handoff_remove')); ?>">&times;</button>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="bootshas_handoff_methods" id="msai-handoff-json" value="<?php echo esc_attr(wp_json_encode($handoff_methods)); ?>" />
                            <button type="button" class="button" id="msai-handoff-add" style="margin-top:6px">+ <?php echo esc_html(bootshas_t('admin_handoff_add')); ?></button>
                            <p class="description" style="margin-top:6px"><?php echo esc_html(bootshas_t('admin_handoff_methods_desc')); ?></p>
                        </td>
                    </tr>
                </table><?php
ob_start();
?>
                (function(){
                    var types = <?php echo wp_json_encode($method_types); ?>;
                    var container = document.getElementById('msai-handoff-methods');
                    var jsonInput = document.getElementById('msai-handoff-json');

                    function syncJson() {
                        var rows = container.querySelectorAll('.msai-handoff-row');
                        var data = [];
                        rows.forEach(function(row) {
                            var t = row.querySelector('.msai-ho-type').value;
                            var v = row.querySelector('.msai-ho-value').value.trim();
                            var l = row.querySelector('.msai-ho-label').value.trim();
                            if (v) data.push({type: t, value: v, label: l});
                        });
                        jsonInput.value = JSON.stringify(data);
                    }

                    function addRow(type, value, label) {
                        var row = document.createElement('div');
                        row.className = 'msai-handoff-row';
                        row.style = 'display:flex;gap:8px;align-items:center;margin-bottom:6px';
                        var sel = '<select class="msai-ho-type" style="width:160px">';
                        for (var k in types) sel += '<option value="'+k+'"'+(k===type?' selected':'')+'>'+types[k]+'</option>';
                        sel += '</select>';
                        row.innerHTML = sel
                            + '<input type="text" class="msai-ho-value" value="'+(value||'')+'" placeholder="<?php echo esc_js(bootshas_t('admin_handoff_value_ph')); ?>" style="width:220px" />'
                            + '<input type="text" class="msai-ho-label" value="'+(label||'')+'" placeholder="<?php echo esc_js(bootshas_t('admin_handoff_label_ph')); ?>" style="width:200px" />'
                            + '<button type="button" class="button msai-ho-remove" title="<?php echo esc_js(bootshas_t('admin_handoff_remove')); ?>">&times;</button>';
                        container.appendChild(row);
                        row.querySelector('.msai-ho-remove').addEventListener('click', function(){ row.remove(); syncJson(); });
                        row.querySelector('.msai-ho-type').addEventListener('change', syncJson);
                        row.querySelector('.msai-ho-value').addEventListener('input', syncJson);
                        row.querySelector('.msai-ho-label').addEventListener('input', syncJson);
                        syncJson();
                    }

                    // Bind existing rows
                    container.querySelectorAll('.msai-ho-remove').forEach(function(btn){
                        btn.addEventListener('click', function(){ btn.closest('.msai-handoff-row').remove(); syncJson(); });
                    });
                    container.querySelectorAll('.msai-ho-type, .msai-ho-value, .msai-ho-label').forEach(function(el){
                        el.addEventListener('change', syncJson);
                        el.addEventListener('input', syncJson);
                    });

                    document.getElementById('msai-handoff-add').addEventListener('click', function(){ addRow('email','',''); });
                })();<?php
wp_add_inline_script('bootflow-shop-assist-admin', ob_get_clean());
?>

                <table class="form-table">
                    <tr>
                        <th><?php echo esc_html(bootshas_t('admin_gdpr_notice')); ?></th>
                        <td>
                            <?php $gdpr_notice = get_option('bootshas_gdpr_notice', ''); ?>
                            <textarea name="bootshas_gdpr_notice" rows="3" style="width:100%;max-width:500px"><?php echo esc_textarea($gdpr_notice); ?></textarea>
                            <p class="description"><?php echo esc_html(bootshas_t('admin_gdpr_notice_desc')); ?></p>
                        </td>
                    </tr>
                </table><?php
ob_start();
?>
                (function(){
                    // Palette radio selection
                    document.querySelectorAll('.msai-palette-radio').forEach(function(radio){
                        radio.addEventListener('change', function(){
                            document.querySelectorAll('.msai-palette-swatch').forEach(function(s){
                                s.style.border = '3px solid transparent';
                                s.parentElement.querySelector('div:last-child').style.fontWeight = '400';
                            });
                            var swatch = this.parentElement.querySelector('.msai-palette-swatch');
                            swatch.style.border = '3px solid #1e293b';
                            swatch.parentElement.querySelector('div:last-child').style.fontWeight = '700';
                            document.getElementById('msai-custom-colors').style.display = (this.value === 'custom') ? '' : 'none';
                        });
                    });
                    // Hover effect on swatches
                    document.querySelectorAll('.msai-palette-swatch').forEach(function(s){
                        s.addEventListener('mouseenter', function(){ this.style.transform = 'scale(1.08)'; });
                        s.addEventListener('mouseleave', function(){ this.style.transform = ''; });
                    });
                    // Font preview
                    var fontSel = document.getElementById('msai_font_select');
                    var sizeSel = document.getElementById('msai_font_size_select');
                    var styleSel = document.getElementById('msai_font_style_select');
                    var preview = document.getElementById('msai-font-preview');
                    function updateFontPreview(){
                        var f = fontSel.value;
                        preview.style.fontFamily = "'" + f + "', system-ui, -apple-system, sans-serif";
                        preview.style.fontSize = sizeSel.value + 'px';
                        var st = styleSel.value;
                        preview.style.fontWeight = (st === 'bold') ? '700' : '400';
                        preview.style.fontStyle = (st === 'italic') ? 'italic' : 'normal';
                    }
                    fontSel.addEventListener('change', updateFontPreview);
                    sizeSel.addEventListener('change', updateFontPreview);
                    styleSel.addEventListener('change', updateFontPreview);
                    updateFontPreview();
                })();<?php
wp_add_inline_script('bootflow-shop-assist-admin', ob_get_clean());
?>

                <hr>

                <!-- White-label / Branding -->
                <h2>🏷️ <?php echo esc_html(bootshas_t('admin_wl_title')); ?></h2>
                <p><?php echo esc_html(bootshas_t('admin_wl_desc')); ?></p>

                <table class="form-table">
                    <tr>
                        <th><?php echo esc_html(bootshas_t('admin_wl_name')); ?></th>
                        <td>
                            <input type="text" name="bootshas_wl_name" value="<?php echo esc_attr(get_option('bootshas_wl_name', '')); ?>" style="width:300px" placeholder="<?php echo esc_attr(bootshas_t('admin_wl_name_ph')); ?>" />
                            <p class="description"><?php echo esc_html(bootshas_t('admin_wl_name_desc')); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html(bootshas_t('admin_wl_icon')); ?></th>
                        <td>
                            <input type="text" name="bootshas_wl_icon" value="<?php echo esc_attr(get_option('bootshas_wl_icon', '')); ?>" style="width:120px" placeholder="💬" />
                            <p class="description"><?php echo esc_html(bootshas_t('admin_wl_icon_desc')); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html(bootshas_t('admin_wl_welcome')); ?></th>
                        <td>
                            <input type="text" name="bootshas_wl_welcome" value="<?php echo esc_attr(get_option('bootshas_wl_welcome', '')); ?>" style="width:100%;max-width:500px" placeholder="<?php echo esc_attr(bootshas_t('admin_wl_welcome_ph')); ?>" />
                            <p class="description"><?php echo esc_html(bootshas_t('admin_wl_welcome_desc')); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html(bootshas_t('admin_wl_admin_name')); ?></th>
                        <td>
                            <input type="text" name="bootshas_wl_admin_name" value="<?php echo esc_attr(get_option('bootshas_wl_admin_name', '')); ?>" style="width:300px" placeholder="Bootflow Shop Assist" />
                            <p class="description"><?php echo esc_html(bootshas_t('admin_wl_admin_name_desc')); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo esc_html(bootshas_t('admin_wl_powered_by')); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="bootshas_wl_powered_by" value="1" <?php checked(get_option('bootshas_wl_powered_by', '0'), '1'); ?> />
                                <?php echo esc_html(bootshas_t('admin_wl_powered_by_desc')); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <?php
            /**
             * Hook: bootflow_shop_assist_settings_after_form
             * Add-ons can inject extra settings UI.
             */
            do_action('bootflow_shop_assist_settings_after_form');

            ?>

            <div style="margin-top:20px;padding:12px 16px;background:#f0f6fc;border:1px solid #c3c4c7;border-left:4px solid #72aee6;">
                <p><strong><?php echo esc_html(bootshas_t('admin_product_data')); ?></strong></p>
                <ul>
                    <li>JSON <?php echo esc_html(bootshas_t('admin_file_label')); ?> <?php echo wp_kses($products_json_url ? '<span style="color: green;">' . esc_html(bootshas_t('admin_exported')) . '</span>' : '<span style="color: red;">' . esc_html(bootshas_t('admin_not_exported')) . '</span>', ['span' => ['style' => []]]); ?></li>
                    <?php if ($products_export_time): ?>
                        <li><?php echo esc_html(bootshas_t('admin_last_export')); ?> <?php echo esc_html(gmdate('Y-m-d H:i:s', (int) $products_export_time)); ?></li>
                    <?php endif; ?>
                    <?php
                    $json_path = get_option('bootshas_products_json_path');
                    if ($json_path && file_exists($json_path)) {
                        $json_data = json_decode(file_get_contents($json_path), true);
                        $product_count = is_array($json_data) ? count($json_data) : 0;
                        $file_size = round(filesize($json_path) / 1048576, 2);
                        echo '<li>' . esc_html(sprintf(bootshas_t('admin_products_info'), $product_count, $file_size)) . '</li>';
                    }
                    ?>
                </ul>

                <p><strong><?php echo esc_html(bootshas_t('admin_voice_notes')); ?></strong></p>
                <ul>
                    <li><strong><?php echo esc_html(bootshas_t('admin_browser_speech')); ?></strong> Chrome, Edge, Safari (<?php echo esc_html(bootshas_t('admin_requires_https')); ?>)</li>
                    <li><strong><?php echo esc_html(bootshas_t('admin_mic_permissions')); ?></strong> <?php echo esc_html(bootshas_t('admin_mic_first_time')); ?></li>
                    <li><strong><?php echo esc_html(bootshas_t('admin_language_support')); ?></strong> <?php echo esc_html(bootshas_t('admin_languages_list')); ?></li>
                    <li><strong><?php echo esc_html(bootshas_t('admin_if_not_working')); ?></strong> <?php echo esc_html(bootshas_t('admin_check_console')); ?> <code>debugSpeechRecognition()</code></li>
                </ul>
            </div>

            <hr>

            <!-- Import / Export Settings -->
            <h2>📦 <?php echo esc_html(bootshas_t('admin_ie_title')); ?></h2>
            <p><?php echo esc_html(bootshas_t('admin_ie_desc')); ?></p>

            <?php
            // Show import result notice
            $import_notice_nonce = isset($_GET['msai_notice_nonce']) ? sanitize_text_field(wp_unslash((string) $_GET['msai_notice_nonce'])) : '';
            if (isset($_GET['msai_import']) && wp_verify_nonce($import_notice_nonce, 'bootshas_notice')) {
                $status = sanitize_text_field(wp_unslash((string) $_GET['msai_import']));
                if ($status === 'success') {
                    $cnt = isset($_GET['msai_count']) ? intval(wp_unslash((string) $_GET['msai_count'])) : 0;
                    echo '<div class="notice notice-success is-dismissible"><p>✅ ' . esc_html(sprintf(bootshas_t('admin_ie_import_ok'), $cnt)) . '</p></div>';
                } elseif ($status === 'no_file') {
                    echo '<div class="notice notice-error is-dismissible"><p>❌ ' . esc_html(bootshas_t('admin_ie_no_file')) . '</p></div>';
                } elseif ($status === 'invalid') {
                    echo '<div class="notice notice-error is-dismissible"><p>❌ ' . esc_html(bootshas_t('admin_ie_invalid')) . '</p></div>';
                }
            }
            ?>

            <div style="display:flex;gap:24px;flex-wrap:wrap;margin-top:12px;">
                <!-- Export -->
                <div style="flex:1;min-width:280px;padding:16px;background:#f9f9f9;border:1px solid #ddd;border-radius:8px;">
                    <h3 style="margin-top:0;">⬇️ <?php echo esc_html(bootshas_t('admin_ie_export')); ?></h3>
                    <p style="font-size:13px;color:#666;"><?php echo esc_html(bootshas_t('admin_ie_export_desc')); ?></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('bootshas_export_settings'); ?>
                        <input type="hidden" name="action" value="bootshas_export_settings">
                        <button type="submit" class="button button-primary">⬇️ <?php echo esc_html(bootshas_t('admin_ie_export_btn')); ?></button>
                    </form>
                </div>

                <!-- Import -->
                <div style="flex:1;min-width:280px;padding:16px;background:#fff8f0;border:1px solid #f0d0a0;border-radius:8px;">
                    <h3 style="margin-top:0;">⬆️ <?php echo esc_html(bootshas_t('admin_ie_import')); ?></h3>
                    <p style="font-size:13px;color:#666;"><?php echo esc_html(bootshas_t('admin_ie_import_desc')); ?></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                        <?php wp_nonce_field('bootshas_import_settings'); ?>
                        <input type="hidden" name="action" value="bootshas_import_settings">
                        <input type="file" name="settings_file" accept=".json" required style="margin-bottom:8px;">
                        <br>
                        <button type="submit" class="button" onclick="return confirm('<?php echo esc_js(bootshas_t('admin_ie_confirm')); ?>');">⬆️ <?php echo esc_html(bootshas_t('admin_ie_import_btn')); ?></button>
                    </form>
                </div>
            </div>

            <hr>

            <h2><?php echo esc_html(bootshas_t('admin_content_export')); ?></h2>
            <p><?php echo esc_html(bootshas_t('admin_export_desc')); ?></p>
            <p><em><?php echo esc_html(bootshas_t('admin_export_auto')); ?></em></p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('export_products'); ?>
                <input type="hidden" name="action" value="export_products">
                <p>
                    <input type="submit" class="button button-primary" value="<?php echo esc_attr(bootshas_t('admin_export_button')); ?>">
                </p>
            </form>

            <?php if ($products_json_url): ?>
                <p><strong><?php echo esc_html(bootshas_t('admin_json_available')); ?></strong> <a href="<?php echo esc_url($products_json_url); ?>" target="_blank"><?php echo esc_url($products_json_url); ?></a></p>
            <?php endif; ?>
        <?php
    }

    /**
     * Export all plugin settings as a JSON file download.
     */
    public function handle_export_settings() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html(bootshas_t('admin_unauthorized')));
        }
        check_admin_referer('bootshas_export_settings');

        $option_keys = [
            'bootshas_language',
            'bootshas_excluded_tags',
            'bootshas_color_palette',
            'bootshas_custom_primary',
            'bootshas_custom_text',
            'bootshas_custom_bg',
            'bootshas_color_details',
            'bootshas_color_compare',
            'bootshas_color_cart',
            'bootshas_font',
            'bootshas_font_size',
            'bootshas_font_style',
            'bootshas_voice_mode',
            'bootshas_voice_silence',
            'bootshas_show_default_starters',
            'bootshas_gdpr_notice',
            'bootshas_auto_contact',
            'bootshas_handoff_enabled',
            'bootshas_handoff_context',
            'bootshas_handoff_methods',
            'bootshas_wl_name',
            'bootshas_wl_icon',
            'bootshas_wl_welcome',
            'bootshas_wl_admin_name',
            'bootshas_wl_powered_by',
            'bootshas_custom_responses',
            'bootshas_starter_questions',
        ];

        /**
         * Filter: bootflow_shop_assist_export_option_keys
         * Add-ons can append keys to the export list.
         */
        $option_keys = apply_filters('bootflow_shop_assist_export_option_keys', $option_keys);

        $data = [
            'plugin'     => 'bootflow_shop_assist_for_woocommerce',
            'version'    => defined('BOOTFLOW_SHOP_ASSIST_VERSION') ? BOOTFLOW_SHOP_ASSIST_VERSION : '1.0.0',
            'exported'   => gmdate('Y-m-d H:i:s'),
            'site'       => get_site_url(),
            'settings'   => [],
        ];

        foreach ($option_keys as $key) {
            $val = get_option($key);
            if ($val !== false) {
                $data['settings'][$key] = $val;
            }
        }

        $filename = 'bootflow-shop-assist-settings-' . gmdate('Y-m-d') . '.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store');
        echo wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Sanitize imported option values by known key to avoid unsafe direct writes.
     *
     * @param string $key Option key.
     * @param mixed  $value Raw imported value.
     * @return mixed
     */
    private function sanitize_imported_setting($key, $value) {
        switch ($key) {
            case 'bootshas_language':
                $lang = sanitize_key((string) $value);
                $allowed = ['auto', 'lv', 'en', 'de', 'ru', 'lt', 'et', 'es', 'fr'];
                return in_array($lang, $allowed, true) ? $lang : 'en';

            case 'bootshas_color_palette':
                $palette = sanitize_key((string) $value);
                $allowed = ['indigo', 'blue', 'emerald', 'rose', 'amber', 'slate', 'custom'];
                return in_array($palette, $allowed, true) ? $palette : 'indigo';

            case 'bootshas_font_size':
                $size = (string) $value;
                $allowed = ['12', '13', '14', '15', '16', '17', '18'];
                return in_array($size, $allowed, true) ? $size : '14';

            case 'bootshas_font_style':
                $style = sanitize_key((string) $value);
                $allowed = ['normal', 'bold', 'italic'];
                return in_array($style, $allowed, true) ? $style : 'normal';

            case 'bootshas_voice_mode':
                $mode = sanitize_key((string) $value);
                $allowed = ['manual', 'delayed', 'instant'];
                return in_array($mode, $allowed, true) ? $mode : 'delayed';

            case 'bootshas_voice_silence':
                $silence = intval($value);
                return max(2, min(15, $silence));

            case 'bootshas_show_default_starters':
            case 'bootshas_auto_contact':
            case 'bootshas_handoff_enabled':
            case 'bootshas_handoff_context':
            case 'bootshas_wl_powered_by':
                return !empty($value) ? '1' : '0';

            case 'bootshas_custom_primary':
            case 'bootshas_custom_text':
            case 'bootshas_custom_bg':
            case 'bootshas_color_details':
            case 'bootshas_color_compare':
            case 'bootshas_color_cart':
                return sanitize_hex_color((string) $value) ?: '';

            case 'bootshas_gdpr_notice':
                return wp_kses_post((string) $value);

            case 'bootshas_handoff_methods':
                if (is_string($value)) {
                    $decoded = json_decode(wp_unslash($value), true);
                } else {
                    $decoded = $value;
                }

                if (!is_array($decoded)) {
                    return '[]';
                }

                $clean = [];
                $allowed_types = ['email', 'whatsapp', 'telegram', 'facebook', 'instagram', 'tiktok', 'custom'];
                foreach ($decoded as $method) {
                    if (!is_array($method)) {
                        continue;
                    }

                    $type = sanitize_text_field($method['type'] ?? '');
                    $method_value = sanitize_text_field($method['value'] ?? '');
                    if ($type === '' || $method_value === '' || !in_array($type, $allowed_types, true)) {
                        continue;
                    }

                    $clean[] = [
                        'type'  => $type,
                        'value' => $method_value,
                        'label' => sanitize_text_field($method['label'] ?? ''),
                    ];
                }

                return wp_json_encode($clean);

            case 'bootshas_custom_responses':
                if (!is_array($value)) {
                    return [];
                }

                $items = [];
                foreach ($value as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $keywords = trim(sanitize_text_field($item['keywords'] ?? ''));
                    $response = trim(wp_kses_post($item['response'] ?? ''));
                    if ($keywords === '' || $response === '') {
                        continue;
                    }
                    $items[] = [
                        'keywords' => $keywords,
                        'response' => $response,
                    ];
                }

                return $items;

            case 'bootshas_starter_questions':
                if (!is_array($value)) {
                    return [];
                }

                $items = [];
                foreach ($value as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $question = trim(sanitize_text_field($item['question'] ?? ''));
                    if ($question === '') {
                        continue;
                    }

                    $type = sanitize_key((string) ($item['type'] ?? 'text'));
                    if ($type === 'ai' || $type === 'auto') {
                        $type = 'text';
                    }
                    if (!in_array($type, ['text', 'search', 'faq'], true)) {
                        $type = 'text';
                    }

                    $clean_item = [
                        'question' => $question,
                        'type'     => $type,
                    ];

                    if ($type === 'text') {
                        $clean_item['text'] = trim(wp_kses_post($item['text'] ?? ''));
                    } elseif ($type === 'search') {
                        $search_mode = sanitize_key((string) ($item['search_mode'] ?? 'keyword'));
                        if (!in_array($search_mode, ['keyword', 'category', 'sale', 'new'], true)) {
                            $search_mode = 'keyword';
                        }

                        $clean_item['search_mode'] = $search_mode;
                        $clean_item['search_keyword'] = trim(sanitize_text_field($item['search_keyword'] ?? ''));
                        $clean_item['search_text'] = trim(wp_kses_post($item['search_text'] ?? ''));

                        $raw_cats = is_array($item['search_cats'] ?? null) ? $item['search_cats'] : [];
                        $clean_item['search_cats'] = array_values(array_filter(array_map('sanitize_text_field', $raw_cats)));

                        $search_products = [];
                        $raw_products = is_array($item['search_products'] ?? null) ? $item['search_products'] : [];
                        foreach ($raw_products as $product) {
                            if (is_array($product)) {
                                $pid = absint($product['id'] ?? 0);
                                $ptitle = sanitize_text_field($product['title'] ?? '');
                            } else {
                                $pid = absint($product);
                                $ptitle = '';
                            }

                            if ($pid <= 0) {
                                continue;
                            }

                            if ($ptitle === '') {
                                $ptitle = get_the_title($pid);
                                $ptitle = $ptitle ? $ptitle : '';
                            }

                            $search_products[] = [
                                'id'    => $pid,
                                'title' => sanitize_text_field($ptitle),
                            ];
                        }
                        $clean_item['search_products'] = $search_products;
                    } elseif ($type === 'faq') {
                        $clean_item['faq_page'] = absint($item['faq_page'] ?? 0);
                        $clean_item['faq_text'] = trim(wp_kses_post($item['faq_text'] ?? ''));
                    }

                    $items[] = $clean_item;
                }

                return $items;

            case 'bootshas_excluded_tags':
            case 'bootshas_font':
            case 'bootshas_wl_name':
            case 'bootshas_wl_icon':
            case 'bootshas_wl_welcome':
            case 'bootshas_wl_admin_name':
                return sanitize_text_field((string) $value);
        }

        // Preserve compatibility with add-on keys while allowing external sanitization.
        return apply_filters('bootflow_shop_assist_sanitize_imported_setting', $value, $key);
    }

    /**
     * Import plugin settings from an uploaded JSON file.
     */
    public function handle_import_settings() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html(bootshas_t('admin_unauthorized')));
        }
        check_admin_referer('bootshas_import_settings');

        $redirect = admin_url('admin.php?page=bootflow-shop-assist');
        $notice_nonce = wp_create_nonce('bootshas_notice');

        if (empty($_FILES['settings_file']) || !is_array($_FILES['settings_file'])) {
            wp_safe_redirect(add_query_arg(['msai_import' => 'no_file', 'msai_notice_nonce' => $notice_nonce], $redirect));
            exit;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- file array is validated and each used field is sanitized below
        $file = wp_unslash($_FILES['settings_file']);
        $tmp_name = isset($file['tmp_name']) ? sanitize_text_field((string) $file['tmp_name']) : '';
        $file_error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        $file_size = isset($file['size']) ? (int) $file['size'] : 0;

        if ($tmp_name === '' || $file_error !== UPLOAD_ERR_OK || $file_size > 524288 || !is_uploaded_file($tmp_name)) { // max 512KB
            wp_safe_redirect(add_query_arg(['msai_import' => 'invalid', 'msai_notice_nonce' => $notice_nonce], $redirect));
            exit;
        }

        $content = file_get_contents($tmp_name);
        $data = json_decode($content, true);

        if (!is_array($data) || empty($data['plugin']) || (string) $data['plugin'] !== 'bootflow_shop_assist_for_woocommerce' || empty($data['settings'])) {
            wp_safe_redirect(add_query_arg(['msai_import' => 'invalid', 'msai_notice_nonce' => $notice_nonce], $redirect));
            exit;
        }

        $allowed_keys = [
            'bootshas_language',
            'bootshas_excluded_tags',
            'bootshas_color_palette',
            'bootshas_custom_primary',
            'bootshas_custom_text',
            'bootshas_custom_bg',
            'bootshas_color_details',
            'bootshas_color_compare',
            'bootshas_color_cart',
            'bootshas_font',
            'bootshas_font_size',
            'bootshas_font_style',
            'bootshas_voice_mode',
            'bootshas_voice_silence',
            'bootshas_show_default_starters',
            'bootshas_gdpr_notice',
            'bootshas_auto_contact',
            'bootshas_handoff_enabled',
            'bootshas_handoff_context',
            'bootshas_handoff_methods',
            'bootshas_wl_name',
            'bootshas_wl_icon',
            'bootshas_wl_welcome',
            'bootshas_wl_admin_name',
            'bootshas_wl_powered_by',
            'bootshas_custom_responses',
            'bootshas_starter_questions',
        ];

        /**
         * Filter: bootflow_shop_assist_import_allowed_keys
         * Add-ons can append keys to the import allowed list.
         */
        $allowed_keys = apply_filters('bootflow_shop_assist_import_allowed_keys', $allowed_keys);

        $count = 0;
        foreach ($data['settings'] as $key => $value) {
            if (in_array($key, $allowed_keys, true)) {
                $sanitized_value = $this->sanitize_imported_setting($key, $value);
                update_option($key, $sanitized_value);
                $count++;
            }
        }

        wp_safe_redirect(add_query_arg(['msai_import' => 'success', 'msai_count' => $count, 'msai_notice_nonce' => $notice_nonce], $redirect));
        exit;
    }

    public function handle_save_custom_responses() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html(bootshas_t('admin_no_permission')));
        }
        check_admin_referer('save_custom_responses');

        $keywords = isset($_POST['cr_keywords']) ? array_map('sanitize_text_field', wp_unslash((array) $_POST['cr_keywords'])) : [];
        $responses = isset($_POST['cr_response']) ? array_map('wp_kses_post', wp_unslash((array) $_POST['cr_response'])) : [];

        $items = [];
        foreach ($keywords as $i => $kw) {
            $kw = trim($kw);
            $resp = isset($responses[$i]) ? trim($responses[$i]) : '';
            if ($kw !== '' && $resp !== '') {
                $items[] = [
                    'keywords' => $kw,
                    'response' => $resp,
                ];
            }
        }

        update_option('bootshas_custom_responses', $items);

        wp_safe_redirect(add_query_arg([
            'page' => 'bootflow-shop-assist-responses',
            'saved' => '1',
            'msai_notice_nonce' => wp_create_nonce('bootshas_notice'),
        ], admin_url('admin.php')));
        exit;
    }

    private function render_custom_responses_tab() {
        $items = get_option('bootshas_custom_responses', []);
        if (!is_array($items)) $items = [];
        ?><?php
ob_start();
?>
            .msai-cr-wrap { max-width: 900px; margin-top: 16px; }
            .msai-cr-item {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 6px;
                padding: 16px;
                margin-bottom: 14px;
                position: relative;
            }
            .msai-cr-item:hover { border-color: #2271b1; }
            .msai-cr-item label { display: block; font-weight: 600; margin-bottom: 4px; font-size: 13px; }
            .msai-cr-keywords {
                width: 100%;
                padding: 8px 10px;
                border: 1px solid #8c8f94;
                border-radius: 4px;
                font-size: 14px;
                margin-bottom: 10px;
            }
            .msai-cr-response {
                width: 100%;
                min-height: 120px;
                padding: 10px;
                border: 1px solid #8c8f94;
                border-radius: 4px;
                font-family: monospace;
                font-size: 13px;
                resize: vertical;
            }
            .msai-cr-remove {
                position: absolute;
                top: 10px;
                right: 10px;
                background: #d63638;
                color: #fff;
                border: none;
                border-radius: 4px;
                padding: 4px 10px;
                cursor: pointer;
                font-size: 12px;
            }
            .msai-cr-remove:hover { background: #a02224; }
            .msai-cr-add {
                background: #2271b1;
                color: #fff;
                border: none;
                border-radius: 4px;
                padding: 8px 18px;
                cursor: pointer;
                font-size: 14px;
                margin-top: 6px;
            }
            .msai-cr-add:hover { background: #135e96; }
            .msai-cr-num {
                display: inline-block;
                background: #2271b1;
                color: #fff;
                border-radius: 50%;
                width: 22px;
                height: 22px;
                text-align: center;
                line-height: 22px;
                font-size: 12px;
                font-weight: 700;
                margin-right: 6px;
            }<?php
wp_add_inline_style('bootflow-shop-assist-admin-css', ob_get_clean());
?>

        <div class="msai-cr-wrap">
            <h2><?php echo esc_html(bootshas_t('admin_tab_responses')); ?></h2>
            <p><?php echo esc_html(bootshas_t('admin_cr_description')); ?><br>
            <strong><?php echo esc_html(bootshas_t('admin_cr_keywords_label')); ?></strong> — <?php echo esc_html(bootshas_t('admin_cr_keywords_hint')); ?> (<code><?php echo esc_html(bootshas_t('admin_cr_placeholder_kw')); ?></code>). <strong><?php echo esc_html(bootshas_t('admin_cr_response_label')); ?></strong> — <?php echo esc_html(bootshas_t('admin_cr_response_hint')); ?>.</p>

            <?php
            $cr_saved_notice_nonce = isset($_GET['msai_notice_nonce']) ? sanitize_text_field(wp_unslash((string) $_GET['msai_notice_nonce'])) : '';
            if (isset($_GET['saved']) && wp_verify_nonce($cr_saved_notice_nonce, 'bootshas_notice')):
            ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html(bootshas_t('admin_cr_saved')); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="msai-cr-form">
                <?php wp_nonce_field('save_custom_responses'); ?>
                <input type="hidden" name="action" value="save_custom_responses">

                <div id="msai-cr-list">
                    <?php if (empty($items)): ?>
                    <div class="msai-cr-item" data-index="0">
                        <button type="button" class="msai-cr-remove" onclick="this.parentElement.remove(); msaiCrRenum();" title="<?php echo esc_attr(bootshas_t('admin_cr_delete')); ?>">✕ <?php echo esc_html(bootshas_t('admin_cr_delete')); ?></button>
                        <label><span class="msai-cr-num">1</span> <?php echo esc_html(bootshas_t('admin_cr_keywords_label')); ?></label>
                        <input type="text" name="cr_keywords[]" class="msai-cr-keywords" placeholder="<?php echo esc_attr(bootshas_t('admin_cr_placeholder_kw')); ?>">
                        <label><?php echo esc_html(bootshas_t('admin_cr_response_label')); ?></label>
                        <textarea name="cr_response[]" class="msai-cr-response" placeholder="<?php echo esc_attr(bootshas_t('admin_cr_placeholder_resp')); ?>"></textarea>
                    </div>
                    <?php else: ?>
                        <?php foreach ($items as $i => $item): ?>
                        <div class="msai-cr-item" data-index="<?php echo (int) $i; ?>">
                            <button type="button" class="msai-cr-remove" onclick="this.parentElement.remove(); msaiCrRenum();" title="<?php echo esc_attr(bootshas_t('admin_cr_delete')); ?>">✕ <?php echo esc_html(bootshas_t('admin_cr_delete')); ?></button>
                            <label><span class="msai-cr-num"><?php echo (int) $i + 1; ?></span> <?php echo esc_html(bootshas_t('admin_cr_keywords_label')); ?></label>
                            <input type="text" name="cr_keywords[]" class="msai-cr-keywords" value="<?php echo esc_attr($item['keywords']); ?>">
                            <label><?php echo esc_html(bootshas_t('admin_cr_response_label')); ?></label>
                            <textarea name="cr_response[]" class="msai-cr-response"><?php echo esc_textarea($item['response']); ?></textarea>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <button type="button" class="msai-cr-add" onclick="msaiCrAdd()"><?php echo esc_html(bootshas_t('admin_cr_add_new')); ?></button>

                <p style="margin-top:16px">
                    <?php submit_button(bootshas_t('admin_cr_save'), 'primary', 'submit', false); ?>
                </p>
            </form>
        </div><?php
ob_start();
?>
        var msaiCrLabels = {
            del: <?php echo wp_json_encode(bootshas_t('admin_cr_delete')); ?>,
            kw:  <?php echo wp_json_encode(bootshas_t('admin_cr_keywords_label')); ?>,
            resp: <?php echo wp_json_encode(bootshas_t('admin_cr_response_label')); ?>
        };
        function msaiCrAdd() {
            var list = document.getElementById('msai-cr-list');
            var idx = list.children.length;
            var div = document.createElement('div');
            div.className = 'msai-cr-item';
            div.innerHTML = '<button type="button" class="msai-cr-remove" onclick="this.parentElement.remove(); msaiCrRenum();" title="' + msaiCrLabels.del + '">✕ ' + msaiCrLabels.del + '</button>'
                + '<label><span class="msai-cr-num">' + (idx + 1) + '</span> ' + msaiCrLabels.kw + '</label>'
                + '<input type="text" name="cr_keywords[]" class="msai-cr-keywords">'
                + '<label>' + msaiCrLabels.resp + '</label>'
                + '<textarea name="cr_response[]" class="msai-cr-response"></textarea>';
            list.appendChild(div);
            div.querySelector('input').focus();
        }
        function msaiCrRenum() {
            var items = document.querySelectorAll('#msai-cr-list .msai-cr-item');
            items.forEach(function(el, i) {
                var num = el.querySelector('.msai-cr-num');
                if (num) num.textContent = i + 1;
            });
        }<?php
wp_add_inline_script('bootflow-shop-assist-admin', ob_get_clean());
?>
        <?php
        // --- Starter Questions section ---
        $this->render_starter_questions_section();
    }

    public function ajax_admin_product_search() {
        if (!current_user_can('manage_options') || !check_ajax_referer('msai_admin_product_search', 'nonce', false)) {
            wp_send_json_error(bootshas_t('admin_unauthorized'));
            return;
        }
        $q = isset($_GET['q']) ? sanitize_text_field(wp_unslash((string) $_GET['q'])) : '';
        if (strlen($q) < 2) { wp_send_json_success([]); return; }

        $json_path = get_option('bootshas_products_json_path');
        if (!$json_path || !file_exists($json_path)) { wp_send_json_success([]); return; }

        $products = json_decode(file_get_contents($json_path), true);
        if (!is_array($products)) { wp_send_json_success([]); return; }

        $q_lower = mb_strtolower($q);
        $results = [];
        foreach ($products as $p) {
            if (($p['type'] ?? '') !== 'product') continue;
            if (empty($p['stock_status']) || $p['stock_status'] === 'outofstock') continue;
            $title = isset($p['title']) ? sanitize_text_field((string) $p['title']) : '';
            if (mb_strpos(mb_strtolower($title), $q_lower) !== false) {
                $results[] = [
                    'id' => absint($p['id'] ?? 0),
                    'title' => $title,
                    'price' => isset($p['price']) ? sanitize_text_field((string) $p['price']) : '',
                ];
                if (count($results) >= 15) break;
            }
        }
        wp_send_json_success($results);
    }

    public function handle_save_starter_questions() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html(bootshas_t('admin_unauthorized')));
        }
        check_admin_referer('save_starter_questions');

        $questions = isset($_POST['sq_question']) ? array_map('sanitize_text_field', wp_unslash((array) $_POST['sq_question'])) : [];
        $types     = isset($_POST['sq_type'])     ? array_map('sanitize_text_field', wp_unslash((array) $_POST['sq_type'])) : [];
        $texts     = isset($_POST['sq_text'])      ? array_map('wp_kses_post', wp_unslash((array) $_POST['sq_text'])) : [];
        $search_modes = isset($_POST['sq_search_mode']) ? array_map('sanitize_text_field', wp_unslash((array) $_POST['sq_search_mode'])) : [];
        $search_keywords = isset($_POST['sq_search_keyword']) ? array_map('sanitize_text_field', wp_unslash((array) $_POST['sq_search_keyword'])) : [];
        $search_cats = [];
        if (isset($_POST['sq_search_cats']) && is_array($_POST['sq_search_cats'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- values are sanitized per item immediately below
            foreach ((array) wp_unslash($_POST['sq_search_cats']) as $index => $cats) {
                $search_cats[(int) $index] = array_map('sanitize_text_field', (array) $cats);
            }
        }
        $search_texts = isset($_POST['sq_search_text']) ? array_map('wp_kses_post', wp_unslash((array) $_POST['sq_search_text'])) : [];
        $faq_pages = isset($_POST['sq_faq_page']) ? array_map('absint', (array) wp_unslash($_POST['sq_faq_page'])) : [];
        $faq_texts = isset($_POST['sq_faq_text']) ? array_map('wp_kses_post', wp_unslash((array) $_POST['sq_faq_text'])) : [];

        $items = [];
        foreach ($questions as $i => $q) {
            $q = trim($q);
            if ($q === '') continue;

            $type = $types[$i] ?? 'text';
            $item = ['question' => $q, 'type' => $type];

            if ($type === 'text') {
                $item['text'] = trim($texts[$i] ?? '');
            } elseif ($type === 'search') {
                $item['search_mode'] = $search_modes[$i] ?? 'keyword';
                $item['search_keyword'] = trim($search_keywords[$i] ?? '');
                $raw_cats = $search_cats[$i] ?? [];
                $item['search_cats'] = array_map('sanitize_text_field', is_array($raw_cats) ? $raw_cats : []);
                $item['search_text'] = trim($search_texts[$i] ?? '');
                // Specific products
                $raw_prod_ids = isset($_POST['sq_search_products'][$i]) ? array_map('absint', (array) wp_unslash($_POST['sq_search_products'][$i])) : [];
                $search_prods = [];
                foreach ($raw_prod_ids as $pid) {
                    if ($pid > 0) {
                        $title = get_the_title($pid);
                        if ($title) $search_prods[] = ['id' => $pid, 'title' => $title];
                    }
                }
                $item['search_products'] = $search_prods;
            } elseif ($type === 'faq') {
                $item['faq_page'] = $faq_pages[$i] ?? 0;
                $item['faq_text'] = trim($faq_texts[$i] ?? '');
            } else {
                $item['type'] = 'text';
                $item['text'] = trim($texts[$i] ?? '');
            }
            $items[] = $item;
        }

        update_option('bootshas_starter_questions', $items);
        wp_safe_redirect(add_query_arg([
            'page' => 'bootflow-shop-assist-responses',
            'sq_saved' => '1',
            'msai_notice_nonce' => wp_create_nonce('bootshas_notice'),
        ], admin_url('admin.php')));
        exit;
    }

    private function render_starter_questions_section() {
        $items = get_option('bootshas_starter_questions', []);
        if (!is_array($items)) $items = [];
        foreach ($items as &$item) {
            if (isset($item['type']) && in_array($item['type'], ['ai', 'auto'], true)) {
                $item['type'] = 'text';
            }
        }
        unset($item);

        // Get published WP pages for FAQ type dropdown
        $wp_pages = get_pages(['sort_column' => 'post_title', 'post_status' => 'publish']);

        // Get WooCommerce categories for dropdown (hierarchical: top-level + direct children only)
        $wc_cats_grouped = [];
        $wc_cats_flat = [];
        if (taxonomy_exists('product_cat')) {
            $tops = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'parent' => 0, 'orderby' => 'name']);
            if (!is_wp_error($tops)) {
                foreach ($tops as $top) {
                    if ($top->slug === 'bez-kategorijas' || $top->slug === 'uncategorized') continue;
                    $children = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'parent' => $top->term_id, 'orderby' => 'name']);
                    $kids = [];
                    if (!is_wp_error($children)) {
                        foreach ($children as $ch) {
                            $kids[] = ['slug' => $ch->slug, 'name' => $ch->name];
                            $wc_cats_flat[$ch->slug] = $ch->name;
                        }
                    }
                    $wc_cats_flat[$top->slug] = $top->name;
                    $wc_cats_grouped[] = ['name' => $top->name, 'slug' => $top->slug, 'children' => $kids];
                }
            }
        }
        ?><?php
ob_start();
?>
            .msai-sq-wrap { max-width: 900px; margin-top: 30px; }
            .msai-sq-item {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 6px;
                padding: 16px;
                margin-bottom: 14px;
                position: relative;
            }
            .msai-sq-item:hover { border-color: #2271b1; }
            .msai-sq-item label { display: block; font-weight: 600; margin-bottom: 4px; font-size: 13px; }
            .msai-sq-question {
                width: 100%;
                padding: 8px 10px;
                border: 1px solid #8c8f94;
                border-radius: 4px;
                font-size: 14px;
                margin-bottom: 10px;
            }
            .msai-sq-type-select {
                padding: 6px 10px;
                border: 1px solid #8c8f94;
                border-radius: 4px;
                font-size: 13px;
                margin-bottom: 12px;
            }
            .msai-sq-block { margin-top: 8px; padding: 10px; background: #f9f9f9; border: 1px solid #e2e4e7; border-radius: 4px; }
            .msai-sq-block textarea, .msai-sq-block input[type="text"] {
                width: 100%;
                padding: 8px 10px;
                border: 1px solid #8c8f94;
                border-radius: 4px;
                font-size: 13px;
                margin-bottom: 6px;
            }
            .msai-sq-block textarea { min-height: 80px; resize: vertical; font-family: monospace; }
            .msai-sq-remove {
                position: absolute;
                top: 10px;
                right: 10px;
                background: #d63638;
                color: #fff;
                border: none;
                border-radius: 4px;
                padding: 4px 10px;
                cursor: pointer;
                font-size: 12px;
            }
            .msai-sq-remove:hover { background: #a02224; }
            .msai-sq-add {
                background: #2271b1;
                color: #fff;
                border: none;
                border-radius: 4px;
                padding: 8px 18px;
                cursor: pointer;
                font-size: 14px;
                margin-top: 6px;
            }
            .msai-sq-add:hover { background: #135e96; }
            .msai-sq-num {
                display: inline-block;
                background: #00a32a;
                color: #fff;
                border-radius: 50%;
                width: 22px;
                height: 22px;
                text-align: center;
                line-height: 22px;
                font-size: 12px;
                font-weight: 700;
                margin-right: 6px;
            }
            .msai-sq-radio-group { display: flex; gap: 16px; margin-bottom: 8px; }
            .msai-sq-radio-group label { display: inline-flex; align-items: center; gap: 4px; font-weight: normal; cursor: pointer; }
            .msai-sq-cat-list { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
            .msai-sq-cat-tag {
                display: inline-flex;
                align-items: center;
                background: #e7f3ff;
                border: 1px solid #72aee6;
                border-radius: 12px;
                padding: 2px 10px;
                font-size: 12px;
            }
            .msai-sq-cat-tag .msai-sq-cat-remove { cursor: pointer; margin-left: 6px; color: #d63638; font-weight: bold; }
            .msai-sq-prod-search-wrap { position: relative; margin-bottom: 8px; }
            .msai-sq-prod-results { position: absolute; z-index: 100; background: #fff; border: 1px solid #8c8f94; border-top: 0; max-height: 200px; overflow-y: auto; width: 100%; display: none; }
            .msai-sq-prod-results:not(:empty) { display: block; }
            .msai-sq-prod-result-item { padding: 6px 10px; cursor: pointer; font-size: 13px; border-bottom: 1px solid #f0f0f0; }
            .msai-sq-prod-result-item:hover { background: #e7f3ff; }
            .msai-sq-prod-list { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }<?php
wp_add_inline_style('bootflow-shop-assist-admin-css', ob_get_clean());
?>

        <div class="msai-sq-wrap">
            <hr style="margin: 20px 0;">
            <h2><?php echo esc_html(bootshas_t('admin_sq_title')); ?></h2>
            <p><?php echo esc_html(bootshas_t('admin_sq_description')); ?></p>

            <?php
            $sq_saved_notice_nonce = isset($_GET['msai_notice_nonce']) ? sanitize_text_field(wp_unslash((string) $_GET['msai_notice_nonce'])) : '';
            if (isset($_GET['sq_saved']) && wp_verify_nonce($sq_saved_notice_nonce, 'bootshas_notice')):
            ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html(bootshas_t('admin_sq_saved')); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="msai-sq-form">
                <?php wp_nonce_field('save_starter_questions'); ?>
                <input type="hidden" name="action" value="save_starter_questions">

                <div id="msai-sq-list">
                    <?php if (empty($items)): ?>
                        <p style="color:#666;font-style:italic;"><?php echo esc_html(bootshas_t('admin_sq_empty')); ?></p>
                    <?php else: ?>
                        <?php foreach ($items as $i => $item): ?>
                        <div class="msai-sq-item" data-index="<?php echo (int) $i; ?>">
                            <button type="button" class="msai-sq-remove" onclick="this.parentElement.remove(); msaiSqRenum();">✕</button>
                            <label><span class="msai-sq-num"><?php echo (int) $i + 1; ?></span> <?php echo esc_html(bootshas_t('admin_sq_question_label')); ?></label>
                            <input type="text" name="sq_question[]" class="msai-sq-question" value="<?php echo esc_attr($item['question']); ?>" placeholder="<?php echo esc_attr(bootshas_t('admin_sq_question_ph')); ?>">

                            <label><?php echo esc_html(bootshas_t('admin_sq_type_label')); ?></label>
                            <select name="sq_type[]" class="msai-sq-type-select" onchange="msaiSqToggle(this)">
                                <option value="text" <?php selected($item['type'], 'text'); ?>><?php echo esc_html(bootshas_t('admin_sq_type_text')); ?></option>
                                <option value="search" <?php selected($item['type'], 'search'); ?>><?php echo esc_html(bootshas_t('admin_sq_type_search')); ?></option>
                                <option value="faq" <?php selected($item['type'], 'faq'); ?>><?php echo esc_html(bootshas_t('admin_sq_type_faq')); ?></option>
                            </select>

                            <!-- Text/HTML block -->
                            <div class="msai-sq-block msai-sq-b-text" style="<?php echo esc_attr($item['type'] !== 'text' ? 'display:none' : ''); ?>">
                                <label><?php echo esc_html(bootshas_t('admin_sq_text_label')); ?></label>
                                <textarea name="sq_text[]"><?php echo esc_textarea($item['text'] ?? ''); ?></textarea>
                            </div>

                            <!-- Product search block -->
                            <div class="msai-sq-block msai-sq-b-search" style="<?php echo esc_attr($item['type'] !== 'search' ? 'display:none' : ''); ?>">
                                <label><?php echo esc_html(bootshas_t('admin_sq_search_text_label')); ?></label>
                                <input type="text" name="sq_search_text[]" value="<?php echo esc_attr($item['search_text'] ?? ''); ?>" placeholder="<?php echo esc_attr(bootshas_t('admin_sq_search_text_ph')); ?>">

                                <label><?php echo esc_html(bootshas_t('admin_sq_search_mode_label')); ?></label>
                                <div class="msai-sq-radio-group">
                                    <label><input type="radio" name="sq_search_mode[<?php echo (int) $i; ?>]" value="keyword" <?php checked(($item['search_mode'] ?? 'keyword'), 'keyword'); ?> onchange="msaiSqSearchMode(this)"> <?php echo esc_html(bootshas_t('admin_sq_mode_keyword')); ?></label>
                                    <label><input type="radio" name="sq_search_mode[<?php echo (int) $i; ?>]" value="category" <?php checked(($item['search_mode'] ?? ''), 'category'); ?> onchange="msaiSqSearchMode(this)"> <?php echo esc_html(bootshas_t('admin_sq_mode_category')); ?></label>
                                    <label><input type="radio" name="sq_search_mode[<?php echo (int) $i; ?>]" value="sale" <?php checked(($item['search_mode'] ?? ''), 'sale'); ?> onchange="msaiSqSearchMode(this)"> <?php echo esc_html(bootshas_t('admin_sq_mode_sale')); ?></label>
                                    <label><input type="radio" name="sq_search_mode[<?php echo (int) $i; ?>]" value="new" <?php checked(($item['search_mode'] ?? ''), 'new'); ?> onchange="msaiSqSearchMode(this)"> <?php echo esc_html(bootshas_t('admin_sq_mode_new')); ?></label>
                                </div>

                                <div class="msai-sq-kw-wrap" style="<?php echo esc_attr(($item['search_mode'] ?? 'keyword') !== 'keyword' ? 'display:none' : ''); ?>">
                                    <input type="text" name="sq_search_keyword[]" value="<?php echo esc_attr($item['search_keyword'] ?? ''); ?>" placeholder="<?php echo esc_attr(bootshas_t('admin_sq_keyword_ph')); ?>">
                                </div>

                                <div class="msai-sq-cat-wrap" style="<?php echo esc_attr(($item['search_mode'] ?? '') !== 'category' ? 'display:none' : ''); ?>">
                                    <div class="msai-sq-cat-list">
                                        <?php foreach (($item['search_cats'] ?? []) as $cat_slug): ?>
                                            <?php $cat_name = $wc_cats_flat[$cat_slug] ?? $cat_slug; ?>
                                            <span class="msai-sq-cat-tag"><?php echo esc_html($cat_name); ?><input type="hidden" name="sq_search_cats[<?php echo (int) $i; ?>][]" value="<?php echo esc_attr($cat_slug); ?>"><span class="msai-sq-cat-remove" onclick="this.parentElement.remove()">✕</span></span>
                                        <?php endforeach; ?>
                                    </div>
                                    <select class="msai-sq-cat-select" onchange="msaiSqAddCat(this, <?php echo (int) $i; ?>)">
                                        <option value=""><?php echo esc_html(bootshas_t('admin_sq_select_category')); ?></option>
                                        <?php foreach ($wc_cats_grouped as $group): ?>
                                            <optgroup label="<?php echo esc_attr($group['name']); ?>">
                                                <option value="<?php echo esc_attr($group['slug']); ?>"><?php echo esc_html($group['name']); ?> (<?php echo esc_html(bootshas_t('admin_sq_all_subcats')); ?>)</option>
                                                <?php foreach ($group['children'] as $ch): ?>
                                                    <option value="<?php echo esc_attr($ch['slug']); ?>"><?php echo esc_html($ch['name']); ?></option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <label><?php echo esc_html(bootshas_t('admin_sq_products_label')); ?></label>
                                <div class="msai-sq-prod-list">
                                    <?php foreach (($item['search_products'] ?? []) as $sp): ?>
                                        <span class="msai-sq-cat-tag"><?php echo esc_html($sp['title']); ?><input type="hidden" name="sq_search_products[<?php echo (int) $i; ?>][]" value="<?php echo esc_attr($sp['id']); ?>" data-title="<?php echo esc_attr($sp['title']); ?>"><span class="msai-sq-cat-remove" onclick="this.parentElement.remove()">✕</span></span>
                                    <?php endforeach; ?>
                                </div>
                                <div class="msai-sq-prod-search-wrap">
                                    <input type="text" class="msai-sq-prod-input" placeholder="<?php echo esc_attr(bootshas_t('admin_sq_products_ph')); ?>" oninput="msaiSqProdSearch(this, <?php echo (int) $i; ?>)" autocomplete="off">
                                    <div class="msai-sq-prod-results"></div>
                                </div>
                            </div>

                            <!-- FAQ / Page block -->
                            <div class="msai-sq-block msai-sq-b-faq" style="<?php echo esc_attr($item['type'] !== 'faq' ? 'display:none' : ''); ?>">
                                <label><?php echo esc_html(bootshas_t('admin_sq_faq_page_label')); ?></label>
                                <select name="sq_faq_page[]">
                                    <option value="0"><?php echo esc_html(bootshas_t('admin_sq_faq_page_none')); ?></option>
                                    <?php foreach ($wp_pages as $pg): ?>
                                        <option value="<?php echo esc_attr($pg->ID); ?>" <?php selected(($item['faq_page'] ?? 0), $pg->ID); ?>><?php echo esc_html($pg->post_title); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <label><?php echo esc_html(bootshas_t('admin_sq_faq_text_label')); ?></label>
                                <textarea name="sq_faq_text[]" placeholder="<?php echo esc_attr(bootshas_t('admin_sq_faq_text_ph')); ?>"><?php echo esc_textarea($item['faq_text'] ?? ''); ?></textarea>
                                <p class="description" style="margin-top:2px;font-size:12px;color:#646970;"><?php echo esc_html(bootshas_t('admin_sq_faq_text_desc')); ?></p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <button type="button" class="msai-sq-add" onclick="msaiSqAdd()"><?php echo esc_html(bootshas_t('admin_sq_add_new')); ?></button>

                <p style="margin-top:16px">
                    <?php submit_button(bootshas_t('admin_sq_save'), 'primary', 'submit', false); ?>
                </p>
            </form>
        </div><?php
ob_start();
?>
        var msaiSqLabels = {
            question: <?php echo wp_json_encode(bootshas_t('admin_sq_question_label')); ?>,
            question_ph: <?php echo wp_json_encode(bootshas_t('admin_sq_question_ph')); ?>,
            type: <?php echo wp_json_encode(bootshas_t('admin_sq_type_label')); ?>,
            type_text: <?php echo wp_json_encode(bootshas_t('admin_sq_type_text')); ?>,
            type_search: <?php echo wp_json_encode(bootshas_t('admin_sq_type_search')); ?>,
            type_faq: <?php echo wp_json_encode(bootshas_t('admin_sq_type_faq')); ?>,
            faq_page_label: <?php echo wp_json_encode(bootshas_t('admin_sq_faq_page_label')); ?>,
            faq_page_none: <?php echo wp_json_encode(bootshas_t('admin_sq_faq_page_none')); ?>,
            faq_text_label: <?php echo wp_json_encode(bootshas_t('admin_sq_faq_text_label')); ?>,
            faq_text_ph: <?php echo wp_json_encode(bootshas_t('admin_sq_faq_text_ph')); ?>,
            faq_text_desc: <?php echo wp_json_encode(bootshas_t('admin_sq_faq_text_desc')); ?>,
            text_label: <?php echo wp_json_encode(bootshas_t('admin_sq_text_label')); ?>,
            search_text: <?php echo wp_json_encode(bootshas_t('admin_sq_search_text_label')); ?>,
            search_text_ph: <?php echo wp_json_encode(bootshas_t('admin_sq_search_text_ph')); ?>,
            search_mode: <?php echo wp_json_encode(bootshas_t('admin_sq_search_mode_label')); ?>,
            mode_keyword: <?php echo wp_json_encode(bootshas_t('admin_sq_mode_keyword')); ?>,
            mode_category: <?php echo wp_json_encode(bootshas_t('admin_sq_mode_category')); ?>,
            mode_sale: <?php echo wp_json_encode(bootshas_t('admin_sq_mode_sale')); ?>,
            mode_new: <?php echo wp_json_encode(bootshas_t('admin_sq_mode_new')); ?>,
            keyword_ph: <?php echo wp_json_encode(bootshas_t('admin_sq_keyword_ph')); ?>,
            select_cat: <?php echo wp_json_encode(bootshas_t('admin_sq_select_category')); ?>,
            products_label: <?php echo wp_json_encode(bootshas_t('admin_sq_products_label')); ?>,
            products_ph: <?php echo wp_json_encode(bootshas_t('admin_sq_products_ph')); ?>
        };
        var msaiAdminAjax = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var msaiAdminNonce = <?php echo wp_json_encode(wp_create_nonce('msai_admin_product_search')); ?>;
        var msaiWpPages = <?php echo wp_json_encode(array_map(function($pg) { return ['id' => $pg->ID, 'title' => $pg->post_title]; }, $wp_pages)); ?>;
        var msaiWcCats = <?php echo wp_json_encode($wc_cats_grouped); ?>;
        var msaiWcCatsAllLabel = <?php echo wp_json_encode(bootshas_t('admin_sq_all_subcats')); ?>;

        function msaiSqToggle(sel) {
            var item = sel.closest('.msai-sq-item');
            item.querySelector('.msai-sq-b-text').style.display = sel.value === 'text' ? '' : 'none';
            item.querySelector('.msai-sq-b-search').style.display = sel.value === 'search' ? '' : 'none';
            item.querySelector('.msai-sq-b-faq').style.display = sel.value === 'faq' ? '' : 'none';
        }
        function msaiSqSearchMode(radio) {
            var block = radio.closest('.msai-sq-b-search');
            block.querySelector('.msai-sq-kw-wrap').style.display = radio.value === 'keyword' ? '' : 'none';
            block.querySelector('.msai-sq-cat-wrap').style.display = radio.value === 'category' ? '' : 'none';
        }
        function msaiSqAddCat(sel, idx) {
            if (!sel.value) return;
            var list = sel.closest('.msai-sq-cat-wrap').querySelector('.msai-sq-cat-list');
            // Check duplicate
            var existing = list.querySelectorAll('input[type=hidden]');
            for (var e = 0; e < existing.length; e++) { if (existing[e].value === sel.value) { sel.value = ''; return; } }
            var name = sel.options[sel.selectedIndex].text;
            var tag = document.createElement('span');
            tag.className = 'msai-sq-cat-tag';
            tag.appendChild(document.createTextNode(name));

            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'sq_search_cats[' + idx + '][]';
            hidden.value = sel.value;
            tag.appendChild(hidden);

            var removeBtn = document.createElement('span');
            removeBtn.className = 'msai-sq-cat-remove';
            removeBtn.textContent = '✕';
            removeBtn.onclick = function() { tag.remove(); };
            tag.appendChild(removeBtn);

            list.appendChild(tag);
            sel.value = '';
        }
        function msaiSqRenum() {
            var items = document.querySelectorAll('#msai-sq-list .msai-sq-item');
            items.forEach(function(el, i) {
                el.setAttribute('data-index', i);
                var num = el.querySelector('.msai-sq-num');
                if (num) num.textContent = i + 1;
                // Fix radio names and cat names for index
                el.querySelectorAll('input[type=radio]').forEach(function(r) {
                    r.name = r.name.replace(/\[\d+\]/, '[' + i + ']');
                });
                el.querySelectorAll('.msai-sq-cat-tag input[type=hidden]').forEach(function(h) {
                    h.name = h.name.replace(/\[\d+\]/, '[' + i + ']');
                });
                var catSel = el.querySelector('.msai-sq-b-search .msai-sq-cat-select');
                if (catSel) catSel.setAttribute('onchange', 'msaiSqAddCat(this, ' + i + ')');
                var prodInput = el.querySelector('.msai-sq-prod-input');
                if (prodInput) prodInput.setAttribute('oninput', 'msaiSqProdSearch(this, ' + i + ')');
            });
        }
        function msaiSqAdd() {
            var list = document.getElementById('msai-sq-list');
            // Remove empty message if present
            var emptyP = list.querySelector('p');
            if (emptyP) emptyP.remove();
            var idx = list.querySelectorAll('.msai-sq-item').length;
            var catOpts = '<option value="">' + msaiSqLabels.select_cat + '</option>';
            var faqPageOpts = '<option value="0">' + msaiSqLabels.faq_page_none + '</option>';
            msaiWpPages.forEach(function(pg) {
                faqPageOpts += '<option value="' + pg.id + '">' + pg.title.replace(/</g, '&lt;') + '</option>';
            });
            msaiWcCats.forEach(function(g) {
                catOpts += '<optgroup label="' + g.name + '">';
                catOpts += '<option value="' + g.slug + '">' + g.name + ' (' + msaiWcCatsAllLabel + ')</option>';
                g.children.forEach(function(c) { catOpts += '<option value="' + c.slug + '">' + c.name + '</option>'; });
                catOpts += '</optgroup>';
            });
            var div = document.createElement('div');
            div.className = 'msai-sq-item';
            div.setAttribute('data-index', idx);
            div.innerHTML = '<button type="button" class="msai-sq-remove" onclick="this.parentElement.remove(); msaiSqRenum();">✕</button>'
                + '<label><span class="msai-sq-num">' + (idx + 1) + '</span> ' + msaiSqLabels.question + '</label>'
                + '<input type="text" name="sq_question[]" class="msai-sq-question" placeholder="' + msaiSqLabels.question_ph + '">'
                + '<label>' + msaiSqLabels.type + '</label>'
                + '<select name="sq_type[]" class="msai-sq-type-select" onchange="msaiSqToggle(this)">'
                + '<option value="text">' + msaiSqLabels.type_text + '</option>'
                + '<option value="search">' + msaiSqLabels.type_search + '</option>'
                + '<option value="faq">' + msaiSqLabels.type_faq + '</option></select>'
                + '<div class="msai-sq-block msai-sq-b-text"><label>' + msaiSqLabels.text_label + '</label><textarea name="sq_text[]"></textarea></div>'
                + '<div class="msai-sq-block msai-sq-b-search" style="display:none">'
                + '<label>' + msaiSqLabels.search_text + '</label><input type="text" name="sq_search_text[]" placeholder="' + msaiSqLabels.search_text_ph + '">'
                + '<label>' + msaiSqLabels.search_mode + '</label>'
                + '<div class="msai-sq-radio-group">'
                + '<label><input type="radio" name="sq_search_mode[' + idx + ']" value="keyword" checked onchange="msaiSqSearchMode(this)"> ' + msaiSqLabels.mode_keyword + '</label>'
                + '<label><input type="radio" name="sq_search_mode[' + idx + ']" value="category" onchange="msaiSqSearchMode(this)"> ' + msaiSqLabels.mode_category + '</label>'
                + '<label><input type="radio" name="sq_search_mode[' + idx + ']" value="sale" onchange="msaiSqSearchMode(this)"> ' + msaiSqLabels.mode_sale + '</label>'
                + '<label><input type="radio" name="sq_search_mode[' + idx + ']" value="new" onchange="msaiSqSearchMode(this)"> ' + msaiSqLabels.mode_new + '</label></div>'
                + '<div class="msai-sq-kw-wrap"><input type="text" name="sq_search_keyword[]" placeholder="' + msaiSqLabels.keyword_ph + '"></div>'
                + '<div class="msai-sq-cat-wrap" style="display:none"><div class="msai-sq-cat-list"></div>'
                + '<select class="msai-sq-cat-select" onchange="msaiSqAddCat(this, ' + idx + ')">' + catOpts + '</select></div>'
                + '<label>' + msaiSqLabels.products_label + '</label>'
                + '<div class="msai-sq-prod-list"></div>'
                + '<div class="msai-sq-prod-search-wrap"><input type="text" class="msai-sq-prod-input" placeholder="' + msaiSqLabels.products_ph + '" oninput="msaiSqProdSearch(this, ' + idx + ')" autocomplete="off"><div class="msai-sq-prod-results"></div></div></div>'
                + '<div class="msai-sq-block msai-sq-b-faq" style="display:none">'
                + '<label>' + msaiSqLabels.faq_page_label + '</label>'
                + '<select name="sq_faq_page[]">' + faqPageOpts + '</select>'
                + '<label>' + msaiSqLabels.faq_text_label + '</label><textarea name="sq_faq_text[]" placeholder="' + msaiSqLabels.faq_text_ph + '"></textarea>'
                + '<p class="description" style="margin-top:2px;font-size:12px;color:#646970;">' + msaiSqLabels.faq_text_desc + '</p></div>';
            list.appendChild(div);
            div.querySelector('input').focus();
        }
        var msaiProdTimer = null;
        function msaiSqProdSearch(input, idx) {
            var q = input.value.trim();
            var wrap = input.closest('.msai-sq-prod-search-wrap');
            var results = wrap.querySelector('.msai-sq-prod-results');
            if (q.length < 2) { results.innerHTML = ''; return; }
            clearTimeout(msaiProdTimer);
            msaiProdTimer = setTimeout(function() {
                var xhr = new XMLHttpRequest();
                xhr.open('GET', msaiAdminAjax + '?action=bootshas_admin_product_search&nonce=' + msaiAdminNonce + '&q=' + encodeURIComponent(q));
                xhr.onload = function() {
                    if (xhr.status !== 200) return;
                    var data = JSON.parse(xhr.responseText);
                    if (!data.success) return;
                    results.innerHTML = '';
                    data.data.forEach(function(p) {
                        var div = document.createElement('div');
                        div.className = 'msai-sq-prod-result-item';
                        div.textContent = p.title + ' — ' + p.price + ' €';
                        div.onclick = function() {
                            var prodList = input.closest('.msai-sq-b-search').querySelector('.msai-sq-prod-list');
                            // Check duplicate
                            var existH = prodList.querySelectorAll('input[type=hidden]');
                            for (var e = 0; e < existH.length; e++) { if (existH[e].value == p.id) return; }
                            var tag = document.createElement('span');
                            tag.className = 'msai-sq-cat-tag';
                            tag.appendChild(document.createTextNode(p.title));

                            var hidden = document.createElement('input');
                            hidden.type = 'hidden';
                            hidden.name = 'sq_search_products[' + idx + '][]';
                            hidden.value = p.id;
                            hidden.setAttribute('data-title', p.title);
                            tag.appendChild(hidden);

                            var removeBtn = document.createElement('span');
                            removeBtn.className = 'msai-sq-cat-remove';
                            removeBtn.textContent = '✕';
                            removeBtn.onclick = function() { tag.remove(); };
                            tag.appendChild(removeBtn);

                            prodList.appendChild(tag);
                            results.innerHTML = '';
                            input.value = '';
                        };
                        results.appendChild(div);
                    });
                };
                xhr.send();
            }, 300);
        }<?php
wp_add_inline_script('bootflow-shop-assist-admin', ob_get_clean());
?>
        <?php
    }

    private function render_analytics_dashboard() {
        echo '<div class="notice notice-info"><p>' . esc_html__('Analytics dashboard is not enabled in this screen.', 'bootflow-shop-assist-for-woocommerce') . '</p></div>';
    }

    public function ajax_csv_export() {
        wp_die(esc_html__('Analytics export is not enabled.', 'bootflow-shop-assist-for-woocommerce'));
    }

    public function maybe_redirect_after_activation() {
        if (!get_transient('bootshas_activated')) {
            return;
        }
        delete_transient('bootshas_activated');
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only core activation flag
        if (wp_doing_ajax() || isset($_GET['activate-multi'])) {
            return;
        }
        wp_safe_redirect(admin_url('admin.php?page=bootflow-shop-assist'));
        exit;
    }
}
?>
