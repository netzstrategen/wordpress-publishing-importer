<?php

/**
 * @file
 * Contains \Netzstrategen\PublishingImporter\Plugin.
 */

namespace Netzstrategen\PublishingImporter;

/**
 * Main front-end functionality.
 */
class Plugin {

  /**
   * Gettext localization domain.
   *
   * @var string
   */
  const L10N = 'publishing_importer';

  /**
   * @var string
   */
  private static $baseUrl;

  /**
   * @implements init
   */
  public static function init() {
    if (is_admin() || defined('WP_CLI')) {
      return;
    }
    //static::importContent();
  }

  /**
   * Imports new content from filesystem folders.
   *
   * wp eval --user=system 'Netzstrategen\PublishingImporter\Plugin::importContent();'
   * wp eval --user=system 'Netzstrategen\PublishingImporter\Plugin::importContent("pz", "123456.xml");'
   *
   * @param string $only_publisher_id
   *   (optional) The publisher ID to import; e.g. 'pz'.
   * @param string $only_article_filename
   *   (optional) The article filename to import; e.g. '123456.xml'.
   */
  public static function importContent($only_publisher_id = NULL, $only_article_filename = NULL) {
    // Read configuration.
    $config = json_decode(file_get_contents(static::getBasePath() . '/config.json'), TRUE);
    if ($config === NULL) {
      throw new \Exception("config.json parsing error: " . json_last_error_msg());
    }
    // Check for local (site-specific) override configuration.
    if (file_exists(ABSPATH . '.publishing_importer.config.json')) {
      $config_local = json_decode(file_get_contents(ABSPATH . '.publishing_importer.config.json'), TRUE);
      if ($config_local === NULL) {
        throw new \Exception(".publishing_importer.config.json parsing error: " . json_last_error_msg());
      }
      $config = array_replace_recursive($config, $config_local);
    }
    // Validate configuration.
    foreach ($config as $publisher => $publisher_config) {
      $config[$publisher]['id'] = $publisher;
      foreach ($config[$publisher]['importDirectories'] as $name => $path) {
        if (!$realpath = realpath(ABSPATH . $path)) {
          throw new \LogicException("'$name' import directory not found: '$path'");
        }
        $config[$publisher]['importDirectories'][$name] = $realpath;
      }
    }

    if ($only_publisher_id) {
      $config = [$only_publisher_id => $config[$only_publisher_id]];
    }

    // @todo Implement HZ.
    unset($config['hz']);

    foreach ($config as $publisher_config) {
      $extension = '.' . $publisher_config['parserClass']::FILE_EXTENSION;
      if ($only_article_filename) {
        static::importOne($publisher_config, $publisher_config['importDirectories']['articles'] . '/' . $only_article_filename, (int) basename($only_article_filename, $extension));
        break;
      }
      $glob = $publisher_config['importDirectories']['articles'] . '/*' . $extension;
      $git = new \GlobIterator($glob, \FilesystemIterator::KEY_AS_PATHNAME | \FilesystemIterator::CURRENT_AS_FILEINFO | \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS);
      foreach ($git as $pathname => $fileinfo) {
        static::importOne($publisher_config, $pathname, (int) $fileinfo->getBasename($extension));
      }
    }
  }

  public static function importOne($publisher_config, $pathname, $external_id) {
    try {
      $post = $publisher_config['parserClass']::createFromFile($publisher_config, $pathname, $external_id);
      if ($post->isPristine()) {
        if ($post->isRawDifferent()) {
          $post->parse();
          $post->save();
          echo "Processed article $external_id (ID $post->ID).", "\n";
        }
        else {
          echo "Article $external_id (ID $post->ID) is same as original.", "\n";
        }
      }
      else {
        echo "Article $external_id (ID $post->ID) has been manually edited.", "\n";
      }
    }
    catch (\Exception $e) {
      static::error($e);
    }
  }

  public static function error($e) {
    // @todo Support more than CLI?
    echo 'ERROR: ', $e->getMessage(), "\n";
    echo $e->getTraceAsString(), "\n";
    echo "\n";
  }

  /**
   * Loads the plugin textdomain.
   */
  public static function loadTextdomain() {
    load_plugin_textdomain(static::L10N, FALSE, strtr(static::L10N, '_', '-') . '/languages/');
  }

  /**
   * The base URL path to this plugin's folder.
   *
   * Uses plugins_url() instead of plugin_dir_url() to avoid a trailing slash.
   */
  public static function getBaseUrl() {
    if (!isset(static::$baseUrl)) {
      static::$baseUrl = plugins_url('', static::getBasePath() . '/publishing_importer.php');
    }
    return static::$baseUrl;
  }

  /**
   * The absolute filesystem base path of this plugin.
   *
   * @return string
   */
  public static function getBasePath() {
    return dirname(__DIR__);
  }

}
