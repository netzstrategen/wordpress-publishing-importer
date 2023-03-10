<?php

/**
 * @file
 * Contains \Netzstrategen\PublishingImporter\Parser\Alfa\Subscriber.
 */

namespace Netzstrategen\PublishingImporter\Parser\Alfa;

use Netzstrategen\PublishingImporter\Parser\User;

/**
 * Parser for Alfa Epaper XML Subscribers.
 */
class Subscriber extends User {

  public function parse() {
    // Mark newly created user account as unverified.
    if (empty($this->ID)) {
      $this->meta['alg_wc_ev_is_activated'] = '0';
    }

    apply_filters('publishing_importer/user/parse', $this, $this->raw);
  }

  public function save() {
    parent::save();
  }

  protected function insertAttachment($attachment_id, array $file, $current_number) {}

}
