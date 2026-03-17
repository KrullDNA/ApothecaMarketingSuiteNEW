# Apotheca Marketing Sync — Setup Guide

## Architecture Overview

| Site | Domain | Plugin | Purpose |
|------|--------|--------|---------|
| **Site A** (Main Store) | `yoursite.com` | `apotheca-marketing-sync` | Hooks WooCommerce events, pushes data to Site B |
| **Site B** (Marketing) | `marketing.yoursite.com` | `apotheca-marketing-suite` | Receives data, manages subscribers, sends campaigns |

## Installation Order

### Step 1: Install on Site B (Marketing Subdomain) First

1. Upload `apotheca-marketing-suite` to `wp-content/plugins/` on `marketing.yoursite.com`.
2. Activate the plugin via **Plugins > Installed Plugins**.
3. The plugin will create all required database tables on activation.
4. Go to **Marketing Suite > Settings** and note the ingest endpoint: `https://marketing.yoursite.com/wp-json/ams/v1/sync/ingest`.

### Step 2: Install on Site A (Main Store)

1. Upload `apotheca-marketing-sync` to `wp-content/plugins/` on `yoursite.com`.
2. Activate the plugin. It requires WooCommerce 8.0+.
3. The plugin will create the `ams_sync_log` table on activation.

### Step 3: Configure the Shared Secret

The shared secret authenticates communication between the two sites. Both sites must use the **exact same secret**.

#### On Site A:
1. Go to **WooCommerce > Marketing Sync**.
2. Enter the Marketing Subdomain URL: `https://marketing.yoursite.com`
3. Enter a strong shared secret (32+ characters recommended). Copy this value.
4. Click **Save Settings**.

#### On Site B:
1. Go to **Marketing Suite > Settings > Sync** tab.
2. Enter the **same shared secret** you used on Site A.
3. Click **Save**.

### Step 4: Test the Connection

1. On Site A, go to **WooCommerce > Marketing Sync**.
2. Click **Test Connection**.
3. You should see "Connected!" if everything is configured correctly.

## Event Configuration

Each event type can be individually toggled on/off from the Settings page:

| Event | WooCommerce Hook | Description |
|-------|-----------------|-------------|
| Customer Registered | `user_register` | New account creation |
| Order Placed | `woocommerce_checkout_order_processed` | Completed checkout |
| Order Status Changed | `woocommerce_order_status_changed` | Status transitions |
| Cart Updated | `woocommerce_cart_updated` | Cart add/remove/update |
| Product Viewed | JS beacon on product pages | Single product page views |
| Checkout Started | `woocommerce_before_checkout_form` | Checkout page loaded |
| Abandoned Cart | Action Scheduler cron (15 min) | Carts inactive > 60 min |

## Security

- All payloads are signed with **HMAC-SHA256** using the shared secret.
- Timestamps are validated (5 minute window) to prevent replay attacks.
- The shared secret is stored **AES-256-CBC encrypted** in the database (key derived from `AUTH_KEY`).
- The SSO link token expires after 60 seconds.

## Troubleshooting

### Connection Test Fails
- Verify the Marketing Subdomain URL is correct and accessible.
- Ensure the shared secret matches on both sites.
- Check that the ingest endpoint is reachable: `curl https://marketing.yoursite.com/wp-json/ams/v1/sync/ingest`
- Verify SSL certificates are valid on both domains.

### Events Not Syncing
- Check the **Sync Health** panel on the Settings page for error details.
- Ensure the relevant event toggle is enabled.
- Verify Action Scheduler is running: **Tools > Scheduled Actions**.
- Use **Retry Failed (Last 24h)** to re-queue failed dispatches.

### Product View Beacon
- The beacon JS is only loaded on single product pages (`is_singular('product')`).
- It uses `navigator.sendBeacon()` for non-blocking delivery.
- Verify `Product Viewed` is enabled in event toggles.

## Review Meta Bridge

The sync plugin exposes a REST endpoint for Site B to fetch KDNA review metadata:

```
GET /wp-json/ams-bridge/v1/review-meta?ids=1,2,3
Header: X-AMS-Signature: {hmac}
Header: X-AMS-Timestamp: {timestamp}
```

This endpoint is used automatically by the Reviews Cache Job on Site B.

## SSO Access

Users with `manage_woocommerce` capability will see a **Marketing Suite** link in the WordPress admin toolbar. Clicking it generates a signed, single-use SSO token and opens the marketing subdomain in a new tab.
