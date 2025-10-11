<?php

namespace Drupal\ai_drush_agents\Plugin\AiFunctionCall;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
 * Plugin implementation of the getting config entities function.
 */
#[FunctionCall(
  id: 'ai_agent:get_config_entity',
  function_name: 'ai_agent_get_config_entity',
  name: 'Get Config Entity',
  description: 'This method gets one config entity.',
  group: 'information_tools',
  context_definitions: [
    'entity_id' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Config ID"),
      description: new TranslatableMarkup("The exact entity id of the config entity. If its an entity config, its the entity id."),
      required: TRUE,
    ),
    'entity_type' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Entity Type"),
      description: new TranslatableMarkup("The data name of the entity type you want to get a config for, if its an config entity. If its a general config, leave this empty."),
      required: FALSE,
    ),
  ],
)]
class GetConfigEntity extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The entity type service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

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
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->configFactory = $container->get('config.factory');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * The list.
   *
   * @var string
   */
  protected string $list = "";

  /**
   * {@inheritdoc}
   */
  public function execute() {
    // Get the entity id.
    $entity_id = $this->getContextValue('entity_id');
    $entity_type = $this->getContextValue('entity_type');

    // If its an entity we get the storage.
    if ($entity_type) {
      try {
        $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
      }
      catch (\Exception $e) {
        // Could not load the entity.
        $this->list = "Could not load the entity.";
        return;
      }
      if (!$entity) {
        // The entity does not exist.
        $this->list = "The entity does not exist.";
        return;
      }
      // Check access.
      if (!$entity->access('view', $this->currentUser)) {
        // The user does not have access to the entity.
        $this->list = "You do not have access to the entity.";
        return;
      }
      // Yaml encode the entity.
      $this->list = Yaml::dump($entity->toArray(), 10, 2);
    }
    else {
      // If its a config entity we get the config.
      if (!$this->currentUser->hasPermission('administer site configuration')) {
        $this->list = 'You do not have permission to view configuration.';
        return;
      }

      $editable_config = $this->configFactory->getEditable($entity_id);
      if ($editable_config->isNew()) {
        $this->list = "The configuration \"$entity_id\" does not exist.";
        return;
      }

      $config = $this->configFactory->get($entity_id);

      // Yaml encode the config.
      $this->list = Yaml::dump($config->getRawData(), 10, 2);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->list;
  }

}
