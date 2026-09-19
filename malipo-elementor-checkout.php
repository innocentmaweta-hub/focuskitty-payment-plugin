<?php
/**
 * Plugin Name: Malipo Checkout for Elementor
 * Description: Lightweight Malipo hosted checkout for Elementor/WordPress without WooCommerce.
 * Version: 1.0.0
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
        add_shortcode('malipo_checkout', [$this, 'shortcode']);
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
                return [
                    'app_id' => sanitize_text_field($v['app_id'] ?? ''),
                    'api_key' => sanitize_text_field($v['api_key'] ?? ''),
                    'success_page' => esc_url_raw($v['success_page'] ?? home_url('/')),
                ];
            }
        ]);
    }

    public function settings_page() {
        if (!current_user_can('manage_options')) return;
        $s = get_option(self::OPT, []);
        ?>
        <div class="wrap">
            <h1>Malipo Checkout</h1>
            <p>Enter the credentials from your Malipo developer/merchant portal. Keep the API key private.</p>
            <form method="post" action="options.php">
                <?php settings_fields('mec_settings_group'); ?>
                <table class="form-table">
                    <tr><th><label for="mec_app_id">App ID</label></th><td>
                        <input class="regular-text" id="mec_app_id" name="<?php echo esc_attr(self::OPT); ?>[app_id]" value="<?php echo esc_attr($s['app_id'] ?? ''); ?>">
                    </td></tr>
                    <tr><th><label for="mec_api_key">API Key</label></th><td>
                        <input type="password" class="regular-text" id="mec_api_key" name="<?php echo esc_attr(self::OPT); ?>[api_key]" value="<?php echo esc_attr($s['api_key'] ?? ''); ?>" autocomplete="new-password">
                    </td></tr>
                    <tr><th><label for="mec_success_page">Return URL</label></th><td>
                        <input type="url" class="regular-text" id="mec_success_page" name="<?php echo esc_attr(self::OPT); ?>[success_page]" value="<?php echo esc_attr($s['success_page'] ?? home_url('/')); ?>">
                        <p class="description">Customers return here after Malipo checkout. The plugin appends ?malipo_order=ORDER_ID.</p>
                    </td></tr>
                </table>
                <?php submit_button('Save Malipo Settings'); ?>
            </form>
            <h2>Callback URL</h2>
            <code><?php echo esc_html(rest_url('malipo/v1/callback')); ?></code>
            <p>Give this URL to Malipo if their merchant setup asks for an IPN/callback URL.</p>
            <h2>Elementor usage</h2>
            <p>Use an Elementor Shortcode widget and enter:</p>
            <code>[malipo_checkout product="Tummy Tonic" amount="15000"]</code>
        </div>
        <?php
    }

    public function assets() {
        wp_register_style('mec-style', false);
        wp_enqueue_style('mec-style');
        wp_add_inline_style('mec-style', '
            .mec-box{max-width:520px;padding:20px;border:1px solid #ddd;border-radius:12px;background:#fff}
            .mec-box label{display:block;margin:0 0 6px;font-weight:600}
            .mec-box input{width:100%;padding:11px;margin:0 0 14px;box-sizing:border-box}
            .mec-buy{width:100%;padding:13px;border:0;border-radius:8px;background:#111;color:#fff;font-weight:700;cursor:pointer}
            .mec-msg{margin-top:12px}.mec-error{color:#b00020}.mec-success{color:#087f23}
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

    public function shortcode($atts) {
        $atts = shortcode_atts([
            'product' => 'Product',
            'amount' => '',
            'button' => 'Buy Now - Pay with Malipo',
        ], $atts, 'malipo_checkout');

        $amount = (float)$atts['amount'];
        if ($amount <= 1) return '<p>Malipo checkout: please set a valid amount.</p>';

        $nonce = wp_create_nonce('mec_create_payment');
        ob_start(); ?>
        <div class="mec-box" data-product="<?php echo esc_attr($atts['product']); ?>" data-amount="<?php echo esc_attr($amount); ?>">
            <label>Name</label>
            <input type="text" class="mec-name" autocomplete="name" required>
            <label>Phone number</label>
            <input type="tel" class="mec-phone" autocomplete="tel" placeholder="265XXXXXXXXX" required>
            <label>Delivery address</label>
            <input type="text" class="mec-address" autocomplete="street-address" required>
            <button type="button" class="mec-buy"><?php echo esc_html($atts['button']); ?></button>
            <div class="mec-msg" aria-live="polite"></div>
        </div>
        <script>
        document.addEventListener('click', function(e){
            if(!e.target.classList.contains('mec-buy')) return;
            const box=e.target.closest('.mec-box'), msg=box.querySelector('.mec-msg');
            msg.textContent='Preparing secure payment...'; msg.className='mec-msg';
            e.target.disabled=true;
            const fd=new FormData();
            fd.append('nonce','<?php echo esc_js($nonce); ?>');
            fd.append('product',box.dataset.product);
            fd.append('amount',box.dataset.amount);
            fd.append('name',box.querySelector('.mec-name').value);
            fd.append('phone',box.querySelector('.mec-phone').value);
            fd.append('address',box.querySelector('.mec-address').value);
            fetch('<?php echo esc_url(rest_url('malipo/v1/create')); ?>',{
                method:'POST', body:fd, credentials:'same-origin'
            }).then(r=>r.json()).then(data=>{
                if(data.payment_link){ window.location.href=data.payment_link; return; }
                throw new Error(data.message||'Could not create payment.');
            }).catch(err=>{
                msg.textContent=err.message; msg.className='mec-msg mec-error'; e.target.disabled=false;
            });
        });
        </script>
        <?php return ob_get_clean();
    }

    private function settings() {
        return get_option(self::OPT, []);
    }

    public function create_payment(WP_REST_Request $request) {
        $p = $request->get_param('nonce');
        if (!$p || !wp_verify_nonce($p, 'mec_create_payment')) {
            return new WP_Error('bad_nonce', 'Security check failed.', ['status'=>403]);
        }

        $product = sanitize_text_field($request->get_param('product'));
        $amount = (float)$request->get_param('amount');
        $name = sanitize_text_field($request->get_param('name'));
        $phone = preg_replace('/[^0-9+]/', '', (string)$request->get_param('phone'));
        $address = sanitize_text_field($request->get_param('address'));

        if (!$product || $amount <= 1 || !$name || !$phone || !$address) {
            return new WP_Error('missing_fields', 'Please complete all fields.', ['status'=>400]);
        }

        $s = $this->settings();
        if (empty($s['app_id']) || empty($s['api_key'])) {
            return new WP_Error('not_configured', 'Malipo is not configured yet.', ['status'=>500]);
        }

        $order_id = 'WP-' . gmdate('YmdHis') . '-' . wp_rand(1000,9999);
        $post_id = wp_insert_post([
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'post_title' => $order_id . ' - ' . $product,
        ]);
        if (!$post_id) return new WP_Error('order_error', 'Could not create order.', ['status'=>500]);

        update_post_meta($post_id, 'merchant_trx_id', $order_id);
        update_post_meta($post_id, 'product', $product);
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
            return new WP_Error('malipo_connection', 'Could not connect to Malipo.', ['status'=>502]);
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || empty($body['payment_link'])) {
            update_post_meta($post_id, 'status', 'Error');
            return new WP_Error('malipo_error', $body['message'] ?? 'Malipo did not return a payment link.', ['status'=>502]);
        }

        update_post_meta($post_id, 'payment_link', esc_url_raw($body['payment_link']));
        return rest_ensure_response(['payment_link' => esc_url_raw($body['payment_link']), 'order_id' => $order_id]);
    }

    public function callback(WP_REST_Request $request) {
        $data = $request->get_json_params();
        if (!$data) $data = $request->get_params();

        $merchant = sanitize_text_field($data['merchant_trx_id'] ?? '');
        $status = sanitize_text_field($data['status'] ?? '');
        $transaction = sanitize_text_field($data['transaction_id'] ?? '');
        $customer_ref = sanitize_text_field($data['customer_reference'] ?? '');

        if (!$merchant) return new WP_REST_Response(['ok'=>false], 400);

        $posts = get_posts([
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'numberposts' => 1,
            'meta_key' => 'merchant_trx_id',
            'meta_value' => $merchant,
        ]);
        if (!$posts) return new WP_REST_Response(['ok'=>false], 404);

        $id = $posts[0]->ID;
        update_post_meta($id, 'status', $status === 'Completed' ? 'Paid' : ($status === 'Failed' ? 'Failed' : $status));
        update_post_meta($id, 'malipo_transaction_id', $transaction);
        update_post_meta($id, 'customer_reference', $customer_ref);

        return new WP_REST_Response(['ok'=>true], 200);
    }
}

new Malipo_Elementor_Checkout();
