<?php

/**
 * @file
 * Contains \Netzstrategen\PublishingImporter\Schema.
 */

namespace Netzstrategen\PublishingImporter;

/**
 * Generic plugin lifetime and maintenance functionality.
 */
class Schema {

  /**
   * register_activation_hook() callback.
   */
  public static function activate() {
    global $wpdb;
//    add_option('', []);
  }

  /**
   * register_deactivation_hook() callback.
   */
  public static function deactivate() {
  }

  /**
   * register_uninstall_hook() callback.
   */
  public static function uninstall() {
//    delete_option('');
  }

}
