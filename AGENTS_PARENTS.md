# AI Drush Agents - CLI Companion for Coding Agents

This module exposes Drupal AI agents and their tools through Drush so that a command-line driven coding agent (or any automation) can select, inspect, and run agents without using a browser. This guide summarizes the conventions and flags an autonomous CLI agent should understand to operate safely and predictably.

## 1. Discover the available agents

List all configured agents with compact metadata:

```bash
drush agents:list
```

Helpful variants:

- `drush agents:list --format=json` - machine-friendly payload with `id`, `label`, `description`, and the tool identifiers enabled for each agent.
- `drush agents:list --format=yaml` - same data but easier for humans to review inline.

## 2. Inspect a single agent before execution

Use the detail command to understand prompts, tool limits, and other execution settings:

```bash
drush agents:info <agent_id> --format=json
```

The JSON structure contains:

- `system_prompt` and `secured_system_prompt`
- `tools`, `tool_settings`, `tool_usage_limits`
- Loop limits and orchestration flags
- Parsed `default_information_tools`, if defined

Always read this detail before running an unfamiliar agent so you can confirm which tools it may call.

## 3. Run an agent non-interactively

Syntax for a single-shot interaction:

```bash
drush agents:run <agent_id> "<prompt>" --output=json --with-history
```

Key flags for automation:

- `--output=cli|markdown|json` (default: `cli`)
  - `cli` - Markdown rendered for terminals (same output as before).
  - `markdown` - Raw model response, untouched.
  - `json` - Structured payload; ideal for downstream parsing.
- `--with-history` - Include every message exchanged so far. Only affects JSON output.
- `--no-spinner` - Suppress spinner/progress messages so stdout contains only the response.
- `--stdin` - Read the prompt from STDIN when no positional prompt argument is provided. Ensure you pipe or redirect content to avoid blocking.

Example using STDIN:

```bash
echo "Summarize the config export" | drush agents:run config_export_explainer --stdin --output=json --no-spinner
```

### JSON response schema

When `--output=json` is used, stdout contains a single JSON document:

```json
{
  "agent_id": "config_export_explainer",
  "response": "<raw markdown returned by the agent>",
  "history": [
    { "role": "user", "content": "..." },
    { "role": "assistant", "content": "..." }
  ],
  "tool_results": [
    {
      "class": "Drupal\\ai_drush_agents\\Plugin\\AiFunctionCall\\GetDrushCommands",
      "plugin_id": "ai_drush_agents:get_drush_commands",
      "function_name": "ai_drush_agent_get_drush_commands",
      "output": "..."
    }
  ]
}
```

`history` is optional (requires `--with-history`). `tool_results` lists any Tools the agent invoked during the run so that a controller agent can make follow-up decisions.

## 4. Managing multi-turn sessions

Interactive sessions (`drush agents:run` without positional prompt) still work for humans, but coding agents should prefer stateless single calls to keep control over conversation state. If you must resume context:

1. Capture the JSON history from the prior call.
2. Reconstruct the conversation client-side.
3. Send the next user message as the prompt in a new invocation.

This avoids relying on the interactive CLI loop, which expects manual input.

## 5. Working with tools

Agents may trigger AI function calls (tools) defined in this module or others. You can now exercise those tools directly from the CLI when you do not need the full agent runtime.

### 5.1 Call a tool via an agent

Use `drush agents:run ... --output=json` and read the `tool_results` array (see Section 3) to observe which tools ran and what they returned.

### 5.2 Call a tool directly

List the registered tools when you need to discover IDs or groups:

- `drush agents:tools` - compact table of every tool with group, label, and function name.
- `drush agents:tools --group=information_tools --format=json` - filter and emit machine-friendly metadata.

Inspect a specific tool before calling it:

- `drush agents:tool-info ai_agent:get_config_by_id --format=json` - view context schema, constraints, and module dependencies.

Run a specific tool (by plugin ID or function name) with `agents:tool`:

```bash
drush agents:tool ai_agent:get_config_by_id --context=config_id=system.site --output=json
```

Supported options:

- `--context=<name>=<value>` - Supply individual context values. Repeat the option for multiple entries.
- `--context-json='{"name":"value"}'` - Provide all contexts as a single JSON object. Pass `--context-json=-` to read the JSON payload from STDIN.
- `--output=text|json` - `json` returns a payload with `tool_id`, `function_name`, `output`, the sanitized execution result, and both the provided/resolved contexts.

Contexts are validated against the tool definition. If a required context is missing or invalid the command fails with a descriptive error. Basic type coercion is handled automatically (`true/false`, integers, floats, comma-delimited lists), but complex values should be supplied through `--context-json`.

Most tools expect elevated permissions; the command temporarily masquerades as user 1 (matching the agent runtime) before execution.

## 6. Additional useful commands

Beyond the generic runner, the module ships specialized commands:

- `drush agents:config_export_explain` - Run the config export explainer agent with a built-in system prompt.
- `drush agents:drush_explain` - Ask for drush command recommendations.

These still benefit from the options above when run through `agents:run`, but they remain available for convenience.

## 7. Safety checklist for automation

1. Always inspect agent metadata before execution.
2. Prefer `--output=json --no-spinner` on agents to simplify parsing.
3. Use `agents:tool` only when you can supply every required context deterministically; the command will not prompt for missing data.
4. Capture stderr separately; errors and warnings are logged there.
5. Timeouts and retries should be implemented by the calling controller since the command itself will block until the work completes.
6. Keep the Drush binary version in sync with this module so attribute-based options remain compatible.

Armed with these conventions, a CLI-only coding agent can reliably explore available agents, understand their capabilities, invoke the tools they expose, and track conversational or tool-specific context without leaving the terminal.
