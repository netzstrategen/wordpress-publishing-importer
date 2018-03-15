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
   * User role name for users whose subscription expired.
   *
   * @var string
   */
  const ROLE_EXSUBSCRIBER = 'ex-subscriber';

  /**
   * @var string
   */
  private static $baseUrl;

  /**
   * @var resource
   */
  public static $importFileHandle;

  /**
   * @var bool
   */
  public static $redirectAllMails = FALSE;

  /**
   * @implements init
   */
  public static function init() {
    add_filter('wp_mail', __CLASS__ . '::wp_mail');
    if (is_admin() || defined('WP_CLI')) {
      return;
    }
  }

  /**
   * Returns the parsed plugin configuration.
   *
   * @return array
   * @throws \Exception if any config.json file cannot be parsed.
   */
  public static function readConfig() {
    // Read configuration.
    $config = json_decode(file_get_contents(static::getBasePath() . '/config.json'), TRUE);
    if ($config === NULL) {
      throw new \DomainException("config.json: Parse error: " . json_last_error_msg());
    }
    // Check for local (site-specific) override configuration.
    if (file_exists(ABSPATH . '.publishing_importer.config.json')) {
      $config_local = json_decode(file_get_contents(ABSPATH . '.publishing_importer.config.json'), TRUE);
      if ($config_local === NULL) {
        throw new \DomainException(".publishing_importer.config.json: Parse error: " . json_last_error_msg());
      }
      $config = array_replace_recursive($config, $config_local);
    }
    return $config;
  }

  /**
   * Returns the plugin configuration.
   *
   * @param array $overrides
   *   (optional) Possible config overrides.
   *
   * @return array
   * @throws \LogicException if any directory or file path specified in the
   *   configuration does not exist.
   */
  public static function getConfig(array $overrides = []) {
    $config = static::readConfig();
    // Override config if argument is given.
    if ($overrides) {
      $config = array_replace_recursive($config, $overrides);
    }
    // Validate configuration.
    foreach ($config as $publisher => $publisher_config) {
      $config[$publisher]['id'] = $publisher;
      foreach ($config[$publisher]['types'] as $type => $type_config) {
        foreach ($type_config as $name => $path) {
          if ($name !== 'directory' && $name !== 'media' && $name !== 'file') {
            continue;
          }
          if ($name === 'media' || $name === 'file') {
            $path = $config[$publisher]['types'][$type]['directory'] . '/' . $path;
          }
          $original_path = $path;
          if (file_exists(ABSPATH . $path)) {
            $path = ABSPATH . $path;
          }
          if (!$realpath = realpath($path)) {
            throw new \LogicException("Import directory for type '$type' not found: '" . $original_path . "'");
          }
          $config[$publisher]['types'][$type][$name] = $realpath;
        }
        if (!isset($config[$publisher]['types'][$type]['media'])) {
          $config[$publisher]['types'][$type]['media'] = $config[$publisher]['types'][$type]['directory'];
        }
      }
    }
    return $config;
  }

  /**
   * Imports new content from filesystem folders.
   *
   * wp --user=system publishing-importer import
   * wp --user=system publishing-importer import --publisher=pz --filename=123456.xml
   *
   * @param array $args
   *   (optional) Parameters to limit the import:
   *   - only_publisher_id: The publisher ID to import; e.g. 'pz'.
   *   - only_article_filename: The article filename to import; e.g. '123456.xml'.
   */
  public static function importContent($args = []) {
    // The import needs to be launched with administrative privileges, because
    // WordPress Core and third party plugins are testing user privileges even in
    // internal API functions.
    if (!current_user_can('manage_categories')) {
      static::error('Unable to import due to missing user session (missing administrative privileges). Hint: --user=system', 127);
    }

    $args += [
      'only_publisher_id' => NULL,
      'only_article_filename' => NULL,
      'config_overrides' => [],
      'type' => NULL,
    ];
    $only_publisher_id = $args['only_publisher_id'];
    $only_article_filename = $args['only_article_filename'];
    $only_type = $args['type'];
    $config = static::getConfig($args['config_overrides']);
    if ($only_publisher_id) {
      $config = [$only_publisher_id => $config[$only_publisher_id]];
    }
    if ($only_type) {
      foreach ($config as $publisher => $publisher_config) {
        foreach ($publisher_config['types'] as $type => $type_config) {
          if ($only_type !== $type) {
            unset($config[$publisher]['types'][$type]);
          }
        }
      }
    }

    // Allow to create users without email addresses and skip duplicate email check.
    define('WP_IMPORTING', TRUE);

    // Disable notification emails from wp_update_user() and redirect any
    // remaining mails to site administrator.
    add_filter('send_password_change_email', '__return_false', 100);
    add_filter('send_email_change_email', '__return_false', 100);
    static::$redirectAllMails = TRUE;

    // Ensure all dates and times are calculated based on this site's timezone.
    $original_timezone = date_default_timezone_get();
    date_default_timezone_set(get_option('timezone_string'));

    foreach ($config as $publisher_config) {
      foreach ($publisher_config['types'] as $type => $type_config) {
        if (method_exists($type_config['parserClass'], 'beforeImport')) {
          $type_config['parserClass']::beforeImport();
        }

        $extension = '.' . $type_config['parserClass']::FILE_EXTENSION;
        if ($only_article_filename) {
          static::importOne($publisher_config, $type, $type_config['directory'] . '/' . $only_article_filename, basename($only_article_filename, $extension));
          break;
        }
        if (!isset($type_config['file'])) {
          if (!empty($type_config['recursive'])) {
            $directory = new \RecursiveDirectoryIterator($type_config['directory'], \FilesystemIterator::KEY_AS_PATHNAME | \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS | \FilesystemIterator::FOLLOW_SYMLINKS);
            $filter = new \RecursiveCallbackFilterIterator($directory, [$type_config['parserClass'], 'recursiveCallbackFilter']);
            $git = new \RecursiveIteratorIterator($filter);
            $git = $type_config['parserClass']::beforeRecurse($git);
          }
          else {
            $glob = $type_config['directory'] . '/*' . $extension;
            $git = new \GlobIterator($glob, \FilesystemIterator::KEY_AS_PATHNAME | \FilesystemIterator::CURRENT_AS_FILEINFO | \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS);
          }
          foreach ($git as $pathname => $fileinfo) {
            static::importOne($publisher_config, $type, $pathname, $fileinfo->getBasename($extension));
          }
        }
        else {
          $pathname = $type_config['file'];
          if ($extension === '.csv') {
            static::$importFileHandle = fopen($pathname, 'r');
            while (!feof(static::$importFileHandle) && FALSE !== static::importOne($publisher_config, $type, $pathname, basename($pathname, $extension))) {
            }
            fclose(static::$importFileHandle);
          }
          elseif ($extension === '.json') {
            static::$importFileHandle = json_decode(file_get_contents($pathname), TRUE);
            while (FALSE !== static::importOne($publisher_config, $type, $pathname, basename($pathname, $extension))) {
            }
          }
          else {
            throw new \LogicException("No file import handler for extension $extension");
          }
        }

        if (method_exists($type_config['parserClass'], 'afterImport')) {
          $type_config['parserClass']::afterImport();
        }
      }
    }

    remove_filter('send_password_change_email', '__return_false', 100);
    remove_filter('send_email_change_email', '__return_false', 100);
    static::$redirectAllMails = FALSE;

    date_default_timezone_set($original_timezone);
  }

  public static function importOne($publisher_config, $type, $pathname, $raw_filename) {
    try {
      $type_config = $publisher_config['types'][$type];
      $entity_noun = ucfirst($type);
      $entity = $type_config['parserClass']::createFromFile($publisher_config, $pathname, $raw_filename);
      if (!$entity instanceof $type_config['parserClass']) {
        return $entity;
      }
      if ($entity->isPristine()) {
        if ($entity->isRawDifferent()) {
          $entity->parse();
          $entity->save();
          static::notice("Processed $type $raw_filename (ID $entity->ID).");
        }
        else {
          static::debug("$entity_noun $raw_filename (ID $entity->ID) is same as original.");
        }
      }
      else {
        static::notice("$entity_noun $raw_filename (ID $entity->ID) has been manually edited.");
      }
    }
    catch (\Exception $e) {
      static::error($e);
    }
  }

  /**
   * Outputs a debug message (WP_CLI only).
   *
   * @param string $message
   *   The message to output.
   */
  public static function debug($message) {
    if (defined('WP_CLI')) {
      \WP_CLI::debug($message);
    }
  }

  /**
   * Outputs a notice/log message (WP_CLI only).
   *
   * @param string $message
   *   The message to output.
   */
  public static function notice($message) {
    if (defined('WP_CLI')) {
      \WP_CLI::log($message);
    }
  }

  /**
   * Reports an error message, optionally halting execution.
   *
   * @param \Exception $e
   *   The exception message to output.
   * @param int $exit_code
   *   (optional) The exit status code to signal. If TRUE or >= 1 then script
   *   execution is halted.
   */
  public static function error($e, $exit_code = 0) {
    $message = $e instanceof \Exception ? $e->getMessage() : $e;
    if (defined('WP_CLI')) {
      \WP_CLI::error($message, $exit_code);
    }
    else {
      echo 'ERROR: ', $message, "\n";
      if ($e instanceof \Exception) {
        echo $e->getTraceAsString(), "\n";
      }
      echo "\n";
      if ($exit_code === TRUE || (is_int($exit_code) && $exit_code >= 1)) {
        exit($exit_code);
      }
    }
  }

  /**
   * Redirects emails to configured site administrator email.
   */
  public static function wp_mail(array $message) {
    if (static::$redirectAllMails) {
      $admin_email = get_option('admin_email', 'service@netzstrategen.com');
      $to_parts = explode('@', $admin_email);
      $alias = preg_replace('@[^a-zA-Z0-9_-]@', '-', $message['to']);
      $message['to'] = $to_parts[0] . '+' . $alias . '@' . $to_parts[1];
    }
    return $message;
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
