<?php

/**
 * @file
 * Contains \Netzstrategen\PublishingImporter\Parser\WoodWing7.
 */

namespace Netzstrategen\PublishingImporter\Parser;

/**
 * Parser for WoodWing 7 (DB + PZ).
 */
class WoodWing7 extends Post {

  const FILE_EXTENSION = 'xml';

  protected static function readFile($pathname) {
    $raw = parent::readFile($pathname);
    // Some WoodWing XML files happen to be *partially* double-encoded in UTF-8;
    // ensure to fix the encoding once prior to further processing.
    // @todo Does this apply to the single example artikel-absatzformate.xml only?
    if (strpos($raw, 'Ãberschrift') || strpos($raw, 'ÃŒberschrift')) {
      $raw = iconv('UTF-8', 'ISO-8859-15', $raw);
    }
    return $raw;
  }

  public function parse() {
    $xml = simplexml_load_string($this->raw);

    // @todo Negotiate parser implementation based on the actual info.
    $system = (string) $xml->articlelist->article['sourcesystem'];
    $version = (string) $xml->articlelist->article['exportmoduleversion'];
    $version = preg_replace('@[^a-zA-Z0-9_.-]@', '-', $version);
    $this->meta['_publishing_system'] = $system . '/' . $version;

    $this->parseMeta($xml);

    // Process content.
    $this->post_content = $this->parseContent($xml->articlelist->article->body);

    // Process image captions.
    foreach ($this->captions as $i => $caption) {
      if (count($parts = preg_split('@(?<=[\s.!?])\s*Fotos?:\s*@', $caption['caption'])) > 1) {
        $this->captions[$i]['caption'] = $parts[0];
        $this->captions[$i]['credit'] = $parts[1];
      }
    }
    $count_files = count($this->files);
    $count_captions = count($this->captions);
    if ($count_files === $count_captions) {
      // The most simple case can appear more complex: Multiple image filenames
      // directly below each other, followed by separate image captions
      // directly below each other, making a direct mapping to "the last" during
      // parsing impossible.
      foreach ($this->files as $filename => $file) {
        $this->files[$filename] += array_shift($this->captions);
      }
    }
    elseif ($count_files && $count_captions) {
      // The first must be for the post thumbnail/featured image.
      reset($this->files);
      $this->files[key($this->files)] += array_shift($this->captions);
      // Try to map each caption to the filename that appeared directly before it.
      foreach ($this->captions as $i => $caption) {
        if (!empty($caption['lastFilename'])) {
          foreach ($this->files as $filename => $file) {
            if ($filename === $caption['lastFilename']) {
              $this->files[$filename] += $caption;
              unset($this->captions[$i]);
            }
          }
        }
      }
      // If any captions are left, we can only guess wildly.
      foreach ($this->captions as $i => $caption) {
        foreach ($this->files as $filename => $file) {
          if (!isset($file['caption'])) {
            $this->files[$filename] += $caption;
            unset($this->captions[$i]);
          }
        }
      }
    }

    if (empty($this->post_author)) {
      $this->post_author = $this->parseAuthor();
    }
  }

  public function parseMeta(\SimpleXMLElement $xml) {
    global $wpdb;

    $article_id = (string) $xml->xpath('//metadata[@name="ArticleId"]')[0]['value'];
    $this->guid = 'http://' . $this->config['publisher'] . '/' . $this->config['system'] . '/' . $article_id;
    $this->meta['_publishing_importer_article_id'] = $article_id;

    // @todo Possible Type strings?
    $map_types = [
      'Article' => 'post',
      '?' => 'gallery',
    ];
    $type = (string) $xml->xpath('//BasicMetaData_Type')[0];
    if (isset($map_types[$type])) {
      $this->post_type = $map_types[$type];
    }

    // Check for explicitly specified author name.
    // @see static::parseAuthor()
    foreach (['//Custom_Author', '//CustomMetaData[@name="C_AUTOR"]'] as $xpath) {
      if ($author_name = (string) $xml->xpath($xpath)[0]) {
        // Save the value for literal output in frontend.
        $this->meta['author'] = $author_name;
        // Try to map it to an existing system user.
        if ($user_id = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE display_name = %s OR user_login = %s LIMIT 0,1", [$author_name, $author_name]))) {
          $this->post_author = $user_id;
        }
        break;
      }
    }

    $map_status = [
      'Artikel online sofort' => 'publish',
      'Artikel online morgen' => 'publish', // automatically adjusted into 'future' by wp_insert_post()
    ];
    $status = (string) $xml->xpath('//metadata[@name="Status"]')[0]['value'];
    $this->post_status = isset($map_status[$status]) ? $map_status[$status] : 'draft';

    $date = (string) $xml->xpath('//Custom_Publication_Date')[0];
    if ($date) {
      // @todo Make assumed publishing time of day ("next morning") configurable?
      $date .= ' 04:00:00';
      $this->post_date = $date;
    }

    $this->taxonomies = [];

    if ('JA' === (string) $xml->xpath('//CustomMetaData[@name="C_BOTE"]')[0]) {
      $this->taxonomies['publisher']['der-bote'] = (int) term_exists('der-bote', 'publisher')['term_id'];
    }
    if ('JA' === (string) $xml->xpath('//CustomMetaData[@name="C_HZ"]')[0]) {
      $this->taxonomies['publisher']['hersbrucker-zeitung'] = (int) term_exists('hersbrucker-zeitung', 'publisher')['term_id'];
    }
    if ('JA' === (string) $xml->xpath('//CustomMetaData[@name="C_PZ"]')[0]) {
      $this->taxonomies['publisher']['pegnitz-zeitung'] = (int) term_exists('pegnitz-zeitung', 'publisher')['term_id'];
    }

    if ($category = (string) $xml->xpath('//BasicMetaData_Section_Name')[0]) {
      // Only add category News if no other category was set (see below).
      if ($category !== 'Lokales') {
        if ($term = get_term_by('name', $category, 'category')) {
          $this->taxonomies['category'][$category] = $term->term_id;
        }
      }
    }
    foreach ($xml->xpath('//CustomMetaData[@name="C_ORTSNAME"]') as $category) {
      $category = (string) $category;
      // @todo Term ID mapping via term_meta() in WP 4.4 - use backport plugin.
      if ($term = get_term_by('name', $category, 'category')) {
        $this->taxonomies['category'][$category] = $term->term_id;
      }
    }
    // Default the category to News if no other category could be determined.
    if (empty($this->taxonomies['category'])) {
      if ($term = get_term_by('slug', 'news', 'category')) {
        $this->taxonomies['category']['news'] = $term->term_id;
      }
    }

    if ($topic = (string) $xml->xpath('//CustomMetaData[@name="C_SERIENTHEMA"]')[0]) {
      if ($term = get_term_by('name', $topic, 'topic')) {
        $this->taxonomies['topic'][$topic] = $term->term_id;
      }
    }

    if ('JA' === (string) $xml->xpath('//CustomMetaData[@name="C_TOPARTIKEL"]')[0]) {
      if ($term = get_term_by('slug', 'top-story', 'category')) {
        $this->taxonomies['category']['top-story'] = $term->term_id;
      }
    }
    // @todo Determine meaning + possible values for "C_PLATZIERT".
    if ('JA' === (string) $xml->xpath('//CustomMetaData[@name="C_PLATZIERT"]')[0]) {
    }
    // Additional flag signaling that this post should be syndicated to nordbayern.de.
    if ('JA' === (string) $xml->xpath('//CustomMetaData[@name="C_NONNBAYERN"]')[0]) {
      if ($term = get_term_by('slug', 'nordbayern-export', 'post_tag')) {
        $this->taxonomies['post_tag'][] = $term->term_id;
      }
    }

/*
    if ($excerpt = (string) $xml->xpath('//CustomMetaData[@name="C_ANREISER"]')[0]) {
      $this->post_excerpt = $excerpt;
    }
*/
    if ($subtitle = (string) $xml->xpath('//CustomMetaData[@name="C_DACHZEILE"]')[0]) {
      $this->meta['wps_subtitle'] = $this->ensureSingleLine($subtitle);
    }
  }

  public function parseContent(\SimpleXMLElement $content) {
    $html = '';
    $style_classes = [
      'fett' => 'intro',
      'interview',
      'vorspann' => 'intro',
      'nachspann' => 'outro',
      'frage' => 'question',
      'antwort' => 'answer',
    ];
    foreach ($content as $name => $element) {
      if ($name === 'box') {
        if ($element['type'] == 'picture') {
          if ($filename = (string) $element->content->picture) {
            if (!isset($this->files[$filename])) {
              $this->files[$filename]['name'] = $this->config['uploadsPrefix'] . $filename;
              $this->lastFilename = $filename;
              $html .= "<!-- $filename -->\n\n";
            }
          }
          continue;
        }
      }
      if ($name === 'geometrylist') {
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
      if (isset($element['style'])) {
        $style = mb_strtolower($element['style']);
        // Inline styles.
        if (FALSE !== strpos($style, 'kursiv')) {
          $innerhtml = '<em>' . $innerhtml . '</em>';
        }
        // Block formats.
        if ($name === 'paragraph') {
          $tag = '';
          $classes = [];
          // Image caption:
          // - PZ: "Bildunterzeile_x12"
          // - DB: "Bildtext 8 Punkt_x12"
          // NOT image captions, but regular text:
          // - PZ: "Bildtext_x08"
          // - DB: "Bildunterschrift_x08"
          // Therefore, ensure to match a trailing space after "bildtext ".
          if (FALSE !== strpos($style, 'bildunterzeile') || FALSE !== strpos($style, 'bildtext ')) {
            $this->captions[] = [
              'caption' => $innerhtml,
              'lastFilename' => $this->lastFilename,
            ];
            continue;
          }
          if (FALSE !== strpos($style, 'autorenzeile')) {
            $this->meta['publishing_author'] = $this->ensureSingleLine($innerhtml);
            continue;
          }
          if (FALSE !== strpos($style, 'überschrift') || FALSE !== strpos($style, 'kurzmeldung')) {
            // The title/heading may be split into two consecutive paragraphs.
            if (!isset($this->post_title)) {
              $this->post_title = '';
            }
            elseif (!empty($this->post_title)) {
              $this->post_title .= ' ';
            }
            $this->post_title .= $this->ensureSingleLine($innerhtml);
            continue;
          }
          elseif (FALSE !== strpos($style, 'unterzeile')) {
            // We assume that C_DACHZEILE has precedence.
            if (empty($this->meta['wps_subtitle'])) {
              $this->meta['wps_subtitle'] = $this->ensureSingleLine($innerhtml);
            }
            continue;
          }
          elseif (FALSE !== strpos($style, 'zwischenzeile')) {
            $tag = 'h3';
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
      }
      $html .= $innerhtml;
      if ($name === 'paragraph') {
        $html .= "\n\n";
      }
    }
    return $html;
  }

  public function parseAuthor() {
    global $wpdb;

    // Check for an exact match of display names of administrators, editors, authors.
    if ($user_id = $this->getUserIdFromAuthorInContent($this->post_content)) {
    }
    // Check for a paraph at the end of the article (not necessarily end of
    // document, due to possibly embedded images below the text).
    // Works by coincidence, because all paragraphs in an article happen to have
    // a trailing space before the newline (due to XML conversion), so this
    // pattern only matches the end of the last paragraph.
    // Matches: 'sb$', 's.b.$'
    elseif (preg_match_all('@([a-z.]{2,5})\n@', $this->post_content, $matches)) {
      $result = $wpdb->get_row($wpdb->prepare("SELECT user_id, meta_value AS initials FROM {$wpdb->usermeta} WHERE meta_key = 'publishing_importer_initials' AND meta_value IN (" . str_repeat('%s,', count($matches[1]) - 1) . "%s)", $matches[1]));
      if ($result) {
        $user_id = (int) $result->user_id;
        $this->post_content = str_replace($result->initials, '', $this->post_content);
      }
    }
    if (!empty($user_id)) {
      return $user_id;
    }
    $defaults = [
      'der-bote' => 'der.bote',
      'hersbrucker-zeitung' => 'hersbrucker.zeitung',
      'pegnitz-zeitung' => 'pegnitzzeitung',
    ];
    return username_exists($defaults[$this->config['publisher']]);
  }

}
