<?php
/*
Plugin Name: Ovri Banking
Plugin URI: https://my.ovri.app
Description: Accept credit cards in less than 5 minutes
Version: 1.5.5
Author: OVRI SAS
Author URI: https://www.ovri.com
License: OVRI SAS
Domain Path: /languages
Text Domain: ovri
*/

define('OvriVersion', "1.5.5");

/* Additional links on the plugin page */
add_filter('plugin_row_meta', 'ovri_register_plugin_links', 10, 2);

/* Auto update plugins */
function ovri_update_auto_plins($update, $item)
{
  // Array of plugin slugs to always auto-update
  $plugins = array(
    'ovri'
  );
  if (in_array($item->slug, $plugins)) {
    return true;
  } else {
    return $update;
  }
}
add_filter('auto_update_plugin', 'ovri_update_auto_plins', 10, 2);

/* Securing file calls by taking into account specific installations */
function ovri_get_file($namefiles = "")
{
  $plugin_url = plugin_dir_url(__FILE__);
  return $plugin_url . $namefiles;
}
/* Add styles Css */
function ovri_load_plugin_css()
{
  $plugin_url = plugin_dir_url(__FILE__);
  wp_enqueue_style('ovri', $plugin_url . 'assets/css/styles.css');
}
add_action('wp_enqueue_scripts', 'ovri_load_plugin_css');
/* Function for universal calling in the payment sub-modules */
function ovri_universale_params()
{
  $baseUriOvriWEB = "https://checkout.ovri.app";
  $baseUriOvriAPI = "https://api.ovri.app/payment";
  $config = array(
    'Version' => "1.5.5",
    'ApiInitPayment' => $baseUriOvriAPI . "/init_transactions/",
    'ApiGetTransaction' => $baseUriOvriAPI . "/transactions/",
    'ApiGetTransactionByOrderId' => $baseUriOvriAPI . "/transactions_by_merchantid/",
    'WebUriStandard' => $baseUriOvriWEB . "/pay/standard/token/",
    'WebUriInstallment' => $baseUriOvriWEB . "/pay/installment/token/",
    'WebUriSubscription' => $baseUriOvriWEB . "/pay/subscription/token/",

  );
  return $config;
}

function ovri_register_plugin_links($links, $file)
{
  $base = plugin_basename(__FILE__);
  if ($file == $base) {
    $links[] = '<a href="https://docs.ovri.app" target="_blank">' . __('Documentation', 'ovri') . '</a>';
  }
  return $links;
}

/* WooCommerce fallback notice. */
function ovri_ipg_fallback_notice()
{
  $htmlToReturn = '<div class="error">';
  $htmlToReturn .= '<p>' . sprintf(__('The Ovri module works from Woocommerce version %s minimum', 'ovri'), '<a href="http://wordpress.org/extend/plugins/woocommerce/">WooCommerce</a>') . '</p>';
  $htmlToReturn .= '</div>';
  echo $htmlToReturn;
}

/* Loading both payment methods */
function custom_Ovri_gateway_load()
{
  global $woocommerce;
  if (!class_exists('WC_Payment_Gateway')) {
    add_action('admin_notices', 'ovri_ipg_fallback_notice');
    return;
  }
  /* Payment classic */
  function wc_CustomOvri_add_gateway($methods)
  {
    $methods[] = 'WC_Ovri';
    return $methods;
  }
  /* Payment by installments */
  function wc_CustomOvriPnfTwo_add_gateway($methods)
  {
    $methods[] = 'WC_OvriPnfTwo';
    return $methods;
  }

  function wc_CustomOvriPnfThree_add_gateway($methods)
  {
    $methods[] = 'WC_OvriPnfThree';
    return $methods;
  }

  function wc_CustomOvriPnfFour_add_gateway($methods)
  {
    $methods[] = 'WC_OvriPnfFour';
    return $methods;
  }
  add_filter('woocommerce_payment_gateways', 'wc_CustomOvri_add_gateway');
  add_filter('woocommerce_payment_gateways', 'wc_CustomOvriPnfTwo_add_gateway');
  add_filter('woocommerce_payment_gateways', 'wc_CustomOvriPnfThree_add_gateway');
  add_filter('woocommerce_payment_gateways', 'wc_CustomOvriPnfFour_add_gateway');
  /* Load class for both payment methods */
  require_once plugin_dir_path(__FILE__) . 'class-wc-ovri.php';
  require_once plugin_dir_path(__FILE__) . 'class-wc-ovripnf-two.php';
  require_once plugin_dir_path(__FILE__) . 'class-wc-ovripnf-three.php';
  require_once plugin_dir_path(__FILE__) . 'class-wc-ovripnf-four.php';
}
add_action('plugins_loaded', 'custom_Ovri_gateway_load', 0);

/* Adds custom settings url in plugins page. */
function ovri_action_links($links)
{
  $settings = array(
    'settings' => sprintf(
      '<a href="%s">%s</a>',
      admin_url('admin.php?page=wc-settings&tab=checkout'),
      __('Payment Gateways', 'Ovri')
    )
  );
  return array_merge($settings, $links);
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'ovri_action_links');

/* Filtering of methods according to the amount of the basket */
add_filter('woocommerce_available_payment_gateways', 'ovri_payment_method_filters', 1);

function ovri_payment_method_filters($gateways)
{


  if (isset($gateways['ovri'])) {
    if ($gateways['ovri']->{'enabled'} == "yes") {
      if ((!$gateways['ovri']->{'ovri_gateway_api_key'} || $gateways['ovri']->{'ovri_gateway_api_key'} == ' ') || (!$gateways['ovri']->{'ovri_gateway_secret_key'} || $gateways['ovri']->{'ovri_gateway_secret_key'} == ' ')) {
        if (!is_admin()) {
          wc_add_notice('<b>Ovri</b> : ' . __('Module not configured, API key or ENCRYPTION key missing', 'ovri') . '', 'error');
        }
        unset($gateways['ovri']); //Not avialable cause not settings
      }
    }
  }
  if (isset($gateways['ovripnftwo'])) {
    if ($gateways['ovripnftwo']->{'enabled'} == "yes") {
      if ((!$gateways['ovri']->{'ovri_gateway_api_key'} || $gateways['ovri']->{'ovri_gateway_api_key'} == ' ') || (!$gateways['ovri']->{'ovri_gateway_secret_key'} || $gateways['ovri']->{'ovri_gateway_secret_key'} == ' ')) {
        if (!is_admin()) {
          wc_add_notice('<b>Ovri (2X)</b> : ' . __('Module not configured, API key or ENCRYPTION key missing', 'ovri') . '', 'error');
        }
        unset($gateways['ovripnftwo']); //Not avialable cause not settings
      }
    }
  }

  if (isset($gateways['ovripnfthree'])) {
    if ($gateways['ovripnfthree']->{'enabled'} == "yes") {
      if ((!$gateways['ovri']->{'ovri_gateway_api_key'} || $gateways['ovri']->{'ovri_gateway_api_key'} == ' ') || (!$gateways['ovri']->{'ovri_gateway_secret_key'} || $gateways['ovri']->{'ovri_gateway_secret_key'} == ' ')) {
        if (!is_admin()) {
          wc_add_notice('<b>Ovri (3X)</b> : ' . __('Module not configured, API key or ENCRYPTION key missing', 'ovri') . '', 'error');
        }
        unset($gateways[wc_CustomOvriPnfThree_add_gateway()]); //Not avialable cause not settings
      }
    }
  }

  if (isset($gateways['ovripnffour'])) {
    if ($gateways['ovripnffour']->{'enabled'} == "yes") {
      if ((!$gateways['ovri']->{'ovri_gateway_api_key'} || $gateways['ovri']->{'ovri_gateway_api_key'} == ' ') || (!$gateways['ovri']->{'ovri_gateway_secret_key'} || $gateways['ovri']->{'ovri_gateway_secret_key'} == ' ')) {
        if (!is_admin()) {
          wc_add_notice('<b>Ovri (4X)</b> : ' . __('Module not configured, API key or ENCRYPTION key missing', 'ovri') . '', 'error');
        }
        unset($gateways[wc_CustomOvriPnfThree_add_gateway()]); //Not avialable cause not settings
      }
    }
  }

  //Check first if payment module is settings	
  if (isset($gateways['ovripnftwo'])) {
    if ($gateways['ovripnftwo']->{'enabled'} == "yes") {
      /* Check if the amount of the basket is sufficient to display the method in several installments*/
      global $woocommerce;

      $IPSPnf = $gateways['ovripnftwo']->{'settings'};

      if (isset($woocommerce->cart->total) && $woocommerce->cart->total < $IPSPnf['seuil']) {
        unset($gateways['ovripnftwo']);
      }
    }
  }
  if (isset($gateways['ovripnfthree'])) {
    if ($gateways['ovripnfthree']->{'enabled'} == "yes") {
      /* Check if the amount of the basket is sufficient to display the method in several installments*/
      global $woocommerce;

      $IPSPnf = $gateways['ovripnfthree']->{'settings'};

      if (isset($woocommerce->cart->total) && $woocommerce->cart->total < $IPSPnf['seuil']) {
        unset($gateways['ovripnfthree']);
      }
    }
  }
  if (isset($gateways['ovripnffour'])) {
    if ($gateways['ovripnffour']->{'enabled'} == "yes") {
      /* Check if the amount of the basket is sufficient to display the method in several installments*/
      global $woocommerce;

      $IPSPnf = $gateways['ovripnffour']->{'settings'};

      if (isset($woocommerce->cart->total) && $woocommerce->cart->total < $IPSPnf['seuil']) {
        unset($gateways['ovripnffour']);
      }
    }
  }
  /* Return of available methods */
  return $gateways;
}

/* Adding translation files */
load_plugin_textdomain('ovri', false, dirname(plugin_basename(__FILE__)) . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR);
