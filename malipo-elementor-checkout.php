<?php
/**
 * Plugin Name: OneKhusa Checkout for Elementor
 * Description: OneKhusa Hosted Checkout integration for Elementor/WordPress without WooCommerce.
 * Version: 2.0.0
 * Author: Custom Integration
 */

if (!defined('ABSPATH')) exit;

class OneKhusa_Elementor_Checkout {
    const OPT = 'okec_settings';
    const CPT = 'okec_order';

    public function __construct() {
        add_action('init', [$this, 'register_order_cpt']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        add_shortcode('onekhusa_buy', [$this, 'buy_shortcode']);
        add_shortcode('onekhusa_checkout', [$this, 'checkout_shortcode']);
        add_shortcode('onekhusa_order_status', [$this, 'order_status_shortcode']);

        // Backwards-compatible aliases so existing Elementor widgets can be reused.
        add_shortcode('malipo_buy', [$this, 'buy_shortcode']);
        add_shortcode('malipo_checkout', [$this, 'checkout_shortcode']);
        add_shortcode('malipo_order_status', [$this, 'order_status_shortcode']);

        add_action('rest_api_init', [$this, 'routes']);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
    }

    public function register_order_cpt() {
        register_post_type(self::CPT, [
            'labels' => ['name' => 'OneKhusa Orders', 'singular_name' => 'OneKhusa Order'],
            'public' => false,
            'show_ui' => true,
            'menu_icon' => 'dashicons-cart',
            'supports' => ['title'],
        ]);
    }

    public function admin_menu() {
        add_options_page(
            'OneKhusa Checkout',
            'OneKhusa Checkout',
            'manage_options',
            'okec-settings',
            [$this, 'settings_page']
        );
    }

    public function register_settings() {
        register_setting('okec_settings_group', self::OPT, [
            'sanitize_callback' => function($v) {
                $products = [];
                $names = [
                    'tummy-tonic' => 'Tummy Tonic',
                    'pompo-pompo' => 'Pompo Pompo',
                    'booty-booster' => 'Booty Booster',
                ];

                foreach ($names as $slug => $name) {
                    $products[$slug] = [
                        'name' => $name,
                        'price' => max(0, (float)($v['products'][$slug]['price'] ?? 0)),
                    ];
                }

                return [
                    'api_key' => sanitize_text_field($v['api_key'] ?? ''),
                    'api_secret' => sanitize_text_field($v['api_secret'] ?? ''),
                    'organisation_id' => sanitize_text_field($v['organisation_id'] ?? ''),
                    'merchant_account' => preg_replace('/[^0-9]/', '', (string)($v['merchant_account'] ?? '')),
                    'webhook_secret' => sanitize_text_field($v['webhook_secret'] ?? ''),
                    'environment' => (($v['environment'] ?? 'sandbox') === 'live') ? 'live' : 'sandbox',
                    'checkout_page' => esc_url_raw($v['checkout_page'] ?? ''),
                    'success_page' => esc_url_raw($v['success_page'] ?? ''),
                    'products' => $products,
                ];
            }
        ]);
    }

    public function settings_page() {
        if (!current_user_can('manage_options')) return;

        $s = $this->settings();
        $products = $this->products();
        $webhook = rest_url('onekhusa/v1/webhook');

        ?>
        <div class="wrap">
            <h1>OneKhusa Hosted Checkout</h1>
            <p>
                This integration uses OneKhusa's Hosted Checkout flow:
                customer details → OneKhusa payment screen → payment → FocusKitty confirmation.
                No WooCommerce is required.
            </p>

            <form method="post" action="options.php">
                <?php settings_fields('okec_settings_group'); ?>

                <h2>OneKhusa API</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="okec_environment">Environment</label></th>
                        <td>
                            <select id="okec_environment" name="<?php echo esc_attr(self::OPT); ?>[environment]">
                                <option value="sandbox" <?php selected($s['environment'] ?? 'sandbox', 'sandbox'); ?>>Sandbox / Test</option>
                                <option value="live" <?php selected($s['environment'] ?? 'sandbox', 'live'); ?>>Live / Production</option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="okec_api_key">API Key</label></th>
                        <td>
                            <input type="text" class="regular-text" id="okec_api_key"
                                name="<?php echo esc_attr(self::OPT); ?>[api_key]"
                                value="<?php echo esc_attr($s['api_key'] ?? ''); ?>"
                                autocomplete="off">
                        </td>
                    </tr>

                    <tr>
                        <th><label for="okec_api_secret">API Secret</label></th>
                        <td>
                            <input type="password" class="regular-text" id="okec_api_secret"
                                name="<?php echo esc_attr(self::OPT); ?>[api_secret]"
                                value="<?php echo esc_attr($s['api_secret'] ?? ''); ?>"
                                autocomplete="new-password">
                            <p class="description">Keep this server-side. Never place it in Elementor page code.</p>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="okec_org">Organisation ID</label></th>
                        <td>
                            <input type="text" class="regular-text" id="okec_org"
                                name="<?php echo esc_attr(self::OPT); ?>[organisation_id]"
                                value="<?php echo esc_attr($s['organisation_id'] ?? ''); ?>">
                        </td>
                    </tr>

                    <tr>
                        <th><label for="okec_merchant">Merchant Account Number</label></th>
                        <td>
                            <input type="text" class="regular-text" id="okec_merchant"
                                name="<?php echo esc_attr(self::OPT); ?>[merchant_account]"
                                value="<?php echo esc_attr($s['merchant_account'] ?? ''); ?>">
                        </td>
                    </tr>

                    <tr>
                        <th><label for="okec_webhook_secret">Webhook Secret</label></th>
                        <td>
                            <input type="password" class="regular-text" id="okec_webhook_secret"
                                name="<?php echo esc_attr(self::OPT); ?>[webhook_secret]"
                                value="<?php echo esc_attr($s['webhook_secret'] ?? ''); ?>"
                                autocomplete="new-password">
                            <p class="description">
                                The merchant webhook secret used to verify OneKhusa HMAC-SHA512 webhook signatures.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="okec_checkout_page">Checkout Page URL</label></th>
                        <td>
                            <input type="url" class="regular-text" id="okec_checkout_page"
                                name="<?php echo esc_attr(self::OPT); ?>[checkout_page]"
                                value="<?php echo esc_attr($s['checkout_page'] ?? ''); ?>">
                            <p class="description">Elementor page containing <code>[onekhusa_checkout]</code>.</p>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="okec_success_page">Success / Status Page URL</label></th>
                        <td>
                            <input type="url" class="regular-text" id="okec_success_page"
                                name="<?php echo esc_attr(self::OPT); ?>[success_page]"
                                value="<?php echo esc_attr($s['success_page'] ?? ''); ?>">
                            <p class="description">Elementor page containing <code>[onekhusa_order_status]</code>.</p>
                        </td>
                    </tr>
                </table>

                <h2>FocusKitty Products</h2>
                <table class="widefat striped" style="max-width:700px">
                    <thead><tr><th>Product</th><th>Price (MWK)</th></tr></thead>
                    <tbody>
                    <?php foreach ($products as $slug => $product): ?>
                        <tr>
                            <td><?php echo esc_html($product['name']); ?></td>
                            <td>
                                <input type="number" min="1" step="1"
                                    name="<?php echo esc_attr(self::OPT); ?>[products][<?php echo esc_attr($slug); ?>][price]"
                                    value="<?php echo esc_attr($product['price']); ?>">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php submit_button('Save OneKhusa Settings'); ?>
            </form>

            <h2>Elementor Shortcodes</h2>
            <p><strong>Product page:</strong> <code>[onekhusa_buy product="tummy-tonic"]</code></p>
            <p><strong>Checkout page:</strong> <code>[onekhusa_checkout]</code></p>
            <p><strong>Success/status page:</strong> <code>[onekhusa_order_status]</code></p>

            <h2>OneKhusa Webhook URL</h2>
            <p><code><?php echo esc_html($webhook); ?></code></p>
            <p class="description">
                Register this HTTPS URL as the collection/checkout callback in your OneKhusa merchant portal.
            </p>
        </div>
        <?php
    }

    public function assets() {
        wp_register_style('okec-style', false);
        wp_enqueue_style('okec-style');

        wp_add_inline_style('okec-style', '
            .okec-box{max-width:520px;padding:24px;border:1px solid #ddd;border-radius:12px;background:#fff;box-sizing:border-box}
            .okec-box label{display:block;margin:0 0 6px;font-weight:600}
            .okec-box input{width:100%;padding:11px;margin:0 0 14px;box-sizing:border-box}
            .okec-buy,.okec-submit{width:100%;padding:13px;border:0;border-radius:8px;background:#111;color:#fff;font-weight:700;cursor:pointer}
            .okec-buy[disabled],.okec-submit[disabled]{opacity:.6;cursor:wait}
            .okec-msg{margin-top:12px}.okec-error{color:#b00020}.okec-success{color:#087f23}
            .okec-summary{padding:12px;background:#f7f7f7;border-radius:8px;margin-bottom:16px}
        ');
    }

    public function routes() {
        register_rest_route('onekhusa/v1', '/create', [
            'methods' => 'POST',
            'callback' => [$this, 'create_payment'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('onekhusa/v1', '/webhook', [
            'methods' => 'POST',
            'callback' => [$this, 'webhook'],
            'permission_callback' => '__return_true',
        ]);
    }

    private function products() {
        $s = $this->settings();

        $defaults = [
            'tummy-tonic' => ['name' => 'Tummy Tonic', 'price' => 0],
            'pompo-pompo' => ['name' => 'Pompo Pompo', 'price' => 0],
            'booty-booster' => ['name' => 'Booty Booster', 'price' => 0],
        ];

        if (!empty($s['products']) && is_array($s['products'])) {
            return array_merge($defaults, $s['products']);
        }

        return $defaults;
    }

    private function settings() {
        return get_option(self::OPT, []);
    }

    private function product_from_slug($slug) {
        $slug = sanitize_key($slug);
        $products = $this->products();

        return isset($products[$slug]) && (float)$products[$slug]['price'] > 0
            ? $products[$slug] + ['slug' => $slug]
            : null;
    }

    private function base_url() {
        $s = $this->settings();

        return (($s['environment'] ?? 'sandbox') === 'live')
            ? 'https://api.onekhusa.com/live/v1'
            : 'https://api.onekhusa.com/sandbox/v1';
    }

    private function checkout_page() {
        $s = $this->settings();
        return !empty($s['checkout_page']) ? $s['checkout_page'] : home_url('/');
    }

    private function success_page() {
        $s = $this->settings();
        return !empty($s['success_page']) ? $s['success_page'] : home_url('/');
    }

    private function get_access_token() {
        $s = $this->settings();

        if (
            empty($s['api_key']) ||
            empty($s['api_secret']) ||
            empty($s['organisation_id']) ||
            empty($s['merchant_account'])
        ) {
            return new WP_Error('onekhusa_credentials', 'OneKhusa credentials are not configured.');
        }

        $cache_key = 'okec_access_token_' . md5(
            $s['api_key'] . '|' . $s['organisation_id'] . '|' . $s['merchant_account']
        );

        $cached = get_transient($cache_key);
        if ($cached) return $cached;

        $response = wp_remote_post($this->base_url() . '/account/getAccessToken', [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept-Language' => 'en',
            ],
            'body' => wp_json_encode([
                'apiKey' => $s['api_key'],
                'apiSecret' => $s['api_secret'],
                'organisationId' => $s['organisation_id'],
                'merchantAccountNumber' => (int)$s['merchant_account'],
            ]),
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('onekhusa_token_connection', 'Could not connect to OneKhusa.');
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300 || empty($body['accessToken'])) {
            return new WP_Error(
                'onekhusa_token_error',
                !empty($body['message']) ? sanitize_text_field($body['message']) : 'OneKhusa authentication failed.'
            );
        }

        // OneKhusa tokens live for about 5 minutes; cache for 4 minutes.
        set_transient($cache_key, sanitize_text_field($body['accessToken']), 4 * MINUTE_IN_SECONDS);

        return sanitize_text_field($body['accessToken']);
    }

    public function buy_shortcode($atts) {
        $atts = shortcode_atts([
            'product' => '',
            'button' => 'Buy Now',
        ], $atts, 'onekhusa_buy');

        $product = $this->product_from_slug($atts['product']);
        if (!$product) return '<p>Product is not configured for checkout.</p>';

        $url = add_query_arg('product', $product['slug'], $this->checkout_page());

        return '<a class="okec-buy" href="' . esc_url($url) . '" style="display:block;text-align:center;text-decoration:none;box-sizing:border-box">' .
            esc_html($atts['button']) . '</a>';
    }

    public function checkout_shortcode() {
        $slug = sanitize_key($_GET['product'] ?? '');
        $product = $this->product_from_slug($slug);

        if (!$product) {
            return '<div class="okec-box"><p>Product not found or not configured.</p></div>';
        }

        $nonce = wp_create_nonce('okec_create_payment');

        ob_start();
        ?>
        <div class="okec-box" data-product="<?php echo esc_attr($product['slug']); ?>">
            <div class="okec-summary">
                <strong><?php echo esc_html($product['name']); ?></strong><br>
                MWK <?php echo esc_html(number_format((float)$product['price'], 0)); ?>
            </div>

            <label for="okec-name">Full name</label>
            <input id="okec-name" type="text" class="okec-name" autocomplete="name" required>

            <label for="okec-phone">Phone number</label>
            <input id="okec-phone" type="tel" class="okec-phone" autocomplete="tel" placeholder="265XXXXXXXXX" required>

            <label for="okec-address">Delivery address</label>
            <input id="okec-address" type="text" class="okec-address" autocomplete="street-address" required>

            <label for="okec-quantity">Quantity</label>
            <input id="okec-quantity" type="number" class="okec-quantity" min="1" max="20" value="1" required>

            <button type="button" class="okec-submit">Proceed to Payment</button>
            <div class="okec-msg" aria-live="polite"></div>
        </div>

        <script>
        document.addEventListener('click', function(e){
            if(!e.target.classList.contains('okec-submit')) return;

            const button = e.target;
            const box = button.closest('.okec-box');
            const msg = box.querySelector('.okec-msg');
            const name = box.querySelector('.okec-name').value.trim();
            const phone = box.querySelector('.okec-phone').value.trim();
            const address = box.querySelector('.okec-address').value.trim();
            const quantity = parseInt(box.querySelector('.okec-quantity').value, 10);

            if(!name || !phone || !address || !quantity || quantity < 1 || quantity > 20){
                msg.textContent = 'Please complete all fields correctly.';
                msg.className = 'okec-msg okec-error';
                return;
            }

            msg.textContent = 'Preparing secure OneKhusa payment...';
            msg.className = 'okec-msg';
            button.disabled = true;

            const fd = new FormData();
            fd.append('nonce', '<?php echo esc_js($nonce); ?>');
            fd.append('product', box.dataset.product);
            fd.append('name', name);
            fd.append('phone', phone);
            fd.append('address', address);
            fd.append('quantity', quantity);

            fetch('<?php echo esc_url(rest_url('onekhusa/v1/create')); ?>', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            }).then(async r => {
                const data = await r.json();

                if (!r.ok) {
                    throw new Error(data.message || 'Could not create payment.');
                }

                if(data.payment_link){
                    window.location.href = data.payment_link;
                    return;
                }

                throw new Error(data.message || 'OneKhusa did not return a checkout link.');
            }).catch(err => {
                msg.textContent = err.message;
                msg.className = 'okec-msg okec-error';
                button.disabled = false;
            });
        });
        </script>
        <?php

        return ob_get_clean();
    }

    public function create_payment(WP_REST_Request $request) {
        $nonce = $request->get_param('nonce');

        if (!$nonce || !wp_verify_nonce($nonce, 'okec_create_payment')) {
            return new WP_Error('bad_nonce', 'Security check failed.', ['status' => 403]);
        }

        $slug = sanitize_key($request->get_param('product'));
        $product = $this->product_from_slug($slug);

        $name = sanitize_text_field($request->get_param('name'));
        $phone = preg_replace('/[^0-9+]/', '', (string)$request->get_param('phone'));
        $address = sanitize_text_field($request->get_param('address'));
        $quantity = max(1, min(20, absint($request->get_param('quantity'))));

        if (!$product || !$name || !$phone || !$address || $quantity < 1) {
            return new WP_Error('missing_fields', 'Please complete all fields.', ['status' => 400]);
        }

        $s = $this->settings();

        $token = $this->get_access_token();
        if (is_wp_error($token)) {
            return new WP_Error(
                'onekhusa_auth',
                $token->get_error_message(),
                ['status' => 502]
            );
        }

        $amount = (float)$product['price'] * $quantity;
        $order_id = 'FK-' . gmdate('YmdHis') . '-' . wp_rand(1000, 9999);

        $post_id = wp_insert_post([
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'post_title' => $order_id . ' - ' . $product['name'],
        ]);

        if (!$post_id) {
            return new WP_Error('order_error', 'Could not create order.', ['status' => 500]);
        }

        update_post_meta($post_id, 'merchant_reference', $order_id);
        update_post_meta($post_id, 'product_slug', $product['slug']);
        update_post_meta($post_id, 'product', $product['name']);
        update_post_meta($post_id, 'unit_price', $product['price']);
        update_post_meta($post_id, 'quantity', $quantity);
        update_post_meta($post_id, 'amount', $amount);
        update_post_meta($post_id, 'customer_name', $name);
        update_post_meta($post_id, 'customer_phone', $phone);
        update_post_meta($post_id, 'delivery_address', $address);
        update_post_meta($post_id, 'status', 'Pending');

        $success = add_query_arg(
            'onekhusa_order',
            rawurlencode($order_id),
            $this->success_page()
        );

        $failure = add_query_arg(
            [
                'onekhusa_order' => rawurlencode($order_id),
                'payment_failed' => '1',
            ],
            $this->success_page()
        );

        $site_base = home_url('/');
        $webhook = rest_url('onekhusa/v1/webhook');

        $payload = [
            'authentication' => [
                'apiKey' => $s['api_key'],
                'apiSecret' => $s['api_secret'],
            ],
            'merchant' => [
                'organisationId' => $s['organisation_id'],
                'merchantAccountNumber' => (int)$s['merchant_account'],
            ],
            'payment' => [
                'sourceReferenceNumber' => $order_id,
                'description' => 'FocusKitty - ' . $product['name'] . ' x ' . $quantity,
                'amount' => $amount,
            ],
            'route' => [
                'successRedirectionUrl' => $success,
                'failureRedirectionUrl' => $failure,
                'callbackApiUrl' => $webhook,
            ],
        ];

        $idempotency_key = 'FK-' . wp_generate_uuid4();

        $response = wp_remote_post(
            $this->base_url() . '/checkout/rtp/initiate',
            [
                'timeout' => 30,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept-Language' => 'en',
                    'Authorization' => 'Bearer ' . $token,
                    'X-Idempotency-Key' => $idempotency_key,
                ],
                'body' => wp_json_encode($payload),
            ]
        );

        if (is_wp_error($response)) {
            update_post_meta($post_id, 'status', 'Error');
            return new WP_Error('onekhusa_connection', 'Could not connect to OneKhusa.', ['status' => 502]);
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300 || empty($body['paymentTransactionId'])) {
            update_post_meta($post_id, 'status', 'Error');

            $message = !empty($body['message'])
                ? sanitize_text_field($body['message'])
                : 'OneKhusa did not return a payment transaction ID.';

            return new WP_Error('onekhusa_error', $message, ['status' => 502]);
        }

        $payment_transaction_id = sanitize_text_field($body['paymentTransactionId']);

        update_post_meta($post_id, 'payment_transaction_id', $payment_transaction_id);
        update_post_meta($post_id, 'idempotency_key', $idempotency_key);

        $payment_link = add_query_arg(
            'ptid',
            rawurlencode($payment_transaction_id),
            'https://checkout.onekhusa.com/requestToPay/initiate'
        );

        update_post_meta($post_id, 'payment_link', esc_url_raw($payment_link));

        return rest_ensure_response([
            'payment_link' => esc_url_raw($payment_link),
            'order_id' => $order_id,
        ]);
    }

    private function find_order($reference) {
        $posts = get_posts([
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'numberposts' => 1,
            'meta_key' => 'merchant_reference',
            'meta_value' => $reference,
        ]);

        return $posts ? $posts[0]->ID : 0;
    }

    private function webhook_signature_valid($raw_body, $signature) {
        $s = $this->settings();

        if (empty($s['webhook_secret']) || empty($signature)) {
            return false;
        }

        $expected = hash_hmac('sha512', $raw_body, $s['webhook_secret']);

        return hash_equals(strtolower($expected), strtolower(trim($signature)));
    }

    public function webhook(WP_REST_Request $request) {
        $raw_body = $request->get_body();
        $signature = $request->get_header('X-OneKhusa-Webhook-Signature');

        if (!$this->webhook_signature_valid($raw_body, $signature)) {
            return new WP_REST_Response(['ok' => false, 'message' => 'Invalid webhook signature.'], 401);
        }

        $data = json_decode($raw_body, true);
        if (!is_array($data)) {
            return new WP_REST_Response(['ok' => false], 400);
        }

        $event = strtolower(sanitize_text_field(
            $request->get_header('X-OneKhusa-Webhook-Event')
        ));

        $reference = sanitize_text_field(
            $data['sourceReferenceNumber']
            ?? $data['referenceNumber']
            ?? $data['ReferenceNumber']
            ?? $data['payment']['sourceReferenceNumber']
            ?? $data['metaData']['referenceNumber']
            ?? $data['metaData']['ReferenceNumber']
            ?? ''
        );

        if (!$reference) {
            return new WP_REST_Response(['ok' => true], 200);
        }

        $id = $this->find_order($reference);

        // Acknowledge unknown references so OneKhusa does not repeatedly retry
        // an event that does not belong to this WordPress installation.
        if (!$id) {
            return new WP_REST_Response(['acknowledged' => true], 200);
        }

        $status_code = strtoupper(sanitize_text_field(
            $data['transactionStatusCode'] ?? $data['status'] ?? ''
        ));

        $is_success = in_array($event, ['payment.success', 'payrequest.success'], true)
            || $status_code === 'S';

        $is_failed = in_array($event, ['payment.failed', 'payrequest.failed', 'payment.reversed', 'payrequest.reversed'], true)
            || $status_code === 'F';

        if ($is_success) {
            update_post_meta($id, 'status', 'Paid');
        } elseif ($is_failed) {
            update_post_meta($id, 'status', 'Failed');
        } else {
            update_post_meta($id, 'status', 'Pending');
        }

        $transaction_ref = sanitize_text_field(
            $data['transactionReferenceNumber']
            ?? $data['TransactionReferenceNumber']
            ?? ''
        );

        if ($transaction_ref) {
            update_post_meta($id, 'transaction_reference', $transaction_ref);
        }

        if (isset($data['transactionAmount'])) {
            update_post_meta($id, 'confirmed_amount', (float)$data['transactionAmount']);
        }

        update_post_meta($id, 'webhook_event', $event);
        update_post_meta($id, 'webhook_received_at', current_time('mysql', true));

        return new WP_REST_Response(['acknowledged' => true], 200);
    }

    public function order_status_shortcode() {
        $reference = sanitize_text_field($_GET['onekhusa_order'] ?? '');

        if (!$reference) {
            return '<div class="okec-box"><p>No order was supplied.</p></div>';
        }

        $id = $this->find_order($reference);

        if (!$id) {
            return '<div class="okec-box"><p>Order not found.</p></div>';
        }

        $status = get_post_meta($id, 'status', true) ?: 'Pending';
        $product = get_post_meta($id, 'product', true);
        $amount = (float)get_post_meta($id, 'amount', true);
        $transaction = get_post_meta($id, 'transaction_reference', true);

        $failed_redirect = !empty($_GET['payment_failed']);

        if ($status === 'Paid') {
            $html = '<div class="okec-box okec-success"><h3>Payment successful</h3><p>Thank you. Your FocusKitty order has been received.</p>';
        } elseif ($status === 'Failed' || $failed_redirect) {
            $html = '<div class="okec-box okec-error"><h3>Payment not completed</h3><p>Your payment was not completed. You can return to checkout and try again.</p>';
        } else {
            $html = '<div class="okec-box"><h3>Payment processing</h3><p>We are waiting for OneKhusa to confirm your payment.</p>';
        }

        $html .= '<div class="okec-summary"><strong>Order:</strong> ' . esc_html($reference) .
            '<br><strong>Product:</strong> ' . esc_html($product) .
            '<br><strong>Total:</strong> MWK ' . esc_html(number_format($amount, 0));

        if ($transaction) {
            $html .= '<br><strong>Transaction:</strong> ' . esc_html($transaction);
        }

        $html .= '</div></div>';

        // Keep the page useful if the webhook arrives a few seconds after the redirect.
        if ($status === 'Pending' && !$failed_redirect) {
            $html .= '<script>
                setTimeout(function(){ window.location.reload(); }, 5000);
            </script>';
        }

        return $html;
    }
}

new OneKhusa_Elementor_Checkout();
