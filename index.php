<?php

/*
 * Plugin Name:  Bleumi Payments for WooCommerce
 * Description:  Accept Traditional and Crypto Currency Payments
 * Version:      1.0.0
 * Author:       Bleumi Inc
 * Author URI:   https://bleumi.com/
 * License:      Copyright 2020 Bleumi, MIT License
 
WC requires at least: 3.0.9
WC tested up to: 5.2.2

*/

if (!defined('ABSPATH')) :
    exit;
endif;

add_action('plugins_loaded', 'wc_bleumi_payment_aggregator_init');

function wc_bleumi_payment_aggregator_init()
{
    if (class_exists('WC_Payment_Gateway')) {
        define('BLEUMI_PLUGIN_URL', plugin_dir_url(__FILE__));

        /**
         * WC_Gateway_Bleumi Class.
         */
        class WC_Gateway_Bleumi extends WC_Payment_Gateway
        {
            /** @var bool Whether or not logging is enabled */
            public static $log_enabled = false;

            /** @var WC_Logger Logger instance */
            public static $log = false;

            /**
             * Constructor for the gateway.
             */
            public function __construct()
            {
                $this->id = 'bleumi';
                $this->icon = apply_filters('woocommerce_bleumi_icon', BLEUMI_PLUGIN_URL . 'assets/images/Bleumi.png');
                $this->has_fields = false;
                $this->order_button_text = __('Pay with Bleumi', 'bleumi');
                $this->method_title = __('Bleumi', 'bleumi');
                $this->method_description = '<p>' . __('Accept Traditional or Crypto Currency Payments', 'bleumi') . '</p>';

                // Load the settings.
                $this->init_form_fields();
                $this->init_settings();

                // Define user set variables.
                $this->title = $this->get_option('title');
                $this->description = $this->get_option('description');
                $this->debug = 'yes' === $this->get_option('debug', 'no');

                self::$log_enabled = $this->debug;

                // Actions
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(
                    $this,
                    'process_admin_options'
                ));

                add_action('woocommerce_api_wc_gateway_bleumi', array(
                    $this,
                    'ipn_callback'
                ));
            }

            /**
             * Logging method.
             *
             * @param string $message Log message.
             * @param string $level   Optional. Default 'info'.
             *     emergency|alert|critical|error|warning|notice|info|debug
             */
            public static function log($message, $level = 'info')
            {
                if (self::$log_enabled) {
                    if (empty(self::$log)) {
                        self::$log = wc_get_logger();
                    }
                    self::$log->log($level, $message, array(
                        'source' => 'bleumi_payment_aggregator'
                    ));
                }
            }

            /**
             * Init the API class and set the API key
             */
            protected function init_api()
            {
                include_once dirname(__FILE__) . '/lib/bleumi/class-bleumi-api-handler.php';
                Bleumi_APIHandler::$log = get_class($this) . '::log';
                Bleumi_APIHandler::$api_key = $this->get_option('api_key');
            }

            /**
             * Get the cancel url.
             * @param WC_Order $order Order object.
             * @return string
             */
            public function get_cancel_url($order)
            {
                $return_url = $order->get_cancel_order_url();
                if (is_ssl() || get_option('woocommerce_force_ssl_checkout') == 'yes') {
                    $return_url = str_replace('http:', 'https:', $return_url);
                }
                return apply_filters('woocommerce_get_cancel_url', $return_url, $order);
            }

            /**
             * Initialise Gateway Settings Form Fields.
             */
            public function init_form_fields()
            {
                $next_statuses_arr = array(
                    'processing' => 'Processing',
                    'completed' => 'Completed'
                );

                $this->form_fields = array(
                    'enabled' => array(
                        'title' => __('Enable/Disable', 'woocommerce'),
                        'type' => 'checkbox',
                        'label' => __('Enable Bleumi Payments', 'bleumi'),
                        'default' => 'yes',
                    ),
                    'title' => array(
                        'title' => __('Title', 'woocommerce'),
                        'type' => 'text',
                        'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
                        'default' => __('Pay with Traditional or Crypto Currency', 'bleumi'),
                        'desc_tip' => true,
                    ),
                    'description' => array(
                        'title' => __('Description', 'woocommerce'),
                        'type' => 'text',
                        'desc_tip' => true,
                        'description' => __('This is the message box that will appear on the <b>checkout page</b> when they select Bleumi Payments.', 'woocommerce'),
                        'default' => __('PayPal, Credit/Debit Card, Algorand, USD Coin, Celo, Celo Dollar, R-BTC, Dollar on Chain.'),
                    ),
                    'api_key' => array(
                        'title' => __('API Key', 'bleumi'),
                        'type' => 'password',
                        'default' => '',
                        'description' => sprintf(__('You can view and manage your Bleumi API keys from: <a href = "https://account.bleumi.com/account/?app=paymentlink&mode=production&tab=integration" target = "_blank">Bleumi Dashboard</a>', 'bleumi'), esc_url('https://account.bleumi.com')),
                    ),
                    'bleumi_confirmed_status' => array(
                        'title' => __('Order Status after Payment Confirmation', 'woocommerce'),
                        'type' => 'select',
                        'description' => __('Configure your Payment <b>Confirmation</b> status to one of the available WooCommerce order states.<br>All WooCommerce confirmation status options are listed here for your convenience.', 'woocommerce'),
                        'options' => $next_statuses_arr,
                        'default' => 'processing',
                    ),
                    'debug' => array(
                        'title' => __('Debug log', 'woocommerce'),
                        'type' => 'checkbox',
                        'label' => __('Enable logging', 'woocommerce'),
                        'default' => 'no',
                        'description' => sprintf(__('Log Bleumi API events inside %s', 'bleumi'), '<code>' . WC_Log_Handler_File::get_log_file_path('bleumi') . '</code>'),
                    ),
                );
            }

            /**
             * Process the payment and return the result.
             * @param  int $order_id
             * @return array
             */
            public function process_payment($order_id)
            {
                self::log('[Info] Started process_payment() with Order ID: ' . $order_id . '...');

                if (true === empty($order_id)) {
                    self::log('[Error] Order ID is missing. Validation failed. Unable to proceed.');
                    throw new \Exception('Order ID is missing. Validation failed. Unable to proceed.');
                }

                global $woocommerce;
                $order = new WC_Order($order_id);

                if (false === $order) {
                    self::log('[Error] Unable to retrieve the order details for Order ID ' . $order_id . '. Unable to proceed.');
                    throw new \Exception('Unable to retrieve the order details for Order ID ' . $order_id . '. Unable to proceed.');
                }

                self::log('[Info] Attempting to generate payment for Order ID: ' . $order->get_order_number() . '...');

                $this->init_api();
                $requestParams = array(
                    "id" => (string)$order->get_id(),
                    "currency" => get_woocommerce_currency(),
                    "invoice_date" => intval(date("Ymd")),
                    "allow_partial_payments" => false,
                    "metadata" => array(
                        "no_invoice" => true
                    ),
                    "success_url" => $this->get_return_url($order),
                    "cancel_url" => $this->get_cancel_url($order),
                    "notify_url" => str_replace('https:', 'http:', add_query_arg('wc-api', 'WC_Gateway_Bleumi', home_url('/'))),
                    "record" => array(
                        "client_info" => array(
                            "type" => "individual",
                            "name" => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                            "email" => $order->get_billing_email()
                        ),
                        "line_item" => array(
                            array(
                                "name" => "Order",
                                "description" => "#" . $order->get_id(),
                                "quantity" => 1,
                                "rate" => $order->get_total()
                            )
                        )
                    )
                );

                $result = Bleumi_APIHandler::sendRequest($requestParams, "POST");
                $order->save();

                self::log('[INFO] Response: ' . print_r($result, true));

                if (!empty($result['payment_url'])) {
                    return array(
                        'result' => 'success',
                        'redirect' => $result['payment_url'],
                    );
                } else {
                    return array(
                        'result' => 'fail',
                    );
                }
            }

            /**
             * Handle requests sent to webhook.
             */
            public function ipn_callback()
            {
                $this->init_api();
                try {
                    $body = file_get_contents("php://input");
                    $request = json_decode($body, true);
                    $order_id = intval($request['id']);

                    if (!empty($order_id)) {
                        $response = Bleumi_APIHandler::sendRequest($order_id, "GET");
                        $this->update_order_status($order_id, $response);
                    }
                } catch (\Throwable $th) {
                    self::log('[ERROR] Bleumi payment validation failed ' . filter_input(INPUT_GET, 'order_id'), ["exception" => $th]);
                }
            }

            /**
             * Update the order status based on the payment status from Bleumi
             */
            public function update_order_status($order_id, $response)
            {
                if (!empty($response['record'])) {
                    $amt_due = floatval($response['record']['amt_due']);
                    $amt_recv_pending = floatval($response['record']['amt_recv_online_pending']);
                    $order = new WC_Order($order_id);

                    self::log('[INFO] amt_due: ' . $amt_due);
                    self::log('[INFO] amt_recv_pending: ' . $amt_recv_pending);
                    self::log('[INFO] total: ' . $response['record']['total']);

                    if ($amt_recv_pending > 0) {
                        $order->update_status('awaitingconfirm', __('Bleumi payment detected, but awaiting confirmation.', 'bleumi'));
                    } else {
                        if ($amt_due > 0) {
                            if ($response['record']['amt_due'] === $response['record']['total']) {
                                $this->log('user marked as paid');
                                $order->add_order_note('User marked as paid, payment not verified by Bleumi');
                                return;
                            } else {
                                $order->update_status('partially-paid', __('Bleumi payment detected, Amount Partially Paid.', 'bleumi'));
                            }
                        } elseif ($amt_due < 0) {
                            $order->update_status('over-paid', __('Bleumi payment detected, Amount Over Paid.', 'bleumi'));
                        } else {
                            $next_status = $this->get_option('bleumi_confirmed_status');

                            if (isset($next_status)) {
                                $order->update_status($next_status, __('Bleumi Payment Completed.', 'bleumi'));
                            } else {
                                $order->update_status('processing', __('Bleumi payment Completed.', 'bleumi'));
                            }

                            self::log('[INFO] new order status: ' . $next_status);
                        }
                    }
                }
            }

            /**
             * Validate the payment and change order status accordingly.
             */
            public function bleumi_verify_payment()
            {
                $this->init_api();
                $order_id = '';

                $query_string = urldecode($_SERVER['QUERY_STRING']);
                $query_string = str_replace('&amp;', '&', $query_string);
                parse_str($query_string, $data);

                global $wp;
                $order_id = absint($wp->query_vars['order-received']);
                self::log('[INFO] order id: ' . $order_id);

                if (isset($data['cancel_order']) && !is_null($data['cancel_order'])) {
                    if ($data['cancel_order'] == 'true') {
                        $order_id = $data['order_id'];
                        if ($order_id != '') {
                            self::log('[INFO] bleumi_verify_payment: user cancelled order-id:' . $order_id);
                            $order = wc_get_order($order_id);
                            $order->update_status('cancelled', __('User cancelled payment.', 'bleumi'));
                            $order->save();
                            return;
                        }
                    }
                }

                /* do not proceed to validate if we are not on the appropriate page */
                if (!is_wc_endpoint_url('order-received')) {
                    return;
                }

                if (!empty($order_id)) {
                    $response = Bleumi_APIHandler::sendRequest($order_id, "GET");
                    $this->update_order_status($order_id, $response);
                }
            }
        }
    } else {
        global $wpdb;
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins_url = admin_url('plugins.php');

        $plugins = get_plugins();
        foreach ($plugins as $file => $plugin) {
            if ('Bleumi Payments for WooCommerce' === $plugin['Name'] && true === is_plugin_active($file)) {
                deactivate_plugins(plugin_basename(__FILE__));
                wp_die('WooCommerce needs to be installed and activated before Bleumi Payments for WooCommerce can be activated.<br><a href="' . $plugins_url . '">Return to plugins screen</a>');
            }
        }
    }
};

/**
 * Register wc-awaitingconfirm, wc-over-paid, wc_partially-paid statuses as valid for payment.
 */

add_filter('woocommerce_valid_order_statuses_for_payment', 'bleumi_wc_status_valid_for_payment', 10, 2);

function bleumi_wc_status_valid_for_payment($statuses, $order)
{
    $statuses[] = array(
        'wc-awaitingconfirm',
        'wc-over-paid',
        'wc-partially-paid'
    );
    return $statuses;
}

/**
 * Add registered status to list of WC Order statuses
 * @param array $wc_statuses_arr Array of all order statuses on the website.
 */
add_filter('wc_order_statuses', 'bleumi_wc_add_status');

function bleumi_wc_add_status($wc_statuses_arr)
{
    $new_statuses_arr = array();

    // Add new order statuses after payment pending.
    foreach ($wc_statuses_arr as $id => $label) {
        $new_statuses_arr[$id] = $label;

        if ('wc-pending' === $id) { // after "Payment Pending" status.
            $new_statuses_arr['wc-awaitingconfirm'] = __('Awaiting Payment Confirmation', 'bleumi');
            $new_statuses_arr['wc-over-paid'] = __('Over Paid', 'bleumi');
            $new_statuses_arr['wc-partially-paid'] = __('Partially Paid', 'bleumi');
        }
    }

    return $new_statuses_arr;
}


/**
 * Register new statuses
 * with ID "wc-awaitingconfirm" and label "Awaiting Payment Confirmation"
 * with ID "wc-partially-paid" and label "Partially Paid"
 * with ID "wc-over-paid" and label "Over Paid"
 */
add_action('init', 'bleumi_wc_register_new_statuses');

function bleumi_wc_register_new_statuses()
{
    register_post_status('wc-awaitingconfirm', array(
        'label' => _x('Awaiting Payment Confirmation', 'WooCommerce Order status', 'bleumi'),
        'public' => true,
        'show_in_admin_all_list' => true,
        'show_in_admin_status_list' => true,
        'exclude_from_search' => false,
        'label_count' => _n_noop('Awaiting Payment Confirmation <span class="count">(%s)</span>', 'Awaiting Payment Confirmation <span class="count">(%s)</span>'),
    ));

    register_post_status('wc-partially-paid', array(
        'label' => _x('Partially Paid', 'WooCommerce Order status', 'bleumi'),
        'public' => true,
        'show_in_admin_all_list' => true,
        'show_in_admin_status_list' => true,
        'exclude_from_search' => false,
        'label_count' => _n_noop('Partially Paid <span class="count">(%s)</span>', 'Partially Paid <span class="count">(%s)</span>'),
    ));

    register_post_status('wc-over-paid', array(
        'label' => _x('Over Paid', 'WooCommerce Order status', 'bleumi'),
        'public' => true,
        'show_in_admin_all_list' => true,
        'show_in_admin_status_list' => true,
        'exclude_from_search' => false,
        'label_count' => _n_noop('Over Paid <span class="count">(%s)</span>', 'Over Paid <span class="count">(%s)</span>'),
    ));
}

/*
 * Add custom link
 * The url will be http://yourwordpress/wp-admin/admin.php?=wc-settings&tab=checkout
*/
function bleumi_add_action_link_payment($links)
{
    $plugin_links = array(
        '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=bleumi') . '">' . __('Settings', 'bleumi') . '</a>',
    );
    return array_merge($plugin_links, $links);
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'bleumi_add_action_link_payment');

//To invoke the bleumi_verify_payment function
function bleumi_validate_payment()
{
    $gateway = WC()->payment_gateways()
        ->payment_gateways()['bleumi'];
    return $gateway->bleumi_verify_payment();
}
add_action('template_redirect', 'bleumi_validate_payment');

/*
 * Check Bleumi webhook request is valid.
 * @param  string $payload
*/

add_filter('woocommerce_payment_gateways', 'wc_bleumi_payment_aggregator_add_to_gateways');
function wc_bleumi_payment_aggregator_add_to_gateways($gateways)
{
    $gateways[] = 'WC_Gateway_Bleumi';
    return $gateways;
}
