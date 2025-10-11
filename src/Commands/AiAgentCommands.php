<?php

namespace Drupal\ai_drush_agents\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai_agents\PluginBase\AiAgentEntityWrapper;
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Drush\Attributes\Argument;
use Drush\Attributes\Command;
use Drush\Attributes\Usage;
use Drush\Commands\DrushCommands;
use SevenEcks\Markdown\MarkdownTerminal;
use Symfony\Component\Console\Question\Question;

/**
 * A Drush commandfile for running AI agents.
 */
final class AiAgentCommands extends DrushCommands {

  /**
   * The Chat history.
   *
   * @var array
   */
  private array $chatHistory = [];

  /**
   * The AI agent instance.
   *
   * @var \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper|null
   */
  private ?AiAgentEntityWrapper $agent = NULL;

  /**
   * Constructs an AiAgentCommands object.
   *
   * @param \Drupal\ai_agents\PluginManager\AiAgentManager $aiAgentManager
   *   The AI agent manager service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user service.
   */
  public function __construct(
    private readonly AiAgentManager $aiAgentManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
  ) {
    parent::__construct();
  }

  /**
   * Run a specific AI agent.
   *
   * @param string|null $agent_id
   *   The ID of the AI agent to run.
   * @param string|null $initial_prompt
   *   The initial prompt to send to the AI agent.
   *
   * @command agents:run
   * @aliases agent
   * @usage drush agents:run
   *   Interactively select an AI agent to run.
   * @usage drush agents:run config_export_explainer
   *   Run the 'config_export_explainer' AI agent.
   */
  #[Command(name: 'agents:run', aliases: ['agent'])]
  #[Argument(name: 'agent_id', description: 'The ID of the AI agent to run.')]
  #[Argument(name: 'initial_prompt', description: 'The initial prompt to send to the AI agent.')]
  #[Usage(name: 'drush agents:run', description: 'Interactively select an AI agent to run.')]
  #[Usage(name: 'drush agents:run config_export_explainer', description: 'Run the "config_export_explainer" AI agent.')]
  #[Usage(name: 'drush agents:run config_export_explainer "Explain the latest config export"', description: 'Run the agent with an initial prompt.')]
  public function runAgent(
    ?string $agent_id = NULL,
    ?string $initial_prompt = NULL,
  ) {
    if (empty($agent_id)) {
      $agent_id = $this->selectAgent();
    }
    // Load the agent into memory.
    /** @var \Drupal\ai_agents\PluginInterfaces\ConfigAiAgentInterface $agent */
    $agent = $this->aiAgentManager->createInstance($agent_id);
    $this->agent = $agent;

    // Set the user to user 1, so we can run the agent.
    /** @var \Drupal\user\UserInterface $account */
    $account = $this->entityTypeManager
      ->getStorage('user')
      ->load(1);
    $this->currentUser->setAccount($account);

    // Always switch over so you are user 1.
    $this->io()->writeln("<info>Running AI Agent: $agent_id</info>");

    // Run the specified agent directly.
    if ($initial_prompt !== NULL) {
      return $this->processAgent($initial_prompt, FALSE);
    }

    $this->processAgent();
  }

  /**
   * Run a specified AI agent by ID.
   */
  private function processAgent(?string $prompt = NULL, bool $interactive = TRUE) {
    if ($interactive || $prompt === NULL) {
      // Prompt user for input, multiline.
      $question = new Question('Your prompt (multiline, add newline, send empty to exit):');
      $question->setMultiline(true);

      $prompt = $interactive ? $this->io()->askQuestion($question) : $prompt;
    }

    if (!$prompt) {
      if ($interactive) {
        $this->io()->writeln('No input provided. Exiting.');
      }
      return NULL;
    }

    $input = $prompt;
    $this->chatHistory[] = new ChatMessage('user', $input);
    $generateResponse = function () use ($input) {
      $this->agent->setChatHistory($this->chatHistory);
      try {
        $this->agent->determineSolvability();
      }
      catch (\Exception $e) {
        $this->logger()->error($e->getMessage());
        return $e->getMessage();
      }
      $markdown = $this->agent->solve();
      if ($markdown) {
        $this->chatHistory[] = new ChatMessage('assistant', $markdown);
      }
      return $markdown ?? '';
    };

    if ($interactive) {
      $this->output()->writeln('Fetching response...');
      // Simulate a long-running task with a spinner.
      $response = $this->io()->spin($generateResponse);
    }
    else {
      $response = $generateResponse();
    }

    $parser = new MarkdownTerminal();
    $markdown = $parser->parse($response);
    // Add agent response to history.
    $this->chatHistory[] = new ChatMessage('assistant', $markdown);
    // The response.
    if ($interactive) {
      $this->output()->writeln('');
      $this->output()->writeln("<fg=cyan>Bot:</> $markdown");

      return $this->processAgent();
    }

    return $markdown;
  }

  /**
   * Select an AI agent interactively.
   */
  private function selectAgent() {
    // Get all available AI agents.
    $agents = $this->entityTypeManager
      ->getStorage('ai_agent')
      ->loadMultiple();

    $items = [];
    foreach ($agents as $agent) {
      $items[$agent->id()] = $agent->label();
    }

    return $this->io()->select('Available AI Agents', $items);
  }

}
