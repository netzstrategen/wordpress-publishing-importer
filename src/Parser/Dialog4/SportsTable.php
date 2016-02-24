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

    $this->post_author = username_exists($this->config['defaultAuthor']);
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
      $this->post_date = date_i18n('Y-m-d H:i:s', strtotime($post_date));
    }
    $this->comment_status = 'closed';
  }

  public function parseContent(\SimpleXMLElement $content) {
    $html = ' ';

    foreach ($content->xpath('//nitf-table') as $table) {
      $rows = [];
      $headings = [];
      foreach ($table->xpath('nitf-table-metadata/nitf-col') as $thead) {
        $headings[(string) $thead->attributes()->id] = (string) $thead->attributes()->value;
      }
      foreach ($table->xpath('table/tbody/tr') as $row) {
        $cells = [];
        foreach ($row as $cell) {
          $cells[$headings[(string) $cell->attributes()->idref]] = (string) $cell;
        }
        $rows[] = $cells;
      }
      if (empty($rows)) {
        continue;
      }
      $this->meta['_publishing_sporttables'][strtolower($table->{'nitf-table-metadata'}->attributes()->class)]['table'] = $rows;
      $this->meta['_publishing_sporttables'][strtolower($table->{'nitf-table-metadata'}->attributes()->class)]['headings'] = $headings;
    }
    return $html;
  }

  protected function insertAttachment($attachment_id, array $file, $current_number) {
  }

}
