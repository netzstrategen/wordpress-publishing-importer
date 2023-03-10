<?php

/**
 * @file
 * Contains \Netzstrategen\PublishingImporter\Parser\Dpa\Article.
 */

namespace Netzstrategen\PublishingImporter\Parser\Dpa;

use Netzstrategen\PublishingImporter\Parser\Post;
use Netzstrategen\PublishingImporter\Plugin;

use MatthiasWeb\RealMediaLibrary\attachment\Structure as RML_Structure;

/**
 * Parser for DPA NITF 3.
 */
class Article extends Post {

  const FILE_EXTENSION = 'xml';

  public static function createFromFile(array $config, $pathname, &$raw_filename = NULL) {
    if (strpos($raw_filename, 'topthemen') && !strpos($raw_filename, 'hintergruende')) {
      Plugin::debug("Skipped (duplicate) 'topthemen' article $raw_filename");
      return;
    }
    if (strpos($raw_filename, 'eilmeldungen')) {
      Plugin::debug("Skipped (too short) 'eilmeldungen' article $raw_filename");
      return;
    }
    if (strpos($raw_filename, 'schlaglichter')) {
      Plugin::debug("Skipped (too short) 'schlaglichter' article $raw_filename");
      return;
    }
    if (strpos($raw_filename, 'kalenderblatt')) {
      Plugin::debug("Skipped (useless) 'kalenderblatt' article $raw_filename");
      return;
    }
    if (strpos($raw_filename, 'bild')) {
      Plugin::debug("Skipped (unsupported) 'bilder_des_tages' attachment $raw_filename");
      return;
    }
    if (strpos($raw_filename, 'boersefrankfurttabelle')) {
      Plugin::debug("Skipped (unsupported) 'boersefrankfurttabelle' article $raw_filename");
      return;
    }
    return parent::createFromFile($config, $pathname, $raw_filename);
  }

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

  public function parse() {
    $xml = simplexml_load_string($this->raw);

    $this->meta['_publishing_system'] = 'NITF/3';

    $this->parseMeta($xml);

    if (isset($xml->body)) {
      $dom = dom_import_simplexml($xml->body);

      $this->post_excerpt = '';
      $abstract = $dom->getElementsByTagName('abstract');
      if ($abstract->length) {
        $this->post_excerpt = trim($this->parseContent($abstract->item(0)));
      }

      $this->post_content = $this->parseContent($dom->getElementsByTagName('body.content')->item(0));
    }

    // Posts without any images should be reviewed before getting published.
    if (empty($this->files)) {
      $this->post_status = 'pending';
    }
  }

  public function parseMeta(\SimpleXMLElement $xml) {
    // @todo Do we need to strip the (category) suffix from the filename for uniqueness?
    //$article_id = preg_replace('/[_:].*/', '', $this->rawFilename);
    $article_id = $this->rawFilename;

    $this->guid = 'http://' . $this->config['publisher'] . '/' . $this->config['system'] . '/' . $article_id;
    $this->meta['_publishing_importer_id'] = $article_id;

    if ($post_date = $xml->xpath('//date.issue/@norm')) {
      $this->post_date = date('Y-m-d H:i:s', strtotime((string) $post_date[0]));
    }

    $this->post_author = username_exists('dpa');

    $title = $xml->xpath('//hedline/hl1');
    $this->post_title = $title ? (string) $title[0] : '';

    if ($subtitle = $xml->xpath('//hedline/hl2')) {
      $this->meta['wps_subtitle'] = (string) $subtitle[0];
    }

    if ($category = $xml->xpath('//doc-scope/@scope')) {
      $category = (string) $category[0];
      $this->taxonomies['category'][] = strtr($category, [
        'pl' => 'Politik',
        'sp' => 'Sport',
        'vm' => 'Blick in die Welt',
        'wi' => 'Wirtschaft',
        'ku' => 'Kultur',
      ]);
    }

    if ($tags = $xml->xpath('//keyword/@key')) {
      $tags = (string) $tags[0];
      $this->taxonomies['post_tag'] = array_filter(explode('/', $tags));
    }

    if ($urgency = $xml->xpath('//urgency/@ed-urg')) {
      $this->meta['urgency'] = (string) $urgency[0];
    }

    apply_filters('publishing_importer/post/parse_meta', $this, $xml);
  }

  public function parseContent(\DOMNode $content) {
    $html = '';
    $style_classes = [
      'vorspann' => 'intro',
      'interview-frage' => 'interview',
      'info' => 'infobox',
    ];
    foreach ($content->childNodes as $element) {
      $name = '';
      if (isset($element->tagName)) {
        $name = $element->tagName;
      }

      if ($name === 'media') {
        $image = NULL;
        foreach ($element->getElementsByTagName('media-reference') as $derivative) {
          if ($derivative->hasAttribute('source') && (!$image || $derivative->getAttribute('width') > $image->getAttribute('width'))) {
            $image = $derivative;
          }
        }
        if (!$image) {
          continue;
        }
        if ($filepath = (string) $image->getAttribute('source')) {
          $basename = basename($filepath);
          if (!isset($this->files[$filepath])) {
            $this->files[$filepath] = [
              'filename' => $filepath,
              'name' => $this->config['uploadsPrefix'] . $basename,
            ];
            $caption = $element->getElementsByTagName('media-caption')->item(0);
            if ($caption && $caption = trim($caption->textContent)) {
              if (count($parts = preg_split('@(?<=[\s.!?])\s*(Fotos?|Quelle|Archivfotos?):\s*@', $caption)) > 1) {
                $caption = $parts[0];
                $this->files[$filepath]['credit'] = $parts[1];
              }
              $this->files[$filepath]['caption'] = $caption;
            }
            if ($alt = trim($image->getAttribute('alternate-text'))) {
              $this->files[$filepath]['_wp_attachment_image_alt'] = $alt;
            }
            // Add all provided metadata as raw, editable meta fields.
            foreach ($element->getElementsByTagName('media-metadata') as $meta) {
              if ($value = $meta->getAttribute('value')) {
                $this->files[$filepath][$meta->getAttribute('name')] = $value;
              }
            }
            $html .= "<!-- $basename -->\n\n";
          }
        }
        continue;
      }

      if ($element->hasChildNodes()) {
        $innerhtml = trim($this->parseContent($element));
        if ($name === 'ul' || $name === 'ol') {
          $innerhtml = "\n" . $innerhtml . "\n";
        }
      }
      else {
        $innerhtml = preg_replace('@\s+@', ' ', $element->textContent);
      }
      // Skip empty paragraphs/elements.
      if ($innerhtml === '' || preg_match('@^\s+$@', $innerhtml)) {
        continue;
      }
      $tag = '';
      $style = '';
      $attributes = [];
      $classes = [];
      if ($name === 'block') {
        // @todo Rich-text editor is not able to handle DIVs, nor a p.infobox that contains a heading.
        $tag = 'div';
        $style = mb_strtolower($element->getAttribute('style'));
        // Internal links are essentially "related articles"; ignored until properly supported here.
        if ($style === 'internal-links') {
          continue;
        }
        if ($style === 'external-links') {
          $tag = 'nav';
          $classes[] = 'related related--external';
          $innerhtml = strtr('<ul>' . $innerhtml . '</ul>', [
            '<a href' => '<li><a class="external" target="_blank" href',
            '</a>' => '</a></li>',
          ]);
        }
      }
      // infobox heading; according to NITF manual, the main content never contains subheadings.
      elseif ($name === 'hl1') {
        $tag = 'h3';
      }
      elseif (in_array($name, ['ol', 'ul', 'li', 'strong', 'em', 'b', 'i', 'a'], TRUE)) {
        $tag = $name;
      }
      if ($name === 'a') {
        foreach (['href', 'target', 'id'] as $attr) {
          if ($element->hasAttribute($attr)) {
            $attributes[$attr] = $element->getAttribute($attr);
          }
        }
        // Remove internal cross-links to other DPA articles (requires dependency tree).
        if (isset($attributes['href']) && 0 === strpos($attributes['href'], 'dpa-infocom:')) {
          $attributes = $style = $classes = $tag = NULL;
        }
      }
      if ($style) {
        foreach ($style_classes as $candidate => $classname) {
          if (FALSE !== strpos($style, is_string($candidate) ? $candidate : $classname)) {
            $classes[] = $classname;
          }
        }
      }
      if ($attributes || $classes || $tag) {
        // Values do not need escaping as they were read from XML/HTML already.
        $attributes = $attributes ? ' ' . implode(' ', array_map(function ($k, $v) { return "$k=\"$v\""; }, array_keys($attributes), $attributes)) : '';
        $classes = $classes ? ' class="' . implode(' ', $classes) . '"' : '';
        if (!$tag) {
          $tag = 'p';
        }
        $innerhtml = '<' . $tag . $attributes . $classes . '>' . $innerhtml . '</' . $tag . '>';
      }

      if ($name === 'p' || ($innerhtml[0] === '<' && ($innerhtml[1] === 'p' || $innerhtml[1] === 'o' || $innerhtml[1] === 'h' || $innerhtml[1] === 'u'))) {
        $innerhtml .= "\n\n";
      }
      elseif ($name === 'li') {
        $innerhtml .= "\n";
      }
      $html .= $innerhtml;
    }
    return $html;
  }

  protected function insertAttachment($attachment_id, array $file, $current_number) {
    $html = '';
    if ($current_number == 1) {
      set_post_thumbnail($this->ID, $attachment_id);
    }
    elseif ($this->post_type !== 'gallery') {
      // @see wp_ajax_send_attachment_to_editor()
      $html = get_image_send_to_editor($attachment_id, $file['caption'], '', 'none', '', '', 'post-thumbnail');
    }
    if ($this->post_type === 'gallery') {
      $this->meta['images'][] = $attachment_id;
    }
    // Additionally trim to remove leading whitespace before text content;
    // i.e., after removing placeholder for post thumbnail/featured image.
    $this->post_content = trim(strtr($this->post_content, ["<!-- $file[orig_filename] -->" => $html])) . "\n";
  }

  /**
   * Puts a given list of attachments into real-media-library PDA folder.
   *
   * @param array $attachment_ids
   *   The list of attachments IDs to organize into the PDA folder.
   */
  protected function organizeAttachments(array $attachment_ids) {
    $this->setAttachmentRmlFolder($attachment_ids, 'dpa');
  }

  /**
   * Puts a given list of attachments into a real-media-library folder name.
   *
   * @param string $folder_name
   *   The name of the real-media-library folder.
   * @param array $attachment_ids
   *   The list of attachments IDs to organize into the folder.
   *
   * @return array|bool
   *   TRUE if attachments were organized successfully, an array in case
   *   of any error, or NULL if the real-media-library plugin is not
   *   installed.
   */
  private function setAttachmentRmlFolder(array $attachment_ids, $folder_name) {
    if (!class_exists(RML_Structure::class)) {
      Plugin::error('Plugin real-media-library not found.', TRUE);
      return;
    }

    $status = FALSE;
    foreach (RML_Structure::getInstance()->getRows() as $folder) {
      if ($folder->slug === $folder_name) {
        $status = wp_rml_move($folder->id, $attachment_ids, TRUE, FALSE);
        break;
      }
    }

    if ($status === TRUE) {
      wp_rml_update_count([$folder->id]);
    }

    return $status;
  }

}
