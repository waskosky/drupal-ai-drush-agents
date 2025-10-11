<?php

namespace Drupal\ai_drush_agents\Plugin\AiFunctionCall;

use Drupal\Component\Diff\Diff;
use Drupal\Component\Diff\DiffFormatter;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Plugin implementation of the list tools function.
 */
#[FunctionCall(
  id: 'ai_drush_agents:config_diff',
  function_name: 'ai_drush_agents_config_diff',
  name: 'Config Diff',
  description: 'This function will give you the difference between the staged config and the active config.',
  group: 'information_tools',
)]
class ConfigDiff extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The active storage service.
   *
   * @var \Drupal\Core\Config\ConfigStorageInterface
   */
  protected $active;

  /**
   * The unsynced storage service.
   *
   * @var \Drupal\Core\Config\ConfigStorageInterface
   */
  protected $staging;

  /**
   * The config manager service.
   *
   * @var \Drupal\Core\Config\ConfigManagerInterface
   */
  protected $configManager;

  /**
   * The current user service.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Load from dependency injection container.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai.context_definition_normalizer'),
    );
    $instance->active = $container->get('config.storage');
    $instance->staging = $container->get('config.storage.sync');
    $instance->configManager = $container->get('config.manager');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * The markdown output.
   *
   * @var string
   */
  protected string $markdown = '';

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $active_names = $this->active->listAll();
    $staging_names = $this->staging->listAll();
    $diffs = [];

    $created = array_diff($active_names, $staging_names);
    $deleted = array_diff($staging_names, $active_names);
    $common = array_intersect($staging_names, $active_names);

    $updated = [];

    // Compare normalized versions to avoid false positives.
    foreach ($common as $name) {
      $active_data = $this->active->read($name);
      $staging_data = $this->staging->read($name);

      if (!is_array($active_data) || !is_array($staging_data)) {
        continue;
      }

      // Sort recursively and clean before comparing.
      $active_data = $this->normalizeConfigArray($active_data);
      $staging_data = $this->normalizeConfigArray($staging_data);

      if ($active_data !== $staging_data) {
        $updated[] = $name;

        $active_yaml = Yaml::dump($active_data, 3, 2);
        $staging_yaml = Yaml::dump($staging_data, 3, 2);

        $diff = new Diff(
          explode("\n", $staging_yaml),
          explode("\n", $active_yaml)
        );

        $formatter = new DiffFormatter();
        $formatter->leading_context_lines = 1;
        $formatter->trailing_context_lines = 1;
        $formatter->show_header = FALSE;

        $diffs[$name] = $formatter->format($diff);
      }
    }

    // Output the diff result.
    $this->markdown .= "CREATED:\n";
    foreach ($created as $name) {
      $this->markdown .= " + $name\n";
    }

    $this->markdown .= "\nDELETED:\n";
    foreach ($deleted as $name) {
      $this->markdown .= " - $name\n";
    }

    $this->markdown .= "\nUPDATED:\n";
    foreach ($updated as $name) {
      $this->markdown .= " * $name\n";
    }

    $this->markdown .= Yaml::dump($diffs, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->markdown;
  }

  /**
   * Recursively normalize a config array.
   *
   * @param array $data
   *   The config array to normalize.
   *
   * @return array
   *   The normalized config array.
   */
  public function normalizeConfigArray(array $data): array {
    ksort($data);
    foreach ($data as $key => &$value) {
      if (is_array($value)) {
        $value = $this->normalizeConfigArray($value);
      }
    }
    return $data;
  }

  /**
   * Recursively find the difference between two arrays.
   *
   * @param array $array1
   *   The first array.
   * @param array $array2
   *   The second array.
   *
   * @return array
   *   The difference between the two arrays.
   */
  public function arrayDiffArrayRecursive(array $array1, array $array2): array {
    $difference = [];

    foreach ($array1 as $key => $value) {
      if (is_array($value) && isset($array2[$key]) && is_array($array2[$key])) {
        $new_diff = $this->arrayDiffArrayRecursive($value, $array2[$key]);
        if (!empty($new_diff)) {
          $difference[$key] = $new_diff;
        }
      } elseif (!array_key_exists($key, $array2) || $array2[$key] !== $value) {
        $difference[$key] = $value;
      }
    }

    return $difference;
  }

}
