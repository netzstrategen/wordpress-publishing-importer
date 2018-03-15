<?php

/**
 * @file
 * Contains \Netzstrategen\PublishingImporter\Parser\User.
 */

namespace Netzstrategen\PublishingImporter\Parser;

/**
 * User account class for publishing system imports.
 */
abstract class User extends \WP_User {

  /**
   * The publishing importer configuration applicable for this user.
   *
   * @see config.json
   *
   * @var array
   */
  protected $config;

  /**
   * User meta data to save for the user.
   *
   * @var array
   */
  public $meta = [];

  /**
   * Attachments to save for this user, keyed by filename.
   *
   * @var array
   */
  protected $files = [];

  /**
   * The original user_login of an existing user account.
   *
   * @var string
   */
  protected $originalUserLogin;

  /**
   * Filename (without extension) of the original serialized import file.
   *
   * @var string
   */
  protected $rawFilename;

  /**
   * The original serialized import file content.
   *
   * @var string
   */
  protected $raw;

  /**
   * Constructs a new User for potential parsing.
   *
   * @param array $config
   *   The publishing importer configuration applicable for the user to import.
   * @param array|\WP_User $properties
   *   (optional) Key/value pairs to set for the newly created user instance.
   * @param string $raw
   *   (optional) The original serialized import file content to use for parsing.
   */
  public function __construct(array $config, $properties = [], $raw = NULL) {
    if ($properties instanceof \WP_User) {
      parent::__construct($properties);
    }
    else {
      parent::__construct();
      if (is_array($properties)) {
        foreach ($properties as $property => $value) {
          $this->$property = $value;
        }
      }
    }
    if (!empty($this->user_login)) {
      $this->originalUserLogin = $this->user_login;
    }
    $this->config = $config;
    if (isset($raw)) {
      $this->setRaw($raw);
    }
  }

  /**
   * Sets the original serialized import file content.
   *
   * @param string $raw
   *   The content to set.
   *
   * @return $this
   */
  public function setRaw($raw) {
    $this->raw = $raw;
    return $this;
  }

  /**
   * Returns whether the user has not been manually edited in the CMS.
   *
   * @return bool
   *   FALSE if manually edited, TRUE otherwise.
   */
  public function isPristine() {
    global $wpdb;
    if (empty($this->ID)) {
      return TRUE;
    }
    return TRUE;
  }

  /**
   * Returns whether the stored raw content is different from the original file's raw content.
   *
   * @return bool
   */
  public function isRawDifferent() {
    global $wpdb;
    if (empty($this->ID)) {
      return TRUE;
    }
    $old_raw = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = '_publishing_importer_raw' LIMIT 0,1", $this->ID));
    $new_raw = json_encode($this->raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
//    if ($old_raw !== $new_raw) {
//      echo "\n";
//      echo $old_raw, "\n";
//      echo $new_raw, "\n\n";
//    }
    return $new_raw !== $old_raw;
  }

  /**
   * Parses the raw record into user object properties.
   */
  abstract public function parse();

  /**
   * Removes leading, trailing, and inner white-space (newlines) from a given string.
   *
   * @param string $string
   *   The string to clean.
   *
   * @return string
   */
  public function ensureSingleLine($string) {
    return preg_replace('@\s+@', ' ', trim($string));
  }

  /**
   * Saves this user to the database.
   */
  public function save() {
    global $wpdb;

    if (empty($this->ID)) {
      $result = wp_insert_user($this);
      if ($result instanceof \WP_Error) {
        throw new \RuntimeException(sprintf("Failed to insert user with login '%s' and email '%s': %s", $this->user_login, $this->user_email, implode(', ', $result->get_error_messages())));
      }
      $this->ID = $result;
    }
    else {
      $result = wp_update_user($this);
      if ($result instanceof \WP_Error) {
        throw new \RuntimeException(sprintf("Failed to update user with login '%s' and email '%s': %s", $this->user_login, $this->user_email, implode(', ', $result->get_error_messages())));
      }
      if (!empty($this->originalUserLogin) && $this->originalUserLogin !== $this->user_login) {
        $wpdb->update($wpdb->users, ['user_login' => $this->user_login], ['ID' => $this->ID]);
      }
    }

    $this->meta['_publishing_importer_raw'] = json_encode($this->raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    foreach ($this->meta as $key => $value) {
      update_user_meta($this->ID, $key, is_string($value) ? wp_slash($value) : $value);
    }
  }

  /**
   * Inserts or associates an attachment with this user.
   *
   * @param int $attachment_id
   *   The post ID of the attachment file.
   * @param array $file
   *   Meta data that has been passed to media_handle_sideload().
   */
  abstract protected function insertAttachment($attachment_id, array $file, $current_number);

  public function render() {
    $this->ID = 1;

    $GLOBALS['wp_query'] = $query = new \WP_Query();
    $query->init();
    $query->is_author = TRUE;
    $query->found_posts = 1;
    $query->post_count = 1;
    $query->posts = [];
    $query->queried_object = $this;

    $GLOBALS['post'] = $this->wp_post;
    $template = get_query_template('author', ['author.php']);
    $template = apply_filters('template_include', $template);
    include $template;
  }

  public static function exitHtml($var) {
    header('Content-Type: text/html; charset=utf-8');
    echo "<pre>\n", var_dump($var), "</pre>\n";
    exit;
  }

}
