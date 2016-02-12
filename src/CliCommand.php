<?php
namespace Netzstrategen\PublishingImporter;

class CliCommand extends \WP_CLI_Command {

  /**
   * Imports articles via publishing-importer plugin.
   *
   * @see Netzstrategen\PublishingImporter\Plugin::importContent()
   * @synopsis [--dir=<directorypath>] [--publisher=<ge>] [--filename=<filename>]
   */
  public function import(array $args, array $options) {
    if (isset($options['publisher'])) {
      $options['only_publisher_id'] = $options['publisher'];
    }
    if (isset($options['dir'])) {
      if (isset($options['publisher'])) {
        $options['config_overrides'][$options['publisher']]['importDirectories']['articles'] = $options['dir'];
      }
      else {
        $config = Plugin::readConfig();
        foreach ($config as $publisher => $publisher_config) {
          $options['config_overrides'][$publisher]['importDirectories']['articles'] = $options['dir'];
        }
      }
    }
    if (isset($options['filename'])) {
      $options['only_article_filename'] = $options['filename'];
    }
    try {
      Plugin::importContent($options);
      \WP_CLI::success('Articles has been sucessfully processed.');
    }
    catch (\Exception $e) {
      \WP_CLI::error($e->getMessage());
    }
  }

}
