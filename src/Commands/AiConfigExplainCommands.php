<?php

namespace Drupal\ai_drush_agents\Commands;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Drush\Attributes\Command;
use Drush\Attributes\Usage;
use Drush\Commands\DrushCommands;

/**
 * A Drush commandfile.
 */
final class AiConfigExplainCommands extends DrushCommands {

  /**
   * Constructs an AiConfigExplainCommands object.
   */
  public function __construct(
    private readonly AiAgentManager $pluginManagerAiAgents,
    private readonly AiProviderPluginManager $pluginManagerAiProviders,
  ) {
    parent::__construct();
  }

  /**
   * Use this command to explain the config export.
   *
   * @command agents:config_explain
   * @aliases agecex
   *
   * @usage drush agents:config_export_explain
   *   Use this command to explain the config export.
   */
  #[Command(name: 'agents:config_export_explain', aliases: ['agecex'])]
  #[Usage(name: 'drush config_export_explain', description: 'Use this command to explain the config export.')]
  public function exportExplain() {
    /** @var \Drupal\ai_agents\PluginInterfaces\AiAgentInterface $agent */
    $agent = $this->pluginManagerAiAgents->createInstance('config_export_explainer');
    $agent->setChatInput(new ChatInput([
      new ChatMessage('user', 'Explain the config export'),
    ]));
    $this->output()->writeln('Trying to figure out how to explain the config export.');
    $markdown = $this->io()->spin(function () use ($agent) {
      try {
        $agent->determineSolvability();
      }
      catch (\Exception $e) {
        $this->logger()->error($e->getMessage());
        return;
      }
      $markdown = $agent->solve();
      return $markdown;
    });

    $this->output()->writeln($markdown);
  }

}
