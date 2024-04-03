<?php

use UltimatePushNotifications\admin\options\functions\AppConfig;
use UltimatePushNotifications\admin\options\functions\SetNotifications;

/**
 * Plugin Name: Woocommerce Rest Api Toolkit 
 * Plugin URI: https://e-solutionsgroup.org/
 * Description: So many cool rest api tools for authentication, cart, notifications, and caching.
 * Version: 0.1
 * Author: E solutions group
 * Author URI: https://e-solutionsgroup.org/
 **/

final class woocommerce_custom_api
{
    public function __construct()
    {
        // activation hook
        register_deactivation_hook(__FILE__, array($this, 'activate'));
    }
    /**
     * Endpoint namespace.
     *
     * @var string
     */
    protected $namespace = 'wc/v3';

    /**
     * Get the default REST API version.
     *
     * @since  3.0.0
     * @return string
     */
    protected function get_default_api_version()
    {
        return 'wp_api_v3';
    }

    function woocomm_add_to_cart($param)
    {

        global $wpdb;
        $user_id = $param['user_id'];
        wp_set_current_user($user_id);

        $objProduct = new WC_Session_Handler();
        $wc_session_data = $objProduct->get_session($user_id);

        // Get the persistent cart may be _woocommerce_presistent_cart can be in your case check in user_meta table
        $full_user_meta = get_user_meta($user_id, '_woocommerce_presistent_cart_1', true);

        if (defined('WC_ABSPATH')) {
            // WC 3.6+ - Cart and other frontend functions are not included for REST requests
            include_once WC_ABSPATH . 'includes/wc-cart-functions.php';
            include_once WC_ABSPATH . 'includes/wc-notice-functions.php';
            include_once WC_ABSPATH . 'includes/wc-template-hooks.php';
        }

        if (null === WC()->session) {
            $session_class = apply_filters('woocommerce_session_handler', 'WC_Session_Handler');

            WC()->session = new $session_class();
            WC()->session->init();
        }

        if (null === WC()->customer) {
            WC()->customer = new WC_Customer(get_current_user_id(), true);
        }

        if (null === WC()->cart) {
            WC()->cart = new WC_Cart();

            // We need to force a refresh of the cart contents from session here
            // (cart content are normally refreshed on wp_loaded, which has already happend by this point)
            WC()->cart->get_cart();
        }

        // create new Cart Object
        $cartObj = WC()->cart;

        //Add old cart data to newly created cart object
        // if (isset($full_user_meta['cart'])) {
        // 	foreach ($full_user_meta['cart'] as $prod) {
        // 		$cartObj->add_to_cart($prod['product_id'], $prod['quantity']);
        // 	}
        // }
        $items = [];
        foreach ($cartObj->cart_contents as $key => $value) {
            $items[$key] = $value['product_id'];
        }

        foreach ($param['products'] as $prod) {
            if (!$cartObj->is_empty()) {
                $key = array_search($prod['product_id'], $items);
                if ($key) {
                    $cartObj->remove_cart_item($key);
                }

                if ($prod['quantity'] != 0) {
                    $cartObj->add_to_cart($prod['product_id'], $prod['quantity']);
                }
            } else {
                if ($prod['quantity'] != 0) {
                    $cartObj->add_to_cart($prod['product_id'], $prod['quantity']);
                }
            }
        }


        $updatedCart = [];
        foreach ($cartObj->cart_contents as $key => $val) {
            unset($val['data']);
            $updatedCart[$key] = $val;
        }

        // If there is a current session cart, overwrite it with the new cart
        if (!is_null($wc_session_data)) {
            $wc_session_data['cart'] = serialize($updatedCart);
            $serializedObj = maybe_serialize($wc_session_data);

            $table_name = 'wp_woocommerce_sessions';

            // Update the wp_sessions table with the updated cart data
            $sql = "UPDATE $table_name SET session_value = '" . $serializedObj . "' WHERE session_key = '" . $user_id . "'";

            // Execute the query
            $rez = $wpdb->query($sql);
        }

        $productsInCart = [];
        foreach ($cartObj->cart_contents as $cart_item) {
            $product = wc_get_product($cart_item['product_id']);
            $image_id = $product->get_image_id();
            $image_url = wp_get_attachment_image_url($image_id, 'full');
            $productsInCart[] = (object)[
                'product_id' => $cart_item['product_id'],
                'product_name' => $product->get_name(),
                'product_regular_price' => $product->get_regular_price(),
                'product_sale_price' => $product->get_sale_price(),
                'product_on_sale' => $product->is_on_sale('api'),
                'thumbnail' => $image_url,
                'qty' => $cart_item['quantity'],
                'line_subtotal' => $cart_item['line_subtotal'],
                'line_total' => $cart_item['line_total'],
            ];
        }

        // Overwrite the persistent cart with the new cart data
        $full_user_meta = [];
        $full_user_meta['cart'] = $updatedCart;

        // return rest_ensure_response($updatedCart);
        update_user_meta(
            get_current_user_id(),
            '_woocommerce_presistent_cart_1',
            $full_user_meta
        );

        $response = [
            'status' => true,
            'total' => (float) $cartObj->get_total('api'),
            'total_discount' => (float) $cartObj->get_total_discount(),
            'total_fee' => (float) $cartObj->get_fee_total(),
            'data' => $full_user_meta['cart'] != null ? $productsInCart : [],
        ];

        return rest_ensure_response($response);
    }

    function woocomm_cart_list($param)
    {
        $user_id = $param['user_id'];
        wp_set_current_user($user_id);

        // Get the persistent cart may be _woocommerce_presistent_cart can be in your case check in user_meta table
        $full_user_meta = get_user_meta($user_id, '_woocommerce_presistent_cart_1', true);

        if (defined('WC_ABSPATH')) {
            // WC 3.6+ - Cart and other frontend functions are not included for REST requests
            include_once WC_ABSPATH . 'includes/wc-cart-functions.php';
            include_once WC_ABSPATH . 'includes/wc-notice-functions.php';
            include_once WC_ABSPATH . 'includes/wc-template-hooks.php';
        }
        if (null === WC()->session) {
            $session_class = apply_filters('woocommerce_session_handler', 'WC_Session_Handler');

            WC()->session = new $session_class();
            WC()->session->init();
        }

        if (null === WC()->customer) {
            WC()->customer = new WC_Customer(get_current_user_id(), true);
        }

        if (null === WC()->cart) {
            WC()->cart = new WC_Cart();

            // We need to force a refresh of the cart contents from session here
            // (cart content are normally refreshed on wp_loaded, which has already happend by this point)
            WC()->cart->get_cart();
        }

        // create new Cart Object
        $cartObj = WC()->cart;



        // foreach ($full_user_meta['cart'] ?? [] as $key => $value) {
        // 	$cartObj->add_to_cart($value['product_id'], $value['quantity']);
        // }

        $productsInCart = [];
        foreach ($cartObj->cart_contents as $cart_item) {
            $product = wc_get_product($cart_item['product_id']);
            $image_id = $product->get_image_id();
            $image_url = wp_get_attachment_image_url($image_id, 'full');
            $productsInCart[] = (object)[
                'product_id' => $cart_item['product_id'],
                'product_name' => $product->get_name(),
                'product_regular_price' => $product->get_regular_price(),
                'product_sale_price' => $product->get_sale_price(),
                'product_on_sale' => $product->is_on_sale('api'),
                'thumbnail' => $image_url,
                'qty' => $cart_item['quantity'],
                'line_subtotal' => $cart_item['line_subtotal'],
                'line_total' => $cart_item['line_total'],
                'product_step' => get_metadata('post', $cart_item['product_id'], 'product_step', true),
                'min_quantity' => get_metadata('post', $cart_item['product_id'], 'min_quantity', true),
                'max_quantity' => get_metadata('post', $cart_item['product_id'], 'max_quantity', true),
            ];
        }
        // return rest_ensure_response($cartObj->cart_contents);

        $response = [
            'status' => true,
            'total' => (float) $cartObj->get_total('api'),
            'total_discount' => (float) $cartObj->get_total_discount(),
            'total_fee' => (float) $cartObj->get_fee_total(),
            'data' => $productsInCart,
        ];

        return rest_ensure_response($response);
    }

    function woocomm_login_customer($param)
    {
        $user = wp_authenticate($param['email'], $param['password']);
        if (is_wp_error($user)) {
            $error_code = $user->get_error_code();

            return new WP_Error(
                $error_code,
                $user->get_error_message($error_code),
                [
                    'status' => 403,
                ]
            );
        }

        return rest_ensure_response([
            'user_id' => $user->ID
        ]);
    }

    function woocomm_register_customer($param)
    {
        $email = $param['email'];
        $password = $param['password'];
        $first_name = $param['first_name'];
        $last_name = $param['last_name'];
        $company = $param['company'];
        $zra_number = $param['zra_number'];
        $phone = $param['phone'];
        $birth_date = $param['birth_date'];
        $meta_input = [
            'shipping_first_name' => $first_name,
            'shipping_last_name' => $last_name,
            'shipping_company' => $company,
            'shipping_zra_number' => $zra_number,
            'shipping_phone' => $phone,
            'shipping_birth_date' => $birth_date,
            'billing_first_name' => $first_name,
            'billing_last_name' => $last_name,
            'billing_company' => $company,
            'billing_zra_number' => $zra_number,
            'billing_phone' => $phone,
            'billing_birth_date' => $birth_date,
        ];

        $customer_id = wc_create_new_customer($email, $email, $password, compact('first_name', 'last_name', 'meta_input'));
        if ($customer_id instanceof WP_Error) {
            return rest_ensure_response($customer_id);
        }

        return rest_ensure_response([
            'user_id' => $customer_id,
        ]);
    }

    public function register_routes()
    {
        register_rest_route(
            $this->namespace,
            '/addtocart',
            array(
                'methods' => 'POST',
                'callback' => array($this, 'woocomm_add_to_cart')
            )
        );

        register_rest_route(
            $this->namespace,
            '/cart',
            array(
                'methods' => 'GET',
                'callback' => array($this, 'woocomm_cart_list'),
            )
        );

        register_rest_route(
            $this->namespace,
            '/register',
            array(
                'methods' => 'POST',
                'callback' => array($this, 'woocomm_register_customer')
            )
        );

        register_rest_route(
            $this->namespace,
            '/login',
            array(
                'methods' => 'POST',
                'callback' => array($this, 'woocomm_login_customer')
            )
        );

        register_rest_route(
            $this->namespace,
            '/change-password',
            array(
                'methods' => 'POST',
                'callback' => array($this, 'woocomm_change_password_customer')
            )
        );

        register_rest_route(
            $this->namespace,
            '/forgot-password',
            array(
                'methods' => 'POST',
                'callback' => array($this, 'woocomm_forgot_password_customer')
            )
        );

        $config = new AppConfig();

        register_rest_route(
            $this->namespace,
            '/fcm',
            array(
                'methods' => 'POST',
                'callback' => array($config, 'cs_update_token')
            )
        );

        register_rest_route(
            $this->namespace,
            '/notifications',
            array(
                'methods' => 'GET',
                'callback' => array($this, 'getNotifications')
            )
        );

        register_rest_route(
            $this->namespace,
            '/read-notifications',
            array(
                'methods' => 'POST',
                'callback' => array($this, 'readNotifications')
            )
        );
    }

    public function woocomm_change_password_customer($param)
    {
        $user_id = $param['user_id'];

        wp_set_password($param['password'], $user_id);

        return rest_ensure_response([
            'status' => true
        ]);
    }

    public function woocomm_forgot_password_customer($param)
    {
        $user = get_user_by('email', $param['email']);
        if (!$user) {
            return rest_ensure_response(new WP_HTTP_Response([
                'message' =>    'Email is not found in our system',
            ], 400));
        }

        $code = rand(0, 999999);

        wp_mail($param['email'], "Verify Email", "Your verification code is $code");

        return rest_ensure_response([
            'status' => true,
            'user_id' => $user->ID,
            'code' => $code,
        ]);
    }

    public function getNotifications($param)
    {
        global $wpdb;
        $user_id = $param['user_id'];

        $notifications = $wpdb->get_results(
            $wpdb->prepare(
                "select * from `{$wpdb->prefix}_users_notifications` where user_id = %d ",
                $user_id
            )
        );

        return rest_ensure_response($notifications);
    }

    public function readNotifications($param)
    {
        global $wpdb;
        $user_id = $param['user_id'];

        $wpdb->update(
            "{$wpdb->prefix}_users_notifications",
            array('is_read' => 1),
            array('user_id' => $user_id)
        );

        return rest_ensure_response([
            'message' => 'success',
        ]);
    }

    public function create_banners()
    {
        register_post_type(
            'banner',
            array(
                'labels' => array(
                    'name' => __('Banners'),
                    'singular_name' => __('Banner')
                ),
                'public' => true,
                'has_archive' => false,
                'show_in_rest' => true,
                'rewrite' => array('slug' => 'banners'),
            )
        );
    }

    public function listen_to_order_status_changed_hook($order_id, $status_from, $status_to, $instance)
    {
        global $wpdb;
        if (!$order_id) {
            return;
        }

        $order = new \WC_Order($order_id);
        if (empty($order)) {
            return;
        }

        $first_name = $order->get_billing_first_name();
        $last_name  = $order->get_billing_last_name();
        $full_name  = $first_name . ' ' . $last_name;

        $items   = $order->get_items();
        $authors = array();
        foreach ($items as $item) {
            $product_id = $item->get_product_id();
            $post       = \get_post($product_id);
            if (isset($authors['author_' . $post->post_author])) {
                $old_data                                  = $authors['author_' . $post->post_author];
                $authors['author_' . $post->post_author] = array(
                    'author_id'  => $post->post_author,
                    'total_sold' => $item->get_total() + $old_data['total_sold'],
                );
            } else {
                $authors += array(
                    'author_' . $post->post_author => array(
                        'author_id'  => $post->post_author,
                        'total_sold' => $item->get_total(),
                    ),
                );
            }
        }

        $find            = array(
            '{first_name}',
            '{last_name}',
            '{full_name}',
            '{order_id}',
            '{status_from}',
            '{status_to}',
        );
        $replace         = array(
            $first_name,
            $last_name,
            $full_name,
            $order_id,
            $status_from,
            $status_to,
        );
        $icon            = CS_UPN_PLUGIN_ASSET_URI . 'img/icon-order-status.png';

        $data = array(
            'object_id' => $order_id,
            'object_type' => 'order',
        );

        $data['user_id'] = $order->get_customer_id('api');

        if ($authors) {
            foreach ($authors as $author) {
                $hasUserAsked = SetNotifications::has_user_asked_for_notification($author['author_id'], 'orderStatusUpdated');
                if ($hasUserAsked) {
                    $dataObj = (array) $hasUserAsked + array(
                        'find'         => $find,
                        'replace'      => $replace,
                        'icon'         => $icon,
                        'click_action' => $order->get_view_order_url(),
                    );
                    $dataObj = \is_object($dataObj) ? $dataObj : (object) $dataObj;

                    $data['title']       = \str_replace($dataObj->find, $dataObj->replace, $dataObj->title);
                    $data['body'] = \str_replace($dataObj->find, $dataObj->replace, $dataObj->body);
                }
            }
        }

        $wpdb->insert(
            "{$wpdb->prefix}_users_notifications",
            $data
        );

        $app_config = (object) AppConfig::get_config();

        $tokens = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT token from {$wpdb->prefix}upn_user_devices where user_id = %d ",
                $data['user_id']
            )
        );


        foreach ($tokens as $item) {
            wp_remote_post(
                'https://fcm.googleapis.com/fcm/send',
                array(
                    'method'      => 'POST',
                    'timeout'     => 45,
                    'redirection' => 5,
                    'httpversion' => '1.0',
                    'blocking'    => true,
                    'headers'     => array(
                        'Authorization' => 'key=' . $app_config->key,
                        'Content-Type'  => 'application/json',
                    ),
                    'body'        => json_encode(
                        array(
                            'notification' => $data,
                            'to'           => $item->token,
                        )
                    ),
                    'cookies'     => array(),
                )
            );
        }
    }

    public function activate()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sqls = array(
            "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}_users_notifications`(
			`id` int(11) NOT NULL auto_increment,
			`user_id` bigint,
			`title` mediumtext,
			`body` mediumtext,
			`object_id` bigint,
			`object_type` varchar(50),
			`is_read` TINYINT(1) DEFAULT 0,
			`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY ( `id`)
			) $charset_collate",
        );
        foreach ($sqls as $sql) {
            if ($wpdb->query($sql) === false) {
                continue;
            }
        }
    }
}

$woocommerce_custom_api = new woocommerce_custom_api();

// remove this line if you don't want a banners in your dashboard
add_action('init', array($woocommerce_custom_api, 'create_banners'));

add_action('woocommerce_order_status_changed', array($woocommerce_custom_api, 'listen_to_order_status_changed_hook'), 10, 4);

add_action('rest_api_init', array($woocommerce_custom_api, 'register_routes'));

add_action('woocommerce_new_order', function ($order_id) {

    global $wpdb;

    $order = wc_get_order($order_id); // Do whatever you need with the order ID    

    $title = 'Order need to pay';
    $body = "The order: #{$order_id} need to pay";

    $data = array(
        'object_id' => $order_id,
        'object_type' => 'order',
        'title' => $title,
        'body' => $body,
    );

    $data['user_id'] = $order->get_customer_id('api');

    $wpdb->insert(
        "{$wpdb->prefix}_users_notifications",
        $data
    );

    $app_config = (object) AppConfig::get_config();

    $tokens = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT token from {$wpdb->prefix}upn_user_devices where user_id = %d ",
            $data['user_id']
        )
    );

    $data['icon'] = CS_UPN_PLUGIN_ASSET_URI . 'img/icon-order-status.png';
    $data['click_action'] = $order->get_view_order_url();

    foreach ($tokens as $item) {
        wp_remote_post(
            'https://fcm.googleapis.com/fcm/send',
            array(
                'method'      => 'POST',
                'timeout'     => 45,
                'redirection' => 5,
                'httpversion' => '1.0',
                'blocking'    => true,
                'headers'     => array(
                    'Authorization' => 'key=' . $app_config->key,
                    'Content-Type'  => 'application/json',
                ),
                'body'        => json_encode(
                    array(
                        'notification' => $data,
                        'to'           => $item->token,
                    )
                ),
                'cookies'     => array(),
            )
        );
    }


    $user_id = $data['user_id'];
    wp_set_current_user($user_id);

    // Get the persistent cart may be _woocommerce_presistent_cart can be in your case check in user_meta table
    $full_user_meta = get_user_meta($user_id, '_woocommerce_presistent_cart_1', true);

    if (defined('WC_ABSPATH')) {
        // WC 3.6+ - Cart and other frontend functions are not included for REST requests
        include_once WC_ABSPATH . 'includes/wc-cart-functions.php';
        include_once WC_ABSPATH . 'includes/wc-notice-functions.php';
        include_once WC_ABSPATH . 'includes/wc-template-hooks.php';
    }
    if (null === WC()->session) {
        $session_class = apply_filters('woocommerce_session_handler', 'WC_Session_Handler');

        WC()->session = new $session_class();
        WC()->session->init();
    }

    if (null === WC()->customer) {
        WC()->customer = new WC_Customer(get_current_user_id(), true);
    }

    if (null === WC()->cart) {
        WC()->cart = new WC_Cart();

        // We need to force a refresh of the cart contents from session here
        // (cart content are normally refreshed on wp_loaded, which has already happend by this point)
        WC()->cart->get_cart();
    }

    // create new Cart Object
    $cartObj = WC()->cart;

    $cartObj->empty_cart();

    // Overwrite the persistent cart with the new cart data
    $full_user_meta = [];
    $full_user_meta['cart'] = $updatedCart;

    // return rest_ensure_response($updatedCart);
    update_user_meta(
        get_current_user_id(),
        '_woocommerce_presistent_cart_1',
        $full_user_meta
    );
});

add_filter('wp_rest_cache/allowed_endpoints', function ($allowed_endpoints) {
    // remove this line if you don't want to cache products
    if (!isset($allowed_endpoints['wc/v3']) || !in_array('products', $allowed_endpoints['wc/v3'])) {
        $allowed_endpoints['wc/v3'][] = 'products';
    }
    // remove this line if you don't want to cache orders
    if (!isset($allowed_endpoints['wc/v3']) || !in_array('orders', $allowed_endpoints['wc/v3'])) {
        $allowed_endpoints['wc/v3'][] = 'orders';
    }

    return $allowed_endpoints;
}, 10, 1);
