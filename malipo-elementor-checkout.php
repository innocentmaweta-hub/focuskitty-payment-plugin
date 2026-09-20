<?php
/**
 * Plugin Name: Malipo Checkout for Elementor
 * Description: Secure Malipo hosted checkout for Elementor/WordPress without WooCommerce.
 * Version: 1.1.0
 * Author: Custom Integration
 */

if (!defined('ABSPATH')) exit;

class Malipo_Elementor_Checkout {
    const OPT = 'mec_settings';
    const CPT = 'mec_order';

    public function __construct() {
        add_action('init', [$this, 'register_order_cpt']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_shortcode('malipo_buy', [$this, 'buy_shortcode']);
        add_shortcode('malipo_checkout', [$this, 'checkout_shortcode']);
        add_shortcode('malipo_order_status', [$this, 'order_status_shortcode']);
        add_action('rest_api_init', [$this, 'routes']);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
    }

    public function register_order_cpt() {
        register_post_type(self::CPT, [
            'labels' => ['name' => 'Malipo Orders', 'singular_name' => 'Malipo Order'],
            'public' => false,
            'show_ui' => true,
            'menu_icon' => 'dashicons-cart',
            'supports' => ['title'],
        ]);
    }

    public function admin_menu() {
        add_options_page('Malipo Checkout', 'Malipo Checkout', 'manage_options', 'mec-settings', [$this, 'settings_page']);
    }

    public function register_settings() {
        register_setting('mec_settings_group', self::OPT, [
            'sanitize_callback' => function($v) {
                $products = [];
                $names = ['tummy-tonic' => 'Tummy Tonic', 'pompo-pompo' => 'Pompo Pompo', 'booty-booster' => 'Booty Booster'];
                foreach ($names as $slug => $name) {
                    $products[$slug] = [
                        'name' => $name,
                        'price' => max(0, (float)($v['products'][$slug]['price'] ?? 0)),
                    ];
                }
                return [
                    'app_id' => sanitize_text_field($v['app_id'] ?? ''),
                    'api_key' => sanitize_text_field($v['api_key'] ?? ''),
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
        ?>
        <div class="wrap">
            <h1>Malipo Checkout</h1>
            <p>Configure Malipo and your FocusKitty product prices. API credentials stay on the server and are never sent to customers.</p>
            <form method="post" action="options.php">
                <?php settings_fields('mec_settings_group'); ?>
                <table class="form-table">
                    <tr><th><label for="mec_app_id">App ID</label></th><td>
                        <input class="regular-text" id="mec_app_id" name="<?php echo esc_attr(self::OPT); ?>[app_id]" value="<?php echo esc_attr($s['app_id'] ?? ''); ?>">
                    </td></tr>
                    <tr><th><label for="mec_api_key">API Key</label></th><td>
                        <input type="password" class="regular-text" id="mec_api_key" name="<?php echo esc_attr(self::OPT); ?>[api_key]" value="<?php echo esc_attr($s['api_key'] ?? ''); ?>" autocomplete="new-password">
                    </td></tr>
                    <tr><th><label for="mec_checkout_page">Checkout Page URL</label></th><td>
                        <input type="url" class="regular-text" id="mec_checkout_page" name="<?php echo esc_attr(self::OPT); ?>[checkout_page]" value="<?php echo esc_attr($s['checkout_page'] ?? ''); ?>">
                        <p class="description">The Elementor page containing <code>[malipo_checkout]</code>.</p>
                    </td></tr>
                    <tr><th><label for="mec_success_page">Success Page URL</label></th><td>
                        <input type="url" class="regular-text" id="mec_success_page" name="<?php echo esc_attr(self::OPT); ?>[success_page]" value="<?php echo esc_attr($s['success_page'] ?? ''); ?>">
                        <p class="description">The Elementor page containing <code>[malipo_order_status]</code>.</p>
                    </td></tr>
                </table>

                <h2>FocusKitty Products</h2>
                <table class="widefat striped" style="max-width:700px">
                    <thead><tr><th>Product</th><th>Price (MWK)</th></tr></thead>
                    <tbody>
                    <?php foreach ($products as $slug => $product): ?>
                        <tr>
                            <td><?php echo esc_html($product['name']); ?></td>
                            <td><input type="number" min="1" step="1" name="<?php echo esc_attr(self::OPT); ?>[products][<?php echo esc_attr($slug); ?>][price]" value="<?php echo esc_attr($product['price']); ?>"></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php submit_button('Save Malipo Settings'); ?>
            </form>

            <h2>How to build the Elementor pages</h2>
            <p><strong>Product page:</strong> Add a Shortcode widget with, for example:</p>
            <code>[malipo_buy product="tummy-tonic"]</code>
            <p><strong>Checkout page:</strong> Add:</p>
            <code>[malipo_checkout]</code>
            <p><strong>Success page:</strong> Add:</p>
            <code>[malipo_order_status]</code>

            <h2>Callback URL</h2>
            <code><?php echo esc_html(rest_url('malipo/v1/callback')); ?></code>
            <p>Give this URL to Malipo if your merchant setup asks for an IPN/callback URL.</p>
        </div>
        <?php
    }

    public function assets() {
        wp_register_style('mec-style', false);
        wp_enqueue_style('mec-style');
        wp_add_inline_style('mec-style', '
            .mec-box{max-width:520px;padding:24px;border:1px solid #ddd;border-radius:12px;background:#fff;box-sizing:border-box}
            .mec-box label{display:block;margin:0 0 6px;font-weight:600}
            .mec-box input{width:100%;padding:11px;margin:0 0 14px;box-sizing:border-box}
            .mec-buy,.mec-submit{width:100%;padding:13px;border:0;border-radius:8px;background:#111;color:#fff;font-weight:700;cursor:pointer}
            .mec-buy[disabled],.mec-submit[disabled]{opacity:.6;cursor:wait}
            .mec-msg{margin-top:12px}.mec-error{color:#b00020}.mec-success{color:#087f23}
            .mec-summary{padding:12px;background:#f7f7f7;border-radius:8px;margin-bottom:16px}
        ');
    }

    public function routes() {
        register_rest_route('malipo/v1', '/create', [
            'methods' => 'POST',
            'callback' => [$this, 'create_payment'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('malipo/v1', '/callback', [
            'methods' => 'POST',
            'callback' => [$this, 'callback'],
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
        return !empty($s['products']) && is_array($s['products']) ? array_merge($defaults, $s['products']) : $defaults;
    }

    private function settings() {
        return get_option(self::OPT, []);
    }

    private function product_from_slug($slug) {
        $slug = sanitize_key($slug);
        $products = $this->products();
        return isset($products[$slug]) && (float)$products[$slug]['price'] > 0 ? $products[$slug] + ['slug' => $slug] : null;
    }

    public function buy_shortcode($atts) {
        $atts = shortcode_atts([
            'product' => '',
            'button' => 'Buy Now',
        ], $atts, 'malipo_buy');

        $product = $this->product_from_slug($atts['product']);
        if (!$product) return '<p>Product is not configured for checkout.</p>';

        $s = $this->settings();
        $checkout = !empty($s['checkout_page']) ? $s['checkout_page'] : home_url('/');
        $url = add_query_arg('product', $product['slug'], $checkout);

        return '<a class="mec-buy" href="' . esc_url($url) . '" style="display:block;text-align:center;text-decoration:none;box-sizing:border-box">' . esc_html($atts['button']) . '</a>';
    }

    public function checkout_shortcode() {
        $slug = sanitize_key($_GET['product'] ?? '');
        $product = $this->product_from_slug($slug);
        if (!$product) return '<div class="mec-box"><p>Product not found or not configured.</p></div>';

        $nonce = wp_create_nonce('mec_create_payment');
        ob_start(); ?>
        <div class="mec-box" data-product="<?php echo esc_attr($product['slug']); ?>">
            <div class="mec-summary">
                <strong><?php echo esc_html($product['name']); ?></strong><br>
                MWK <?php echo esc_html(number_format((float)$product['price'], 0)); ?>
            </div>

            <label for="mec-name">Full name</label>
            <input id="mec-name" type="text" class="mec-name" autocomplete="name" required>

            <label for="mec-phone">Phone number</label>
            <input id="mec-phone" type="tel" class="mec-phone" autocomplete="tel" placeholder="265XXXXXXXXX" required>

            <label for="mec-address">Delivery address</label>
            <input id="mec-address" type="text" class="mec-address" autocomplete="street-address" required>

            <label for="mec-quantity">Quantity</label>
            <input id="mec-quantity" type="number" class="mec-quantity" min="1" max="20" value="1" required>

            <button type="button" class="mec-submit">Proceed to Malipo</button>
            <div class="mec-msg" aria-live="polite"></div>
        </div>

        <script>
        document.addEventListener('click', function(e){
            if(!e.target.classList.contains('mec-submit')) return;

            const button = e.target;
            const box = button.closest('.mec-box');
            const msg = box.querySelector('.mec-msg');
            const name = box.querySelector('.mec-name').value.trim();
            const phone = box.querySelector('.mec-phone').value.trim();
            const address = box.querySelector('.mec-address').value.trim();
            const quantity = parseInt(box.querySelector('.mec-quantity').value, 10);

            if(!name || !phone || !address || !quantity || quantity < 1 || quantity > 20){
                msg.textContent = 'Please complete all fields correctly.';
                msg.className = 'mec-msg mec-error';
                return;
            }

            msg.textContent = 'Preparing secure payment...';
            msg.className = 'mec-msg';
            button.disabled = true;

            const fd = new FormData();
            fd.append('nonce', '<?php echo esc_js($nonce); ?>');
            fd.append('product', box.dataset.product);
            fd.append('name', name);
            fd.append('phone', phone);
            fd.append('address', address);
            fd.append('quantity', quantity);

            fetch('<?php echo esc_url(rest_url('malipo/v1/create')); ?>', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            }).then(async r => {
                const data = await r.json();
                if (!r.ok) throw new Error(data.message || 'Could not create payment.');
                if(data.payment_link){ window.location.href = data.payment_link; return; }
                throw new Error(data.message || 'Could not create payment.');
            }).catch(err => {
                msg.textContent = err.message;
                msg.className = 'mec-msg mec-error';
                button.disabled = false;
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    public function create_payment(WP_REST_Request $request) {
        $nonce = $request->get_param('nonce');
        if (!$nonce || !wp_verify_nonce($nonce, 'mec_create_payment')) {
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
        if (empty($s['app_id']) || empty($s['api_key'])) {
            return new WP_Error('not_configured', 'Malipo is not configured yet.', ['status' => 500]);
        }

        $unit_price = (float)$product['price'];
        $amount = $unit_price * $quantity;
        $order_id = 'FK-' . gmdate('YmdHis') . '-' . wp_rand(1000, 9999);

        $post_id = wp_insert_post([
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'post_title' => $order_id . ' - ' . $product['name'],
        ]);

        if (!$post_id) return new WP_Error('order_error', 'Could not create order.', ['status' => 500]);

        update_post_meta($post_id, 'merchant_trx_id', $order_id);
        update_post_meta($post_id, 'product_slug', $product['slug']);
        update_post_meta($post_id, 'product', $product['name']);
        update_post_meta($post_id, 'unit_price', $unit_price);
        update_post_meta($post_id, 'quantity', $quantity);
        update_post_meta($post_id, 'amount', $amount);
        update_post_meta($post_id, 'customer_name', $name);
        update_post_meta($post_id, 'customer_phone', $phone);
        update_post_meta($post_id, 'delivery_address', $address);
        update_post_meta($post_id, 'status', 'Pending');

        $return = !empty($s['success_page']) ? $s['success_page'] : home_url('/');
        $return = add_query_arg('malipo_order', rawurlencode($order_id), $return);

        $response = wp_remote_post('https://app.malipo.mw/api/v1/invoice/prepare', [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-app-id' => $s['app_id'],
                'x-api-key' => $s['api_key'],
            ],
            'body' => wp_json_encode([
                'merchantTrxId' => $order_id,
                'amount' => $amount,
                'redirect_url' => $return,
            ]),
        ]);

        if (is_wp_error($response)) {
            update_post_meta($post_id, 'status', 'Error');
            return new WP_Error('malipo_connection', 'Could not connect to Malipo.', ['status' => 502]);
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300 || empty($body['payment_link'])) {
            update_post_meta($post_id, 'status', 'Error');
            return new WP_Error('malipo_error', $body['message'] ?? 'Malipo did not return a payment link.', ['status' => 502]);
        }

        update_post_meta($post_id, 'payment_link', esc_url_raw($body['payment_link']));
        return rest_ensure_response([
            'payment_link' => esc_url_raw($body['payment_link']),
            'order_id' => $order_id,
        ]);
    }

    private function find_order($merchant) {
        $posts = get_posts([
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'numberposts' => 1,
            'meta_key' => 'merchant_trx_id',
            'meta_value' => $merchant,
        ]);
        return $posts ? $posts[0]->ID : 0;
    }

    public function callback(WP_REST_Request $request) {
        $data = $request->get_json_params();
        if (!$data) $data = $request->get_params();

        $merchant = sanitize_text_field($data['merchant_trx_id'] ?? '');
        $status = sanitize_text_field($data['status'] ?? '');
        $transaction = sanitize_text_field($data['transaction_id'] ?? '');
        $customer_ref = sanitize_text_field($data['customer_reference'] ?? '');

        if (!$merchant) return new WP_REST_Response(['ok' => false], 400);

        $id = $this->find_order($merchant);
        if (!$id) return new WP_REST_Response(['ok' => false], 404);

        update_post_meta($id, 'status', $status === 'Completed' ? 'Paid' : ($status === 'Failed' ? 'Failed' : $status));
        update_post_meta($id, 'malipo_transaction_id', $transaction);
        update_post_meta($id, 'customer_reference', $customer_ref);

        return new WP_REST_Response(['ok' => true], 200);
    }

    private function enquire_transaction($merchant) {
        $s = $this->settings();
        if (empty($s['app_id']) || empty($s['api_key']) || !$merchant) return null;

        $url = 'https://app.malipo.mw/api/v1/payment/enquire/' . rawurlencode($merchant);
        $response = wp_remote_get($url, [
            'timeout' => 20,
            'headers' => [
                'x-app-id' => $s['app_id'],
                'x-api-key' => $s['api_key'],
            ],
        ]);

        if (is_wp_error($response)) return null;

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || empty($body['data'])) return null;

        return $body['data'];
    }

    public function order_status_shortcode() {
        $merchant = sanitize_text_field($_GET['malipo_order'] ?? '');
        if (!$merchant) return '<div class="mec-box"><p>No order was supplied.</p></div>';

        $id = $this->find_order($merchant);
        if (!$id) return '<div class="mec-box"><p>Order not found.</p></div>';

        $remote = $this->enquire_transaction($merchant);
        if ($remote && !empty($remote['status'])) {
            $status = sanitize_text_field($remote['status']);
            update_post_meta($id, 'status', $status === 'Completed' ? 'Paid' : ($status === 'Failed' ? 'Failed' : $status));
            if (!empty($remote['transId'])) update_post_meta($id, 'malipo_transaction_id', sanitize_text_field($remote['transId']));
            if (!empty($remote['customer_ref'])) update_post_meta($id, 'customer_reference', sanitize_text_field($remote['customer_ref']));
        } else {
            $status = get_post_meta($id, 'status', true) ?: 'Pending';
        }

        $product = get_post_meta($id, 'product', true);
        $amount = (float)get_post_meta($id, 'amount', true);
        $transaction = get_post_meta($id, 'malipo_transaction_id', true);

        if ($status === 'Paid') {
            $html = '<div class="mec-box mec-success"><h3>Payment successful</h3><p>Thank you. Your order has been received.</p>';
        } elseif ($status === 'Failed') {
            $html = '<div class="mec-box mec-error"><h3>Payment failed</h3><p>Your payment was not completed. Please try again.</p>';
        } else {
            $html = '<div class="mec-box"><h3>Payment pending</h3><p>We are waiting for Malipo to confirm your payment.</p>';
        }

        $html .= '<div class="mec-summary"><strong>Order:</strong> ' . esc_html($merchant) .
            '<br><strong>Product:</strong> ' . esc_html($product) .
            '<br><strong>Total:</strong> MWK ' . esc_html(number_format($amount, 0));
        if ($transaction) $html .= '<br><strong>Transaction:</strong> ' . esc_html($transaction);
        $html .= '</div></div>';

        return $html;
    }
}

new Malipo_Elementor_Checkout();
