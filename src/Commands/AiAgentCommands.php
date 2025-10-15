<?php

namespace Drupal\ai_drush_agents\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager;
use Drupal\ai_agents\PluginBase\AiAgentEntityWrapper;
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\Core\Session\AccountProxyInterface;
use Drush\Attributes\Argument;
use Drush\Attributes\Command;
use Drush\Attributes\FieldLabels;
use Drush\Attributes\Option;
use Drush\Attributes\Usage;
use Drush\Commands\DrushCommands;
use SevenEcks\Markdown\MarkdownTerminal;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * A Drush commandfile for running AI agents and tools.
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
   * Tracks the ID of the active agent for the current session.
   */
  private ?string $activeAgentId = NULL;

  /**
   * Constructs an AiAgentCommands object.
   */
  public function __construct(
    private readonly AiAgentManager $aiAgentManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly FunctionCallPluginManager $functionCallPluginManager,
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
  #[Option(name: 'output', description: 'Output mode: cli (default), markdown, or json.')]
  #[Option(name: 'with-history', description: 'Include chat history in JSON output.')]
  #[Option(name: 'no-spinner', description: 'Disable the interactive spinner when waiting for results.')]
  #[Option(name: 'stdin', description: 'Read the initial prompt from STDIN when not provided as an argument.')]
  public function runAgent(
    ?string $agent_id = NULL,
    ?string $initial_prompt = NULL,
    array $options = [
      'output' => 'cli',
      'with-history' => FALSE,
      'no-spinner' => FALSE,
      'stdin' => FALSE,
    ],
  ) {
    $this->chatHistory = [];

    $output_mode = strtolower((string) ($options['output'] ?? 'cli'));
    if (!in_array($output_mode, ['cli', 'markdown', 'json'], TRUE)) {
      throw new \InvalidArgumentException(sprintf('Unsupported output mode "%s". Allowed values are cli, markdown, json.', $output_mode));
    }
    $options['output'] = $output_mode;

    if (empty($agent_id)) {
      $agent_id = $this->selectAgent();
    }
    /** @var \Drupal\ai_agents\PluginInterfaces\ConfigAiAgentInterface $agent */
    $agent = $this->aiAgentManager->createInstance($agent_id);
    $this->agent = $agent;
    $this->activeAgentId = $agent_id;

    /** @var \Drupal\user\UserInterface $account */
    $account = $this->entityTypeManager
      ->getStorage('user')
      ->load(1);
    if ($account) {
      $this->currentUser->setAccount($account);
    }

    $this->io()->writeln("<info>Running AI Agent: $agent_id</info>");

    if ($initial_prompt === NULL && !empty($options['stdin'])) {
      $stdin = stream_get_contents(STDIN);
      if ($stdin !== FALSE) {
        $initial_prompt = rtrim($stdin);
      }
    }

    if ($initial_prompt !== NULL) {
      return $this->processAgent($initial_prompt, FALSE, $options);
    }

    $this->processAgent(NULL, TRUE, $options);
  }

  /**
   * Run a specified AI agent by ID.
   */
  private function processAgent(?string $prompt, bool $interactive, array $options) {
    if ($interactive || $prompt === NULL) {
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
    $generateResponse = function () {
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

    $use_spinner = $interactive && empty($options['no-spinner']);

    if ($use_spinner) {
      $this->output()->writeln('Fetching response...');
      $response = $this->io()->spin($generateResponse);
    }
    else {
      $response = $generateResponse();
    }

    $response = (string) ($response ?? '');
    $formatted = $this->formatResponse($response, $options);

    if ($interactive) {
      $this->output()->writeln('');
      if ($options['output'] === 'json') {
        $this->output()->writeln($formatted);
      }
      else {
        $this->output()->writeln("<fg=cyan>Bot:</> $formatted");
      }

      return $this->processAgent(NULL, TRUE, $options);
    }

    return $formatted;
  }

  /**
   * Select an AI agent interactively.
   */
  private function selectAgent() {
    $agents = $this->entityTypeManager
      ->getStorage('ai_agent')
      ->loadMultiple();

    $items = [];
    foreach ($agents as $agent) {
      $items[$agent->id()] = $agent->label();
    }

    return $this->io()->select('Available AI Agents', $items);
  }

  /**
   * List available AI agents with their high-level metadata.
   */
  #[Command(name: 'agents:list', aliases: ['agent:list'])]
  #[Usage(name: 'drush agents:list', description: 'List all available AI agents as a table.')]
  #[Usage(name: 'drush agents:list --format=json', description: 'Return agent metadata in JSON for downstream tooling.')]
  #[FieldLabels(labels: [
    'id' => 'ID',
    'label' => 'Label',
    'description' => 'Description',
    'tools' => 'Tools',
  ])]
  public function listAgents(array $options = ['format' => 'table']): RowsOfFields {
    $storage = $this->entityTypeManager->getStorage('ai_agent');
    $agents = $storage->loadMultiple();

    $rows = [];
    foreach ($agents as $agent) {
      $tools = array_keys(array_filter($agent->get('tools') ?? []));
      $rows[$agent->id()] = [
        'id' => $agent->id(),
        'label' => $agent->label(),
        'description' => (string) ($agent->get('description') ?? ''),
        'tools' => implode(', ', $tools),
      ];
    }

    ksort($rows);

    return new RowsOfFields($rows);
  }

  /**
   * Display detailed configuration for an AI agent.
   */
  #[Command(name: 'agents:info', aliases: ['agent:info'])]
  #[Argument(name: 'agent_id', description: 'The ID of the AI agent to inspect.')]
  #[Usage(name: 'drush agents:info config_export_explainer', description: 'Show configuration details for the config_export_explainer agent.')]
  #[Usage(name: 'drush agents:info config_export_explainer --format=json', description: 'Return agent details as JSON for machine consumption.')]
  public function describeAgent(string $agent_id, array $options = ['format' => 'yaml']): array {
    $storage = $this->entityTypeManager->getStorage('ai_agent');
    $agent = $storage->load($agent_id);
    if (!$agent) {
      throw new \InvalidArgumentException(sprintf('AI agent "%s" was not found.', $agent_id));
    }

    $tools = array_keys(array_filter($agent->get('tools') ?? []));

    return [
      'id' => $agent->id(),
      'label' => $agent->label(),
      'description' => (string) ($agent->get('description') ?? ''),
      'system_prompt' => (string) ($agent->get('system_prompt') ?? ''),
      'secured_system_prompt' => (string) ($agent->get('secured_system_prompt') ?? ''),
      'tools' => $tools,
      'tool_settings' => $agent->get('tool_settings') ?? [],
      'tool_usage_limits' => $agent->get('tool_usage_limits') ?? [],
      'default_information_tools' => $this->parseDefaultInformationTools($agent->get('default_information_tools') ?? ''),
      'max_loops' => $agent->get('max_loops'),
      'orchestration_agent' => (bool) $agent->get('orchestration_agent'),
      'triage_agent' => (bool) $agent->get('triage_agent'),
      'structured_output_enabled' => $agent->get('structured_output_enabled'),
      'structured_output_schema' => $agent->get('structured_output_schema'),
    ];
  }

  /**
   * Execute a single tool directly from the CLI.
   */
  #[Command(name: 'agents:tool', aliases: ['agent:tool'])]
  #[Argument(name: 'tool_id', description: 'The tool plugin ID or function name to execute.')]
  #[Usage(name: 'drush agents:tool ai_agent:get_config_by_id --context=config_id=system.site', description: 'Fetch configuration by ID using the tool directly.')]
  #[Usage(name: 'drush agents:tool ai_drush_agents:get_drush_commands --output=json', description: 'Run a tool and return the response in JSON.')]
  #[Option(name: 'context', description: 'Context value as key=value. Repeat the option for multiple contexts.')]
  #[Option(name: 'context-json', description: 'Provide all context values as a JSON object. Pass "-" to read from STDIN.')]
  #[Option(name: 'output', description: 'Output mode: text (default) or json.')]
  public function runTool(
    string $tool_id,
    array $options = [
      'context' => [],
      'context-json' => NULL,
      'output' => 'text',
    ],
  ) {
    $output_mode = strtolower((string) ($options['output'] ?? 'text'));
    if (!in_array($output_mode, ['text', 'json'], TRUE)) {
      throw new \InvalidArgumentException('Only "text" or "json" output modes are supported for agents:tool.');
    }

    $contextAssignments = $options['context'] ?? [];
    if (!is_array($contextAssignments)) {
      $contextAssignments = $contextAssignments !== NULL ? [$contextAssignments] : [];
    }

    $contextJson = $options['context-json'] ?? NULL;
    if (is_string($contextJson)) {
      $contextJson = trim($contextJson);
      if ($contextJson === '-') {
        $stdin = stream_get_contents(STDIN);
        if ($stdin === FALSE) {
          throw new \RuntimeException('Unable to read context JSON from STDIN.');
        }
        $contextJson = trim($stdin);
      }
      if ($contextJson === '') {
        $contextJson = NULL;
      }
    }

    $tool = $this->instantiateTool($tool_id);

    $contextValues = $this->parseContextOptions($tool, $contextJson, $contextAssignments);
    foreach ($contextValues as $name => $value) {
      $tool->setContextValue($name, $value);
    }

    $originalAccount = $this->currentUser->getAccount();
    try {
      /** @var \Drupal\user\UserInterface $account */
      $account = $this->entityTypeManager->getStorage('user')->load(1);
      if ($account) {
        $this->currentUser->setAccount($account);
      }

      $this->validateToolContexts($tool);
      $executionResult = $tool->execute();
    }
    finally {
      $this->currentUser->setAccount($originalAccount);
    }

    $readableOutput = $tool->getReadableOutput();
    $resolvedContext = $this->collectResolvedContexts($tool);

    if ($output_mode === 'json') {
      $payload = [
        'tool_id' => $tool->getPluginId(),
        'function_name' => $tool->getFunctionName(),
        'output' => $readableOutput,
        'result' => $this->serializeContextValue($executionResult ?? NULL),
        'provided_context' => $this->serializeContextValue($contextValues),
        'resolved_context' => $resolvedContext,
      ];
      return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    $rendered = $readableOutput !== '' ? (new MarkdownTerminal())->parse($readableOutput) : '';
    if ($rendered !== '') {
      $this->output()->writeln($rendered);
    }
    else {
      $this->io()->success(sprintf('Tool "%s" executed.', $tool->getPluginId()));
    }

    return NULL;
  }

  /**
   * Format an agent response for CLI or machine consumption.
   */
  private function formatResponse(string $rawResponse, array $options): string {
    return match ($options['output']) {
      'markdown' => $rawResponse,
      'json' => $this->buildJsonResponse($rawResponse, !empty($options['with-history'])),
      default => (new MarkdownTerminal())->parse($rawResponse),
    };
  }

  /**
   * Build a JSON encoded response payload for downstream tooling.
   */
  private function buildJsonResponse(string $rawResponse, bool $includeHistory): string {
    $payload = [
      'agent_id' => $this->activeAgentId,
      'response' => $rawResponse,
    ];

    if ($includeHistory) {
      $payload['history'] = $this->serializeHistory();
    }

    $toolResults = $this->summarizeToolResults();
    if ($toolResults) {
      $payload['tool_results'] = $toolResults;
    }

    return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
  }

  /**
   * Convert the internal chat history to a serializable structure.
   */
  private function serializeHistory(): array {
    $history = [];
    foreach ($this->chatHistory as $message) {
      if ($message instanceof ChatMessage) {
        $history[] = [
          'role' => $message->getRole(),
          'content' => $message->getText(),
        ];
      }
    }
    return $history;
  }

  /**
   * Provide a stable summary of tool results for machine parsing.
   */
  private function summarizeToolResults(): array {
    if (!$this->agent) {
      return [];
    }

    $results = [];
    foreach ($this->agent->getToolResults(TRUE) as $tool) {
      if (!is_object($tool)) {
        continue;
      }

      $results[] = array_filter([
        'class' => get_class($tool),
        'plugin_id' => method_exists($tool, 'getPluginId') ? $tool->getPluginId() : NULL,
        'function_name' => method_exists($tool, 'getFunctionName') ? $tool->getFunctionName() : NULL,
        'output' => method_exists($tool, 'getReadableOutput') ? $tool->getReadableOutput() : NULL,
      ], static fn ($value) => $value !== NULL && $value !== '');
    }

    return $results;
  }

  /**
   * Decode the stored default information tool YAML into an array.
   */
  private function parseDefaultInformationTools(?string $definition): array {
    if (!$definition) {
      return [];
    }

    try {
      $parsed = Yaml::parse($definition);
      return is_array($parsed) ? $parsed : ['raw' => $definition];
    }
    catch (ParseException $exception) {
      $this->logger()->warning(sprintf('Failed to parse default information tools YAML: %s', $exception->getMessage()));
      return ['raw' => $definition];
    }
  }

  /**
   * Instantiate a tool by plugin ID or function name.
   */
  private function instantiateTool(string $identifier): ExecutableFunctionCallInterface {
    try {
      $tool = $this->functionCallPluginManager->createInstance($identifier);
    }
    catch (PluginException $exception) {
      try {
        $tool = $this->functionCallPluginManager->getFunctionCallFromFunctionName($identifier);
      }
      catch (\Exception $inner) {
        throw new \InvalidArgumentException(sprintf('Unable to load tool "%s" as a plugin ID or function name.', $identifier));
      }
    }

    if (!$tool instanceof ExecutableFunctionCallInterface) {
      throw new \InvalidArgumentException(sprintf('Tool "%s" is not executable.', $identifier));
    }

    return $tool;
  }

  /**
   * Parse CLI-supplied context information and coerce values.
   */
  private function parseContextOptions(ExecutableFunctionCallInterface $tool, ?string $contextJson, array $contextAssignments): array {
    $provided = [];

    if ($contextJson !== NULL) {
      $decoded = json_decode($contextJson, TRUE);
      if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \InvalidArgumentException(sprintf('Failed to decode --context-json payload: %s', json_last_error_msg()));
      }
      if (!is_array($decoded) || array_is_list($decoded)) {
        throw new \InvalidArgumentException('The --context-json option must decode to a JSON object with named properties.');
      }
      $provided = $decoded;
    }

    foreach ($contextAssignments as $assignment) {
      if (!is_string($assignment) || $assignment === '') {
        continue;
      }
      if (!str_contains($assignment, '=')) {
        throw new \InvalidArgumentException(sprintf('Context "%s" must use key=value format.', $assignment));
      }
      [$name, $raw] = explode('=', $assignment, 2);
      $name = trim($name);
      $provided[$name] = $raw;
    }

    $definitions = $tool->getContextDefinitions();
    if (empty($definitions) && !empty($provided)) {
      $this->logger()->warning(sprintf('Tool "%s" does not declare contexts; ignoring provided values.', $tool->getPluginId()));
      return [];
    }

    foreach (array_keys($provided) as $contextName) {
      if (!array_key_exists($contextName, $definitions)) {
        throw new \InvalidArgumentException(sprintf(
          'Unknown context "%s" for tool "%s". Allowed contexts: %s',
          $contextName,
          $tool->getPluginId(),
          implode(', ', array_keys($definitions))
        ));
      }
    }

    $contextValues = [];
    foreach ($definitions as $name => $definition) {
      if (!array_key_exists($name, $provided)) {
        if ($definition->isRequired() && $definition->getDefaultValue() === NULL) {
          throw new \InvalidArgumentException(sprintf('Missing required context "%s" for tool "%s".', $name, $tool->getPluginId()));
        }
        continue;
      }
      $contextValues[$name] = $this->coerceContextValue($provided[$name], $definition->getDataType());
    }

    return $contextValues;
  }

  /**
   * Coerce a value to align with the declared context data type.
   */
  private function coerceContextValue(mixed $value, ?string $dataType = NULL): mixed {
    if (is_string($value)) {
      $trimmed = trim($value);
      $decoded = json_decode($trimmed, TRUE);
      if (json_last_error() === JSON_ERROR_NONE) {
        $value = $decoded;
      }
      else {
        $lower = strtolower($trimmed);
        if ($lower === 'true') {
          $value = TRUE;
        }
        elseif ($lower === 'false') {
          $value = FALSE;
        }
        elseif ($lower === 'null') {
          $value = NULL;
        }
        elseif (is_numeric($trimmed)) {
          $value = str_contains($trimmed, '.') ? (float) $trimmed : (int) $trimmed;
        }
        else {
          $value = $trimmed;
        }
      }
    }

    if ($dataType) {
      $value = $this->castByDataType($value, $dataType);
    }

    return $value;
  }

  /**
   * Cast a scalar value based on a context data type hint.
   */
  private function castByDataType(mixed $value, string $dataType): mixed {
    $dataType = strtolower($dataType);

    return match (TRUE) {
      str_starts_with($dataType, 'bool') => (bool) $value,
      str_starts_with($dataType, 'int') || $dataType === 'integer' => is_array($value) ? $value : (int) $value,
      str_starts_with($dataType, 'float') || $dataType === 'decimal' => is_array($value) ? $value : (float) $value,
      $dataType === 'list' && is_string($value) => array_values(array_filter(array_map('trim', explode(',', $value)), static fn ($item) => $item !== '')),
      default => $value,
    };
  }

  /**
   * Validate tool contexts before execution.
   */
  private function validateToolContexts(ExecutableFunctionCallInterface $tool): void {
    $this->normalizeEntityContextValues($tool);

    $violations = $tool->validateContexts();
    if (!count($violations)) {
      return;
    }

    $messages = [];
    foreach ($violations as $violation) {
      if (!$violation instanceof ConstraintViolationInterface) {
        continue;
      }
      $label = NULL;
      $root = $violation->getRoot();
      if (is_object($root) && method_exists($root, 'getDataDefinition')) {
        try {
          $definition = $root->getDataDefinition();
          if ($definition) {
            $label = $definition->getLabel() ?: $definition->getName();
          }
        }
        catch (\Throwable $e) {
          // Ignore and fall back to property path.
        }
      }
      if ($label === NULL) {
        $label = $violation->getPropertyPath() ?: 'context';
      }
      $messages[] = (string) new FormattableMarkup('Invalid value for @property: @violation', [
        '@property' => $label,
        '@violation' => $violation->getMessage(),
      ]);
    }

    if ($messages) {
      throw new \InvalidArgumentException(implode("\n", $messages));
    }
  }

  /**
   * Ensures entity contexts contain entity objects before validation.
   */
  private function normalizeEntityContextValues(ExecutableFunctionCallInterface $tool): void {
    foreach ($tool->getContextDefinitions() as $context_name => $definition) {
      $data_type = $definition->getDataType();
      $entity_type_id = NULL;

      if (is_string($data_type) && str_starts_with($data_type, 'entity:')) {
        $entity_type_id = substr($data_type, 7);
      }
      elseif ($data_type === 'entity') {
        $plugin_type = $tool->getPluginDefinition()['type'] ?? '';
        if (is_string($plugin_type) && $plugin_type !== '' && $this->entityTypeManager->getDefinition($plugin_type, FALSE)) {
          $entity_type_id = $plugin_type;
        }
      }

      $context = $tool->getContext($context_name);
      if (!$context->hasContextValue()) {
        continue;
      }

      $context_value = NULL;
      try {
        $context_value = $context->getContextValue();
      }
      catch (\Throwable $e) {
        continue;
      }

      $context_data = $context->getContextData();
      if ($context_data instanceof EntityAdapter) {
        $entity = $context_data->getValue();
        if ($entity instanceof EntityInterface) {
          $tool->setContextValue($context_name, $entity);
          continue;
        }
      }

      if ($context_value instanceof EntityInterface) {
        continue;
      }
      $entity = NULL;
      $candidate_id = NULL;

      if (is_array($context_value)) {
        if (isset($context_value['entity']) && $context_value['entity'] instanceof EntityInterface) {
          $entity = $context_value['entity'];
        }
        elseif (isset($context_value['target_id'])) {
          $candidate_id = $context_value['target_id'];
        }
        elseif (isset($context_value[0]) && (is_int($context_value[0]) || is_string($context_value[0]))) {
          $candidate_id = $context_value[0];
        }
      }
      elseif (is_int($context_value) || is_string($context_value)) {
        $candidate_id = $context_value;
      }

      if (!$entity && is_string($candidate_id) && str_contains($candidate_id, ':')) {
        [$maybe_type, $maybe_id] = explode(':', $candidate_id, 2);
        if ($entity_type_id === NULL && $this->entityTypeManager->getDefinition($maybe_type, FALSE)) {
          $entity_type_id = $maybe_type;
        }
        $candidate_id = $maybe_id;
      }

      if (!$entity_type_id || $candidate_id === NULL || $candidate_id === '') {
        continue;
      }

      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      if (!$storage) {
        continue;
      }

      $loaded = $storage->load($candidate_id);
      if ($loaded instanceof EntityInterface) {
        $tool->setContextValue($context_name, $loaded);
      }
    }
  }

  /**
   * Serialize context values for JSON responses.
   */
  private function serializeContextValue(mixed $value): mixed {
    if ($value instanceof EntityInterface) {
      $entry = [
        'entity_type' => $value->getEntityTypeId(),
        'id' => $value->id(),
      ];
      if (method_exists($value, 'label')) {
        $entry['label'] = $value->label();
      }
      return $entry;
    }

    if (is_array($value)) {
      $normalized = [];
      foreach ($value as $key => $item) {
        $normalized[$key] = $this->serializeContextValue($item);
      }
      return $normalized;
    }

    if ($value instanceof \DateTimeInterface) {
      return $value->format(DATE_ATOM);
    }

    if (is_object($value)) {
      return method_exists($value, '__toString') ? (string) $value : get_class($value);
    }

    return $value;
  }

  /**
   * Gather the resolved context values after validation.
   */
  private function collectResolvedContexts(ExecutableFunctionCallInterface $tool): array {
    $resolved = [];
    foreach ($tool->getContextDefinitions() as $name => $definition) {
      $context = $tool->getContext($name);
      if (!$context->hasContextValue()) {
        continue;
      }
      try {
        $value = $context->getContextValue();
      }
      catch (\Throwable $e) {
        continue;
      }
      $resolved[$name] = $this->serializeContextValue($value);
    }
    return $resolved;
  }

}
