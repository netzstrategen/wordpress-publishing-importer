<?php
namespace Netzstrategen\PublishingImporter;

class CliCommand extends \WP_CLI_Command {

  /**
   * Imports articles via publishing-importer plugin.
   *
   * @see Netzstrategen\PublishingImporter\Plugin::importContent()
   * @synopsis [--type=<type>] [--dir=<directorypath>] [--publisher=<ge>] [--filename=<filename>]
   */
  public function import(array $args, array $options) {
    if (isset($options['publisher'])) {
      $options['only_publisher_id'] = $options['publisher'];
    }
    if (isset($options['dir'])) {
      if (isset($options['publisher']) && isset($options['type'])) {
        $options['config_overrides'][$options['publisher']]['types'][$options['type']]['directory'] = $options['dir'];
      }
      else {
        $config = Plugin::readConfig();
        foreach ($config as $publisher => $publisher_config) {
          foreach ($publisher_config['types'] as $name => $type) {
            $options['config_overrides'][$publisher]['types'][$name]['directory'] = $options['dir'];
          }
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
