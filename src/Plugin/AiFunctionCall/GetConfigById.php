<?php

namespace Drupal\ai_drush_agents\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai\Utility\ContextDefinitionNormalizer;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the describe config function.
 */
#[FunctionCall(
  id: 'ai_agent:get_config_by_id',
  function_name: 'ai_agent_get_config_by_id',
  name: 'Get Config By ID',
  description: 'This gets the Drupal configuration by id.',
  group: 'information_tools',
  context_definitions: [
    'config_id' => new ContextDefinition(
      data_type: 'string',
      label: 'Configuration id',
      description: 'The id to get the configuration for.',
      required: TRUE,
    ),
  ],
)]
class GetConfigById extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The current user service.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The config data.
   *
   * @var string
   */
  protected string $config_data = '';

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\TypedConfigManagerInterface
   */
  protected $configFactory;

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
    $instance->configFactory = $container->get('config.factory');
    return $instance;
  }

  /**
   * {@inheritdoc}
   * @throws \Drupal\ai_agents\Exception\AgentProcessingException
   */
  public function execute() {
    // Collect the context values.
    $config_id = $this->getContextValue('config_id');
    // We need to ensure the highest level of permissions here.
    // This is because we are accessing the config schema, which may not be
    // accessible to all users. Base tools will give more flexibility
    // in the future.
    if (!$this->currentUser->hasPermission('administer site configuration')) {
      throw new \Exception('You do not have permission to access this function.');
    }

    // If the config id has .yml at the end, remove it.
    if (str_ends_with($config_id, '.yml')) {
      $config_id = str_replace('.yml', '', $config_id);
    }

    // Check if the config id exists.
    $configuration_data = $this->configFactory->getEditable($config_id);
    if ($configuration_data->isNew()) {
      $this->config_data = sprintf('The config "%s" does not exist.', $config_id);
      return;
    }

    $configuration_data = $this->configFactory->get($config_id);
    $this->config_data = json_encode($configuration_data->getRawData());
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->config_data;
  }

}
