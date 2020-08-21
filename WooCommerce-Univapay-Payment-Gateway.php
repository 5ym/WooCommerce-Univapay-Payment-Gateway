<?php
/*
 * Plugin Name: WooCommerce Univapay Payment Gateway
 * Plugin URI: https://
 * Description: Take credit card payments on your store.
 * Author: Ryuki Maruyama
 * Author URI: https://daco.dev/
 * Version: 0.1
 *

 /*
 * This action hook registers our PHP class as a WooCommerce payment gateway
 */
add_filter( 'woocommerce_payment_gateways', 'Univapay_add_gateway_class' );
function univapay_add_gateway_class( $gateways ) {
	$gateways[] = 'WC_Univapay_Gateway'; // your class name is here
	return $gateways;
}
 
/*
 * The class itself, please note that it is inside plugins_loaded action hook
 */
add_action( 'plugins_loaded', 'Univapay_init_gateway_class' );
function Univapay_init_gateway_class() {
 
	class WC_Univapay_Gateway extends WC_Payment_Gateway {
 
 		/**
 		 * Class constructor, more about it in Step 3
 		 */
 		public function __construct() {
            $this->id = 'wcupg'; // payment gateway plugin ID
            $this->icon = ''; // URL of the icon that will be displayed on checkout page near your gateway name
            $this->has_fields = true; // in case you need a custom credit card form
            $this->method_title = 'Univapay Gateway';
            $this->method_description = 'Description of Univapay payment gateway'; // will be displayed on the options page
         
            // gateways can support subscriptions, refunds, saved payment methods,
            // but in this tutorial we begin with simple payments
            $this->supports = array(
                'products'
            );
         
            // Method with all the options fields
            $this->init_form_fields();
         
            // Load the settings.
            $this->init_settings();
            $this->title = $this->get_option( 'title' );
            $this->description = $this->get_option( 'description' );
            $this->enabled = $this->get_option( 'enabled' );
            $this->testmode = 'yes' === $this->get_option( 'testmode' );
            $this->publishable_key = $this->get_option( 'publishable_key' );
            $this->seclevel = 'yse' === $this->get_option( 'seclevel' );
         
            // This action hook saves the settings
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
         
            // We need custom JavaScript to obtain a token
            add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
         
            // You can also register a webhook here
            // add_action( 'woocommerce_api_{webhook name}', array( $this, 'webhook' ) ); 
 		}
 
		/**
 		 * Plugin options, we deal with it in Step 3 too
 		 */
 		public function init_form_fields(){
            $this->form_fields = array(
                'enabled' => array(
                    'title'       => 'Enable/Disable',
                    'label'       => 'Enable Univapay Gateway',
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'no'
                ),
                'title' => array(
                    'title'       => 'Title',
                    'type'        => 'text',
                    'description' => 'This controls the title which the user sees during checkout.',
                    'default'     => 'Credit Card',
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => 'Description',
                    'type'        => 'textarea',
                    'description' => 'This controls the description which the user sees during checkout.',
                    'default'     => 'Pay with your credit card via our super-cool payment gateway.',
                ),
                'testmode' => array(
                    'title'       => 'Test mode',
                    'label'       => 'Enable Test Mode',
                    'type'        => 'checkbox',
                    'description' => 'Place the payment gateway in test mode using test gateway.',
                    'default'     => 'yes',
                    'desc_tip'    => true,
                ),
                'publishable_key' => array(
                    'title'       => 'Live Store ID',
                    'type'        => 'number'
                ),
                'seclevel' => array(
                    'title'       => 'Lower SECLEVEL',
                    'label'       => 'Enable Lower SECLEVEL',
                    'type'        => 'checkbox',
                    'description' => 'In some older environments, unchecking the box may result in a successful payment.',
                    'default'     => 'yes',
                    'desc_tip'    => true,
                ),
            );        
	 	}
 
		/**
		 * You will need it if you want your custom credit card form, Step 4 is about it
		 */
		public function payment_fields() {
            // ok, let's display some description before the payment form
            if ( $this->description ) {
                // you can instructions for test mode, I mean test card numbers etc.
                if ( $this->testmode ) {
                    $this->description .= ' TEST MODE ENABLED. In test mode.';
                    $this->description  = trim( $this->description );
                }
                // display the description with <p> tags etc.
                echo wpautop( wp_kses_post( $this->description ) );
            }
        
            // Add this action hook if you want your custom payment gateway to support it
            do_action( 'woocommerce_credit_card_form_start', $this->id );
            // I recommend to use inique IDs, because other gateways could already use #ccNo, #expdate, #cvc
            echo '<p>Language<select id="lang">
            <option value="en">English</option>
            <option value="ja">日本語</option>
            <option value="cn">簡体字</option>
            <option value="tw">繁体字</option>
            </select></p>
            <p>Card number<input type="text" id="cardno"></p>
            <p>CVV2<input type="text" id="securitycode"></p>
            <p>Card expiration date<input type="text" id="expire_month">/<input type="text" id="expire_year"></p>
            <input type="hidden" name="upcmemberid" id="upcmemberid">';
            do_action( 'woocommerce_credit_card_form_end', $this->id );
		}
 
		/*
		 * Custom CSS and JS, in most cases required only when you decided to go with a custom credit card form
		 */
	 	public function payment_scripts() {
            global $user_ID;
             // we need JavaScript to process a token only on cart/checkout pages, right?
            if ( ! is_cart() && ! is_checkout() && ! isset( $_GET['pay_for_order'] ) ) {
                return;
            }
            // if our payment gateway is disabled, we do not have to enqueue JS too
            if ( 'no' === $this->enabled ) {
                return;
            }
            // no reason to enqueue JavaScript if API keys are not set
            if ( empty( $this->publishable_key ) ) {
                return;
            }
            // do not work with card detailes without SSL unless your website is in a test mode
            if ( ! $this->testmode && ! is_ssl() ) {
                return;
            }
            // let's suppose it is our payment processor JavaScript that allows to obtain a token
            wp_enqueue_script( 'univapay_js', 'https://token.ccps.jp/UpcTokenPaymentMini.js' );
            // and this is our custom JS in your plugin directory that works with token.js
            wp_register_script( 'woocommerce_univapay', plugins_url( 'univapay.js', __FILE__ ), array( 'univapay_js' ) );
            // in most payment processors you have to use PUBLIC KEY to obtain a token
            wp_localize_script( 'woocommerce_univapay', 'univapay_params', array(
                'publishableKey' => $this->publishable_key,
                'user_ID' => $user_ID
            ) );
            wp_enqueue_script( 'woocommerce_univapay' );
	 	}
 
		/*
 		 * Fields validation, more in Step 5
		 */
		public function validate_fields() {
		}
 
		/*
		 * We're processing the payments here, everything about it is in Step 5
		 */
		public function process_payment( $order_id ) {
            global $woocommerce;
            // we need it to get any order detailes
            $order = wc_get_order( $order_id );
            /*
             * Your API interaction could be built with wp_remote_post()
              */
            $sod = $this->testmode ? '&sod=testtransaction' : '';
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, 'https://gw.ccps.jp/memberpay.aspx?sid='.$this->publishable_key.'&svid=1&ptype=1&job=CAPTURE&rt=2&upcmemberid='.$_POST['upcmemberid'].$sod.'&siam1='.$order->get_total().'&sisf1='.$order->get_total_shipping());
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'GET');
            if($this->seclevel)
                curl_setopt($curl, CURLOPT_SSL_CIPHER_LIST, 'DEFAULT@SECLEVEL=1');
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($curl);
            $error = curl_error($curl);
            curl_close($curl);
            var_dump($error);
            var_dump($response);
         
            if( !$error ) {
                $result_array = explode('&', $response);
                $data = [];
                foreach($result_array as $value) {
                    $data[] = explode('=', $value);
                }
                if ( (int)$data[1] == 1 ) {  
                    /* 決済処理成功の場合はここに処理内容を記載 */  
                    // we received the payment
                    $order->payment_complete();
                    $order->reduce_order_stock();
                    // some notes to customer (replace true with false to make it private)
                    $order->add_order_note( 'Hey, your order is paid! Thank you!', true );
                    // Empty cart
                    $woocommerce->cart->empty_cart();
                    // Redirect to the thank you page
                    return array(
                        'result' => 'success',
                        'redirect' => $this->get_return_url( $order )
                    );
                } else {  
                    /* 決済処理失敗の場合はここに処理内容を記載 */  
                    wc_add_notice('Please try again.', 'error');
                    return;
                }
            } else {
                wc_add_notice('Connection error.', 'error');
                return;
            }
	 	}
 
		/*
		 * In case you need a webhook, like PayPal IPN etc
		 */
		public function webhook() {
	 	}
 	}
}