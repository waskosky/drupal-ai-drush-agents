# AI Drush Agents

AI Drush Agents extends the [AI Agents](https://www.drupal.org/project/ai_agents) ecosystem with Drush command wrappers and privileged helper tools. It lets you launch configured agents directly from the terminal, provides canned agents for explaining config exports or Drush usage, and exposes a catalog of function-call plugins that agents can invoke to inspect and manipulate Drupal configuration safely.

## Requirements

- Drupal core 10.3+ or 11.x
- `ai_agents` module (hard dependency)
- Drush 11 or newer (attribute-based commandfiles)
- CLI access to the Drupal root; agents run best via authenticated Drush aliases

## Installation

1. Place the module under `web/modules/custom/ai_drush_agents`.
2. Ensure the `ai_agents` module and your preferred AI provider plugins are enabled.
3. Enable the module: `drush en ai_drush_agents -y`.
4. Grant trusted administrators the `administer site configuration` permission—the bundled tools enforce that permission before touching config, schema, or temp storage.

Once enabled, create/publish AI Agent entities (via the `ai_agents` UI) that reference the provided tools or run the built-in agents described below.

## Provided Drush Commands

| Command | Alias | Summary |
| --- | --- | --- |
| `drush agents:run [agent_id] [initial_prompt]` | `agent` | Launch any AI Agent entity from the CLI. Supports interactive selection, initial prompts via args or STDIN (`--stdin`), spinner control (`--no-spinner`), and output modes (`--output=cli|markdown|json` with optional `--with-history`). |
| `drush agents:config_export_explain` | `agecex` | Executes the `config_export_explainer` agent to summarize the delta between active config and the sync directory. Helpful after `drush cex`. |
| `drush agents:drush_explain` | `agedre` | Prompts for a task and asks the `drush_explain_agent` to suggest Drush commands, rendering Markdown nicely in the terminal. |

All commands temporarily impersonate user 1 via the entity type manager to ensure the agent has sufficient access. Errors are logged through Drupal’s logger so watchdog/syslog capture failures.

## AI Function Call Plugins

Configure your AI Agent entities to call any of the bundled tools (function IDs shown below):

- `ai_drush_agents:config_diff` – Compare active vs. staged configuration, listing created/deleted/updated items plus inline diffs.
- `ai_agent:get_config_by_id` – Return JSON for a specific configuration object (accepts IDs with or without `.yml`).
- `ai_agent:get_config_entity` – Load either a config entity (`entity_type` + `entity_id`) or plain config and return YAML. Includes access checks.
- `ai_drush_agents:get_drush_commands` – Dump the Drush command registry so agents can search available commands.
- `ai_drush_agents:get_drush_code` – Read the PHP source for a Drush command class (validates CLI context and permissions).
- `ai_agent:load_temporary_data` / `ai_agent:save_temporary_data` – Store short-lived snippets (scoped per user, expiring after 24 hours) in a key/value backend for multi-step tool flows.
- `ai_agent:save_schema_file` – Persist YAML stored via the temp-data tools into a module’s `config/schema` directory after validating filename, module path, and YAML syntax.

Each tool enforces the `administer site configuration` permission and performs additional safety checks (e.g., key normalization, module/filename validation) to prevent cross-user leakage or filesystem traversal.

## Usage Notes

- **Agent catalog:** Create AI Agent config entities via the `ai_agents` UI or config YAML, then refer to them by machine ID when running `drush agents:run`.
- **Output formats:** When using `--output=json`, set `--with-history` to include both the latest assistant response and the entire chat transcript for downstream automation.
- **STDIN prompts:** Pipe scripts into `drush agents:run --stdin` to automate prompts (`printf 'Status report' | drush agents:run support_agent --stdin`).
- **Markdown rendering:** The interactive commands use `SevenEcks\Markdown\MarkdownTerminal` to render Markdown, so headings, lists, and tables display cleanly in most terminals.

## Development & Testing

- Follow Drupal coding standards (PSR-4 classes under `src/` and annotations for Drush attributes).
- Run unit tests from the Drupal root to avoid browser-output warnings: `cd web && ../vendor/bin/phpunit -c core modules/custom/ai_drush_agents/tests` (add suites as the module grows).
- After changing PHP code, rebuild caches with `drush cr` so new command definitions and plugins register correctly.
- Log liberally when extending this module—agents rely on watchdog entries for troubleshooting.

## Troubleshooting

- `drush list agents:*` confirms the commands are registered.
- Permission errors usually mean the Drush user lacks `administer site configuration`; adjust roles or run as an account with that permission.
- Schema writes require the destination module to exist locally. Verify module machine names with `drush pm:list --status=enabled` if saves fail.

This README should give site builders and developers enough context to enable the module, expose its Drush commands, and wire the AI function-call plugins into their own agents.
