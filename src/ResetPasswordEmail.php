<?php

/**
 * @file
 * Contains \Netzstrategen\PublishingImporter\ResetPasswordEmail.
 */

namespace Netzstrategen\PublishingImporter;

/**
 * Email confirmation on password update after 'digital' subscribers import.
 */
class ResetPasswordEmail extends \WC_Email {

  public function __construct() {
    $this->id = 'password_updated';
    $this->customer_email = TRUE;
    $this->title = __('Imported existing subscribers password updated', Plugin::L10N);
    $this->description = __('Password update on import \'digital\' subscribers with existing service portal accounts.', Plugin::L10N);
    $this->template_html = 'emails/digital-subscribers-password-update.php';
    $this->template_plain = 'emails/plain/digital-subscribers-password-update.php';
    $this->default_path = Plugin::getBasePath() . '/templates/';
    $this->template_base = Plugin::getBasePath() . '/templates/';

    add_action('imported_subscriber_password_updated_notification', [$this, 'trigger']);

    parent::__construct();
  }

  public function get_default_subject() {
    return __('[{site_title}] Your login credentials have been updated', Plugin::L10N);
  }

  public function get_default_heading() {
    return __('Password Updated', Plugin::L10N);
  }

  /**
   * @implements woocommerce_customer_save_address
   */
  public function trigger($user) {
    $this->setup_locale();
    $this->account = $user;
    $this->user_email = $this->recipient = $user->user_email;
    $this->customer = new \WC_Customer($user->ID);

    if ($this->is_enabled() && $this->get_recipient()) {
      $this->send($this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());
    }

    $this->restore_locale();
  }

  public function get_content_html() {
    $message = wc_get_template_html($this->template_html, [
      'account'       => $this->account,
      'customer'      => $this->customer,
      'email_heading' => $this->get_heading(),
      'plain_text'    => FALSE,
      'email'         => $this,
    ], '', $this->default_path);
    $message = $this->format_string($message);
    return $message;
  }

  public function get_content_plain() {
    $message = wc_get_template_html($this->template_plain, [
      'account'       => $this->account,
      'customer'      => $this->customer,
      'email_heading' => $this->get_heading(),
      'plain_text'    => TRUE,
      'email'         => $this,
    ], '', $this->default_path);
    $message = $this->format_string($message);
    return $message;
  }

}
