<?php

namespace Drupal\patternkit\Commands;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\patternkit\Entity\Pattern;
use Drupal\patternkit\Entity\PatternInterface;
use Drush\Commands\DrushCommands;

/**
 * Commands for working with the dev branch of Patternkit.
 */
class PatternkitCommands extends DrushCommands {

  /**
   * Update from an old dev version of Patternkit.
   *
   * @command patternkit:devUpdate
   */
  public function devUpdate() {
    if (!\Drupal::moduleHandler()->moduleExists('layout_builder')) {
      $this->logger()->notice(t('Patternkit from-dev updates only apply to Layout Builder, which is not enabled. Skipping Patternkit from-dev updates.'));
      return;
    }

    $entity_count = 0;
    $block_count = 0;

    $entity_type_manager = \Drupal::entityTypeManager();
    /** @var \Drupal\Core\Block\BlockManager $block_manager */
    $block_manager = \Drupal::service('plugin.manager.block');
    $block_storage = $entity_type_manager->getStorage('patternkit_block');
    /** @var \Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface $section_storage_manager */
    $section_storage_manager = \Drupal::service('plugin.manager.layout_builder.section_storage');
    $storage_definitions = $section_storage_manager->getDefinitions();
    /** @var \Drupal\layout_builder\LayoutTempstoreRepositoryInterface $tempstore_repository */
    $tempstore_repository = \Drupal::service('layout_builder.tempstore_repository');
    /** @var \Drupal\Core\Entity\ContentEntityStorageInterface $pattern_storage */
    $pattern_storage = $entity_type_manager->getStorage('patternkit_pattern');
    /** @var \Drupal\patternkit\Asset\LibraryInterface $library */
    $library =\Drupal::service('patternkit.asset.library');
    $logger = $this->logger();

    $section_storages = [];
    // Gather section storages from all entities and entity type layouts.
    /** @var \Drupal\Core\Config\Entity\ConfigEntityStorageInterface $view_mode_storage */
    $display_storage = $entity_type_manager->getStorage('entity_view_display');
    $displays = $display_storage->loadMultiple();
    /** @var \Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay $display */
    foreach ($displays as $display) {
      if (!$display instanceof \Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay) {
        continue;
      }
      if (!$display->isLayoutBuilderEnabled()) {
        continue;
      }

      foreach ($storage_definitions as $section_storage_type => $storage_definition) {
        $contexts = [];
        $contexts['display'] = EntityContext::fromEntity($display);
        $contexts['view_mode'] = new Context(new ContextDefinition('string'), $display->getMode());
        // Gathers entity type layouts.
        if ($section_storage = $section_storage_manager->load($section_storage_type, $contexts)) {
          $section_storages[] = $section_storage;
        }

        // Gathers entity layouts.
        $entity_storage = $entity_type_manager->getStorage($display->getTargetEntityTypeId());
        foreach ($entity_storage->loadMultiple() as $entity) {
          $contexts['entity'] = EntityContext::fromEntity($entity);
          $section_storages[] = $section_storage_manager->findByContext($contexts, new CacheableMetadata());
        }
      }
    }

    // Gather section storages from the tempstore, to update layout drafts.
    /** @var \Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface $key_value_factory */
    $key_value_factory = \Drupal::service('keyvalue.expirable');
    foreach (array_keys($storage_definitions) as $section_storage_type) {
      $key_value = $key_value_factory->get("tempstore.shared.layout_builder.section_storage.$section_storage_type");

      foreach ($key_value->getAll() as $key => $value) {
        $key = substr($key, 0, strpos($key, '.', strpos($key, '.') + 1));
        $contexts = $section_storage_manager->loadEmpty($section_storage_type)->deriveContextsFromRoute($key, [], '', []);
        $section_storages[] = $value->data['section_storage'];
        if ($section_storage = $section_storage_manager->load($section_storage_type, $contexts)) {
          $section_storages[] = $section_storage;
        }
      }
    }

    foreach ($section_storages as $section_storage) {
      foreach ($section_storage->getSections() as $section_delta => $section) {
        foreach ($section->getComponents() as $component_delta => $component) {
          $plugin_id = $component->getPluginId();
          if (strpos($plugin_id, 'patternkit') === FALSE) {
            continue;
          }
          $config = $component->get('configuration');
          if (!isset($config['patternkit_block_id'])) {
            continue;
          }
          $logger->debug(t('Updating block plugin with id @plugin:', ['@plugin' => $plugin_id]));
          /**
           * Old plugin ids were in the format:
           *   'patternkit_block:library.name_path_to_pattern'
           *
           * New plugin ids are:
           *   'patternkit_block:library__name_path_to_pattern'
           */
          if (strpos($plugin_id, '.')) {
            $config['id'] = 'patternkit_block:' . str_replace('.', '_', str_replace('_', '__', substr($plugin_id, strlen('patternkit_block:'))));
          }
          $pattern_id = '@' . str_replace('//', '_', str_replace('_', '/', substr($config['id'], strlen('patternkit_block:'))));
          $pattern_asset = $library->getLibraryAsset($pattern_id);
          if ($pattern_asset === NULL) {
            $logger->debug(t('Could not find pattern with ID @id.', ['@id' => $pattern_id]));
            continue;
          }
          try {
            /** @var PatternInterface $pattern */
            $pattern = Pattern::create($pattern_asset);
          }
          catch (\Exception $exception) {
            $logger->debug(t('Could not create pattern with ID @id.', ['@id' => $pattern_id]));
            continue;
          }
          if ($pattern === NULL) {
            $logger->debug(t('Could not finish creating pattern with ID @id.', ['@id' => $pattern_id]));
            continue;
          }
          $pattern_cache = $pattern_storage->loadByProperties(['library' => $pattern->getLibrary(), 'path' => $pattern->getPath()]);
          /** @var PatternInterface $pattern_loaded */
          $pattern_loaded = end($pattern_cache);
          if ($pattern_loaded !== NULL) {
            if ($pattern_loaded->getHash() !== $pattern->getHash()) {
              $pattern->setNewRevision();
              $pattern->isDefaultRevision(TRUE);
            }
            else {
              $pattern = $pattern_loaded;
            }
          }
          $pattern->save();
          $config['pattern'] = $pattern->getRevisionId();
          /** @var \Drupal\patternkit\Entity\PatternkitBlock $patternkit_block */
          $patternkit_block = $block_storage->load($config['patternkit_block_id']);
          $config['patternkit_block_rid'] = $patternkit_block->getRevisionId();
          $section_storage
            ->getSection($section_delta)
            ->getComponent($component->getUuid())
            ->setConfiguration($config);
          $block_count++;
        }
      }
      $section_storage->save();
      $tempstore_repository->set($section_storage);
      $entity_count++;
    }
    $this->logger()->info(t('Updated @entities entity layouts with @blocks Patternkit blocks.',
      ['@entities' => $entity_count, '@blocks' => $block_count]));
    $block_manager->clearCachedDefinitions();
    $entity_type_manager->clearCachedDefinitions();
    $this->logger()->notice(t('Successfully ran Patternkit from-dev updates.'));
  }

  /**
   * Update all patterns in a library.
   *
   * @command patternkit:libUpdate
   */
  public function libUpdate() {
    $lb_enabled = false;
    if (\Drupal::moduleHandler()->moduleExists('layout_builder')) {
      $lb_enabled = true;
    }

    $entity_count = 0;
    $block_count = 0;

    $entity_type_manager = \Drupal::entityTypeManager();
    /** @var \Drupal\Core\Block\BlockManager $block_manager */
    $block_manager = \Drupal::service('plugin.manager.block');
    $block_storage = $entity_type_manager->getStorage('patternkit_block');
    /** @var \Drupal\Core\Entity\ContentEntityStorageInterface $pattern_storage */
    $pattern_storage = $entity_type_manager->getStorage('patternkit_pattern');
    /** @var \Drupal\patternkit\Asset\LibraryInterface $library */
    $library =\Drupal::service('patternkit.asset.library');
    $logger = $this->logger();

    if ($lb_enabled) {
      /** @var \Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface $section_storage_manager */
      $section_storage_manager = \Drupal::service('plugin.manager.layout_builder.section_storage');
      $storage_definitions = $section_storage_manager->getDefinitions();
      /** @var \Drupal\layout_builder\LayoutTempstoreRepositoryInterface $tempstore_repository */
      $tempstore_repository = \Drupal::service('layout_builder.tempstore_repository');
      $section_storages = [];
      // Gather section storages from all entities and entity type layouts.
      /** @var \Drupal\Core\Config\Entity\ConfigEntityStorageInterface $view_mode_storage */
      $display_storage = $entity_type_manager->getStorage('entity_view_display');
      $displays = $display_storage->loadMultiple();
      /** @var \Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay $display */
      foreach ($displays as $display) {
        if (!$display instanceof \Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay) {
          continue;
        }
        if (!$display->isLayoutBuilderEnabled()) {
          continue;
        }

        foreach ($storage_definitions as $section_storage_type => $storage_definition) {
          $contexts = [];
          $contexts['display'] = EntityContext::fromEntity($display);
          $contexts['view_mode'] = new Context(new ContextDefinition('string'), $display->getMode());
          // Gathers entity type layouts.
          if ($section_storage = $section_storage_manager->load($section_storage_type, $contexts)) {
            $section_storages[] = $section_storage;
          }

          // Gathers entity layouts.
          $entity_storage = $entity_type_manager->getStorage($display->getTargetEntityTypeId());
          foreach ($entity_storage->loadMultiple() as $entity) {
            $contexts['entity'] = EntityContext::fromEntity($entity);
            $section_storages[] = $section_storage_manager->findByContext($contexts, new CacheableMetadata());
          }
        }
      }

      // Gather section storages from the tempstore, to update layout drafts.
      /** @var \Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface $key_value_factory */
      $key_value_factory = \Drupal::service('keyvalue.expirable');
      foreach (array_keys($storage_definitions) as $section_storage_type) {
        $key_value = $key_value_factory->get("tempstore.shared.layout_builder.section_storage.$section_storage_type");

        foreach ($key_value->getAll() as $key => $value) {
          $key = substr($key, 0, strpos($key, '.', strpos($key, '.') + 1));
          $contexts = $section_storage_manager->loadEmpty($section_storage_type)
            ->deriveContextsFromRoute($key, [], '', []);
          $section_storages[] = $value->data['section_storage'];
          if ($section_storage = $section_storage_manager->load($section_storage_type, $contexts)) {
            $section_storages[] = $section_storage;
          }
        }
      }

      foreach ($section_storages as $section_storage) {
        foreach ($section_storage->getSections() as $section_delta => $section) {
          foreach ($section->getComponents() as $component_delta => $component) {
            $plugin_id = $component->getPluginId();
            if (strpos($plugin_id, 'patternkit') === FALSE) {
              continue;
            }
            $configuration = $component->get('configuration');
            if (!isset($configuration['patternkit_block_id'])
              && ! ((int) $configuration['patternkit_block_id'] > 0)) {
              continue;
            }
            /** @var \Drupal\patternkit\Entity\PatternkitBlock $patternkit_block */
            if (isset($configuration['patternkit_block_rid'])
              && (int) $configuration['patternkit_block_rid'] > 0) {
              $patternkit_block = $block_storage->loadRevision($configuration['patternkit_block_rid']);
              if ($patternkit_block === NULL) {
                continue;
              }
            }
            else {
              $patternkit_block = $block_storage->load($configuration['patternkit_block_id']);
              if ($patternkit_block === NULL) {
                continue;
              }
              $configuration['patternkit_block_rid'] = $patternkit_block->getLoadedRevisionId();
            }
            $logger->debug(t('Updating block plugin with id @plugin:', ['@plugin' => $plugin_id]));
            try {
              $plugin = $component->getPlugin();
              $pattern_id = $component->getDerivativeId();
              /** @var \Drupal\patternkit\entity\PatternInterface $pattern */
              if (!empty($configuration['pattern'])) {
                $pattern = $pattern_storage->loadRevision($configuration['pattern']);
              }
              else {
                $pattern = $library->getLibraryAsset($pattern_id);
              }
            }
            catch (\Exception $exception) {
              \Drupal::messenger()->addError(t('Unable to load the pattern @pattern. Check the logs for more info.', ['@pattern' => $pattern_id ?? $plugin['pattern']]));
              return ['#markup' => t('Unable to edit a Patternkit block when the pattern fails to load.')];
            }
            $base_pattern = Pattern::create($library->getLibraryAsset($pattern_id));
            if ($base_pattern !== NULL) {
              if ($base_pattern->getHash() !== $pattern->getHash()) {
                $pattern->setNewRevision();
                $pattern->isDefaultRevision(TRUE);
            }
            $pattern->save();
            $configuration['pattern'] = $pattern->getRevisionId();
            /** @var \Drupal\patternkit\Entity\PatternkitBlock $patternkit_block */
            $patternkit_block = $block_storage->load($configuration['patternkit_block_id']);
            $configuration['patternkit_block_rid'] = $patternkit_block->getRevisionId();
            $section_storage
              ->getSection($section_delta)
              ->getComponent($component->getUuid())
              ->setConfiguration($configuration);
            $block_count++;
          }
        }
        $section_storage->save();
        $tempstore_repository->set($section_storage);
        $entity_count++;
      }
    }
    $this->logger()->info(t('Updated @entities entity layouts with @blocks Patternkit blocks.',
      ['@entities' => $entity_count, '@blocks' => $block_count]));
    $block_manager->clearCachedDefinitions();
    $entity_type_manager->clearCachedDefinitions();
    $this->logger()->notice(t('Successfully ran Patternkit from-dev updates.'));
  }

}
