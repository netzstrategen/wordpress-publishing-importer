<?php

namespace Netzstrategen\PublishingImporter;

class User {

  /**
   * @implements admin_init
   */
  public static function admin_init() {
    add_action('show_user_profile', __CLASS__ . '::edit_user_profile');
    add_action('edit_user_profile', __CLASS__ . '::edit_user_profile');
    add_action('personal_options_update', __CLASS__ . '::edit_user_profile_update');
    add_action('edit_user_profile_update', __CLASS__ . '::edit_user_profile_update');
  }

  /**
   * Adds form elements for user meta fields to the user profile form.
   *
   * @param object $user
   */
  public static function edit_user_profile($user) {
    $initials = get_user_meta($user->ID, 'publishing_importer_initials', FALSE);
    $value = implode("\n", $initials);
?>
<h3 id="publishing-importer"><?= __('Importer settings', Plugin::L10N) ?></h3>
<table class="form-table">
  <tr>
    <th><label for="publishing_importer_initials"><?= _x('Author Initials', 'Paraph', Plugin::L10N); ?></label></th>
    <td>
<textarea rows="3" cols="30" id="publishing_importer_initials" name="publishing_importer_initials">
<?= esc_textarea($value) ?>
</textarea>
      <p class="description"><?= __('Enter one paraph (short signature) per line.', Plugin::L10N) ?></p>
    </td>
  </tr>
</table>
<?php
  }

  /**
   * Updates the user meta fields that have been edited on the user profile form.
   *
   * @param int $user_id
   *   The ID of the user being updated.
   */
  public static function edit_user_profile_update($user_id) {
    $new_value = filter_input(INPUT_POST, 'publishing_importer_initials');
    $new_value = preg_replace('@\r?\n+@', "\n", $new_value);
    $new_value = array_filter(explode("\n", $new_value));
    sort($new_value);

    $old_value = get_user_meta($user_id, 'publishing_importer_initials', FALSE);
    if ($old_value != $new_value) {
      delete_user_meta($user_id, 'publishing_importer_initials');
      if ($new_value) {
        foreach ($new_value as $val) {
          add_user_meta($user_id, 'publishing_importer_initials', $val, FALSE);
        }
      }
    }
  }

}
