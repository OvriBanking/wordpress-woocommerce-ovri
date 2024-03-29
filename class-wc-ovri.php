<?php
class WC_Ovri extends WC_Payment_Gateway
{
  /**
   * Construction of the classical method
   *
   * @return void
   */
  public function __construct()
  {
    global $woocommerce;
    $this->version = ovri_universale_params()['Version'];
    $this->id = 'ovri';

    $this->icon = ovri_get_file("assets/img/carte.png");

    $this->init_form_fields();


    // Load the settings.
    $this->init_settings();
    $this->has_fields = false;

    $this->method_title = __('Ovri', 'ovri');


    // Define user set variables.
    $this->title = $this->settings['title'];
    $this->instructions = $this->get_option('instructions');
    $this->method_description = __('Accept credit cards in less than 5 minutes. <a href="https://my.ovri.app">Open an account now !</a>', 'ovri');
    $this->ovri_gateway_api_key = $this->settings['ovri_gateway_api_key'];
    $this->ovri_gateway_secret_key = $this->settings['ovri_gateway_secret_key'];

    // Actions.
    if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
      add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(&$this, 'process_admin_options'));
    } else {
      add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
    }
    // Add listener IPN Function
    add_action('woocommerce_api_wc_ovri', array($this, 'ovri_notification'));
    // Add listener Customer Return after payment and IPN result !
    add_action('woocommerce_api_wc_ovri_return', array($this, 'ovri_return'));
  }
  /* Admin Panel Options.*/
  public function admin_options()
  {
?>
    <h3>
      <?php _e('Ovri configuration', 'ovri'); ?>
    </h3>
    <div class="simplify-commerce-banner updated"> <img src="<?php echo ovri_get_file("assets/img/ovri.png"); ?>" />
      <p class="main"><strong><?php echo __('Accepts payments by credit card with Ovri', 'ovri'); ?></strong></p>
      <p><?php echo __('Ovri is a secure payment solution on the Internet. As a virtual POS (Electronic Payment Terminal), Ovri makes it possible to cash payments made on the Internet 24 hours a day, 7 days a week. This service relieves your site of the entire payment phase; the latter takes place directly on our secure payment platform.', 'ovri'); ?></p>
      <p><?php echo __('For any problem or information contact: hello@ovri.app', 'ovri'); ?></p>
      <p><a href="https://my.ovri.app" target="_blank" class="button button-primary"><?php echo __('Get a Ovri account', 'ovri'); ?></a> <a href="https://my.ovri.app" target="_blank" class="button"><?php echo __('Test free', 'ovri'); ?></a> <a href="https://www.ovri.com" target="_blank" class="button"><?php echo __('Official site', 'ovri'); ?></a> <a href="https://docs.ovri.app" target="_blank" class="button"><?php echo __('Documentation', 'ovri'); ?></a></p>
    </div>
    <div class="simplify-commerce-banner error">
      <p class="main" style="color:red;"><strong> <?php echo __('If you want your customer to be automatically redirected to your site once the payment is accepted or failed, consider activating this option directly in the configuration of your website in your Ovri DashBoard', 'ovri'); ?> </strong></p>
    </div>
    <table class="form-table">
      <?php $this->generate_settings_html(); ?>
    </table>
<?php
  }
  public function ovri_notification()
  {
    global $woocommerce;
    if (isset($_POST['TransId'])) {
      $TransactionId = sanitize_text_field($_POST['TransId']);
    } else {
      /* Display for DEBUG Mod */
      echo "The transaction id is not transmitted";
      error_log('Ovri IPN Error : The transaction id is not transmitted');
      exit();
    }

    $Request = $this->signRequest(array(
      'ApiKey' => $this->ovri_gateway_api_key,
      'TransID' => $TransactionId
    ));

    $result = json_decode($this->getTransactions($Request)['body'], true);
    if (isset($result['ErrorCode'])) {
      /* If error return error log for mode debug and stop process */
      echo 'Ovri IPN Error : ' . esc_attr($result['ErrorCode']) . '-' . esc_attr($result['ErrorDescription']) . '';
      error_log('Ovri IPN Error : ' . $result['ErrorCode'] . '-' . $result['ErrorDescription'] . '');
      exit();
    }
    $order = new WC_Order($result['Merchant_Order_Id']);
    //check if order exist
    if (!$order) {
      echo 'Ovri IPN Error : Order ' . esc_attr($result['Merchant_Order_Id']) . ' not found';
      error_log('Ovri IPN Error : Order ' . $result['Merchant_Order_Id'] . ' not found');
      exit();
    }
    //check if already paid
    if ($order->is_paid()) {
      echo 'Ovri IPN Error : Order ' . esc_attr($result['Merchant_Order_Id']) . ' already paid';
      error_log('Ovri IPN Error : Order ' . $result['Merchant_Order_Id'] . ' already paid');
      exit();
    }
    //check if already completed
    if ($order->has_status('completed')) {
      echo 'Ovri IPN Error : Order ' . esc_attr($result['Merchant_Order_Id']) . ' is completed';
      error_log('Ovri IPN Error : Order ' . $result['Merchant_Order_Id'] . ' is completed');
      exit();
    }
    //check if already processing
    if ($order->has_status('processing')) {
      echo 'Ovri IPN Error : Order ' . esc_attr($result['Merchant_Order_Id']) . ' is in processig';
      error_log('Ovri IPN Error : Order ' . $result['Merchant_Order_Id'] . ' is in processing');
      exit();
    }
    //check if refunded
    if ($order->has_status('refunded')) {
      echo 'Ovri IPN Error : Order ' . esc_attr($result['Merchant_Order_Id']) . ' is refunded';
      error_log('Ovri IPN Error : Order ' . $result['Merchant_Order_Id'] . ' is refunded');
      exit();
    }
    if ($result['Transaction_Status']['State'] == 2) {
      $order->payment_complete($result['Bank']['Internal_IPS_Id']);
      //reduce stock
      wc_maybe_reduce_stock_levels($result['Merchant_Order_Id']);
      //add note to order
      $order->add_order_note('Payment by Ovri credit card accepted (IPN)', true);
      //empty basket
      $woocommerce->cart->empty_cart();
      echo 'Order ' . esc_attr($result['Merchant_Order_Id']) . ' was successfully completed !';
      exit();
    } else {
      //declined or cancelled
      $order->update_status('failed', __('Ovri - Transaction ' . $TransactionId . ' - FAILED (' . $result['Transaction_Status']['Bank_Code_Description'] . ')', 'ovri'));
      echo 'Order ' . esc_attr($result['Merchant_Order_Id']) . ' was successfully cancelled !';
      exit();
    }

    echo "Unknown error";
    exit();
  }

  public function ovri_return()
  {
    global $woocommerce;

    /*Default Url*/
    $returnUri = wc_get_checkout_url();
    /*Securing*/
    $order_id = sanitize_text_field($_GET['mtg_ord']);
    /*Prevalidate*/
    if ($order_id < 1) {
      return;
    }
    /*Validation*/
    $WcOrder = new WC_Order($order_id);
    if (!$WcOrder) {
      return;
    };

    /*Check if the payment method is Ovri for this order */
    if ($WcOrder->get_payment_method() !== "ovri" && $WcOrder->get_payment_method() !== "ovripnftwo" & $WcOrder->get_payment_method() !== "ovripnfthree" && $WcOrder->get_payment_method() !== "ovripnffour") {
      return;
    }


    /*Checking Order Status*/
    if ($WcOrder->get_status() === 'pending') {

      /* If the order is still pending, then we call the Ovri webservice to check the payment again and update the order status */
      $Request = $this->signRequest(array(
        'ApiKey' => $this->ovri_gateway_api_key,
        'MerchantOrderId' => $order_id
      ));
      $checkTransaction = json_decode($this->getPaymentByOrderID($Request)['body'], true);

      /* If an error code is returned then we redirect the client indicating the problem */
      if (isset($checkTransaction['ErrorCode'])) {
        error_log('Ovri API Error @ovri_return : ' . $checkTransaction['ErrorCode'] . ' : ' . $checkTransaction['ErrorDescription'] . '');
        wc_add_notice(__('An internal error occurred', 'ovri'), 'error');
      }

      /* All is ok so we finish the final process */
      $transactionStatuts = $checkTransaction['Transaction_Status']['State'];
      if ($transactionStatuts == "2") {
        /* transaction approved */
        /* Record the payment with the transaction number */
        $WcOrder->payment_complete($checkTransaction['Bank']['Internal_IPS_Id']);
        /* Reduction of the stock */
        wc_reduce_stock_levels($order_id);
        /* Add a note on the order to say that the order is confirmed */
        $WcOrder->add_order_note('Payment by Ovri credit card accepted', true);

        /* We empty the basket */
        $woocommerce->cart->empty_cart();
        $returnUri = $this->get_return_url($WcOrder);
      } else {
        if ($transactionStatuts == "6") {
          /* The transaction is still pending */
          /* A message is displayed to the customer asking him to be patient */
          /* We make it wait 10 seconds then we refresh the page */
          echo __('Please wait a few moments ...', 'ovri');
          header("Refresh:10");
          exit();
        } else {
          /* La transaction est annulé ou refusé */
          /* The customer is redirected to the shopping cart page with the rejection message */
          /* Redirect the customer to the shopping cart and indicate that the payment is declined */
          wc_add_notice(__('Sorry, your payment was declined !', 'ovri'), 'error');
        }
      }
    } else {

      /* The answer from ovri has already arrived (IPN) */
      /* Redirect the customer to the thank you page if the order is well paid */
      /* Fixed also redirects the customer to the acceptance page if the order has a completed status, useful for self-delivered products */
      if ($WcOrder->get_status() === 'processing' || $WcOrder->get_status() === 'completed') {
        $returnUri = $this->get_return_url($WcOrder);
      } else {
        /* Redirect the customer to the shopping cart and indicate that the payment is declined */
        wc_add_notice(__('Sorry, your payment was declined !', 'ovri'), 'error');
        /* force create new order for new attempts */
        WC()->session->set('order_awaiting_payment', false);
      }
    }

    /* Redirect to thank you or decline page */
    wp_redirect($returnUri);
    exit();
  }


  /**  
   * Initialise Gateway Settings Form Fields for ADMIN. 
   */
  public function init_form_fields()
  {
    global $woocommerce;


    $this->form_fields = array(
      'enabled' => array(
        'title' => __('Enable / Disable', 'ovri'),
        'type' => 'checkbox',
        'label' => __('Activate card payment with Ovri', 'ovri'),
        'default' => 'no'
      ),
      'title' => array(
        'title' => __('Method title', 'ovri'),
        'type' => 'text',
        'description' => __('This is the name displayed on your checkout', 'ovri'),
        'desc_tip' => true,
        'default' => __('Credit card payment', 'ovri')
      ),
      'description' => array(
        'title' => __('Message before payment', 'ovri'),
        'type' => 'textarea',
        'description' => __('Message that the customer sees when he chooses this payment method', 'ovri'),
        'desc_tip' => true,
        'default' => __('You will be redirected to our secure server to make your payment', 'ovri')
      ),
      'ovri_gateway_api_key' => array(
        'title' => __('Your API Key', 'ovri'),
        'description' => __('To obtain it go to the configuration of your merchant contract (section "Merchant account").', 'ovri'),
        'type' => 'text'
      ),
      'ovri_gateway_secret_key' => array(
        'title' => __('Your Secret Key', 'ovri'),
        'description' => __('To obtain it go to the configuration of your merchant contract (section "Merchant account").', 'ovri'),
        'type' => 'text'
      )
    );
  }

  /**
   * Retrieve transaction details from the transaction PSP id
   */
  private function getTransactions($arg)
  {
    $response = wp_remote_get(ovri_universale_params()['ApiGetTransaction'] . '?TransID=' . $arg["TransID"] . '&SHA=' . $arg["SHA"] . '&ApiKey=' . $arg["ApiKey"] . '');
    return $response;
  }

  /** 
   * Retrieve transaction details from the order ID
   */
  private function getPaymentByOrderID($arg)
  {


    $response = wp_remote_get(ovri_universale_params()['ApiGetTransactionByOrderId'] . '?MerchantOrderId=' . $arg["MerchantOrderId"] . '&SHA=' . $arg["SHA"] . '&ApiKey=' . $arg["ApiKey"] . '');
    return $response;
  }
  /** 
   * Request authorization token from Ovri 
   * Private function only accessible to internal execution 
   */
  private function getToken($args)
  {
    $ConstructArgs = array(
      'headers' => array(
        'Content-type: application/x-www-form-urlencoded'
      ),
      'sslverify' => false,
      'body' => $this->signRequest($args)
    );
    $response = wp_remote_post(ovri_universale_params()['ApiInitPayment'], $ConstructArgs);
    return $response;
  }
  /* Signature of parameters with your secret key before sending to Ovri */
  /* Private function only accessible to internal execution */
  private function signRequest($params, $beforesign = "")
  {
    $ShaKey = $this->ovri_gateway_secret_key;
    foreach ($params as $key => $value) {
      $beforesign .= $value . "!";
    }
    $beforesign .= $ShaKey;
    $sign = hash("sha512", base64_encode($beforesign . "|" . $ShaKey));
    $params['SHA'] = $sign;
    return $params;
  }


  /**
   * Payment processing and initiation
   * Redirection of the client if the initiation is successful
   * Display of failures on the checkout page in case of error
   */
  public function process_payment($order_id)
  {
    //obtain token for payment processing
    global $woocommerce;
    $order = new WC_Order($order_id);
    $email = method_exists($order, 'get_billing_email') ? $order->get_billing_email() : $order->billing_email;
    $custo_firstname = method_exists($order, 'get_billing_first_name') ? $order->get_billing_first_name() : $order->billing_first_name;
    $custo_lastname = method_exists($order, 'get_billing_last_name') ? $order->get_billing_last_name() : $order->billing_last_name;
    $the_order_id = method_exists($order, 'get_id') ? $order->get_id() : $order->id;
    $the_order_key = method_exists($order, 'get_order_key') ? $order->get_order_key() : $order->order_key;
    $requestToken = array(
      'MerchantKey' => $this->get_option('ovri_gateway_api_key'),
      'amount' => $order->get_total(),
      'RefOrder' => $order_id,
      'Customer_Email' => "$email",
      'Customer_FirstName' => $custo_firstname ? $custo_firstname : $custo_lastname,
      'Customer_Name' => $custo_lastname ? $custo_lastname : $custo_firstname,
      'urlOK' => get_site_url() . '/?wc-api=wc_ovri_return&mtg_ord=' . $order_id . '',
      'urlKO' => get_site_url() . '/?wc-api=wc_ovri_return&mtg_ord=' . $order_id . '',
      'urlIPN' => get_site_url() . '/?wc-api=wc_ovri',
    );
    $getToken = $this->getToken($requestToken);
    if (!is_wp_error($getToken)) {
      $results = json_decode($getToken['body'], true);
      $explanationIS = "";
      if ($getToken['response']['code'] === 400 || $getToken['response']['code'] === 200) {
        if ($results['Explanation']) {
          foreach ($results['Explanation'] as $key => $value) {
            $explanationIS .= "<br><b>" . $key . "</b> : " . $value;
          }
        }
        if ($results['MissingParameters']) {
          $explanationIS .= "<br> List of missing parameters : ";
          foreach ($results['MissingParameters'] as $key => $value) {
            $explanationIS .= "<b>" . $value . " , ";
          }
        }
      }
      if ($getToken['response']['code'] === 200) {

        wc_add_notice(__('Ovri : ' . $results['ErrorCode'] . ' - ' . $results['ErrorDescription'] . ' - ' . $explanationIS . '', 'ovri'), 'error');
        return;
      } else if ($getToken['response']['code'] === 400) {
        wc_add_notice(__('Ovri : ' . $results['ErrorCode'] . ' - ' . $results['ErrorDescription'] . ' - ' . $explanationIS . '', 'ovri'), 'error');
        return;
      } else if ($getToken['response']['code'] === 201) {
        return array(
          'result' => 'success',
          'redirect' => ovri_universale_params()['WebUriStandard'] . $results['SACS']
        );
      } else {
        wc_add_notice('Ovri : Connection error', 'error');
        return;
      }
    } else {
      wc_add_notice('Ovri : Connection error', 'error');
      return;
    }
  }
}
?>