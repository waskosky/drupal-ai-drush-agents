<?php

namespace Drupal\ai_drush_agents\Plugin\AiFunctionCall;

use Drupal\Core\KeyValueStore\KeyValueFactoryExpirable;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the saving temporary data function.
 */
#[FunctionCall(
  id: 'ai_agent:save_temporary_data',
  function_name: 'ai_agent_save_temporary_data',
  name: 'Save Temporary Data',
  description: 'This method saves temporary data to a file that can be retrieved later.',
  group: 'information_tools',
  context_definitions: [
    'key' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Key'),
      required: TRUE,
      description: new TranslatableMarkup('The key for the data you want to save.'),
    ),
    'data' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Data'),
      required: TRUE,
      description: new TranslatableMarkup('The data you want to save.'),
    ),
  ],
)]
class SaveTemporaryData extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The key for the data to save.
   *
   * @var string
   */
  protected string $key = '';

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The key value expirable factory.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueFactoryExpirable
   */
  protected KeyValueFactoryExpirable $keyValueFactory;

  /**
   * The key value store.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface
   */
  protected KeyValueStoreExpirableInterface $store;

  /**
   * The storage collection identifier.
   */
  private const STORAGE_COLLECTION = 'ai_agent_temp_data';

  /**
   * The key prefix used to namespace stored items.
   */
  private const KEY_PREFIX = 'ai_agent_tmp_';


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
    $instance->currentUser = $container->get('current_user');
    $instance->keyValueFactory = $container->get('keyvalue.expirable');
    $instance->store = $instance->keyValueFactory->get(self::STORAGE_COLLECTION);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    // We need to ensure the highest level of permissions here.
    // This is because we are accessing the config schema, which may not be
    // accessible to all users. Base tools will give more flexibility
    // in the future.
    if (!$this->currentUser->hasPermission('administer site configuration')) {
      throw new \Exception('You do not have permission to access this function.');
    }

    $data = $this->getContextValue('data');
    $key = $this->normalizeKey($this->getContextValue('key'));

    // Store the data with a one-day expiry to avoid lingering sensitive data.
    $this->store->setWithExpire($key, $data, 86400);
    $this->key = $key;
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return sprintf("The data has been successfully saved. Use the key: %s", $this->key);
  }

  /**
   * Normalizes a key by enforcing prefix and user scoping.
   */
  private function normalizeKey(string $key): string {
    $key = trim($key);
    if ($key === '') {
      throw new \InvalidArgumentException('A non-empty key is required.');
    }

    // Ensure we scope storage per-user to prevent cross-account access.
    $uid = (string) $this->currentUser->id();

    if (!str_contains($key, ':')) {
      $key = sprintf('%s%s:%s', self::KEY_PREFIX, $uid, $key);
    }

    if (!str_starts_with($key, self::KEY_PREFIX . $uid . ':')) {
      throw new \InvalidArgumentException('The provided key does not belong to the current user.');
    }

    // Replace disallowed characters to avoid filesystem-style traversal issues if
    // keys are logged or re-used elsewhere.
    $suffix = substr($key, strlen(self::KEY_PREFIX . $uid . ':'));
    $sanitized = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $suffix);
    if ($sanitized === '') {
      throw new \InvalidArgumentException('The provided key is not valid after sanitization.');
    }
    return sprintf('%s%s:%s', self::KEY_PREFIX, $uid, $sanitized);
  }

}
