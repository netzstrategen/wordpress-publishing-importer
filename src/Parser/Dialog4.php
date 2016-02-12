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
    if (isset($xml->WebStory)) {
      $this->post_content = $this->parseContent($xml->WebStory->WebStoryContent->TextContent);
    }
    else {
      $this->post_title = $this->ensureSingleLine((string) $xml->xpath('//PictureGalleryHead/@strHeading')[0]);
      $this->post_type = 'gallery';
      $this->meta['_images'] = 'field_gallery';
      $this->post_content = $this->parseContent($xml->PictureGallery->PictureGalleryContent);
    }

    if (empty($this->post_author)) {
      $this->post_author = $this->parseAuthor();
    }
  }

  public function parseMeta(\SimpleXMLElement $xml) {
    global $wpdb;

    $ident = $xml->xpath('//Ident[parent::WebStoryHead | parent::PictureGalleryHead]')[0]->attributes();

    $article_id = implode('_', [
      $ident->kLocationId,
      str_pad($ident->eLogType, 4, '0', STR_PAD_LEFT),
      $ident->kId,
      preg_replace('@[^0-9A-Za-z_]+@', '_', remove_accents((string) $ident->strName)),
    ]);
    // While Dialog allows 56 characters for filenames only, we need to trim it
    //   to ensure some posts won't be processed again.
    $article_id = substr($article_id, 0, 56);
    $this->guid = 'http://' . $this->config['publisher'] . '/' . $this->config['system'] . '/' . $article_id;
    $this->meta['_publishing_importer_id'] = $article_id;
    $this->meta['_publishing_importer_uuid'] = (string) $xml->xpath('//OrigId[parent::WebStoryHead | parent::PictureGalleryHead]/@strDocId')[0];

    // Check for explicitly specified author name.
    // @see static::parseAuthor()
    if ($author_name = (string) $xml->xpath('//TBox[@strContentType="Author"]/p | //PictureGalleryHead/@strCreatorLoginName')[0]) {
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
    if ($post_date = (string) $xml->xpath('//DocAttr[parent::WebStoryHead | parent::PictureGalleryHead]/WebAttr/@dtmWebBegin')[0]) {
      $this->post_date = strtr($post_date, 'T', ' ');
    }

    if ($categories = (string) $xml->xpath('//DocAttr[parent::WebStoryHead | parent::PictureGalleryHead]/@strCatchwords')[0]) {
      $term_ids = [];
      $categories = array_filter(array_map('trim', explode(';', $categories)));
      $placeholders = implode(',', array_fill(0, count($categories), '%s'));
      $query = "SELECT t.name, t.term_id
        FROM {$wpdb->terms} t
        LEFT JOIN {$wpdb->termmeta} tm ON tm.term_id = t.term_id
        WHERE t.name IN ($placeholders)
        OR tm.meta_key = '_publishing_importer_synonyms' AND tm.meta_value IN ($placeholders)
        GROUP BY t.term_id";
      $categories = array_merge($categories, $categories);
      $results = $wpdb->get_results($wpdb->prepare($query, $categories));
      foreach ($results as $term) {
        $this->taxonomies['category'][$term->name] = (int) $term->term_id;
      }
    }
    // Set the default category if no other category could be determined.
    if (empty($this->taxonomies['category'])) {
      $default_category_id = get_option('default_category');
      $this->taxonomies['category'][get_the_category_by_ID($default_category_id)] = (int) $default_category_id;
    }

    if ($location = $xml->xpath('//WebStoryHead/DocAttr/@strLocation')) {
      $this->taxonomies['location'][] = (string) $location[0];
    }

    if ($comment_status = $xml->xpath('//WebStoryHead/DocAttr/WebAttr/@bEnableComments')) {
      $this->comment_status = ((string) $comment_status[0]) === 'true' ? 'open' : 'closed';
    }

    apply_filters('publishing_importer/post/parse_meta', $this, $xml);
  }

  public function parseContent(\SimpleXMLElement $content) {
    $html = '';
    $style_classes = [
      'vorspann' => 'intro',
    ];
    foreach ($content as $name => $element) {
      if ($name === 'PicBox' || $name === 'PicGalleryItem') {
        if ($filename = (string) basename(str_replace('\\', '/', $element->Image['strPathName']))) {
          if (!isset($this->files[$filename])) {
            $this->files[$filename] = [
              'filename' => $filename,
              'name' => $this->config['uploadsPrefix'] . $filename,
            ];
            if ($caption = trim((string) ($name === 'PicBox' ? $element->TBox->p : $element->Description))) {
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
        $style = mb_strtolower($element['strBoxName']);

        if ($type === 'author') {
          // Strip leading 'von' delivered by GrenzEcho XMLs to get correct author name.
          $this->meta['author'] = $this->ensureSingleLine(preg_replace('/^von +/i', '', $innerhtml));
          continue;
        }

        if ($type === 'heading') {
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

        if ($type === 'teaser') {
          $this->post_excerpt = ltrim($innerhtml);
          continue;
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
