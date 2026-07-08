=== Bootflow Shop Assist for WooCommerce ===
Contributors: bootflowio
Tags: woocommerce, chatbot, product search, shop assistant, live chat
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later

Smart product search assistant for WooCommerce — keyword & fuzzy matching, voice input, product comparison, and custom responses.

== Description ==

Bootflow Shop Assist is a lightweight, privacy-focused chatbot for WooCommerce stores. It helps your customers find products instantly using smart keyword and fuzzy search, with all core chatbot processing performed locally on your server.

**Key Features:**

* **Smart Product Search** — Keyword and fuzzy matching across product titles, descriptions, categories, tags, SKUs, and custom fields.
* **Product Comparison** — Side-by-side comparison of multiple products with attributes, prices, and stock status.
* **Voice Input** — Built-in browser-based speech recognition where supported.
* **Custom Responses** — Define keyword-triggered custom responses for FAQs, promotions, or store policies.
* **Starter Questions** — Pre-configured quick-action buttons to guide customers.
* **Delivery & Contact Info** — Automatic delivery zone and contact information from WooCommerce settings.
* **Multi-language** — Built-in translations for 8 languages (LV, EN, DE, RU, LT, ET, ES, FR).
* **White Label** — Customize chatbot name, icon, welcome message, and colors.
* **Theme Customization** — 6 built-in color palettes or fully custom colors via color picker.
* **Import/Export** — Full settings backup and restore.

**Privacy First:**

* Core search and chatbot logic run locally on your WordPress site.
* This plugin does not include external provider integrations.
* No cookies and no embedded analytics scripts.
* The plugin package is self-contained for core features.

== Installation ==

1. Upload the `bootflow-shop-assist-for-woocommerce` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **Shop Assist** in the admin menu to configure settings.
4. The chatbot will appear automatically on your store's frontend.

**Requirements:**

* WordPress 5.8 or higher
* WooCommerce 5.0 or higher
* PHP 7.4 or higher

== Included Libraries ==

The unminified source of the plugin's own JavaScript (`chatbot.js`) is included alongside the minified version (`chatbot.min.js`).

== Frequently Asked Questions ==

= Does this plugin require remote services? =

No. Product search and chatbot logic run locally on your WordPress site.

= Does it work without WooCommerce? =

The plugin requires WooCommerce for product search features. It will detect WooCommerce pages and posts automatically.

= Can I customize the chatbot appearance? =

Yes. You can change colors (6 palettes or custom), the chatbot name, icon, welcome message, and more from the settings page.

= What languages are supported? =

The chatbot interface supports Latvian, English, German, Russian, Lithuanian, Estonian, Spanish, and French out of the box.

= How does voice input work? =

Voice input uses browser speech recognition capabilities. Browser support may vary.

= Can I add custom responses? =

Yes. Go to **Shop Assist → Custom Responses** to define keyword-triggered answers for common questions.

== Screenshots ==

1. Chatbot on the frontend with product search results.
2. Product comparison view.
3. Admin settings page — appearance and language.
4. Custom responses editor.

== Changelog ==

= 2.0.0 =
* Reworked plugin architecture for maintainability.
* Added product comparison.
* Added custom response rules.
* Added starter question buttons.
* Added color palettes and custom color options.
* Added settings import/export.
* Added optional GDPR notice.
* Added multi-language interface support.
* Added white-label options.
* Added optional contact method configuration.
* Added automatic JSON export updates on content changes.
* Improved packaging and dependency handling.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 2.0.0 =
Major update with new features. Review settings after upgrading.
