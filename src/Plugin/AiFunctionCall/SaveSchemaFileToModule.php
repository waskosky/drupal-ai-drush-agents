<?php

namespace Drupal\ai_drush_agents\Plugin\AiFunctionCall;

use Drupal\Core\File\FileSystemInterface;
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
use Symfony\Component\Yaml\Yaml;

/**
 * Plugin to save a schema file to a given module.
 */
#[FunctionCall(
  id: 'ai_agent:save_schema_file',
  function_name: 'ai_agent_save_schema_file',
  name: 'Save Schema File to Module',
  description: 'This method saves a schema file from a temporary storage to a given module\'s config/schema directory.',
  group: 'information_tools',
  context_definitions: [
    'key' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Key'),
      required: TRUE,
      description: new TranslatableMarkup('The key for the data you want to save.'),
    ),
    'filename' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Filename'),
      required: TRUE,
      description: new TranslatableMarkup('The name of the schema file to save, e.g., my_module.schema.yml.'),
    ),
    'module' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Module name'),
      required: TRUE,
      description: new TranslatableMarkup('The machine name of the module to save the schema file to.'),
    ),
  ],
)]
class SaveSchemaFileToModule extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The response..
   *
   * @var string
   */
  protected string $response = '';

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The key value factory.
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
   * The module extension list.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected $moduleExtensionList;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * Flag to indicate if schema file was overwritten.
   *
   * @var bool
   */
  protected bool $schemaFileOverwritten = FALSE;

  /**
   * Storage collection name.
   */
  private const STORAGE_COLLECTION = 'ai_agent_temp_data';

  /**
   * Storage key prefix.
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
    $instance->moduleExtensionList = $container->get('extension.list.module');
    $instance->fileSystem = $container->get('file_system');
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

    // Save the file to the module's config/schema directory.
    $key = $this->normalizeKey($this->getContextValue('key'));
    $filename = $this->getContextValue('filename');
    $module = $this->getContextValue('module');
    $data = $this->store->get($key);
    if ($data === NULL) {
      $this->response = 'Key not found.';
      return;
    }
    // Once the data is read we can remove it to prevent reuse.
    $this->store->delete($key);
    $module = $this->moduleExtensionList->exists($module) ? $module : NULL;
    if ($module === NULL) {
      $this->response = 'Module not found.';
      return;
    }
    $module_path = $this->moduleExtensionList->getPath($module);
    if ($module_path === NULL) {
      $this->response = 'Module not found.';
      return;
    }
    $schema_path = $module_path . '/config/schema';
    if (!$this->fileSystem->prepareDirectory($schema_path, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      $this->response = 'Unable to prepare the schema directory.';
      return;
    }

    // Avoid directory traversal by insisting on a simple filename.
    $cleanFilename = basename($filename);
    if ($cleanFilename !== $filename || str_contains($cleanFilename, '..')) {
      $this->response = 'Invalid filename provided.';
      return;
    }

    if (!str_ends_with($cleanFilename, '.schema.yml')) {
      $this->response = 'Schema files must use the .schema.yml extension.';
      return;
    }

    $file_path = $schema_path . '/' . $cleanFilename;
    // Validate that the data is valid YAML.
    try {
      // Check if another file is using this name.
      if (file_exists($file_path)) {
        $this->schemaFileOverwritten = TRUE;
      }

      $parsed = Yaml::parse($data);
      // Re-encode to ensure proper formatting.
      $yaml_data = Yaml::dump($parsed, 10, 2);
      $this->fileSystem->saveData($yaml_data, $file_path, FileSystemInterface::EXISTS_REPLACE);
      $this->response = 'Schema file saved successfully to ' . $file_path;
    }
    catch (\Exception $e) {
      $this->response = 'Failed to save schema file: ' . $e->getMessage();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    $note = $this->schemaFileOverwritten ? 'Note: An existing file was overwritten.' : '';
    return trim(sprintf('%s %s', $this->response, $note));
  }

  /**
   * Normalizes keys and enforces user ownership.
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
