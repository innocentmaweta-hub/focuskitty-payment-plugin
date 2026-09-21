<?php
/**
 * Plugin Name: PayChangu Direct Charge for FocusKitty
 * Description: PayChangu Mobile Money Direct Charge integration for Gutenberg/Elementor WordPress without WooCommerce.
 * Version: 5.0.0
 * Author: Custom Integration
 */

if (!defined('ABSPATH')) exit;

class FocusKitty_PayChangu_Checkout {
    const OPT = 'okec_settings';
    const CPT = 'okec_order';

    public function __construct() {
        add_action('init', [$this, 'register_order_cpt']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        add_shortcode('onekhusa_buy', [$this, 'buy_shortcode']);
        add_shortcode('onekhusa_checkout', [$this, 'checkout_shortcode']);
        add_shortcode('onekhusa_order_status', [$this, 'order_status_shortcode']);

        add_shortcode('malipo_buy', [$this, 'buy_shortcode']);
        add_shortcode('malipo_checkout', [$this, 'checkout_shortcode']);
        add_shortcode('malipo_order_status', [$this, 'order_status_shortcode']);

        add_action('rest_api_init', [$this, 'routes']);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
    }

    public function register_order_cpt() {
        register_post_type(self::CPT, [
            'labels' => ['name' => 'FocusKitty Orders', 'singular_name' => 'FocusKitty Order'],
            'public' => false,
            'show_ui' => true,
            'menu_icon' => 'dashicons-cart',
            'supports' => ['title'],
        ]);
    }

    public function admin_menu() {
        add_options_page(
            'PayChangu Checkout',
            'PayChangu Checkout',
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
                    'secret_key' => sanitize_text_field($v['secret_key'] ?? ''),
                    'webhook_secret' => sanitize_text_field($v['webhook_secret'] ?? ''),
                    'environment' => (($v['environment'] ?? 'test') === 'live') ? 'live' : 'test',
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
        $webhook = rest_url('paychangu/v1/webhook');
        ?>
        <div class="wrap">
            <h1>PayChangu Direct Charge</h1>
            <p>
                FocusKitty uses PayChangu Mobile Money Direct Charge. Customers enter their details on your
                checkout page, PayChangu initiates the mobile-money charge, and your webhook confirms the result.
                No WooCommerce is required.
            </p>

            <form method="post" action="options.php">
                <?php settings_fields('okec_settings_group'); ?>

                <h2>PayChangu API</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="okec_environment">Environment</label></th>
                        <td>
                            <select id="okec_environment" name="<?php echo esc_attr(self::OPT); ?>[environment]">
                                <option value="test" <?php selected($s['environment'] ?? 'test', 'test'); ?>>Test / Sandbox</option>
                                <option value="live" <?php selected($s['environment'] ?? 'test', 'live'); ?>>Live / Production</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="okec_secret_key">PayChangu Secret Key</label></th>
                        <td>
                            <input type="password" class="regular-text" id="okec_secret_key"
                                name="<?php echo esc_attr(self::OPT); ?>[secret_key]"
                                value="<?php echo esc_attr($s['secret_key'] ?? ''); ?>"
                                autocomplete="new-password">
                            <p class="description">Use the PayChangu test key for testing and live secret key for production. Keep it server-side.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="okec_webhook_secret">Webhook Secret</label></th>
                        <td>
                            <input type="password" class="regular-text" id="okec_webhook_secret"
                                name="<?php echo esc_attr(self::OPT); ?>[webhook_secret]"
                                value="<?php echo esc_attr($s['webhook_secret'] ?? ''); ?>"
                                autocomplete="new-password">
                            <p class="description">The webhook secret from PayChangu Dashboard → Settings → API & Webhooks.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="okec_checkout_page">Checkout Page URL</label></th>
                        <td>
                            <input type="url" class="regular-text" id="okec_checkout_page"
                                name="<?php echo esc_attr(self::OPT); ?>[checkout_page]"
                                value="<?php echo esc_attr($s['checkout_page'] ?? ''); ?>">
                            <p class="description">Gutenberg page containing <code>[onekhusa_checkout]</code>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="okec_success_page">Success / Status Page URL</label></th>
                        <td>
                            <input type="url" class="regular-text" id="okec_success_page"
                                name="<?php echo esc_attr(self::OPT); ?>[success_page]"
                                value="<?php echo esc_attr($s['success_page'] ?? ''); ?>">
                            <p class="description">Page containing <code>[onekhusa_order_status]</code>.</p>
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

                <?php submit_button('Save PayChangu Settings'); ?>
            </form>

            <h2>Shortcodes</h2>
            <p><strong>Product page:</strong> <code>[onekhusa_buy product="tummy-tonic"]</code></p>
            <p><strong>Checkout page:</strong> <code>[onekhusa_checkout]</code></p>
            <p><strong>Status page:</strong> <code>[onekhusa_order_status]</code></p>

            <h2>PayChangu Webhook URL</h2>
            <p><code><?php echo esc_html($webhook); ?></code></p>
            <p class="description">Add this URL under PayChangu Dashboard → Settings → API & Webhooks.</p>
        </div>
        <?php
    }

    public function assets() {
        wp_register_style('okec-style', false);
        wp_enqueue_style('okec-style');
        wp_add_inline_style('okec-style', '
            .okec-box{max-width:520px;padding:24px;border:1px solid #ddd;border-radius:12px;background:#fff;box-sizing:border-box}
            .okec-box label{display:block;margin:0 0 6px;font-weight:600}
            .okec-box input,.okec-box select{width:100%;padding:11px;margin:0 0 14px;box-sizing:border-box}
            .okec-buy,.okec-submit{width:100%;padding:13px;border:0;border-radius:8px;background:#111;color:#fff;font-weight:700;cursor:pointer}
            .okec-buy[disabled],.okec-submit[disabled]{opacity:.6;cursor:wait}
            .okec-msg{margin-top:12px}.okec-error{color:#b00020}.okec-success{color:#087f23}
            .okec-summary{padding:12px;background:#f7f7f7;border-radius:8px;margin-bottom:16px}
        ');
    }

    public function routes() {
        register_rest_route('paychangu/v1', '/create', [
            'methods' => 'POST',
            'callback' => [$this, 'create_payment'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('paychangu/v1', '/webhook', [
            'methods' => 'POST',
            'callback' => [$this, 'webhook'],
            'permission_callback' => '__return_true',
        ]);
    }

    private function settings() {
        return get_option(self::OPT, []);
    }

    private function products() {
        $s = $this->settings();
        $defaults = [
            'tummy-tonic' => ['name' => 'Tummy Tonic', 'price' => 0],
            'pompo-pompo' => ['name' => 'Pompo Pompo', 'price' => 0],
            'booty-booster' => ['name' => 'Booty Booster', 'price' => 0],
        ];
        return (!empty($s['products']) && is_array($s['products']))
            ? array_merge($defaults, $s['products'])
            : $defaults;
    }

    private function product_from_slug($slug) {
        $slug = sanitize_key($slug);
        $products = $this->products();
        return isset($products[$slug]) && (float)$products[$slug]['price'] > 0
            ? $products[$slug] + ['slug' => $slug]
            : null;
    }

    private function api_url($path = '') {
        return 'https://api.paychangu.com' . $path;
    }

    private function secret_key() {
        $s = $this->settings();
        return trim((string)($s['secret_key'] ?? ''));
    }

    private function checkout_page() {
        $s = $this->settings();
        return !empty($s['checkout_page']) ? $s['checkout_page'] : home_url('/');
    }

    private function success_page() {
        $s = $this->settings();
        return !empty($s['success_page']) ? $s['success_page'] : home_url('/');
    }

    private function normalize_phone($phone) {
        $phone = preg_replace('/[^0-9+]/', '', (string)$phone);
        if (strpos($phone, '+265') === 0) return substr($phone, 1);
        if (strpos($phone, '265') === 0) return $phone;
        if (strpos($phone, '0') === 0 && strlen($phone) >= 9) return '265' . substr($phone, 1);
        return $phone;
    }

    private function operator_id_for_network($network) {
        $network = sanitize_key($network);
        $response = wp_remote_get($this->api_url('/mobile-money'), [
            'timeout' => 20,
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $this->secret_key(),
            ],
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('paychangu_operator_connection', 'Could not connect to PayChangu to load mobile-money operators.');
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300) {
            return new WP_Error('paychangu_operator_error', 'PayChangu could not return supported mobile-money operators.');
        }

        $items = [];
        if (!empty($body['data']) && is_array($body['data'])) $items = $body['data'];
        elseif (!empty($body['operators']) && is_array($body['operators'])) $items = $body['operators'];

        $wanted = $network === 'airtel'
            ? ['airtel', 'airtel money']
            : ['tnm', 'mpamba', 'tnm mpamba'];

        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $name = strtolower((string)($item['name'] ?? $item['operator'] ?? $item['display_name'] ?? ''));
            $id = $item['ref_id'] ?? $item['id'] ?? $item['operator_ref_id'] ?? '';
            if (!$id) continue;

            foreach ($wanted as $needle) {
                if (strpos($name, $needle) !== false) return sanitize_text_field($id);
            }
        }

        return new WP_Error(
            'paychangu_operator_not_found',
            'PayChangu did not return a supported operator ID for the selected network.'
        );
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

        if (!$product) return '<div class="okec-box"><p>Product not found or not configured.</p></div>';

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
            <input id="okec-phone" type="tel" class="okec-phone" autocomplete="tel" placeholder="099XXXXXXXX" required>

            <label for="okec-network">Mobile network</label>
            <select id="okec-network" class="okec-network" required>
                <option value="">Select mobile network</option>
                <option value="airtel">Airtel Money</option>
                <option value="tnm">TNM Mpamba</option>
            </select>

            <label for="okec-address">Delivery address</label>
            <input id="okec-address" type="text" class="okec-address" autocomplete="street-address" required>

            <label for="okec-quantity">Quantity</label>
            <input id="okec-quantity" type="number" class="okec-quantity" min="1" max="20" value="1" required>

            <button type="button" class="okec-submit">Pay Now</button>
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
            const network = box.querySelector('.okec-network').value;
            const address = box.querySelector('.okec-address').value.trim();
            const quantity = parseInt(box.querySelector('.okec-quantity').value, 10);

            if(!name || !phone || !network || !address || !quantity || quantity < 1 || quantity > 20){
                msg.textContent = 'Please complete all fields correctly.';
                msg.className = 'okec-msg okec-error';
                return;
            }

            msg.textContent = 'Sending payment request to your phone...';
            msg.className = 'okec-msg';
            button.disabled = true;

            const fd = new FormData();
            fd.append('product', box.dataset.product);
            fd.append('name', name);
            fd.append('phone', phone);
            fd.append('network', network);
            fd.append('address', address);
            fd.append('quantity', quantity);

            fetch('<?php echo esc_url(rest_url('paychangu/v1/create')); ?>', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            }).then(async r => {
                const data = await r.json();

                if(!r.ok){
                    throw new Error(data.message || 'Could not start the payment.');
                }

                msg.textContent = data.message || 'Payment request sent. Please authorize it on your phone.';
                msg.className = 'okec-msg okec-success';

                if(data.order_url){
                    window.location.href = data.order_url;
                }
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
        if (!$this->secret_key()) {
            return new WP_Error('paychangu_credentials', 'PayChangu Secret Key is not configured.', ['status' => 500]);
        }

        $slug = sanitize_key($request->get_param('product'));
        $product = $this->product_from_slug($slug);
        $name = sanitize_text_field($request->get_param('name'));
        $phone = $this->normalize_phone($request->get_param('phone'));
        $address = sanitize_text_field($request->get_param('address'));
        $network = sanitize_key($request->get_param('network'));
        $quantity = max(1, min(20, absint($request->get_param('quantity'))));

        if (!$product || !$name || !$phone || !in_array($network, ['airtel','tnm'], true) || !$address) {
            return new WP_Error('missing_fields', 'Please complete all fields.', ['status' => 400]);
        }

        $amount = (float)$product['price'] * $quantity;
        $order_ref = 'FK-' . gmdate('YmdHis') . '-' . wp_rand(1000, 9999);
        $charge_id = 'FKC-' . gmdate('YmdHis') . '-' . wp_rand(100000, 999999);

        $post_id = wp_insert_post([
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'post_title' => $order_ref . ' - ' . $product['name'],
        ]);

        if (!$post_id) {
            return new WP_Error('order_error', 'Could not create order.', ['status' => 500]);
        }

        update_post_meta($post_id, 'merchant_reference', $order_ref);
        update_post_meta($post_id, 'charge_id', $charge_id);
        update_post_meta($post_id, 'product_slug', $product['slug']);
        update_post_meta($post_id, 'product', $product['name']);
        update_post_meta($post_id, 'unit_price', $product['price']);
        update_post_meta($post_id, 'quantity', $quantity);
        update_post_meta($post_id, 'amount', $amount);
        update_post_meta($post_id, 'customer_name', $name);
        update_post_meta($post_id, 'customer_phone', $phone);
        update_post_meta($post_id, 'network', $network);
        update_post_meta($post_id, 'delivery_address', $address);
        update_post_meta($post_id, 'status', 'Pending');

        $operator_id = $this->operator_id_for_network($network);
        if (is_wp_error($operator_id)) {
            update_post_meta($post_id, 'status', 'Error');
            return new WP_Error('paychangu_operator', $operator_id->get_error_message(), ['status' => 502]);
        }

        $payload = [
            'mobile' => $phone,
            'mobile_money_operator_ref_id' => $operator_id,
            'amount' => (string)$amount,
            'charge_id' => $charge_id,
            'first_name' => $name,
        ];

        $response = wp_remote_post($this->api_url('/mobile-money/payments/initialize'), [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->secret_key(),
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            update_post_meta($post_id, 'status', 'Error');
            return new WP_Error('paychangu_connection', 'Could not connect to PayChangu.', ['status' => 502]);
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $body = json_decode($raw, true);

        update_post_meta($post_id, 'initiation_response', $raw);

        if ($code < 200 || $code >= 300) {
            update_post_meta($post_id, 'status', 'Error');
            $message = !empty($body['message']) ? sanitize_text_field($body['message']) : 'PayChangu could not initiate the payment.';
            return new WP_Error('paychangu_error', 'PayChangu payment failed: ' . $message, ['status' => 502]);
        }

        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $returned_charge_id = sanitize_text_field($data['charge_id'] ?? $body['charge_id'] ?? $charge_id);
        $payment_status = strtolower(sanitize_text_field($data['status'] ?? $body['status'] ?? 'pending'));

        update_post_meta($post_id, 'charge_id', $returned_charge_id);
        update_post_meta($post_id, 'paychangu_status', $payment_status);

        if (in_array($payment_status, ['success', 'successful', 'completed'], true)) {
            update_post_meta($post_id, 'status', 'Paid');
        } elseif (in_array($payment_status, ['failed', 'cancelled', 'canceled'], true)) {
            update_post_meta($post_id, 'status', 'Failed');
        }

        $order_url = add_query_arg('onekhusa_order', $order_ref, $this->success_page());

        return rest_ensure_response([
            'success' => true,
            'status' => $payment_status,
            'message' => in_array($payment_status, ['success','successful','completed'], true)
                ? 'Payment completed successfully.'
                : 'Payment request sent. Please authorize the payment on your phone.',
            'order_id' => $order_ref,
            'order_url' => $order_url,
        ]);
    }

    private function find_order($reference) {
        $reference = sanitize_text_field($reference);
        if (!$reference) return 0;

        $posts = get_posts([
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'numberposts' => 1,
            'meta_query' => [
                'relation' => 'OR',
                ['key' => 'merchant_reference', 'value' => $reference],
                ['key' => 'charge_id', 'value' => $reference],
            ],
        ]);

        return $posts ? $posts[0]->ID : 0;
    }

    private function webhook_signature_valid($raw_body, $signature) {
        $s = $this->settings();
        if (empty($s['webhook_secret']) || empty($signature)) return false;
        $expected = hash_hmac('sha256', $raw_body, $s['webhook_secret']);
        return hash_equals(strtolower($expected), strtolower(trim($signature)));
    }

    public function webhook(WP_REST_Request $request) {
        $raw_body = $request->get_body();
        $signature = $request->get_header('Signature');

        if (!$this->webhook_signature_valid($raw_body, $signature)) {
            return new WP_REST_Response(['ok' => false, 'message' => 'Invalid webhook signature.'], 401);
        }

        $data = json_decode($raw_body, true);
        if (!is_array($data)) return new WP_REST_Response(['ok' => false], 400);

        $reference = sanitize_text_field(
            $data['charge_id']
            ?? $data['reference']
            ?? $data['ref_id']
            ?? $data['data']['charge_id']
            ?? $data['data']['reference']
            ?? ''
        );

        $id = $this->find_order($reference);
        if (!$id) return new WP_REST_Response(['acknowledged' => true], 200);

        $status = strtolower(sanitize_text_field(
            $data['status'] ?? $data['data']['status'] ?? ''
        ));

        if (in_array($status, ['success','successful','completed'], true)) {
            update_post_meta($id, 'status', 'Paid');
        } elseif (in_array($status, ['failed','cancelled','canceled','reversed'], true)) {
            update_post_meta($id, 'status', 'Failed');
        } else {
            update_post_meta($id, 'status', 'Pending');
        }

        update_post_meta($id, 'paychangu_webhook', $raw_body);
        update_post_meta($id, 'webhook_received_at', current_time('mysql', true));

        if (!empty($data['charge_id'])) update_post_meta($id, 'charge_id', sanitize_text_field($data['charge_id']));
        if (!empty($data['data']['charge_id'])) update_post_meta($id, 'charge_id', sanitize_text_field($data['data']['charge_id']));

        return new WP_REST_Response(['acknowledged' => true], 200);
    }

    public function order_status_shortcode() {
        $reference = sanitize_text_field($_GET['onekhusa_order'] ?? '');
        if (!$reference) return '<div class="okec-box"><p>No order was supplied.</p></div>';

        $id = $this->find_order($reference);
        if (!$id) return '<div class="okec-box"><p>Order not found.</p></div>';

        $status = get_post_meta($id, 'status', true) ?: 'Pending';
        $product = get_post_meta($id, 'product', true);
        $amount = (float)get_post_meta($id, 'amount', true);
        $charge_id = get_post_meta($id, 'charge_id', true);

        if ($status === 'Paid') {
            $html = '<div class="okec-box okec-success"><h3>Payment successful</h3><p>Thank you. Your FocusKitty order has been received.</p>';
        } elseif ($status === 'Failed') {
            $html = '<div class="okec-box okec-error"><h3>Payment not completed</h3><p>Your payment was not completed. Please return to checkout and try again.</p>';
        } elseif ($status === 'Error') {
            $html = '<div class="okec-box okec-error"><h3>Payment error</h3><p>We could not start the payment. Please try again.</p>';
        } else {
            $html = '<div class="okec-box"><h3>Waiting for payment</h3><p>Payment request sent. Please authorize the payment on your phone.</p>';
        }

        $html .= '<div class="okec-summary"><strong>Order:</strong> ' . esc_html($reference) .
            '<br><strong>Product:</strong> ' . esc_html($product) .
            '<br><strong>Total:</strong> MWK ' . esc_html(number_format($amount, 0));

        if ($charge_id) $html .= '<br><strong>Payment reference:</strong> ' . esc_html($charge_id);
        $html .= '</div></div>';

        if ($status === 'Pending') {
            $html .= '<script>setTimeout(function(){ window.location.reload(); }, 5000);</script>';
        }

        return $html;
    }
}

new FocusKitty_PayChangu_Checkout();
