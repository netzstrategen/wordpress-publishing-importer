<?php

/**
 * @file
 * Contains \Netzstrategen\PublishingImporter\Admin.
 */

namespace Netzstrategen\PublishingImporter;

/**
 * Administrative back-end functionality.
 */
class Admin {

  /**
   * @implements admin_init
   */
  public static function init() {
    User::admin_init();
  }

}
