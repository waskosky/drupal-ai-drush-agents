<?php

namespace Drupal\ai_drush_agents\Plugin\AiFunctionCall;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Drush\Drush;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the getting Drush commands on the system.
 */
#[FunctionCall(
  id: 'ai_drush_agents:get_drush_commands',
  function_name: 'ai_drush_agent_get_drush_commands',
  name: 'Get Drush Commands',
  description: 'This method gets all the drush commands on the system.',
  group: 'information_tools',
  context_definitions: [],
)]
class GetDrushCommands extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

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

    $application = Drush::getApplication();

    $commands = $application->all();
    $list = "This is a list of all the drush commands on the system:\n\n";
    foreach ($commands as $name => $command) {
      $list .= "  - Name: " . $command->getName() . "\n";
      if ($description = $command->getDescription()) {
        $list .= "  - Description: " . $description . "\n";
      }
      if ($aliases = $command->getAliases()) {
        $list .= "  - Aliases: " . implode(', ', $aliases) . "\n";
      }
      if ($usage = $command->getHelp()) {
        $list .= "  - Usage: " . $usage . "\n";
      }
      if ($class = get_class($command)) {
        $list .= "  - Class: " . $class . "\n";
      }
      $list .= "\n";
    }
    $this->setOutput($list);
  }

}
