<?php

namespace Netzstrategen\PublishingImporter;

class Term {

  /**
   * @implements admin_init
   */
  public static function admin_init() {
    global $wp_taxonomies;

    foreach ($wp_taxonomies as $name => $taxonomy) {
      add_action($name . '_add_form_fields', __CLASS__ . '::add_form_fields');
      add_action($name . '_edit_form_fields', __CLASS__ . '::edit_form_fields', 10, 2);
      add_action('created_' . $name, __CLASS__ . '::onSave', 10, 2);
      add_action('edited_' . $name, __CLASS__ . '::onSave', 10, 2);
    }
  }

  /**
   * Adds form elements for term meta fields to the taxonomy form.
   *
   * @param string $taxonomy_name
   *   The name/slug of the taxonomy the term belongs to.
   */
  public static function add_form_fields($taxonomy_name) {
?>
<div class="form-field term-slug-wrap">
  <label for="publishing-importer-synonyms"><?= __('Synonyms', Plugin::L10N) ?></label>
  <?php static::outputFormTextareaElement($taxonomy_name) ?>
  <p><?= __('List one synonym per line', Plugin::L10N) ?>.</p>
</div>
<?php
  }

  /**
   * Adds form elements for term meta fields to the taxonomy form.
   *
   * @param object $term
   *   The term object being edited.
   * @param string $taxonomy_name
   *   The name/slug of the taxonomy the term belongs to.
   */
  public static function edit_form_fields($term, $taxonomy_name) {
?>
<tr class="form-field">
  <th scope="row"><label for="publishing-importer-synonyms"><?= __('Synonyms', Plugin::L10N) ?></label></th>
  <td>
    <?php static::outputFormTextareaElement($taxonomy_name, $term) ?>
    <p class="description"><?= __('List one synonym per line', Plugin::L10N) ?>.</p>
  </td>
</tr>
<?php
  }

  /**
   * Outputs a textarea form element for a new or a given term.
   *
   * @param string $taxonomy_name
   *   The name/slug of the taxonomy the term belongs to.
   * @param string $term
   *   (optional) The term object being edited.
   */
  public static function outputFormTextareaElement($taxonomy_name, $term = NULL) {
    if (is_object($term)) {
      $publishing_importer_synonyms = get_term_meta($term->term_id, '_publishing_importer_synonyms', FALSE);
    }
    $value = !empty($publishing_importer_synonyms) ? implode("\n", $publishing_importer_synonyms) : '';
?>
<textarea rows="3" cols="30" id="publishing-importer-synonyms" name="_publishing_importer_synonyms"><?= esc_textarea($value) ?></textarea>
<?php
  }

  /**
   * Updates the term meta fields that have been edited on the term edit form.
   *
   * @param int $term_id
   *   The ID of the term being updated.
   */
  public static function onSave($term_id) {
    $new_value = filter_input(INPUT_POST, '_publishing_importer_synonyms');
    $new_value = preg_replace('@\r?\n+@', "\n", $new_value);
    $new_value = array_filter(explode("\n", $new_value));
    sort($new_value);

    $old_value = get_term_meta($term_id, '_publishing_importer_synonyms', FALSE);
    if ($old_value != $new_value) {
      delete_term_meta($term_id, '_publishing_importer_synonyms');
      if ($new_value) {
        foreach ($new_value as $val) {
          add_term_meta($term_id, '_publishing_importer_synonyms', $val, FALSE);
        }
      }
    }
  }

}
