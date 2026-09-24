# GPH Core

Custom plugin for **gaspumpheaven.com**. It holds the store's business logic, so the theme (Astra + child theme) only handles looks.

**This plugin must stay active.** If it's off, the site stays online but quietly loses everything listed below. Nothing on the frontend tells you anything is wrong.

---

## What it does

| Module | File(s) | What it controls | Settings |
|---|---|---|---|
| Checkout Protection | `inc/checkout-protection/` | reCAPTCHA v3 on checkout, failed-payment limits, blocked emails, emergency mode, blocked-attempt log | WooCommerce → Checkout Protection |
| Catalog rules | `inc/woo-logic.php` | Forces manual product order (drag-drop order from "Rearrange Products"); hides parent-category products that also sit in a subcategory | None (code) |
| SKU on product cards | `inc/woo-loop-sku.php` | Shows SKU on shop/category cards (Astra hook) | Customize → WooCommerce → Product Catalog |
| Shipping notices | `inc/woo-shipping-notice.php` | Cart notice + checkout modal | Customize → WooCommerce → Shipping Notice |
| SEO crawl rules | `inc/seo-logic.php` | noindex + X-Robots-Tag on cart, sorting, filter and add-to-cart URLs; clean canonicals | None (code) |
| Schema cleanup | `inc/schema-logic.php` | Removes empty shipping/return fields from product schema (avoids Search Console warnings) | None (code) |
| Helpers | `inc/helpers.php` | WooCommerce context checks used by other modules | — |
| Customizer | `inc/customizer.php` | Registers the Customizer settings above | — |
| Plugins screen | `inc/admin/plugins-screen.*` | Settings links on the Plugins screen + confirm box before deactivating | — |

Checkout Protection has its own detailed README: `inc/checkout-protection/README.md`.

WooCommerce modules only load when WooCommerce is active. If it isn't, an admin notice says so.

**Checkout must stay on the classic `[woocommerce_checkout]` shortcode.** Checkout Protection doesn't run on the block-based checkout, and the plugin declares this, so WooCommerce warns if anyone switches.

---

## Protection against being switched off

There are two layers, because a plugin can't warn about its own deletion:

1. **Deactivate warning (inside this plugin).** A confirm box appears on the Plugins screen, for both the row link and Bulk actions → Deactivate.
2. **Watchdog (must-use plugin).** `mu-plugin/gph-core-watchdog.php` shows a red notice on every admin screen while GPH Core is deactivated, deleted or renamed, with a one-click Activate button when the plugin is still installed. Must-use plugins can't be deactivated from the dashboard.

**The watchdog is installed separately.** Copy `mu-plugin/gph-core-watchdog.php` to `wp-content/mu-plugins/` (create the folder if needed). The repo copy is for version control only: `mu-plugin/` is excluded from the SFTP upload (`.vscode/sftp.json` → `ignore`), and WordPress wouldn't load it from inside the plugin folder anyway.

---

## Data and uninstall

There is **no `uninstall.php`, on purpose.** Deleting the plugin leaves its settings, blocked emails and attempt log in the database, so if it's deleted by accident, reinstalling brings everything back.

To remove Checkout Protection data on purpose, use **Delete all data** on its settings screen.

Data stored:

- Options: `gph_cp_*`, `gph_blocked_emails`, `gph_core_activated`, `gph_core_deactivated`
- Customizer theme mods: `gph_show_loop_sku`, `gph_shipping_notice_content`, `gph_cart_notice_enabled`, `gph_checkout_modal_enabled`
- The Checkout Protection log table (see its README)
- Failed-payment counters: `gph_vl_*` transients (expire on their own)

---

## Deploy

- Required in `wp-config.php`: `GPH_RECAPTCHA_SITE_KEY`, `GPH_RECAPTCHA_SECRET_KEY`.
- Speed rule: nothing new may load on normal frontend pages. Load code only in admin, at checkout or at login.
- Bump `Version` and `GPH_CORE_VERSION` in `gph-core.php` on every release. The version also busts the cache for admin scripts.
- Before each major WooCommerce update, test checkout protection on the new version, then raise `WC tested up to` in `gph-core.php`. Until then WooCommerce's update screen flags GPH Core as untested, which is the reminder.

## Changelog

- **1.1.0**: Settings links and deactivate warning on the Plugins screen; watchdog must-use plugin; HPOS compatible / Cart-Checkout blocks incompatible declared; updated description; this README. Tested on WordPress 7.1, PHP 8.4, WooCommerce 11.1.2 (Sept 2026).
- **1.0.0**: Initial plugin, with Checkout Protection module added later.
