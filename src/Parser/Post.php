<?php

/**
 * @file
 * Contains \Netzstrategen\PublishingImporter\Parser\Post.
 */

namespace Netzstrategen\PublishingImporter\Parser;

/**
 * Smarter post class for publishing system imports.
 *
 * \WP_Post is declared as final (cannot be extended). Thus, with regard to
 * class properties, this class mostly acts as a proxy.
 */
abstract class Post {

  /**
   * @var array
   */
  private static $wp_post_class_vars;

  /**
   * @var array
   */
  protected static $all_authors;

  /**
   * The publishing importer configuration applicable for this post.
   *
   * @see config.json
   *
   * @var array
   */
  protected $config;

  /**
   * @var \WP_Post
   */
  protected $wp_post;

  /**
   * Taxonomy terms to set, keyed by taxonomy name/slug.
   *
   * Values must be term IDs. Only the 'post_tag' taxonomy supports term
   * names/slugs.
   *
   * @var array
   */
  public $taxonomies = [];

  /**
   * Post meta data to save for the post.
   *
   * @var array
   */
  public $meta = [];

  /**
   * Attachments to save (and embed) for the post, keyed by filename.
   *
   * @var \WP_Post[]
   */
  protected $files = [];

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
   * Constructs a new Post from a given file (content) for potential parsing.
   *
   * @param array $config
   *   The publishing importer configuration applicable for the post to import.
   * @param string $pathname
   *   The import file pathname.
   * @param string $raw_filename
   *   (optional) Filename (without extension) of the original serialized import file.
   *
   * @return static
   */
  public static function createFromFile(array $config, $pathname, $raw_filename = NULL) {
    global $wpdb;
    $raw = static::readFile($pathname);
    $wp_post = [];
    if ($raw_filename !== NULL) {
      $wp_post = static::loadByGuid('http://' . $config['publisher'] . '/' . $config['system'] . '/' . $raw_filename) ?: [];
    }
    $post = new static($config, $wp_post, $raw);
    if ($raw_filename !== NULL) {
      $post->rawFilename = $raw_filename;
    }
    return $post;
  }

  protected static function readFile($pathname) {
    if (!file_exists($pathname)) {
      throw new \InvalidArgumentException("Import file not found: '$pathname'");
    }
    $raw = file_get_contents($pathname);
    if (empty($raw)) {
      throw new \RuntimeException("Invalid (empty) import file: '$pathname'");
    }
    return $raw;
  }

  /**
   * Constructs a new Post from an existing \WP_Post for potential re-parsing.
   *
   * @param array $config
   *   The publishing importer configuration applicable for the post to import.
   * @param \WP_Post $wp_post
   *   An existing post.
   *
   * @return static
   */
  public static function createFromPost(array $config, \WP_Post $wp_post) {
    if (empty($wp_post->ID)) {
      throw new \InvalidArgumentException("Post ID cannot be empty.");
    }
    $post = new static($config, $wp_post, get_post_meta($wp_post->ID, '_publishing_importer_raw', TRUE) ?: NULL);
    $post->rawFilename = get_post_meta($post->ID, '_publishing_importer_id', TRUE);
    return $post;
  }

  /**
   * Loads a WordPress post by GUID.
   *
   * @param string $guid
   *   The GUID to look up.
   *
   * @return \WP_Post|null
   */
  public static function loadByGuid($guid) {
    global $wpdb;
    if (empty($guid)) {
      throw new \InvalidArgumentException("GUID cannot be empty.");
    }
    $post_id = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE guid = %s LIMIT 0,1", $guid));
    if ($post_id) {
      return get_post($post_id);
    }
  }

  /**
   * Constructs a new Post for potential parsing.
   *
   * @param array $config
   *   The publishing importer configuration applicable for the post to import.
   * @param array|\WP_Post|object $properties
   *   (optional) Key/value pairs to set for the newly created post instance.
   * @param string $raw
   *   (optional) The original serialized import file content to use for parsing.
   */
  public function __construct(array $config, $properties = [], $raw = NULL) {
    if (!isset(self::$wp_post_class_vars)) {
      self::$wp_post_class_vars = array_fill_keys(array_keys(get_class_vars('\WP_Post')), 1) + [
        'post_category' => 1, // insert + update + __isset + __get + to_array
        'tags_input' => 1, // insert + __isset + __get  + to_array
        'tax_input' => 1, // insert only
        'import_id' => 1, // insert ("suggested post ID for newly imported posts, if not exists")
        'edit_date' => 1, // @see wp_update_post()
      ];
    }
    $this->config = $config;
    if ($properties instanceof \WP_Post) {
      $this->wp_post = $properties;
    }
    else {
      $this->wp_post = new \WP_Post((object) []);
      foreach ($properties as $property => $value) {
        $this->$property = $value;
      }
    }
    if (isset($raw)) {
      $this->setRaw($raw);
    }
    $this->meta['_publisher'] = $config['publisher'];
  }

  /**
   * Proxies into wrapped \WP_Post object properties.
   */
  public function __isset($property) {
    if (isset(self::$wp_post_class_vars[$property])) {
      return isset($this->wp_post->$property);
    }
  }

  /**
   * Proxies into wrapped \WP_Post object properties.
   */
  public function __unset($property) {
    if (isset(self::$wp_post_class_vars[$property])) {
      unset($this->wp_post->$property);
    }
  }

  /**
   * Proxies into wrapped \WP_Post object properties.
   */
  public function __get($property) {
    if (isset(self::$wp_post_class_vars[$property])) {
      return $this->wp_post->$property;
    }
  }

  /**
   * Proxies into wrapped \WP_Post object properties.
   */
  public function __set($property, $value) {
    if (isset(self::$wp_post_class_vars[$property])) {
      $this->wp_post->$property = $value;
    }
    else {
      throw new \InvalidArgumentException("Unknown property '$property'.");
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
   * Returns whether the post has not been manually edited in the CMS.
   *
   * @return bool
   *   FALSE if manually edited, TRUE otherwise.
   */
  public function isPristine() {
    global $wpdb;
    if (empty($this->ID)) {
      return TRUE;
    }
    $uid_system = $wpdb->get_var("SELECT ID FROM {$wpdb->users} WHERE user_login = 'system' LIMIT 0,1");
    $last_modified_by = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_edit_last' ORDER BY meta_id DESC LIMIT 0,1", $this->ID));
    // Any other last editor that 0 or ID of user 'system' means that the post
    // was edited from another user. The query can also return NULL (if the post
    // was never updated), but the first condition matches both 0 and NULL.
    if ($last_modified_by != 0 && $last_modified_by != $uid_system) {
      return FALSE;
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
    $old_raw = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_publishing_importer_raw' ORDER BY meta_id DESC LIMIT 0,1", $this->ID));
    return $this->raw !== $old_raw;
  }

  /**
   * Parses the raw (serialized) content into \WP_Post object properties.
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
   * Saves this post to the database.
   */
  public function save() {
    // Prevent API functions from resetting the specified post_date.
    $this->edit_date = TRUE;

    if (empty($this->ID)) {
      $post_array = get_object_vars($this->wp_post);
      $result = wp_insert_post($post_array, TRUE);
      if ($result instanceof \WP_Error) {
        throw new \RuntimeException('Failed to insert post: ' . implode(', ', $result->get_error_messages()));
      }
      $this->ID = $result;
    }
    else {
      $result = wp_update_post($this->wp_post, TRUE);
      if ($result instanceof \WP_Error) {
        throw new \RuntimeException('Failed to update post: ' . implode(', ', $result->get_error_messages()));
      }
    }
    foreach ($this->taxonomies as $taxonomy => $terms) {
      //wp_set_post_terms($this->ID, $terms, $taxonomy, FALSE);
      wp_set_object_terms($this->ID, $terms, $taxonomy, FALSE);
    }

    if (!empty($this->files)) {
      require_once ABSPATH . 'wp-admin/includes/media.php';
      require_once ABSPATH . 'wp-admin/includes/file.php';
      require_once ABSPATH . 'wp-admin/includes/image.php';

      $post_content_before = $this->post_content;
      foreach ($this->config['types'] as $type => $type_config) {
        $dir = $type_config['media'];
        $i = 0;
        foreach ($this->files as $filename => $file) {
          $i++;
          $orig_filename = $filename;
          $file += ['caption' => ''];
          if (!file_exists($dir . '/' . $filename)) {
            // Try to decode a filename containing hex-encoded characters beyond ASCII.
            // E.g., 'staatsstra_C3_9Fe1.jpg' => 'staatsstraße1.jpg'
            if ($filename !== $encoded_filename = preg_replace('@_([a-z0-9]{2})@i', '%$1', $filename)) {
              $encoded_filename = urldecode($encoded_filename);
              if (DIRECTORY_SEPARATOR === '\\') {
                $encoded_filename = iconv('utf-8', 'cp1252', $encoded_filename);
              }
              if (file_exists($dir . '/' . $encoded_filename)) {
                $filename = $encoded_filename;
              }
            }
            elseif (DIRECTORY_SEPARATOR === '\\' && file_exists($dir . '/' . iconv('utf-8', 'cp1252', $filename))) {
              $filename = iconv('utf-8', 'cp1252', $filename);
            }
          }
          $attachment_id = NULL;
          $guid = 'http://' . $this->config['publisher'] . '/' . $this->config['system'] . '/' . $filename;
          if ($attachment = static::loadByGuid($guid)) {
            $attachment_id = $attachment->ID;
            wp_update_post([
              'ID' => $attachment_id,
              'post_excerpt' => !empty($file['caption']) ? $file['caption'] : '',
            ]);
          }
          elseif (file_exists($dir . '/' . $filename)) {
            // _wp_handle_upload() *moves* $file['tmp_name'] into uploads/$file['name']
            // (prepared by the parser), so ensure to copy/back up the original file first.
            $file['tmp_name'] = $dir . '/' . $file['name'];
            if ($filename == $file['name']) {
              $file['tmp_name'] = strtr($file['tmp_name'], ['.' => '_.']);
            }
            copy($dir . '/' . $filename, $file['tmp_name']);
            $attachment_id = media_handle_sideload($file, $this->ID, NULL, [
              'guid' => $guid,
              'post_title' => $orig_filename,
              'post_excerpt' => $file['caption'],
            ]);
            if ($attachment_id instanceof \WP_Error) {
              $attachment_id = NULL;
            }
          }
          if ($attachment_id) {
            if (!empty($file['credit'])) {
              update_post_meta($attachment_id, 'credit', $file['credit']);
            }
            else {
              delete_post_meta($attachment_id, 'credit');
            }
            $file += ['orig_filename' => $orig_filename];
            $this->insertAttachment($attachment_id, $file, $i);
          }
        }
      }
      // Update post_content to replace/remove image placeholder strings.
      if ($post_content_before !== $this->post_content) {
        $result = wp_update_post($this->wp_post, TRUE);
        if ($result instanceof \WP_Error) {
          throw new \RuntimeException('Failed to update post after embedding images: ' . implode(', ', $result->get_error_messages()));
        }
      }
    }
    $this->meta['_publishing_importer_raw'] = $this->raw;
    foreach ($this->meta as $key => $value) {
      update_post_meta($this->ID, $key, is_string($value) ? wp_slash($value) : $value);
    }
  }

  /**
   * Inserts or associates an attachment with this post.
   *
   * @param int $attachment_id
   *   The post ID of the attachment file.
   * @param array $file
   *   Meta data that has been passed to media_handle_sideload().
   */
  abstract protected function insertAttachment($attachment_id, array $file, $current_number);

  public function render() {
    $this->wp_post->ID = (int) $this->meta['_publishing_importer_id'];
    #$this->wp_post->ID = 1;
    $this->wp_post->post_name = 'dummy';
    $this->wp_post->filter = 'raw';

    $GLOBALS['wp_query'] = $query = new \WP_Query();
    $query->init();
    $query->is_single = TRUE;
    $query->found_posts = 1;
    $query->post_count = 1;
    $query->posts = [$this->wp_post];
    $query->queried_object = $this->wp_post;

    $GLOBALS['post'] = $this->wp_post;
    $template = get_query_template('single', ['single-post.php', 'single.php']);
    $template = apply_filters('template_include', $template);
    include $template;
//    load_template($template, FALSE);
  }

  public static function exitXML($raw) {
    header('Content-Type: text/xml; charset=utf-8');
    echo $raw;
    exit;
  }

  public static function exitPost($post) {
    header('Content-Type: text/html; charset=utf-8');
    echo "<pre>\n", var_dump($post), "</pre>\n";
    exit;
  }

}
