# Checkout Protection

Stops card testing (people checking whether stolen cards work) at checkout, without getting in the way of real customers.

**Admin screen:** WooCommerce → Checkout Protection (Overview · Settings · Blocked emails · Blocked attempts)

Built after the September 2026 card-testing attack. That attack started as bots making 1,000+ attempts a day. reCAPTCHA stopped the bots, and the attackers switched to a few manual-looking attempts a day: the name, email and IP changed every time, but the fake billing zip stayed the same.

---

## How it works

A checkout attempt passes through these layers in order. Each layer stops the attempt **before** an order is created or PayTrace is called.

| # | Layer | Stops | File |
|---|-------|-------|------|
| 1 | reCAPTCHA v3 | Bots (low score). Its keys and settings are in `wp-config.php`. | `recaptcha.php` |
| 2 | Blocked emails | Emails staff have blocked, including simple variations (capitals, Gmail dots, `+anything`) | `blocklist.php` |
| 3 | Failed-payment limit | Anyone whose details (email, zip, name + zip, IP, browser cookie) already failed to pay too often | `limiter.php` |
| 4 | Strict mode | During a site-wide spike of failures, the limit tightens for a few hours | `limiter.php` |

Layers 2–4 are checked in one place, `guard.php`, at both points where a payment can be attempted:

1. **Checkout** (`woocommerce_after_checkout_validation`). This check is skipped if WooCommerce or reCAPTCHA already rejected the submission.
2. **"Pay again" link** on an existing order (`woocommerce_before_pay_action`). This path skips normal checkout validation and reCAPTCHA, so it needs its own check.

When an attempt is stopped, the customer sees **one message, whatever the reason** (so attackers can't tell which rule caught them). The message includes a link to the Contact page and a **reference code** such as `GPH-3F9A1C`. The same code is:

- added to the contact link (`?ref=GPH-3F9A1C`);
- saved in the Blocked attempts log.

When a customer quotes a code, staff can search for it in the log.

### Counting failures

- A failed payment is an order moving to **Failed** during a customer's request. Staff changing an order's status in the dashboard is ignored.
- Each failure adds one to a counter for every enabled identifier, plus one to a site-wide counter.
- Each counter runs for a fixed window, starting from its first failure (default 24 hours).
- When the site-wide counter reaches the spike threshold (default 3 within 60 minutes), **strict mode** switches on for 3 hours with a limit of 1 failure. **One alert email** is sent.
- **Reset all counters** (Overview tab) clears every counter and ends strict mode. Staff use it when a real customer was paused.

### Defaults

| Setting | Default |
|---|---|
| Failed-payment limit | **Off** until an admin turns it on |
| Failures allowed | 2 within 24 hours (3rd attempt paused) |
| Count by | Email, zip, name + zip, IP, browser cookie |
| Strict mode | 3 failures in 60 minutes → 3 hours at 1 failure |
| Alert email | On, to the site admin email unless another address is set |
| Log retention | 90 days (maximum 10,000 rows) |

Every number is kept within a safe range, and strict mode can never be looser than the normal limit.

---

## Files

```
checkout-protection/
├── bootstrap.php          Only file gph-core.php loads. Constants + requires.
├── settings.php           Defaults, validation, reading/saving settings.
├── recaptcha.php          Moved unchanged from inc/recaptcha-checkout.php.
├── blocklist.php          Blocked emails + matching.
├── limiter.php            Counters, strict mode, counting failed payments, browser cookie.
├── guard.php              The two checkpoints. Add new rules to gph_cp_should_block() only.
├── message.php            Customer message, contact link, reference code.
├── log.php                Log table, install/migration, writing, daily cleanup.
├── alerts.php             Strict-mode alert email.
├── cleanup.php            Deactivation + "delete all data".
└── admin/                 Loaded only in wp-admin.
    ├── admin-page.php     Menu, tabs, form handlers, CSV export.
    ├── class-log-table.php
    ├── order-actions.php  "Block this customer's email" on the order screen.
    ├── admin.css          Loaded only on this screen.
    └── views/             One file per tab.
```

The code uses plain `gph_cp_` functions, like the rest of GPH Core. The only class is the log table, because WordPress requires admin list tables to extend `WP_List_Table`.

---

## Site speed

- **Normal pages (home, category, product):** the module adds **no database queries, no scripts, no styles and no cookies**. This was measured: the query count is identical with and without the module. Most visitors are also served WP Fastest Cache HTML, so the code doesn't run for them at all.
- **Checkout only:**
  - the reCAPTCHA script (only on `is_checkout()`);
  - one small browser cookie (`gph_dvc`);
  - the checks, which run only when **Place order** is clicked.
- **Admin only:** the admin files load inside wp-admin, and the stylesheet loads only on this screen.
- **Settings and the blocked-email list:** stored with autoload off, so they're only read when checkout needs them.
- **Counters:** stored as transients. They expire on their own, and move to memory automatically if the host adds Redis or Memcached.

---

## Stored data

| What | Where | Removed by |
|---|---|---|
| Settings | option `gph_cp_settings` | Delete all data |
| Blocked emails | option `gph_blocked_emails` | Delete all data |
| Counters | transients `gph_vl_*` | Expire by themselves; Delete all data |
| Strict mode end time | option `gph_cp_strict_until` | Reset counters; Delete all data |
| Counter generation | option `gph_cp_gen` | Delete all data |
| Log | table `{prefix}gph_blocked_attempts` + option `gph_cp_db_version` | Daily cleanup (older than retention); Delete all data |

**Personal data:**

- The log stores the details customers typed (name, email, phone, address, cart, IP, browser). It keeps them only for the retention period.
- **Card details are never received, so never stored.**

**Upgrading from v1** (the single-file version, `inc/checkout-protection.php`):

- The same setting names are used, so existing settings and blocked emails carry over.
- The v1 log (an option holding the last 50 blocks) is moved into the table once, then deleted.
- v1 also wrote `_gph_cp_ip` / `_gph_cp_device` to orders. v2 doesn't, and **Delete all data** removes them.

The log table is created on the first block (or when v1 data needs migrating), not before. That way, after **Delete all data**, nothing is recreated unless the module actually blocks someone again.

---

## Removing the feature

1. **Switch it off:** untick *Pause checkout after repeated failed payments*. Blocked emails still apply until the list is emptied.
2. **Remove it completely:**
   1. Settings tab → **Delete all Checkout Protection data**.
   2. In `gph-core.php`, remove `'inc/checkout-protection/bootstrap.php'` from `$woo_files`.
   3. Delete this folder.

   Note: reCAPTCHA lives in this folder too. To keep it, move `recaptcha.php` back to `inc/` and load it from `gph-core.php`.
3. **Plugin deactivated:** the daily cleanup is unscheduled automatically. Data is kept.
4. **Future plugin `uninstall.php`:** call `gph_cp_delete_all_data()` from it; don't duplicate the cleanup. Make deleting data on uninstall an opt-in setting, because deleting the plugin just to upload a new version would otherwise wipe the log.

---

## Testing after a deploy

1. Log out. Check out with a blocked email. You should see the message with a reference code, and no new order should be created.
2. Search for that reference under **Blocked attempts**. It should appear with the details entered.
3. Place one normal order. It should go through as usual.
4. Optional, with the limit on: make 2 declined payments with the same zip, then try a 3rd. The 3rd should be paused.

After a real customer is paused: **Overview → Reset all counters**.

---

## Known limits and notes

- **Classic checkout only.** The site uses the `[woocommerce_checkout]` shortcode. If it ever moves to the block-based Checkout, both `guard.php` and `recaptcha.php` must be rewritten against the Store API hooks.
- **IP address.** The limit uses `REMOTE_ADDR`, which Plesk's nginx → Apache proxy sets to the real visitor. If Cloudflare is added later, configure the server to restore the visitor IP. Otherwise every visitor shares Cloudflare's IPs, and the IP counter becomes meaningless (untick it until that's fixed).
- **reCAPTCHA** was moved without any code changes. Its own IP helper trusts `X-Forwarded-For`, which is only used as a hint to Google, not for blocking. That was left as it was, on purpose.
- **Alert email** is sent through `wp_mail()`, so delivery depends on the site's mail setup.
- **Page cache:** the checkout page must not be cached, or the browser cookie won't be set. Check WP Fastest Cache's exclusions include Cart, Checkout and My Account.
