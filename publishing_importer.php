<?php

/*
  Plugin Name: Publishing Importer
  Version: 1.1.0
  Text Domain: publishing_importer
  Description: Imports content from publishing industry content management systems.
  Author: Daniel F. Kudwien (sun)
  Author URI: http://www.netzstrategen.com/sind/daniel-kudwien
  License: GPL-2.0+
  License URI: http://www.gnu.org/licenses/gpl-2.0
*/

namespace Netzstrategen\PublishingImporter;

if (!defined('ABSPATH')) {
  header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
  exit;
}

/**
 * Loads PSR-4-style plugin classes.
 */
function classloader($class) {
  static $ns_offset;
  if (strpos($class, __NAMESPACE__ . '\\') === 0) {
    if ($ns_offset === NULL) {
      $ns_offset = strlen(__NAMESPACE__) + 1;
    }
    include __DIR__ . '/src/' . strtr(substr($class, $ns_offset), '\\', '/') . '.php';
  }
}
spl_autoload_register(__NAMESPACE__ . '\classloader');

register_activation_hook(__FILE__, __NAMESPACE__ . '\Schema::activate');
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\Schema::deactivate');
register_uninstall_hook(__FILE__, __NAMESPACE__ . '\Schema::uninstall');

add_action('plugins_loaded', __NAMESPACE__ . '\Plugin::loadTextdomain');
add_action('init', __NAMESPACE__ . '\Plugin::init', 30);
add_action('admin_init', __NAMESPACE__ . '\Admin::init');

if (defined('WP_CLI') && WP_CLI) {
  \WP_CLI::add_command('publishing-importer', __NAMESPACE__ . '\CliCommand');
}
