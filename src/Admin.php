<?php

/**
 * @file
 * Contains \Netzstrategen\PublishingImporter\Admin.
 */

namespace Netzstrategen\PublishingImporter;

/**
 * Administrative back-end functionality.
 */
class Admin {

  /**
   * @implements admin_init
   */
  public static function init() {
    // User::admin_init();
    Term::admin_init();
    add_action('post_submitbox_misc_actions', __CLASS__ . '::outputPostOriginByGuid', 9);
  }

  /**
   * @implements post_submitbox_misc_actions
   */
  public static function outputPostOriginByGuid($post) {
    if ($post->post_type !== 'post') {
      return;
    }
    $label = __('Origin', Plugin::L10N);
    $guid = html_entity_decode($post->guid);
    if (preg_match('@[?&]p=\d+@', $guid)) {
      $value = __('created manually', Plugin::L10N);
      $details = '';
    }
    else {
      $value = __('imported', Plugin::L10N);
      $parts = parse_url($guid);
      $publisher = esc_html($parts['host']);
      $details = strtr(__(':system ID :id', Plugin::L10N), [
        ':system' => esc_html(trim(dirname($parts['path']), '/')),
        ':id' => esc_html(basename($parts['path'])),
      ]);
      $details = "<span class=\"details\">($publisher: $details)</span>";
    }
    // Re-using .misc-pub-revisions in order to apply the same styling.
    $output = <<<EOD
<div class="misc-pub-section misc-pub-origin misc-pub-revisions" id="origin">
$label: <span><strong>$value</strong> $details</span>
</div>
<style>
#post-body #origin:before {
  content: "\\f310"; /* .dashicons-migrate */
}
#post-body #origin .details {
  font-size: 85%;
}
</style>
EOD;
    echo $output;
  }

}
