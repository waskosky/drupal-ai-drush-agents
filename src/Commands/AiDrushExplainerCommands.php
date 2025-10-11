<?php

namespace Drupal\ai_drush_agents\Commands;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Drush\Attributes\Command;
use Drush\Attributes\Usage;
use Drush\Commands\DrushCommands;
use SevenEcks\Markdown\MarkdownTerminal;

/**
 * A Drush commandfile.
 */
final class AiDrushExplainerCommands extends DrushCommands {

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
   * Use this command to explain the Drush command.
   *
   * @command agents:drush_explain
   * @aliases agedre
   *
   * @usage drush agents:drush_explain
   *   Use this command to explain drush commands.
   */
  #[Command(name: 'agents:drush_explain', aliases: ['agedre'])]
  #[Usage(name: 'drush drush_explain', description: 'Use this command to explain Drush commands on the system.')]
  public function exportExplain() {
    $this->output()->writeln('Welcome to the Drush command explainer! Just write what you want to do with Drush, and I will try to figure out the correct Drush commands for you.');
    $input = $this->io()->ask('You');
    /** @var \Drupal\ai_agents\PluginInterfaces\AiAgentInterface $agent */
    $agent = $this->pluginManagerAiAgents->createInstance('drush_explain_agent');
    $agent->setChatInput(new ChatInput([
      new ChatMessage('user', $input),
    ]));
    $this->output()->writeln('Trying to figure out the correct Drush commands for you.');

    $response = $this->io()->spin(function () use ($agent) {
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

    $parser = new MarkdownTerminal();
    $markdown = $parser->parse($response);

    $this->output()->writeln($markdown);
  }

}
