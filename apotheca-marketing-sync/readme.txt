=== Apotheca Marketing Sync ===
Contributors: apotheca
Tags: woocommerce, marketing, sync, automation
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pushes WooCommerce events to the Apotheca Marketing Suite on your marketing subdomain.

== Description ==

Apotheca Marketing Sync is a lightweight companion plugin installed on your WooCommerce store. It hooks into WooCommerce events and pushes HMAC-signed JSON payloads to the Apotheca Marketing Suite on your marketing subdomain.

Events pushed:

* Customer registered
* Order placed
* Order status changed
* Cart updated
* Product viewed (lightweight JS beacon)
* Checkout started
* Abandoned cart detection

All dispatches are async via Action Scheduler with automatic retry on failure.

== Installation ==

1. Upload `apotheca-marketing-sync` to the `/wp-content/plugins/` directory on your WooCommerce store.
2. Activate the plugin through the 'Plugins' menu.
3. Go to WooCommerce > Marketing Sync to configure the marketing subdomain URL and shared secret.
4. Click "Test Connection" to verify connectivity.

== Changelog ==

= 1.0.0 =
* Initial release.
