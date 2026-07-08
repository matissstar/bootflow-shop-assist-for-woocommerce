<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$bootflow_shop_assist_strings = bootshas_get_strings();
$bootflow_shop_assist_wl_name = get_option('bootshas_wl_name', '');
$bootflow_shop_assist_modal_title = !empty($bootflow_shop_assist_wl_name) ? $bootflow_shop_assist_wl_name : $bootflow_shop_assist_strings['modal_title'];
?>
<div id="bootflow-shop-assist-chatbot">
  <div class="msai-modal-header">
    <div class="msai-modal-title"><?php echo esc_html($bootflow_shop_assist_modal_title); ?></div>
    <div class="msai-modal-controls">
      <button class="msai-modal-btn msai-clear-btn" id="msai-clear" title="<?php echo esc_attr($bootflow_shop_assist_strings['btn_clear_title']); ?>"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg></button>
      <button class="msai-modal-btn msai-minimize-btn" id="msai-minimize" title="<?php echo esc_attr($bootflow_shop_assist_strings['btn_minimize_title']); ?>"><svg class="msai-icon-shrink" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/><line x1="14" y1="10" x2="21" y2="3"/><line x1="3" y1="21" x2="10" y2="14"/></svg><svg class="msai-icon-expand" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg></button>
      <button class="msai-modal-btn msai-close-btn" id="msai-close" title="<?php echo esc_attr($bootflow_shop_assist_strings['btn_close_title']); ?>">×</button>
    </div>
  </div>
  <div class="msai-box">
    <div class="msai-log" id="msai-log"></div>
    <form class="msai-form" id="msai-form">
      <input class="msai-inp" id="msai-q" placeholder="<?php echo esc_attr($bootflow_shop_assist_strings['input_placeholder']); ?>">
      <button class="msai-btn msai-smart-btn msai-smart-voice" type="button" id="msai-smart-btn"><?php echo esc_html($bootflow_shop_assist_strings['btn_voice']); ?></button>
    </form>
    <?php if (get_option('bootshas_wl_powered_by', '0') === '1'): ?>
    <div class="msai-powered-by"><?php echo esc_html($bootflow_shop_assist_strings['powered_by'] ?? 'Powered by'); ?> Bootflow Shop Assist</div>
    <?php endif; ?>
  </div>
</div>
