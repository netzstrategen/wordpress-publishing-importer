<?php

/**
 * @file
 * Contains \Netzstrategen\PublishingImporter\Parser\Dialog4.
 */

namespace Netzstrategen\PublishingImporter\Parser;

/**
 * Parser for Dialog 4.
 */
class Dialog4 extends Post {

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
    $this->post_title = '';
    $this->post_content = $this->parseContent($xml->WebStory->WebStoryContent->TextContent);

    if (empty($this->post_author)) {
      $this->post_author = $this->parseAuthor();
    }
  }

  public function parseMeta(\SimpleXMLElement $xml) {
    global $wpdb;

    $ident = $xml->xpath('//WebStoryHead/Ident')[0]->attributes();
    $article_id = implode('_', [
      $ident->kLocationId,
      str_pad($ident->eLogType, 4, '0', STR_PAD_LEFT),
      $ident->kId,
      preg_replace('@[^0-9A-Za-z_]+@', '_', remove_accents((string) $ident->strName)),
    ]);
    $this->guid = 'http://' . $this->config['publisher'] . '/' . $this->config['system'] . '/' . $article_id;
    $this->meta['_publishing_importer_id'] = $article_id;
    $this->meta['_publishing_importer_uuid'] = (string) $xml->xpath('//WebStoryHead/OrigId/@strDocId')[0];

    // Check for explicitly specified author name.
    // @see static::parseAuthor()
    if ($author_name = (string) $xml->xpath('//DialogDoc/WebStory/WebStoryHead/DocAttr/@strAuthor')[0]) {
      // Strip leading 'von' delivered by GrenzEcho XMLs to get correct author name.
      $author_name = preg_replace('/^von +/i', '', $author_name);
      // Save the value for literal output in frontend.
      // $this->meta['author'] = $author_name;
      // Try to map it to an existing system user.
      if ($user_id = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE display_name = %s OR user_login = %s LIMIT 0,1", [$author_name, $author_name]))) {
        $this->post_author = $user_id;
      }
    }

    // Post status automatically adjusts by wp_insert_post()
    $this->post_status = 'publish';
    if ($post_date = (string) $xml->xpath('//WebStoryHead/DocAttr/WebAttr/@dtmWebBegin')[0]) {
      $this->post_date = strtr($post_date, 'T', ' ');
    }

    if ($categories = (string) $xml->xpath('//WebStoryHead/DocAttr/@strCatchwords')[0]) {
      $categories = array_filter(array_map('trim', explode(';', $categories)));
      $this->taxonomies['category'] = $categories;
    }
    // Set the default category if no other category could be determined.
    if (empty($this->taxonomies['category'])) {
      $this->taxonomies['category'] = [];
    }

    if ($location = (string) $xml->xpath('//WebStoryHead/DocAttr/@strLocation')[0]) {
      $this->taxonomies['location'][] = $location;
    }

    if ($comment_status = (string) $xml->xpath('//WebStoryHead/DocAttr/WebAttr/@bEnableComments')[0]) {
      $this->comment_status = $comment_status === 'true' ? 'open' : 'closed';
    }
  }

  public function parseContent(\SimpleXMLElement $content) {
    $html = '';
    $style_classes = [];
    foreach ($content as $name => $element) {
      if ($name === 'PicBox') {
        if ($filename = (string) basename(str_replace('\\', '/', $element->Image['strPathName']))) {
          if (!isset($this->files[$filename])) {
            $this->files[$filename] = [
              'filename' => $filename,
              'name' => $this->config['uploadsPrefix'] . $filename,
            ];
            if ($caption = trim((string) $element->TBox)) {
              if (count($parts = preg_split('@(?<=[\s.!?])\s*(Fotos?|Quelle|Archivfotos?):\s*@', $caption)) > 1) {
                $caption = $parts[0];
                $this->files[$filename]['credit'] = $parts[1];
              }
              $this->files[$filename]['caption'] = $caption;
            }
            $html .= "<!-- $filename -->\n\n";
          }
        }
        continue;
      }

      if ($element->count()) {
        $innerhtml = $this->parseContent($element);
      }
      else {
        $innerhtml = (string) $element;
      }
      // Skip empty paragraphs/elements.
      if ($innerhtml === '') {
        continue;
      }
      if ($name === 'TBox') {
        $tag = '';
        $classes = [];
        $type = mb_strtolower($element['strContentType']);

        if ($type == 'heading') {
          $this->post_title = $this->ensureSingleLine($innerhtml);
          continue;
        }
        elseif ($type === 'headline') {
          $this->meta['wps_subtitle'] = $this->ensureSingleLine($innerhtml);
          continue;
        }

        foreach ($style_classes as $candidate => $classname) {
          if (FALSE !== strpos($style, is_string($candidate) ? $candidate : $classname)) {
            $classes[] = $classname;
          }
        }
        if ($classes || $tag) {
          $classes = $classes ? ' class="' . implode(' ', $classes) . '"' : '';
          if (!$tag) {
            $tag = 'p';
          }
          $innerhtml = '<' . $tag . $classes . '>' . $innerhtml . '</' . $tag . '>';
        }
      }
      // Sometimes paragraphs contain false leading whitespace.
      $html .= ltrim($innerhtml);
      if ($name === 'p') {
        $html .= "\n\n";
      }
    }
    return $html;
  }

  public function parseAuthor() {
    global $wpdb;

    // Check for an exact match of display names of administrators, editors, authors.
    $user_id = $this->getUserIdFromAuthorInContent($this->post_content);
    if (!empty($user_id)) {
      return $user_id;
    }
    return username_exists($this->config['defaultAuthor']);
  }

}
