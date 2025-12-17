<?php

namespace Drupal\smalk\Plugin\CKEditor5Plugin;

use Drupal\ckeditor5\Plugin\CKEditor5PluginDefault;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * CKEditor5 plugin to allow smalk-ads attribute on div elements.
 *
 * This plugin tells CKEditor5 to allow the smalk-ads attribute on div tags,
 * which is necessary for Drupal 10's CKEditor5 integration.
 *
 * @CKEditor5Plugin(
 *   id = "smalk_ads",
 *   ckeditor5 = @CKEditor5AspectsOfCKEditor5Plugin(
 *     plugins = { "smalk.SmalkAds" },
 *   ),
 *   drupal = @DrupalAspectsOfCKEditor5Plugin(
 *     label = @Translation("Smalk Ads"),
 *     library = "smalk/smalk.ckeditor5",
 *     elements = {
 *       "<div smalk-ads>",
 *       "<div smalk-ads id>",
 *     },
 *   ),
 * )
 */
class SmalkAds extends CKEditor5PluginDefault implements ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition
    );
  }

}


