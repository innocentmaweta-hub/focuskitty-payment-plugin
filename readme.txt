# Malipo Checkout for Elementor

A lightweight WordPress plugin for Malipo hosted checkout without WooCommerce.

## Install
1. Upload `malipo-elementor-checkout.zip` in WordPress: Plugins > Add New > Upload Plugin.
2. Activate it.
3. Go to Settings > Malipo Checkout.
4. Enter your Malipo App ID and API Key.
5. Set a HTTPS return URL/page.
6. Give Malipo the callback URL shown in the settings page:
   `/wp-json/malipo/v1/callback`

## Elementor
Add an Elementor Shortcode widget to a product page and use:
[malipo_checkout product="Tummy Tonic" amount="15000"]

Change product and amount per product.

## Important
This first version uses a shortcode-supplied amount. For a production store with many products, move product prices to server-side WordPress product records so customers cannot tamper with the amount in the browser.
