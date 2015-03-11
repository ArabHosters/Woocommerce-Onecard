<?php
/*
  Plugin Name: One Card
  Plugin URI: arabhosters.com
  Description: OneCard Payment extension for Woo-Commerece
  Version: 1.3.2
  Author: Arabhosters
  Author URI: arabhosters.com
 */

/*
 * Title   : OneCard Payment extension for Woo-Commerece
 * Author  : Nedal
 * Url     : arabhosters.com
 * License : arabhosters.com
 */
if (!defined('ABSPATH'))
    exit; // Exit if accessed directly

function init_onecard_gateway_class() {
    if (!class_exists('WC_Payment_Gateway'))
        return;

    class WC_Gateway_Onecard extends WC_Payment_Gateway {

        var $notify_url;

        /**
         * Constructor for the gateway.
         *
         * @access public
         * @return void
         */
        public function __construct() {
            global $woocommerce;

            $this->id = 'onecard';
            $this->icon = WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/images/credits.png';
            $this->has_fields = false;
            $this->liveurl = 'https://www.onecard.net/customer/integratedPayment.html';
            $this->testurl = 'http://onecard.n2vsb.com/customer/integratedPayment.html';
            $this->method_title = __('Onecard', 'woocommerce');
            $this->notify_url = str_replace('https:', 'http:', add_query_arg('wc-api', 'WC_Gateway_Onecard', home_url('/')));

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->merchant_id = $this->get_option('merchant_id');
            $this->trans_key = $this->get_option('trans_key');
            $this->keyword = $this->get_option('keyword');
            $this->testmode = $this->get_option('testmode');


            // Actions
            add_action('init', array($this, 'check_onecard_response'));
            add_action('valid-onecard-standard-ipn-request', array($this, 'successful_request'));
            add_action('woocommerce_receipt_onecard', array($this, 'receipt_page'));
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

            // Payment listener/API hook
            add_action('woocommerce_api_wc_gateway_onecard', array($this, 'check_ipn_response'));

            if (!$this->is_valid_for_use())
                $this->enabled = false;
        }

        /**
         * Check if this gateway is enabled and available in the user's country
         *
         * @access public
         * @return bool
         */
        function is_valid_for_use() {
            if (!in_array(get_woocommerce_currency(), apply_filters('woocommerce_onecard_supported_currencies', array('EGP', 'SAR', 'USD', 'EUR', 'AED', 'KWD', 'SYP'))))
                return false;

            return true;
        }

        /**
         * Admin Panel Options
         * - Options for bits like 'title' and availability on a country-by-country basis
         *
         * @since 1.0.0
         */
        public function admin_options() {
            ?>
            <h3><?php _e('OneCard', 'woocommerce'); ?></h3>

            <?php if ($this->is_valid_for_use()) : ?>

                <table class="form-table">
                    <?php
                    // Generate the HTML For the settings form.
                    $this->generate_settings_html();
                    ?>
                </table><!--/.form-table-->

            <?php else : ?>
                <div class="inline error"><p><strong><?php _e('Gateway Disabled', 'woocommerce'); ?></strong>: <?php _e('Onecard does not support your store currency.', 'woocommerce'); ?></p></div>
            <?php
            endif;
        }

        /**
         * Initialise Gateway Settings Form Fields
         *
         * @access public
         * @return void
         */
        function init_form_fields() {

            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Enable Onecard', 'woocommerce'),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __('Title', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
                    'default' => __('Onecard', 'woocommerce'),
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => __('Description', 'woocommerce'),
                    'type' => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'woocommerce'),
                    'default' => __('Pay via Onecard; you can pay with your credit card.', 'woocommerce')
                ),
                'merchant_id' => array(
                    'title' => __('Onecard account number', 'woocommerce'),
                    'type' => 'text',
                    'default' => ''
                ),
                'trans_key' => array(
                    'title' => __('Onecard Transaction Key', 'woocommerce'),
                    'type' => 'text',
                    'default' => ''
                ),
                'keyword' => array(
                    'title' => __('onecard keyword', 'woocommerce'),
                    'type' => 'text',
                    'default' => '',
                ),
                'testing' => array(
                    'title' => __('Gateway Testing', 'woocommerce'),
                    'type' => 'title',
                    'description' => '',
                ),
                'testmode' => array(
                    'title' => __('Onecard sandbox', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Enable Onecard sandbox', 'woocommerce'),
                    'default' => 'yes',
                )
            );
        }

        /**
         * Get Onecard Args for passing to PP
         *
         * @access public
         * @param mixed $order
         * @return array
         */
        function get_onecard_args($order) {
            global $woocommerce;

            $order_id = $order->id;
            $transaction_id = $this->invoice_prefix . ltrim($order->get_order_number(), '#');

            // Onecard Args
            $onecard_args = array(
                'OneCard_MerchID' => $this->merchant_id,
                'OneCard_Amount' => $order->order_total, //number_format($order->get_total(), 2, '.', ''),
                'OneCard_Currency' => get_woocommerce_currency(),
                // Order key + ID
                'OneCard_TransID' => $transaction_id,
                'OneCard_Field1' => serialize(array($order_id, $order->order_key)),
                'OneCard_ReturnURL' => add_query_arg(array('pm_onecard_response'=>'pm_onecard','utm_nooverride'=>'1'), trailingslashit(home_url())),
                'OneCard_Timein' => current_time('timestamp')
            );

            // sending encrypted hashkey
            $string_to_hash = $this->merchant_id;
            $string_to_hash .= $transaction_id;
            $string_to_hash .= $order->order_total;
            $string_to_hash .= get_woocommerce_currency();
            $string_to_hash .= current_time('timestamp');
            $string_to_hash .= $this->trans_key;

            //MD5 (OneCard_MerchID + OneCard_TransID + OneCard_Amount + OneCard_Currency + OneCard_Timein + OneCard_TransKey)
            // get a md5 hash of the string
            $hash_key = md5($string_to_hash);

            $onecard_args['OneCard_HashKey'] = $hash_key;

            $item_names = array();
            if (sizeof($order->get_items()) > 0)
                foreach ($order->get_items() as $item)
                    if ($item['qty'])
                        $item_names[] = $item['name'] . ' x ' . $item['qty'];

            $onecard_args['OneCard_MProd'] = implode(', ', $item_names);


            return apply_filters('woocommerce_onecard_args', $onecard_args);
        }

        /**
         * Generate the Onecard button link
         *
         * @access public
         * @param mixed $order_id
         * @return string
         */
        function generate_onecard_form($order_id) {

            $order = new WC_Order($order_id);

            if ($this->testmode == 'yes'):
                $onecard_adr = $this->testurl;
            else :
                $onecard_adr = $this->liveurl;
            endif;

            $onecard_args = $this->get_onecard_args($order);

            $onecard_args_array = array();

            foreach ($onecard_args as $key => $value) {
                $onecard_args_array[] = '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" />';
            }

            return '<form action="' . esc_url($onecard_adr) . '" method="post" id="onecard_payment_form" target="_top">
				' . implode('', $onecard_args_array) . '
                                    <script type="text/javascript">
                                    jQuery("body").block({
					message: "' . esc_js(__('Thank you for your order. We are now redirecting you to Onecard to make payment.', 'woocommerce')) . '",
					baseZ: 99999,
					overlayCSS:
					{
						background: "#fff",
						opacity: 0.6
					},
					css: {
				        padding:        "20px",
				        zindex:         "9999999",
				        textAlign:      "center",
				        color:          "#555",
				        border:         "3px solid #aaa",
				        backgroundColor:"#fff",
				        cursor:         "wait",
				        lineHeight:		"24px",
				    }
				});
			jQuery("#submit_onecard_payment_form").click();
                                    </script>
				<input type="submit" class="button alt" id="submit_onecard_payment_form" value="' . __('Pay via Onecard', 'woocommerce') . '" /> <a class="button cancel" href="' . esc_url($order->get_cancel_order_url()) . '">' . __('Cancel order &amp; restore cart', 'woocommerce') . '</a>
			</form>';
        }

        /**
         * Process the payment and return the result
         *
         * @access public
         * @param int $order_id
         * @return array
         */
        function process_payment($order_id) {

            $order = new WC_Order($order_id);

            return array(
                'result' => 'success',
                'redirect' => add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(woocommerce_get_page_id('pay'))))
            );
        }

        /**
         * Output for the order received page.
         *
         * @access public
         * @return void
         */
        function receipt_page($order) {

            echo '<p>' . __('Thank you for your order, please click the button below to pay with Onecard.', 'woocommerce') . '</p>';

            echo $this->generate_onecard_form($order);
        }

        /**
         * Check Onecard IPN validity
         * */
        function check_ipn_request_is_valid() {

            $transaction_id = $this->invoice_prefix . ltrim($order->get_order_number(), '#');

            $string_to_hash = $this->merchant_id;
            $string_to_hash .= $transaction_id;
            $string_to_hash .= $_POST['OneCard_Amount'];
            $string_to_hash .= $_POST['OneCard_Currency'];
            $string_to_hash .= $_POST['OneCard_RTime'];
            $string_to_hash .= $this->trans_key;
            $string_to_hash .= $_POST['OneCard_Code'];


            // get a md5 hash of the string
            $hash_to_check = md5($string_to_hash);

            // check to match that the key received is
            // exactly the same as the key generated
            //echo '<pre>';print_r($_POST);exit;
            if ($_POST['OneCard_RHashKey'] === $hash_to_check) {
                return true;
            }

            return false;
        }

        /**
         * Check for Onecard IPN Response
         *
         * @access public
         * @return void
         */
        function check_ipn_response() {

            @ob_clean();

            if (!empty($_POST) && $this->check_ipn_request_is_valid()) {

                header('HTTP/1.1 200 OK');

                do_action("valid-onecard-standard-ipn-request", $_POST);
            } else {

                wp_die("Onecard IPN Request Failure");
            }
        }

        /**
         * Check for onecard server callback value
         * */
        function check_onecard_response() {
            if (isset($_GET['pm_onecard_response']) && $_GET['pm_onecard_response'] == 'pm_onecard'):
                @ob_clean();
                $_POST = stripslashes_deep($_POST);

                if ($this->check_ipn_request_is_valid()) {

                    header('HTTP/1.1 200 OK');

                    do_action("valid-onecard-standard-ipn-request", $_POST);

                    exit;
                } else {
                    wp_die("Onecard Request Failure");
                }

            endif;
        }

        /**
         * Successful Payment!
         *
         * @access public
         * @param array $posted
         * @return void
         */
        function successful_request($posted) {
            global $woocommerce;

            $posted = stripslashes_deep($posted);

            //echo $posted['credit_card_processed'];
            // Custom holds post ID
            if (!empty($posted['OneCard_TransID']) && !empty($posted['OneCard_Field1'])) {

                $order = $this->get_onecard_order($posted);

                // We are here so lets check status and do actions
                switch ($posted['OneCard_Code']) {
                    case '00' :
                    case '18' :

                        // Check order not already completed
                        if ($order->status == 'completed') {
                            exit;
                        }

                        // Validate Amount
                        if ($order->get_total() != $posted['OneCard_Amount']) {

                            // Put this order on-hold for manual checking
                            $order->update_status('on-hold', sprintf(__('Validation error: Onecard amounts do not match (gross %s).', 'woocommerce'), $posted['OneCard_Amount']));

                            exit;
                        }

                        $order->add_order_note(__('OneCard payment completed', 'woocommerce'));
                        $order->payment_complete();

                        $woocommerce->cart->empty_cart();

                        $redirect_url = add_query_arg('key', $order->order_key, add_query_arg('order', $order->id, get_permalink(get_option('woocommerce_thanks_page_id'))));
                        wp_redirect($redirect_url);

                        break;
                    default :
                        // No action
                        break;
                }

                exit;
            }
        }

        /**
         * get_onecard_order function.
         *
         * @access public
         * @param mixed $posted
         * @return void
         */
        function get_onecard_order($posted) {
            $custom = maybe_unserialize($posted['OneCard_Field1']);

            // Backwards comp for IPN requests
            if (is_numeric($custom)) {
                $order_id = (int) $custom;
                $order_key = $posted['OneCard_TransID'];
            } elseif (is_string($custom)) {
                $order_id = (int) str_replace($this->invoice_prefix, '', $custom);
                $order_key = $custom;
            } else {
                list( $order_id, $order_key ) = $custom;
            }

            $order = new WC_Order($order_id);

            if (!isset($order->id)) {
                // We have an invalid $order_id, probably because invoice_prefix has changed
                $order_id = woocommerce_get_order_id_by_order_key($order_key);
                $order = new WC_Order($order_id);
            }

            // Validate key
            if ($order->order_key !== $order_key) {
                exit;
            }

            return $order;
        }

    }

    function add_onecard_gateway($methods) {
        $methods[] = 'WC_Gateway_Onecard';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_onecard_gateway');
}

add_action('plugins_loaded', 'init_onecard_gateway_class');
