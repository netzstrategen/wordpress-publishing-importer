<?php

/**
 * @file
 * Contains \Netzstrategen\PublishingImporter\Parser\Dialog4\SportsTable.
 */

namespace Netzstrategen\PublishingImporter\Parser\Dialog4;

use Netzstrategen\PublishingImporter\Parser\Post;

/**
 * Parser for Sportstables from Dialog 4.
 */
class SportsTable extends Post {

  const FILE_EXTENSION = 'xml';

  public function parse() {
    $xml = simplexml_load_string($this->raw);
    // @todo Negotiate parser implementation based on the actual info.
    $system = 'Dialog';
    $version = (string) $xml['strDialogVersion'];
    $version = preg_replace('@[^a-zA-Z0-9_.-]@', '-', $version);
    $this->meta['_publishing_system'] = $system . '/' . $version;
    $this->parseMeta($xml);

    // Process content.
    // Ensure to reset all properties that are collected/stacked up and which
    // may already exist on the WP_Post object when updating an existing post.
    $this->post_title = (string) $xml->xpath('//hl1')[0];
    $this->post_content = $this->parseContent($xml->body->{'body.content'});

    if (empty($this->post_author)) {
      $this->post_author = $this->parseAuthor();
    }
  }

  public function parseMeta(\SimpleXMLElement $xml) {
    global $wpdb;

    $article_id = $this->rawFilename;
    // While Dialog allows 56 characters for filenames only, we need to trim it
    //   to ensure some posts won't be processed again.
    $article_id = substr($article_id, 0, 56);
    $this->guid = 'http://' . $this->config['publisher'] . '/' . $this->config['system'] . '/' . $article_id;
    $this->meta['_publishing_importer_id'] = $article_id;
    $this->meta['_publishing_importer_uuid'] = (string) $xml->xpath('//doc-id/@id-string')[0];

    // Post status automatically adjusts by wp_insert_post()
    $this->post_status = 'publish';
    if ($post_date = (string) $xml->xpath('//date.issue/@norm')[0]) {
      $this->post_date = date_i18n('Y-m-d H:i:s');
    }
    $this->comment_status = 'closed';
  }

  public function parseContent(\SimpleXMLElement $content) {
    $html = ' ';

    $style_classes = [];
    foreach ($content->xpath('//nitf-table') as $table) {
      $value = [];
      $headings = [];
      foreach ($table->xpath('nitf-table-metadata/nitf-col') as $thead) {
        $headings[(string) $thead->attributes()->id] = (string) $thead->attributes()->value;
      }
      foreach ($table->xpath('table/tbody/tr') as $row) {
        $cells = [];
        foreach ($row as $cell) {
          $cells[$headings[(string) $cell->attributes()->idref]] = (string) $cell;
        }
        $value[] = $cells;
      }
      if (empty($value)) {
        continue;
      }
      $this->meta['_publishing_sporttables'][strtolower($table->{'nitf-table-metadata'}->attributes()->class)]['table'] = $value;
      $this->meta['_publishing_sporttables'][strtolower($table->{'nitf-table-metadata'}->attributes()->class)]['headings'] = $headings;
    }
    return $html;
  }

  public function parseAuthor() {
    global $wpdb;

    // Check for an exact match of display names of administrators, editors, authors.
    if (!isset(static::$all_authors)) {
      $result = $wpdb->get_results("SELECT u.ID, u.display_name FROM {$wpdb->users} u INNER JOIN {$wpdb->usermeta} um ON um.user_id = u.ID WHERE um.meta_key = 'wp_capabilities' AND meta_value REGEXP 'administrator|editor|author'", ARRAY_A);
      array_map(function ($row) {
        static::$all_authors[$row['display_name']] = (int) $row['ID'];
      }, $result);
    }
    preg_match('@\b' . implode('\b|\b', array_keys(static::$all_authors)) . '\b@', $this->meta['author'], $matches);
    if (isset($matches[0])) {
      return static::$all_authors[$matches[0]];
    }

    return username_exists($this->config['defaultAuthor']);
  }

  protected function insertAttachment($attachment_id, array $file, $current_number) {
    if ($current_number == 1) {
      set_post_thumbnail($this->ID, $attachment_id);
    }
    $this->meta['images'][] = $attachment_id;
    // Additionally trim to remove leading whitespace before text content;
    // i.e., after removing placeholder for post thumbnail/featured image.
    $this->post_content = trim(strtr($this->post_content, ["<!-- $file[orig_filename] -->" => ''])) . "\n";
  }

}
