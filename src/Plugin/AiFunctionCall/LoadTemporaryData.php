<?php

namespace Drupal\ai_drush_agents\Plugin\AiFunctionCall;

use Drupal\Core\KeyValueStore\KeyValueExpirableFactory;
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
  id: 'ai_agent:load_temporary_data',
  function_name: 'ai_agent_load_temporary_data',
  name: 'Load Temporary Data',
  description: 'This method loads temporary data.',
  group: 'information_tools',
  context_definitions: [
    'key' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Key'),
      required: TRUE,
      description: new TranslatableMarkup('The key for the data you want to load.'),
    ),
  ],
)]
class LoadTemporaryData extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The key value expirable factory.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueExpirableFactory
   */
  protected KeyValueExpirableFactory $keyValueFactory;

  /**
   * The key value store.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface
   */
  protected KeyValueStoreExpirableInterface $store;

  /**
   * The data to return.
   *
   * @var string
   */
  protected string $data = '';

  /**
   * Storage collection name.
   */
  private const STORAGE_COLLECTION = 'ai_agent_temp_data';

  /**
   * Normalized key prefix.
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

    $key = $this->normalizeKey($this->getContextValue('key'));
    $this->data = $this->store->get($key);
    if ($this->data === NULL) {
      $this->data = 'No data found.';
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->data;
  }

  /**
   * Normalizes incoming keys and enforces ownership.
   */
  private function normalizeKey(string $key): string {
    $key = trim($key);
    if ($key === '') {
      throw new \InvalidArgumentException('A non-empty key is required.');
    }

    $uid = (string) $this->currentUser->id();

    if (!str_contains($key, ':')) {
      $key = sprintf('%s%s:%s', self::KEY_PREFIX, $uid, $key);
    }

    if (!str_starts_with($key, self::KEY_PREFIX . $uid . ':')) {
      throw new \InvalidArgumentException('The provided key does not belong to the current user.');
    }

    $suffix = substr($key, strlen(self::KEY_PREFIX . $uid . ':'));
    $sanitized = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $suffix);
    if ($sanitized === '') {
      throw new \InvalidArgumentException('The provided key is not valid after sanitization.');
    }
    return sprintf('%s%s:%s', self::KEY_PREFIX, $uid, $sanitized);
  }

}
