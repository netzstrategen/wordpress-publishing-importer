<?php

/**
 * Password updated for existing account overwritten by import.
 */

defined('ABSPATH') || exit;

namespace Netzstrategen\PublishingImporter;

/**
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p><?= __('Hello, your service portal password has been updated to match the one from BNN news portal.', Plugin::L10N) ?></p>

<hr>

<?php
do_action('woocommerce_email_footer', $email);
