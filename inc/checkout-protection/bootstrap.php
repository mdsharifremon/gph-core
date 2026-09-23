<?php
/**
 * Checkout Protection — module loader.
 *
 * The only file gph-core.php loads for this module. Read README.md in this
 * folder first: it explains how the pieces fit together.
 *
 * Load strategy (site speed):
 * - Frontend files load on every request but do no work until checkout is
 *   submitted — no queries, no scripts, no cookies on normal pages.
 * - Admin files load only inside wp-admin.
 *
 * @package GPH_Core
 */

defined( 'ABSPATH' ) || exit;

define( 'GPH_CP_PATH', trailingslashit( __DIR__ ) );
define( 'GPH_CP_URL', trailingslashit( GPH_CORE_URL . 'inc/checkout-protection' ) );
define( 'GPH_CP_VERSION', '2.0.0' );
define( 'GPH_CP_DB_VERSION', '1' );

// Stored data. Names kept from v1 so live settings carry over.
define( 'GPH_CP_SETTINGS', 'gph_cp_settings' );        // Settings array.
define( 'GPH_CP_BLOCKLIST', 'gph_blocked_emails' );    // Blocked emails array.
define( 'GPH_CP_GEN', 'gph_cp_gen' );                  // Counter generation (bumped by "Reset all counters").
define( 'GPH_CP_STRICT', 'gph_cp_strict_until' );      // Strict mode end timestamp.
define( 'GPH_CP_DB_VERSION_OPT', 'gph_cp_db_version' ); // Installed log-table version.
define( 'GPH_CP_LEGACY_LOG', 'gph_cp_log' );           // v1 log (option), migrated into the table once.
define( 'GPH_CP_COOKIE', 'gph_dvc' );                  // Browser cookie used as a counter key.
define( 'GPH_CP_CRON', 'gph_cp_purge_log' );           // Daily log cleanup event.

// Frontend + shared.
require_once GPH_CP_PATH . 'recaptcha.php';
require_once GPH_CP_PATH . 'settings.php';
require_once GPH_CP_PATH . 'blocklist.php';
require_once GPH_CP_PATH . 'limiter.php';
require_once GPH_CP_PATH . 'message.php';
require_once GPH_CP_PATH . 'log.php';
require_once GPH_CP_PATH . 'alerts.php';
require_once GPH_CP_PATH . 'guard.php';
require_once GPH_CP_PATH . 'cleanup.php';

// Admin only.
if ( is_admin() ) {
	require_once GPH_CP_PATH . 'admin/admin-page.php';
	require_once GPH_CP_PATH . 'admin/order-actions.php';
}

// Plugin deactivated: stop the scheduled cleanup, keep all data.
register_deactivation_hook( GPH_CORE_PATH . 'gph-core.php', 'gph_cp_on_deactivate' );
