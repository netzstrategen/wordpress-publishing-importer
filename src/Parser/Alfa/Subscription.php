<?php

/**
 * @file
 * Contains \Netzstrategen\PublishingImporter\Parser\Alfa\Subscription.
 */

namespace Netzstrategen\PublishingImporter\Parser\Alfa;

use Netzstrategen\PublishingImporter\Plugin;
use Netzstrategen\PublishingImporter\Parser\Post;

/**
 * Parser for Alfa Media Subscribers XML export.
 */
class Subscription extends Post {

  const FILE_EXTENSION = 'xml';

  static protected $seenIds = [];

  static protected $product_id;
  static protected $product;

  protected $xmlElement;
  protected $params = [];

  public static function createFromFile(array $config, $pathname, &$raw_filename = NULL) {
    global $wpdb;

    $xml_element = current(Plugin::$importFileHandle);
    if (!$xml_element) {
      return FALSE;
    }
    next(Plugin::$importFileHandle);

    $raw = $xml_element;
    $raw_filename = (string) $xml_element['Abonummer'][0];

    // If we cannot extract the subscription ID, something is wrong.
    if (!$raw_filename) {
      return FALSE;
    }
    static::$seenIds[$raw_filename] = $raw_filename;

    $wp_post = [];
    if ($raw_filename !== NULL) {
      $wp_post = static::loadByGuid('http://' . $config['publisher'] . '/' . $config['system'] . '/' . $raw_filename) ?: [];
    }
    $post = new static($config, $wp_post, $raw);
    if ($raw_filename !== NULL) {
      $post->rawFilename = $raw_filename;
    }
    $post->xmlElement = $xml_element;
    return $post;
  }

  public function setRaw($raw) {
    $this->raw = $raw->asXML();
    return $this;
  }

  public function isPristine() {
    return TRUE;
  }

  public function parse() {
    $this->guid = 'http://' . $this->config['publisher'] . '/' . $this->config['system'] . '/' . $this->rawFilename;
    $this->meta['_alfa_subscription_id'] = $this->rawFilename;
    $this->meta['_subscription_number_formatted'] = $this->rawFilename;
    // Save a non-empty number, so that woocommerce-sequential-subscription-numbers
    // does not assign a new sequential ID.
    $this->meta['_subscription_number'] = '1';

    $this->params = [
      'created_via' => $this->config['publisher'],
    ];

    $this->params['billing_period'] = get_post_meta(static::$product_id, '_subscription_period', TRUE);
    $this->params['billing_interval'] = get_post_meta(static::$product_id, '_subscription_period_interval', TRUE);

    // Prepare epaper edition access data.
    $epaper_editions = [];
    $start_date = NULL;
    $end_date = NULL;
    foreach ($this->xmlElement[0]->Bezug as $edition) {
      $epaper_editions[] = (string) $edition['Ausgabe'];

      // The user should see the start date of the edition that started first.
      if (!isset($start_date) || strtotime($start_date) > strtotime((string) $edition['Beginn'])) {
        $start_date = (string) $edition['Beginn'];
      }
      // The whole subscription should only end after the latest edition ended.
      if (!empty($edition['Ende']) && (!isset($end_date) || strtotime($end_date) < strtotime((string) $edition['Ende']))) {
        $end_date = (string) $edition['Ende'];
      }
    }
    $this->meta['_epaper_editions'] = $epaper_editions;

    $this->params['start_date'] = $start_date . ' 00:00:00 Europe/Berlin';
    if ($end_date) {
      $this->params['end_date'] = $end_date . ' 23:59:00 Europe/Berlin';
    }

    if (!empty($this->ID)) {
      $this->meta['_created_via'] = $this->params['created_via'];
      $this->meta['_billing_period'] = $this->params['billing_period'];
      $this->meta['_billing_interval'] = $this->params['billing_interval'];
      $this->meta['_schedule_start'] = $this->params['start_date'];
      $this->meta['_schedule_end'] = $end_date ? $this->params['end_date'] : '';
    }

    // Ensure that dates from a previously active timeframe of this same
    // subscription are reset/unset.
    $this->meta['_schedule_next_payment'] = 0;
    $this->meta['_schedule_cancelled'] = 0;

    if (empty($this->xmlElement['Email'][0])
        || !($customer_email = (string) $this->xmlElement['Email'][0])
        || filter_var($customer_email, FILTER_VALIDATE_EMAIL) === FALSE) {
      Plugin::error(vsprintf("Invalid email address for subscription ID '%s': '%s'", [
        $this->meta['_alfa_subscription_id'],
        $customer_email ?? '',
      ]));
      return FALSE;
    }

    $user_array = [
      'user_email' => $customer_email,
      'user_pass' => (string) $this->xmlElement['Passwort'][0],
      'last_name' => (string) $this->xmlElement['Name'][0],
    ];
    if (!empty($this->xmlElement['Vorname'][0])) {
      $user_array['first_name'] = (string) $this->xmlElement['Vorname'][0];
    }
    // If this subscription never had a user account associated, look it up or
    // create it, and associate it.
    $customer_id = $this->wp_post->_customer_user;
    $user = get_user_by('email', $customer_email);
    if (!$customer_id && !$user) {
      if ($user = get_user_by('login', $this->meta['_alfa_subscription_id'])) {
        // Edge case: The user account is not found by email address, but there
        // is already an account with the subscription ID as user_login, so it
        // is not possible to create the new account. As we do not know the
        // reason for this, we just send a notice towards customer service,
        // and skip the record.
        $recipients = 'tech+bnn@netzstrategen.com, ' . get_option('admin_email');
        $message = "Trying to create user account from following:\n\n"
          . $this->raw
          . "\n\nbut an account with same subscription ID and login already exists:\n\n"
          . get_edit_user_link($user->ID);
        $was_sending_emails = Plugin::$redirectAllMails;
        Plugin::$redirectAllMails = FALSE;
        wp_mail($recipients, '[service.bnn.de] Duplicate subscriber ID', $message);
        Plugin::$redirectAllMails = $was_sending_emails;
        return FALSE;
      }
      $user_array += [
        // Normally we would repeat the email as login name, but in this case
        // we want to allow users to still log in with their subscription ID.
        'user_login' => $this->meta['_alfa_subscription_id'],
      ];
      $user = new Subscriber($this->config, $user_array, $user_array);
      $user->meta['_epaper_editions'] = $epaper_editions;
      $user->meta['_send_welcome_email'] = 1;
      $user->parse();
      $user->save();
    }
    else {
      // Update the user that is associated with the subscription, and not a
      // user account that happens to have the same email address.
      if ($customer_id) {
        $user = get_user_by('ID', $customer_id);
      }
      unset($user_array['user_pass']);

      // Notify existing user only once, so before setting user meta field
      // '_publishing_importer_raw'.
      $previous_data = get_user_meta($user->ID, '_publishing_importer_raw', TRUE);
      $notify_user = empty($previous_data);

      $user = new Subscriber($this->config, $user, $user_array);
      $user->meta['_epaper_editions'] = $epaper_editions;
      $user->parse();
      $user->save();
      if (!empty($notify_user)) {
        do_action('imported_subscriber_password_updated', $user);
      }
    }
    $this->params['customer_id'] = $user->ID;
    $this->meta['_customer_user'] = $user->ID;
  }

  protected function wp_insert_post(array $post_array) {
    // Renewals and invoicing continues to be handled in Alfa for the imported
    // subscriptions. Therefore we do not need to set up an order and payment
    // method for them.
    $subscription = wcs_create_subscription($this->params + $post_array);
    if ($subscription instanceof \WP_Error) {
      return $subscription;
    }
    $this->ID = $subscription->get_id();
    $subscription->add_product(static::$product, 1);
    $subscription->update_status('active');
    return $this->ID;
  }

  protected function wp_update_post(array $post_array) {
    // Reactivate a subscription that temporarily disappeared from the XML.
    if ($post_array['post_status'] === 'wc-cancelled') {
      $post_array['post_status'] = 'wc-active';
      $subscription = wcs_get_subscription($this->ID);
      $subscription->update_status('active');
    }
    return wp_update_post($post_array, TRUE);
  }

  protected function insertAttachment($attachment_id, array $file, $current_number) {}

  public static function beforeImport($config) {
    static::$product_id = wc_get_product_id_by_sku($config['sku']);
    static::$product = new \WC_Product(static::$product_id);

    add_filter('woocommerce_new_subscription_data', __CLASS__ . '::woocommerce_new_subscription_data', 10, 2);
    // Ensure validation before setting subscriptions as 'active' on line 160.
    add_filter('woocommerce_can_subscription_be_updated_to_active', '__return_true');

    // Prevent email redirects since dedicated mu-plugin disable-external-emails
    // is handling this globally.
    Plugin::$redirectAllMails = FALSE;
  }

  public static function woocommerce_new_subscription_data(array $post_array, array $args) {
    $post_array['guid'] = $args['guid'];
    $post_array['meta_input'] = $args['meta_input'];
    return $post_array;
  }

  public static function afterImport() {
    global $wpdb;

    if (static::$seenIds) {
      $expired_ids = [];
      $query = "SELECT post_id, meta_value FROM wp_postmeta pm
        INNER JOIN wp_posts p ON pm.post_id = p.id AND p.post_type = 'shop_subscription' AND p.post_status <> 'wc-cancelled'
        WHERE pm.meta_key = '_alfa_subscription_id' ORDER BY pm.post_id";
      $offset = 0;
      while ($chunk = $wpdb->get_results("$query LIMIT $offset,1000")) {
        $offset += 1000;
        foreach ($chunk as $row) {
          if (!isset(static::$seenIds[$row->meta_value])) {
            $expired_ids[] = $row->post_id;
          }
        }
      }
      // Avoid cancelling an unusually high amount of subscriptions in case of
      // unexpected data in the XML import file.
      if (($count = count($expired_ids)) > 400) {
        $expired_ids_formatted = implode("\n  ", $expired_ids);
        Plugin::error("Would cancel more than $count subscriptions. Please verify that the XML file is correct. Skipping cancellations. No subscriptions were cancelled.
  $expired_ids_formatted
");
      }
      elseif ($expired_ids) {
        add_filter('woocommerce_can_subscription_be_updated_to_cancelled', '__return_true');
        add_filter('woocommerce_can_subscription_be_updated_to_pending-cancel', '__return_true');
        foreach ($expired_ids as $subscription_id) {
          wcs_get_subscription($subscription_id)->update_status('cancelled');
          // Ensure the subscription will be processed again if it reappears in the XML.
          delete_post_meta($subscription_id, '_publishing_importer_raw');
          Plugin::notice("Cancelled subscription $subscription_id.");
        }
        remove_filter('woocommerce_can_subscription_be_updated_to_cancelled', '__return_true');
        remove_filter('woocommerce_can_subscription_be_updated_to_pending-cancel', '__return_true');
      }
    }
  }

}
