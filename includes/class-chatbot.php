<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Bootflow Shop Assist — Chatbot
 * 
 * Local keyword/fuzzy search, product comparison, delivery/contact info.
 * No remote service calls. Add-ons can extend via filters.
 */
class Bootflow_Shop_Assist_Chatbot {

    const EXPORT_QUEUE_TRANSIENT = 'bootshas_needs_export';
    const EXPORT_LOCK_TRANSIENT = 'bootshas_export_in_progress';
    const EXPORT_INIT_TRANSIENT = 'bootshas_json_init_in_progress';
    const EXPORT_IMMEDIATE_COOLDOWN_TRANSIENT = 'bootshas_json_immediate_cooldown';

    public function __construct() {
        add_action('wp_ajax_bootshas_chat', [$this, 'handle_chat']);
        add_action('wp_ajax_nopriv_bootshas_chat', [$this, 'handle_chat']);
        add_action('wp_ajax_bootshas_refresh_nonce', [$this, 'refresh_nonce']);
        add_action('wp_ajax_nopriv_bootshas_refresh_nonce', [$this, 'refresh_nonce']);
        add_action('wp_ajax_bootshas_starter_answer', [$this, 'starter_answer']);
        add_action('wp_ajax_nopriv_bootshas_starter_answer', [$this, 'starter_answer']);
        add_action('wp_ajax_bootshas_get_shipping', [$this, 'get_shipping_methods']);
        add_action('wp_ajax_nopriv_bootshas_get_shipping', [$this, 'get_shipping_methods']);
        add_action('wp_ajax_bootshas_set_shipping', [$this, 'set_shipping_method']);
        add_action('wp_ajax_nopriv_bootshas_set_shipping', [$this, 'set_shipping_method']);
        add_action('wp_ajax_bootshas_get_modal', [$this, 'get_modal_html']);
        add_action('wp_ajax_nopriv_bootshas_get_modal', [$this, 'get_modal_html']);
        add_action('wp_ajax_bootshas_get_product_details', [$this, 'get_product_details']);
        add_action('wp_ajax_nopriv_bootshas_get_product_details', [$this, 'get_product_details']);
        add_action('wp_ajax_bootshas_compare_products', [$this, 'compare_products']);
        add_action('wp_ajax_nopriv_bootshas_compare_products', [$this, 'compare_products']);
        add_action('wp_ajax_bootshas_add_to_cart', [$this, 'ajax_add_to_cart']);
        add_action('wp_ajax_nopriv_bootshas_add_to_cart', [$this, 'ajax_add_to_cart']);

        // Add-ons can register additional AJAX handlers via this hook
        do_action('bootflow_shop_assist_register_ajax_handlers', $this);

        // Export products to JSON
        add_action('bootshas_export_products', [$this, 'export_products_to_json']);

        // Ensure JSON index exists even if activation cron did not run yet.
        add_action('init', [$this, 'maybe_initialize_json_index'], 20);

        // Auto-export on post save/delete
        add_action('save_post', [$this, 'on_post_saved'], 20, 2);
        add_action('trashed_post', [$this, 'on_post_deleted']);
        add_action('deleted_post', [$this, 'on_post_deleted']);
    }

    public function on_post_saved($post_id, $post) {
        if (!$post || !($post instanceof WP_Post)) {
            return;
        }
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if (!$this->should_track_post_type($post->post_type)) {
            return;
        }

        if ($post->post_status !== 'publish') {
            if ($this->should_run_immediate_sync()) {
                $this->remove_post_from_json((int) $post_id);
            }
            $this->queue_debounced_export();
            return;
        }

        if ($this->should_run_immediate_sync()) {
            $this->update_single_post_in_json((int) $post_id, $post);
        }
        $this->queue_debounced_export();
    }

    public function on_post_deleted($post_id) {
        $post_type = get_post_type($post_id);
        if ($post_type && $this->should_track_post_type($post_type)) {
            if ($this->should_run_immediate_sync()) {
                $this->remove_post_from_json((int) $post_id);
            }
            $this->queue_debounced_export();
        }
    }

    private function should_run_immediate_sync() {
        $enabled = apply_filters('bootshas_immediate_json_sync_enabled', true);
        if (!$enabled) {
            return false;
        }

        if (defined('WP_CLI') && WP_CLI) {
            return false;
        }

        if (wp_doing_cron()) {
            return false;
        }

        if (get_transient(self::EXPORT_LOCK_TRANSIENT)) {
            return false;
        }

        if ($this->is_probable_bulk_operation()) {
            return false;
        }

        if (get_transient(self::EXPORT_IMMEDIATE_COOLDOWN_TRANSIENT)) {
            return false;
        }

        $cooldown = (int) apply_filters('bootshas_immediate_json_sync_cooldown', 20);
        $cooldown = max(3, min(120, $cooldown));
        set_transient(self::EXPORT_IMMEDIATE_COOLDOWN_TRANSIENT, 1, $cooldown);

        return true;
    }

    private function is_probable_bulk_operation() {
        $request_action = '';
        if (isset($_REQUEST['action'])) {
            $request_action = sanitize_key((string) wp_unslash($_REQUEST['action']));
        }

        if (isset($_REQUEST['action2']) && $request_action === '') {
            $request_action = sanitize_key((string) wp_unslash($_REQUEST['action2']));
        }

        if ($request_action !== '') {
            $bulk_markers = ['bulk', 'import', 'product-import', 'woocommerce_do_ajax_product_import'];
            foreach ($bulk_markers as $marker) {
                if (strpos($request_action, $marker) !== false) {
                    return true;
                }
            }
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_URI'])) : '';
            if ($uri !== '' && (strpos($uri, '/batch') !== false || strpos($uri, 'import') !== false)) {
                return true;
            }
        }

        return false;
    }

    public function maybe_initialize_json_index() {
        $json_path = (string) get_option('bootshas_products_json_path', '');
        if ($json_path !== '' && file_exists($json_path)) {
            return;
        }

        $locations = $this->get_json_export_locations();

        if (file_exists($locations['path'])) {
            update_option('bootshas_products_json_path', $locations['path']);
            update_option('bootshas_products_json_url', $locations['url']);
            update_option('bootshas_products_export_time', filemtime($locations['path']) ?: time());
            return;
        }

        if (get_transient(self::EXPORT_INIT_TRANSIENT)) {
            return;
        }

        set_transient(self::EXPORT_INIT_TRANSIENT, 1, 60);
        $this->export_products_to_json();
        delete_transient(self::EXPORT_INIT_TRANSIENT);
    }

    private function get_json_export_locations() {
        $upload_dir = wp_upload_dir();
        $folder = 'bootflow-shop-assist-for-woocommerce';
        $dir = trailingslashit($upload_dir['basedir']) . $folder;
        $url_dir = trailingslashit($upload_dir['baseurl']) . $folder;

        return [
            'dir' => $dir,
            'path' => trailingslashit($dir) . 'ai-chatboot-products.json',
            'url' => trailingslashit($url_dir) . 'ai-chatboot-products.json',
        ];
    }

    private function queue_debounced_export() {
        // Persist long enough so next 5-minute cron run can pick it up.
        set_transient(self::EXPORT_QUEUE_TRANSIENT, 1, 15 * MINUTE_IN_SECONDS);

        // Safety: if recurring cron is missing (plugin updated without deactivate/activate), recreate it.
        if (!wp_next_scheduled('bootshas_check_export')) {
            wp_schedule_event(time() + 300, 'bootshas_5min', 'bootshas_check_export');
        }
    }

    private function should_track_post_type($post_type) {
        if (!is_string($post_type) || $post_type === '') {
            return false;
        }

        $supported = apply_filters('bootshas_export_post_types', ['product', 'page', 'post']);
        if (!is_array($supported)) {
            $supported = ['product', 'page', 'post'];
        }

        return in_array($post_type, $supported, true);
    }

    private function remove_post_from_json($post_id) {
        $json_path = get_option('bootshas_products_json_path');
        if (!$json_path || !file_exists($json_path)) return;

        $data = json_decode(file_get_contents($json_path), true);
        if (!is_array($data)) return;

        $filtered = array_values(array_filter($data, function($item) use ($post_id) {
            return ($item['id'] ?? 0) != $post_id;
        }));

        if (count($filtered) < count($data)) {
            file_put_contents($json_path, json_encode($filtered, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    private function update_single_post_in_json($post_id, $post) {
        $json_path = get_option('bootshas_products_json_path');

        if (!$json_path || !file_exists($json_path)) {
            $this->export_products_to_json();
            return;
        }

        $data = json_decode(file_get_contents($json_path), true);
        if (!is_array($data)) $data = [];

        $new_entry = $this->format_post_for_json($post_id, $post);
        if (!$new_entry) return;

        $found = false;
        foreach ($data as $i => $item) {
            if (($item['id'] ?? 0) == $post_id) {
                $data[$i] = $new_entry;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $data[] = $new_entry;
        }

        file_put_contents($json_path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Atrod pirmo reāli eksistējošo attēlu pēc vairākām izmēru iespējām.
     */
    private function get_best_image_url($attachment_id, $preferred_sizes = []) {
        $attachment_id = intval($attachment_id);
        if (!$attachment_id) return '';

        $attached_file = get_attached_file($attachment_id);
        if (!$attached_file) return '';

        $sizes = array_values(array_unique(array_filter(array_merge(
            (array) $preferred_sizes,
            ['woocommerce_thumbnail', 'thumbnail', 'medium', 'woocommerce_gallery_thumbnail', 'woocommerce_single', 'large', 'full']
        ))));

        $metadata = wp_get_attachment_metadata($attachment_id);
        foreach ($sizes as $size) {
            if ($size === 'full') {
                if (file_exists($attached_file)) {
                    $full_url = wp_get_attachment_url($attachment_id);
                    if ($full_url) return $full_url;
                }
                continue;
            }

            if (empty($metadata['sizes'][$size]['file'])) {
                continue;
            }

            $candidate_path = trailingslashit(dirname($attached_file)) . $metadata['sizes'][$size]['file'];
            if (!file_exists($candidate_path)) {
                continue;
            }

            $image = wp_get_attachment_image_src($attachment_id, $size);
            if (!empty($image[0])) {
                return $image[0];
            }
        }

        if (file_exists($attached_file)) {
            $full_url = wp_get_attachment_url($attachment_id);
            if ($full_url) return $full_url;
        }

        return '';
    }

    /**
     * Atgriež attēlu URL kandidātus tādā secībā, kādā frontend var tos mēģināt.
     */
    private function get_image_url_candidates($attachment_id, $preferred_sizes = []) {
        $attachment_id = intval($attachment_id);
        if (!$attachment_id) return [];

        $sizes = array_values(array_unique(array_filter(array_merge(
            (array) $preferred_sizes,
            ['woocommerce_thumbnail', 'thumbnail', 'medium', 'woocommerce_gallery_thumbnail', 'woocommerce_single', 'large', 'full']
        ))));

        $candidates = [];
        foreach ($sizes as $size) {
            if ($size === 'full') {
                $url = wp_get_attachment_url($attachment_id);
            } else {
                $image = wp_get_attachment_image_src($attachment_id, $size);
                $url = !empty($image[0]) ? $image[0] : '';
            }

            if ($url && !in_array($url, $candidates, true)) {
                $candidates[] = $url;
            }
        }

        return $candidates;
    }

    /**
     * Atrod attēla URL produkta featured image un galerijas attēlos.
     */
    private function get_product_best_image_url($product, $preferred_sizes = []) {
        if (!$product) return '';

        $image_id = $product->get_image_id();
        
        // If variation has no image, try parent product
        if (!$image_id && $product->is_type('variation')) {
            $parent_id = $product->get_parent_id();
            if ($parent_id) {
                $parent = wc_get_product($parent_id);
                if ($parent) {
                    $image_id = $parent->get_image_id();
                }
            }
        }
        
        $gallery_ids = $product->get_gallery_image_ids();
        
        $attachment_ids = array_filter(array_merge(
            [intval($image_id)],
            array_map('intval', (array) $gallery_ids)
        ));

        foreach ($attachment_ids as $attachment_id) {
            $image_url = $this->get_best_image_url($attachment_id, $preferred_sizes);
            if ($image_url) {
                return $image_url;
            }
        }

        return '';
    }

    /**
     * Produkta image_candidates: featured + gallery attēli.
     */
    private function get_product_image_candidates($product, $preferred_sizes = []) {
        if (!$product) return [];

        $image_id = $product->get_image_id();
        
        // If variation has no image, try parent product
        if (!$image_id && $product->is_type('variation')) {
            $parent_id = $product->get_parent_id();
            if ($parent_id) {
                $parent = wc_get_product($parent_id);
                if ($parent) {
                    $image_id = $parent->get_image_id();
                }
            }
        }
        
        $gallery_ids = $product->get_gallery_image_ids();
        
        $candidates = [];
        $attachment_ids = array_filter(array_merge(
            [intval($image_id)],
            array_map('intval', (array) $gallery_ids)
        ));

        foreach ($attachment_ids as $attachment_id) {
            $attachment_candidates = $this->get_image_url_candidates($attachment_id, $preferred_sizes);
            foreach ($attachment_candidates as $url) {
                if (!in_array($url, $candidates, true)) {
                    $candidates[] = $url;
                }
            }
        }

        return $candidates;
    }

    private function format_post_for_json($post_id, $post = null) {
        if (!$post) $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') return null;

        $type = $post->post_type;

        if ($type === 'product' && function_exists('wc_get_product')) {
            $product = wc_get_product($post_id);
            if ($product) {
                return [
                    'id' => $post_id,
                    'type' => 'product',
                    'title' => $product->get_name(),
                    'permalink' => get_permalink($post_id),
                    'image' => $this->get_product_best_image_url($product, ['woocommerce_thumbnail', 'thumbnail', 'medium', 'woocommerce_single']),
                    'image_candidates' => $this->get_product_image_candidates($product, ['woocommerce_thumbnail', 'thumbnail', 'medium', 'woocommerce_single']),
                    'price' => $product->get_price(),
                    'regular_price' => $product->get_regular_price(),
                    'sale_price' => $product->get_sale_price(),
                    'currency' => get_woocommerce_currency_symbol(),
                    'stock_status' => $product->get_stock_status(),
                    'stock_quantity' => $product->get_stock_quantity(),
                    'categories' => wp_get_post_terms($post_id, 'product_cat', ['fields' => 'names']),
                    'tags' => wp_get_post_terms($post_id, 'product_tag', ['fields' => 'names']),
                    'description' => $product->get_description(),
                    'short_description' => $product->get_short_description(),
                    'sku' => $product->get_sku(),
                    'weight' => $product->get_weight(),
                    'dimensions' => [
                        'length' => $product->get_length(),
                        'width' => $product->get_width(),
                        'height' => $product->get_height()
                    ],
                    'attributes' => $this->get_product_attributes($product),
                    'add_to_cart_url' => $product->add_to_cart_url(),
                    'is_on_sale' => $product->is_on_sale(),
                    'rating' => $product->get_average_rating(),
                    'review_count' => $product->get_review_count()
                ];
            }
        }

        $thumbnail_id = get_post_thumbnail_id($post_id);
        $categories = wp_get_post_terms($post_id, 'category', ['fields' => 'names']);
        $tags = wp_get_post_terms($post_id, 'post_tag', ['fields' => 'names']);

        $all_meta = get_post_meta($post_id);
        $custom_fields = [];
        foreach ($all_meta as $key => $values) {
            if (strpos($key, '_') === 0) continue;
            $custom_fields[$key] = count($values) === 1 ? $values[0] : $values;
        }

        return [
            'id' => $post_id,
            'type' => $type,
            'title' => $post->post_title,
            'permalink' => get_permalink($post_id),
            'image' => $thumbnail_id ? $this->get_best_image_url($thumbnail_id, ['thumbnail', 'medium', 'medium_large']) : '',
            'image_candidates' => $thumbnail_id ? $this->get_image_url_candidates($thumbnail_id, ['thumbnail', 'medium', 'medium_large']) : [],
            'content' => wp_strip_all_tags($post->post_content),
            'excerpt' => $post->post_excerpt ?: wp_trim_words(wp_strip_all_tags($post->post_content), 55),
            'categories' => is_array($categories) ? $categories : [],
            'tags' => is_array($tags) ? $tags : [],
            'custom_fields' => $custom_fields,
            'date' => $post->post_date,
            'modified' => $post->post_modified
        ];
    }

    public function handle_chat() {
        if (!check_ajax_referer('bootshas_nonce', 'nonce', false)) {
            wp_send_json_error(['text' => bootshas_t('security_failed')], 403);
            return;
        }

        $message = isset($_POST['message']) ? sanitize_text_field(wp_unslash($_POST['message'])) : '';
        if (empty($message)) {
            wp_send_json_error(['text' => bootshas_t('be_query_empty')]);
        }

        $lower = strtolower($message);

        // Check custom responses first
        $custom_responses = get_option('bootshas_custom_responses', []);
        $custom_responses = apply_filters('bootflow_shop_assist_custom_responses_for_language', $custom_responses, bootshas_get_language());
        if (is_array($custom_responses)) {
            foreach ($custom_responses as $cr) {
                $keywords = array_map('trim', explode(',', mb_strtolower($cr['keywords'] ?? '')));
                foreach ($keywords as $kw) {
                    if ($kw !== '' && mb_strpos($lower, $kw) !== false) {
                        wp_send_json_success(['text' => $cr['response']]);
                        return;
                    }
                }
            }
        }

        // Delivery information
        $delivery_kw = array_map('trim', explode(',', bootshas_t('delivery_keywords')));
        $is_delivery_query = false;
        foreach ($delivery_kw as $dkw) {
            if ($dkw !== '' && mb_strpos($lower, $dkw) !== false) {
                $is_delivery_query = true;
                break;
            }
        }
        
        if ($is_delivery_query) {
            /**
             * Filter: bootflow_shop_assist_delivery_tracking
             * PRO add-on can provide real order tracking before static delivery info.
             * 
             * @param array|null $response  null = use static delivery info
             * @param string     $message   User's original message
             * @param string     $lower     Lowercase version
             * @param int        $user_id   Current user ID (0 if not logged in)
             * @return array|null  ['text' => '...', 'orders' => [...]] or null
             */
            $user_id = get_current_user_id();
            $tracking_response = apply_filters('bootflow_shop_assist_delivery_tracking', null, $message, $lower, $user_id);
            
            if ($tracking_response !== null) {
                wp_send_json_success($tracking_response);
                return;
            }
            
            // Fallback to static delivery info
            $delivery_info = $this->get_delivery_info();
            wp_send_json_success(['text' => $delivery_info]);
            return;
        }

        // Contact information
        $contact_kw = array_map('trim', explode(',', bootshas_t('contact_keywords')));
        foreach ($contact_kw as $ckw) {
            if ($ckw !== '' && mb_strpos($lower, $ckw) !== false) {
                $contact_info = $this->get_contact_info();
                wp_send_json_success(['text' => $contact_info]);
                return;
            }
        }

        // PRO mode: AI-first and AI-only search path.
        if ($this->is_pro_license_active()) {
            $ai_response = apply_filters('bootflow_shop_assist_ai_search', null, $message, $lower);
            if ($ai_response !== null) {
                wp_send_json_success($ai_response);
                return;
            }

            $keyword_results = $this->search_products($message);
            if (!empty($keyword_results)) {
                wp_send_json_success([
                    'text' => bootshas_t('be_some_options'),
                    'products' => $keyword_results
                ]);
                return;
            }

            $addon_response = apply_filters('bootflow_shop_assist_fallback_response', null, $message, $lower);
            if ($addon_response !== null) {
                wp_send_json_success($addon_response);
                return;
            }

            wp_send_json_success([
                'text' => bootshas_t('be_query_unclear')
            ]);
            return;
        }

        // Gift detection
        $gift_kw = array_map('trim', explode(',', bootshas_t('gift_keywords')));
        $is_gift = false;
        foreach ($gift_kw as $gkw) {
            if ($gkw !== '' && mb_strpos($lower, $gkw) !== false) { $is_gift = true; break; }
        }
        if ($is_gift) {
            $products = $this->get_gift_products($message);
            wp_send_json_success([
                'mode' => 'gift_suggestions',
                'text' => bootshas_t('be_gift_ideas'),
                'products' => $products
            ]);
        }

        /**
         * Filter: bootflow_shop_assist_ai_search
         * PRO add-on can intercept search with AI before keyword search.
         * 
         * @param array|null $response  null = continue to keyword search
         * @param string     $message   User's original message
         * @param string     $lower     Lowercase version
         * @return array|null  ['text' => '...', 'products' => [...]] or null
         */
        $ai_response = apply_filters('bootflow_shop_assist_ai_search', null, $message, $lower);
        if ($ai_response !== null) {
            wp_send_json_success($ai_response);
            return;
        }

        // Keyword search with relevance scoring
        $keyword_results = $this->search_products($message);
        if (!empty($keyword_results)) {
            $top_id = isset($keyword_results[0]['id']) ? $keyword_results[0]['id'] : null;
            wp_send_json_success([
                'text' => bootshas_t('be_some_options'),
                'products' => $keyword_results
            ]);
            return;
        }

        /**
         * Filter: bootflow_shop_assist_fallback_response
         * Add-ons can provide custom responses when local search fails.
         * 
         * @param array|null $response  null = no response yet
         * @param string     $message   User's original message
         * @param string     $lower     Lowercase version
         * @return array|null  ['text' => '...', 'products' => [...]] or null
         */
        $addon_response = apply_filters('bootflow_shop_assist_fallback_response', null, $message, $lower);
        if ($addon_response !== null) {
            wp_send_json_success($addon_response);
            return;
        }

        // No results found
        wp_send_json_success([
            'text' => bootshas_t('be_query_unclear')
        ]);
    }

    private function is_pro_license_active() {
        if (!class_exists('Bootflow_Shop_Assist_Pro_Plugin')) {
            return false;
        }

        if (!method_exists('Bootflow_Shop_Assist_Pro_Plugin', 'is_license_active')) {
            return false;
        }

        return (bool) Bootflow_Shop_Assist_Pro_Plugin::is_license_active();
    }

    private function fuzzy_contains($haystack, $needle) {
        if (mb_strpos($haystack, $needle) !== false) return true;
        $len = mb_strlen($needle);
        if ($len >= 5) {
            if (mb_strpos($haystack, mb_substr($needle, 0, $len - 1)) !== false) return true;
        }
        if ($len >= 6) {
            if (mb_strpos($haystack, mb_substr($needle, 0, $len - 2)) !== false) return true;
        }
        return false;
    }

    public function search_products($query) {
        $query = trim(mb_strtolower($query));

        $products_json_path = get_option('bootshas_products_json_path');
        if ($products_json_path && file_exists($products_json_path)) {
            $products_data = json_decode(file_get_contents($products_json_path), true);
            if (is_array($products_data)) {
                $scored_results = [];

                $query_words = preg_split('/\s+/u', $query);
                $query_words = array_filter(array_map(function($w) {
                    return preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower($w));
                }, $query_words));
                $query_words = array_values($query_words);

                foreach ($products_data as $prod) {
                    $title = mb_strtolower($prod['title'] ?? '');
                    $desc = mb_strtolower(($prod['description'] ?? '') . ' ' . ($prod['short_description'] ?? ''));
                    $cats = mb_strtolower(implode(' ', (array)($prod['categories'] ?? [])));
                    $tags = mb_strtolower(implode(' ', (array)($prod['tags'] ?? [])));
                    $cat_text = $cats . ' ' . $tags;

                    $title_words = preg_split('/[\s\-\_\/\.\,]+/u', $title);
                    $title_words = array_filter(array_map(function($w) {
                        return preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower($w));
                    }, $title_words));
                    $title_words = array_values($title_words);

                    $score = 0;

                    if ($title === $query) {
                        $score = 10000;
                    } elseif (mb_strpos($title, $query) !== false) {
                        $score = 5000;
                    }

                    $matched_in_title = [];
                    $matched_in_cats = [];
                    $matched_in_desc = [];

                    foreach ($query_words as $qi => $qw) {
                        if ($qw === '' || mb_strlen($qw) < 2) continue;

                        $found_in_title = false;
                        if ($this->fuzzy_contains($title, $qw)) {
                            $found_in_title = true;
                        }
                        // Removed partial word matching - was too fuzzy ("blue" matching "bluetooth")
                        
                        if ($found_in_title) {
                            $matched_in_title[$qi] = true;
                        }
                        if ($this->fuzzy_contains($cat_text, $qw)) {
                            $matched_in_cats[$qi] = true;
                        }
                        if ($this->fuzzy_contains($desc, $qw)) {
                            $matched_in_desc[$qi] = true;
                        }
                    }

                    $all_matched = $matched_in_title + $matched_in_cats + $matched_in_desc;
                    $unique_matches = count($all_matched);
                    $total_words = count($query_words);

                    // Require at least 1 keyword match in title/categories (not only description)
                    $has_title_or_cat_match = !empty($matched_in_title) || !empty($matched_in_cats);
                    if ($total_words >= 2 && !$has_title_or_cat_match) {
                        continue;
                    }

                    // Require minimum 2 keywords match for multi-word queries
                    if ($total_words >= 2 && $unique_matches < 2) {
                        continue;
                    }

                    if ($total_words > 0 && $unique_matches > 0) {
                        $score += pow($unique_matches, 2) * 300;
                        if ($unique_matches === $total_words) {
                            $score += 2000;
                        }
                        $score += count($matched_in_title) * 200;
                        $score += count($matched_in_cats) * 150;
                        $score += count($matched_in_desc) * 50;

                        $title_word_count = count($title_words);
                        if ($title_word_count > 0) {
                            $score += (count($matched_in_title) / $title_word_count) * 300;
                        }
                    }

                    if ($score > 0 && mb_strlen($title) > 0) {
                        $score += max(0, 100 - mb_strlen($title));
                    }

                    if ($score > 0) {
                        $prod['_score'] = $score;
                        $scored_results[] = $prod;
                    }
                }

                usort($scored_results, function($a, $b) {
                    return ($b['_score'] ?? 0) - ($a['_score'] ?? 0);
                });

                $max_results = (int) apply_filters('bootshas_search_results_max', 0);
                if ($max_results > 0) {
                    $scored_results = array_slice($scored_results, 0, $max_results);
                }

                $final = [];
                foreach ($scored_results as $r) {
                    if (!empty($r['id'])) {
                        $p = wc_get_product($r['id']);
                        if ($p) {
                            $final[] = $this->format_product($p);
                            continue;
                        }
                    }
                    $final[] = [
                        'id' => $r['id'] ?? 0,
                        'title' => $r['title'] ?? '',
                        'permalink' => $r['permalink'] ?? '',
                        'image' => $r['image'] ?? '',
                        'price' => $r['price'] ?? '',
                        'currency' => $r['currency'] ?? '',
                        'stock_status' => $r['stock_status'] ?? '',
                        'add_to_cart_url' => $r['add_to_cart_url'] ?? ''
                    ];
                }

                if (!empty($final)) return $final;
            }
        }

        // Fallback: WP search
        $args = [
            'post_type' => 'product',
            'posts_per_page' => -1,
            's' => $query,
            'post_status' => 'publish'
        ];
        $products = wc_get_products($args);
        return array_map([$this, 'format_product'], $products);
    }

    private function get_gift_products($query) {
        $budget = preg_match('/(\d+)\s*€/', $query, $matches) ? $matches[1] : null;
        $args = [
            'post_type' => 'product',
            'posts_per_page' => 16,
            'post_status' => 'publish',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- price range lookup requires meta filter on WooCommerce products
            'meta_query' => []
        ];
        if ($budget) {
            $args['meta_query'][] = [
                'key' => '_price',
                'value' => [$budget * 0.8, $budget * 1.2],
                'compare' => 'BETWEEN',
                'type' => 'NUMERIC'
            ];
        }
        $products = wc_get_products($args);
        return array_map([$this, 'format_product'], $products);
    }

    public function format_product($product) {
        return [
            'id' => $product->get_id(),
            'title' => $product->get_name(),
            'permalink' => get_permalink($product->get_id()),
            'image' => $this->get_product_best_image_url($product, ['woocommerce_thumbnail', 'thumbnail', 'medium', 'woocommerce_single']),
            'image_candidates' => $this->get_product_image_candidates($product, ['woocommerce_thumbnail', 'thumbnail', 'medium', 'woocommerce_single']),
            'price' => $product->get_price(),
            'currency' => get_woocommerce_currency_symbol(),
            'stock_status' => $product->get_stock_status(),
            'stock_quantity' => $product->get_stock_quantity(),
            'categories' => wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'names']),
            'description' => $product->get_short_description(),
            'ean' => $product->get_sku(),
            'add_to_cart_url' => $product->add_to_cart_url()
        ];
    }

    public function export_products_to_json() {
        if (get_transient(self::EXPORT_LOCK_TRANSIENT)) {
            return false;
        }

        set_transient(self::EXPORT_LOCK_TRANSIENT, 1, 4 * MINUTE_IN_SECONDS);

        $all_data = [];

        $export_post_types = apply_filters('bootshas_export_post_types', ['product', 'page', 'post']);
        if (!is_array($export_post_types)) {
            $export_post_types = ['product', 'page', 'post'];
        }

        foreach ($export_post_types as $post_type) {
            if ($post_type === 'product' && function_exists('wc_get_products')) {
                $products = wc_get_products([
                    'limit' => -1,
                    'status' => 'publish',
                    'orderby' => 'date',
                    'order' => 'DESC',
                ]);
                foreach ($products as $product) {
                    $all_data[] = $this->format_post_for_json($product->get_id());
                }
                continue;
            }

            $posts = get_posts([
                'post_type' => $post_type,
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'orderby' => 'date',
                'order' => 'DESC',
                'fields' => 'ids',
                'suppress_filters' => false,
            ]);

            foreach ((array) $posts as $pid) {
                $all_data[] = $this->format_post_for_json((int) $pid);
            }
        }

        $all_data = array_values(array_filter($all_data));

        $locations = $this->get_json_export_locations();
        if (!file_exists($locations['dir']) && !wp_mkdir_p($locations['dir'])) {
            delete_transient(self::EXPORT_LOCK_TRANSIENT);
            return false;
        }

        $file_path = $locations['path'];

        $written = file_put_contents($file_path, json_encode($all_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        if ($written !== false) {
            update_option('bootshas_products_json_path', $file_path);
            update_option('bootshas_products_json_url', $locations['url']);
            update_option('bootshas_products_export_time', time());
            delete_transient(self::EXPORT_QUEUE_TRANSIENT);
            delete_transient(self::EXPORT_LOCK_TRANSIENT);
            return true;
        }

        delete_transient(self::EXPORT_LOCK_TRANSIENT);

        return false;
    }

    private function get_product_attributes($product) {
        $attributes = [];
        try {
            $product_attributes = $product->get_attributes();
            foreach ($product_attributes as $attribute) {
                $name = $attribute->get_name();
                $options = $attribute->get_options();
                $attributes[$name] = $options;
            }
        } catch (Exception $e) {
            // Ignore malformed attribute payloads and continue gracefully.
        }
        return $attributes;
    }

    private function get_delivery_info() {
        $info = "🚚 " . bootshas_t('be_delivery_info') . "\n\n";

        if (class_exists('WC_Shipping_Zones')) {
            $zones = WC_Shipping_Zones::get_zones();
            if (!empty($zones)) {
                foreach ($zones as $zone) {
                    $zone_name = $zone['zone_name'];
                    $methods = $zone['shipping_methods'];
                    if (!empty($methods)) {
                        $info .= "- {$zone_name}:\n";
                        foreach ($methods as $method) {
                            if ($method->is_enabled()) {
                                $cost = $method->get_option('cost', '0');
                                $title = $method->get_title();
                                $info .= "  • {$title}";
                                if ($cost > 0) {
                                    $info .= " - " . wc_price($cost);
                                }
                                $info .= "\n";
                            }
                        }
                    }
                }
            }
        }

        $info .= "\n📦 " . bootshas_t('be_general_info') . "\n";
        $info .= "- " . bootshas_t('be_delivery_proc') . "\n";
        $info .= "- " . bootshas_t('be_delivery_time') . "\n";

        return $info;
    }

    private function get_contact_info() {
        $info = "📞 " . bootshas_t('be_contact_info') . "\n\n";

        $site_name = get_bloginfo('name');
        $site_url = get_site_url();
        $info .= "🏪 {$site_name}\n";
        $info .= "🌐 {$site_url}\n\n";

        $admin_email = get_option('admin_email');
        if ($admin_email) {
            $info .= "📧 {$admin_email}\n";
        }

        $phone = get_option('woocommerce_store_phone') ?: get_option('phone') ?: get_option('store_phone');
        if ($phone) {
            $info .= "📱 {$phone}\n";
        }

        $address = get_option('woocommerce_store_address') ?: get_option('store_address');
        $city = get_option('woocommerce_store_city') ?: get_option('store_city');
        $postcode = get_option('woocommerce_store_postcode') ?: get_option('store_postcode');
        $country = get_option('woocommerce_default_country') ?: get_option('store_country');

        if ($address || $city) {
            $info .= "\n🏠 " . bootshas_t('be_label_address') . "\n";
            if ($address) $info .= "{$address}\n";
            if ($city) $info .= "{$city}";
            if ($postcode) $info .= " {$postcode}";
            if ($country && class_exists('WC')) {
                $countries = WC()->countries->get_countries();
                $country_name = isset($countries[$country]) ? $countries[$country] : $country;
                $info .= "\n{$country_name}";
            }
            $info .= "\n";
        }

        return $info;
    }

    public function get_modal_html() {
        ob_start();
        include BOOTFLOW_SHOP_ASSIST_PLUGIN_DIR . 'templates/chatbot-modal.php';
        $modal_html = ob_get_clean();
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template handles its own escaping
        echo $modal_html;
        wp_die();
    }

    public function get_product_details() {
        if (!check_ajax_referer('bootshas_nonce', 'nonce', false)) {
            wp_send_json_error(['text' => bootshas_t('security_failed')], 403);
            return;
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        if (!$product_id) {
            wp_send_json_error(['text' => bootshas_t('be_invalid_product')]);
            return;
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(['text' => bootshas_t('be_product_not_found', 'Produkts nav atrasts.')]);
            return;
        }

        $description = $product->get_description();
        if (empty($description)) {
            $description = $product->get_short_description();
        }

        $description = $this->clean_description($description);

        wp_send_json_success([
            'description' => $description,
            'product_name' => $product->get_name()
        ]);
    }

    private function clean_description($html) {
        if (empty($html)) {
            return bootshas_t('no_description');
        }

        $html = preg_replace('/<h[1-6][^>]*>.*?<\/h[1-6]>/is', '', $html);

        $html = preg_replace_callback('/<table[^>]*>(.*?)<\/table>/is', function($matches) {
            $table_content = $matches[1];
            $table_content = preg_replace('/<tr[^>]*>/i', "\n", $table_content);
            $table_content = preg_replace('/<\/tr>/i', '', $table_content);
            $table_content = preg_replace('/<t[dh][^>]*>/i', '', $table_content);
            $table_content = preg_replace('/<\/t[dh]>/i', ' | ', $table_content);
            $table_content = wp_strip_all_tags($table_content);
            $table_content = preg_replace('/\|(\s*\n)/', "\n", $table_content);
            return "\n" . trim($table_content) . "\n";
        }, $html);

        $html = preg_replace('/\s+/', ' ', $html);
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        
        $text = wp_strip_all_tags($html);
        if (mb_strlen($text) > 500) {
            $text = mb_substr($text, 0, 500) . '...';
        }

        return trim($text) ?: bootshas_t('no_description');
    }

    public function compare_products() {
        if (!check_ajax_referer('bootshas_nonce', 'nonce', false)) {
            wp_send_json_error(['text' => bootshas_t('security_failed')], 403);
            return;
        }

        $product_ids = isset($_POST['product_ids']) && is_array($_POST['product_ids']) ? array_map('absint', $_POST['product_ids']) : [];
        if (count($product_ids) < 2) {
            wp_send_json_error(['text' => bootshas_t('min_compare')]);
            return;
        }

        $products = [];

        foreach ($product_ids as $pid) {
            $product = wc_get_product($pid);
            if (!$product) continue;

            $products[] = [
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'permalink' => get_permalink($product->get_id()),
                'image' => $this->get_product_best_image_url($product, ['woocommerce_thumbnail', 'thumbnail', 'medium']),
                'image_candidates' => $this->get_product_image_candidates($product, ['woocommerce_thumbnail', 'thumbnail', 'medium']),
                'price' => $product->get_price(),
                'sku' => $product->get_sku(),
                'weight' => $product->get_weight(),
                'dimensions' => $product->get_dimensions(false),
                'stock_status' => $product->get_stock_status(),
                'categories' => wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'names']),
                'attributes' => $product->get_attributes(),
                'add_to_cart_url' => $product->add_to_cart_url()
            ];
        }

        if (count($products) < 2) {
            wp_send_json_error(['text' => bootshas_t('compare_error')]);
            return;
        }

        $currency_symbol = html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $comparison = [
            'products' => $products,
            'attributes' => []
        ];

        $comparison['attributes'][] = [
            'label' => bootshas_t('compare_price'),
            'values' => array_map(function($p) use ($currency_symbol) {
                return $p['price'] ? $p['price'] . ' ' . $currency_symbol : '—';
            }, $products)
        ];

        if (array_filter(array_column($products, 'weight'))) {
            $comparison['attributes'][] = [
                'label' => bootshas_t('compare_weight'),
                'values' => array_map(function($p) {
                    return $p['weight'] ? $p['weight'] . ' kg' : '—';
                }, $products)
            ];
        }

        if (array_filter(array_column($products, 'dimensions'))) {
            $comparison['attributes'][] = [
                'label' => bootshas_t('compare_dimensions'),
                'values' => array_map(function($p) {
                    if (!empty($p['dimensions']['length']) && !empty($p['dimensions']['width']) && !empty($p['dimensions']['height'])) {
                        return $p['dimensions']['length'] . ' × ' . $p['dimensions']['width'] . ' × ' . $p['dimensions']['height'] . ' cm';
                    }
                    return '—';
                }, $products)
            ];
        }

        $comparison['attributes'][] = [
            'label' => bootshas_t('compare_availability'),
            'values' => array_map(function($p) {
                return $p['stock_status'] === 'instock' ? '✓ ' . bootshas_t('compare_in_stock') : '✗ ' . bootshas_t('compare_out_of_stock');
            }, $products)
        ];

        $comparison['attributes'][] = [
            'label' => bootshas_t('compare_categories'),
            'values' => array_map(function($p) {
                return !empty($p['categories']) ? implode(', ', $p['categories']) : '—';
            }, $products)
        ];

        $common_attr_keys = [];
        foreach ($products as $p) {
            foreach ($p['attributes'] as $attr) {
                if ($attr->get_visible()) {
                    $common_attr_keys[$attr->get_name()] = $attr->get_name();
                }
            }
        }

        foreach ($common_attr_keys as $attr_key) {
            $attr_values = [];
            foreach ($products as $p) {
                $found = false;
                foreach ($p['attributes'] as $attr) {
                    if ($attr->get_name() === $attr_key) {
                        $options = $attr->get_options();
                        $attr_values[] = is_array($options) ? implode(', ', $options) : $options;
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $attr_values[] = '—';
                }
            }
            
            $comparison['attributes'][] = [
                'label' => wc_attribute_label($attr_key),
                'values' => $attr_values
            ];
        }

        wp_send_json_success(['comparison' => $comparison]);
    }

    public function ajax_add_to_cart() {
        check_ajax_referer('bootshas_nonce', 'nonce');

        $product_id = absint($_POST['product_id'] ?? 0);
        $quantity = absint($_POST['quantity'] ?? 1);

        if (!$product_id) {
            wp_send_json_error(['message' => bootshas_t('cart_invalid_product')]);
        }

        $added = WC()->cart->add_to_cart($product_id, $quantity);

        if ($added) {
            wp_send_json_success(['message' => bootshas_t('cart_added')]);
        } else {
            wp_send_json_error(['message' => bootshas_t('cart_failed')]);
        }
    }

    /**
     * Return a fresh nonce for page-cache safe AJAX retries.
     */
    public function refresh_nonce() {
        wp_send_json_success([
            'nonce' => wp_create_nonce('bootshas_nonce'),
        ]);
    }

    /**
     * Return answer for configured starter question index.
     */
    public function starter_answer() {
        if (!check_ajax_referer('bootshas_nonce', 'nonce', false)) {
            wp_send_json_error(['text' => 'Invalid security token.'], 403);
            return;
        }

        $index = isset($_POST['index']) ? absint($_POST['index']) : -1;
        $items = get_option('bootshas_starter_questions', []);
        $items = apply_filters('bootflow_shop_assist_starter_questions_for_language', $items, bootshas_get_language());

        if (!is_array($items) || !isset($items[$index]) || !is_array($items[$index])) {
            wp_send_json_error(['text' => bootshas_t('be_query_unclear')]);
            return;
        }

        $item = $items[$index];
        $type = sanitize_key((string)($item['type'] ?? 'text'));
        if ($type === 'ai' || $type === 'auto') {
            $type = 'text';
        }
        if (!in_array($type, ['text', 'search', 'faq'], true)) {
            $type = 'text';
        }

        $result = [
            'text' => '',
        ];

        if ($type === 'text') {
            $result['text'] = trim((string) wp_kses_post($item['text'] ?? ''));
            wp_send_json_success($result);
            return;
        }

        if ($type === 'search') {
            $mode = sanitize_key((string)($item['search_mode'] ?? 'keyword'));
            if (!in_array($mode, ['keyword', 'category', 'sale', 'new'], true)) {
                $mode = 'keyword';
            }

            $products = [];

            $raw_selected = is_array($item['search_products'] ?? null) ? $item['search_products'] : [];
            if (!empty($raw_selected)) {
                foreach ($raw_selected as $entry) {
                    $pid = is_array($entry) ? absint($entry['id'] ?? 0) : absint($entry);
                    if ($pid <= 0) {
                        continue;
                    }
                    $product = wc_get_product($pid);
                    if ($product) {
                        $products[] = $this->format_product($product);
                    }
                    if (count($products) >= 12) {
                        break;
                    }
                }
            }

            if (empty($products)) {
                if ($mode === 'keyword') {
                    $keyword = sanitize_text_field((string)($item['search_keyword'] ?? ''));
                    if ($keyword !== '') {
                        $products = $this->search_products($keyword);
                    }
                } elseif ($mode === 'category') {
                    $cat_slugs = is_array($item['search_cats'] ?? null) ? $item['search_cats'] : [];
                    $cat_slugs = array_values(array_filter(array_map('sanitize_text_field', $cat_slugs)));
                    if (!empty($cat_slugs)) {
                        $wc_products = wc_get_products([
                            'status'   => 'publish',
                            'limit'    => 12,
                            'category' => $cat_slugs,
                        ]);
                        $products = array_map([$this, 'format_product'], $wc_products);
                    }
                } elseif ($mode === 'sale') {
                    $wc_products = wc_get_products([
                        'status'  => 'publish',
                        'limit'   => 12,
                        'on_sale' => true,
                    ]);
                    $products = array_map([$this, 'format_product'], $wc_products);
                } elseif ($mode === 'new') {
                    $wc_products = wc_get_products([
                        'status'  => 'publish',
                        'limit'   => 12,
                        'orderby' => 'date',
                        'order'   => 'DESC',
                    ]);
                    $products = array_map([$this, 'format_product'], $wc_products);
                }
            }

            $result['text'] = trim((string) wp_kses_post($item['search_text'] ?? ''));
            if ($result['text'] === '' && !empty($products)) {
                $result['text'] = bootshas_t('be_some_options');
            }
            if (!empty($products)) {
                $result['products'] = $products;
                $result['mode'] = 'search';
            } elseif ($result['text'] === '') {
                $result['text'] = bootshas_t('be_query_unclear');
            }

            wp_send_json_success($result);
            return;
        }

        // FAQ
        $faq_text = trim((string) wp_kses_post($item['faq_text'] ?? ''));
        $faq_page = absint($item['faq_page'] ?? 0);
        if ($faq_page > 0) {
            $faq_url = get_permalink($faq_page);
            if ($faq_url) {
                $faq_title = get_the_title($faq_page);
                $link_label = bootshas_t('faq_read_more');
                $faq_text .= ($faq_text !== '' ? '<br><br>' : '') . '<a href="' . esc_url($faq_url) . '" rel="noopener">' . esc_html($link_label . ': ' . ($faq_title ?: $faq_url)) . '</a>';
            }
        }

        if ($faq_text === '') {
            $faq_text = bootshas_t('be_query_unclear');
        }

        wp_send_json_success(['text' => $faq_text]);
    }

    /**
     * Return available shipping methods for current cart/session.
     */
    public function get_shipping_methods() {
        if (!check_ajax_referer('bootshas_nonce', 'nonce', false)) {
            wp_send_json_error(['text' => 'Invalid security token.'], 403);
            return;
        }

        if (!function_exists('WC') || !WC()->cart) {
            wp_send_json_success([
                'methods'  => [],
                'currency' => html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ]);
            return;
        }

        $methods = [];
        $seen = [];

        try {
            $packages = WC()->cart->get_shipping_packages();
            WC()->shipping()->calculate_shipping($packages);

            foreach ($packages as $package) {
                if (empty($package['rates']) || !is_array($package['rates'])) {
                    continue;
                }

                foreach ($package['rates'] as $rate_id => $rate) {
                    if (isset($seen[$rate_id])) {
                        continue;
                    }
                    $seen[$rate_id] = true;

                    $methods[] = [
                        'id'    => sanitize_text_field((string) $rate_id),
                        'label' => wp_strip_all_tags($rate->get_label()),
                        'cost'  => (float) $rate->get_cost(),
                    ];
                }
            }
        } catch (Exception $e) {
            // Return empty methods to keep frontend behavior stable.
        }

        wp_send_json_success([
            'methods'  => $methods,
            'currency' => html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ]);
    }

    /**
     * Set selected shipping method in session and return checkout URL.
     */
    public function set_shipping_method() {
        if (!check_ajax_referer('bootshas_nonce', 'nonce', false)) {
            wp_send_json_error(['text' => 'Invalid security token.'], 403);
            return;
        }

        $method_id = isset($_POST['method_id']) ? sanitize_text_field(wp_unslash($_POST['method_id'])) : '';
        if ($method_id === '') {
            wp_send_json_error(['text' => bootshas_t('shipping_error')]);
            return;
        }

        if (!function_exists('WC') || !WC()->session) {
            wp_send_json_error(['text' => bootshas_t('shipping_error')]);
            return;
        }

        $package_count = 1;
        if (WC()->cart) {
            $packages = WC()->cart->get_shipping_packages();
            if (is_array($packages) && !empty($packages)) {
                $package_count = count($packages);
            }
        }

        $chosen_methods = array_fill(0, $package_count, $method_id);
        WC()->session->set('chosen_shipping_methods', $chosen_methods);

        if (WC()->cart) {
            WC()->cart->calculate_totals();
        }

        wp_send_json_success([
            'checkout_url' => function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : '/checkout/',
        ]);
    }

}
