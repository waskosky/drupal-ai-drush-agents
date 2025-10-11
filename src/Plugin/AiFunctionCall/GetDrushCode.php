<?php

namespace Drupal\ai_drush_agents\Plugin\AiFunctionCall;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the getting code for a Drush command class function.
 */
#[FunctionCall(
  id: 'ai_drush_agents:get_drush_code',
  function_name: 'ai_drush_agent_get_drush_code',
  name: 'Get Drush Code',
  description: 'This method gets the code for a specific Drush command class.',
  group: 'information_tools',
  context_definitions: [
    'class' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Drush Command Class"),
      description: new TranslatableMarkup("The class of the Drush command to get code for."),
      required: TRUE,
    ),
  ],
)]
class GetDrushCode extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The current user service.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai.context_definition_normalizer'),
    );
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    // Check so its running from Drush.
    if (PHP_SAPI !== 'cli') {
      throw new \RuntimeException('This tool can only be executed from Drush.');
    }

    if (!$this->currentUser->hasPermission('administer site configuration')) {
      throw new \RuntimeException('You do not have permission to access this function.');
    }

    // Load the code for the specified Drush command class.
    $class = $this->getContextValue('class');
    if (!class_exists($class)) {
      throw new \RuntimeException("The class '$class' does not exist.");
    }
    $reflection = new \ReflectionClass($class);
    // Double check so its a Drush command class.
    if (!$reflection->isSubclassOf(DrushCommands::class)) {
      throw new \RuntimeException("The class '$class' is not a Drush command class.");
    }
    $file = $reflection->getFileName();
    if (!$file) {
      throw new \RuntimeException("The class '$class' does not have a file associated with it.");
    }
    $code = file_get_contents($file);
    if ($code === FALSE) {
      throw new \RuntimeException("Could not read the file for class '$class'.");
    }
    $this->setOutput("Here is the code for the Drush command class '$class':\n\n" . $code);
  }

}
