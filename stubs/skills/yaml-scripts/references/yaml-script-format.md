# YAML Script Format Reference

Complete field-by-field specification for Laracode YAML scripts.

## Top-Level Keys

### `name` (string, required)

Colon-separated identifier matching the file's directory path. `worktree/add.yaml` → `worktree:add`. The ScriptLoader derives this automatically from the file path, but it must be present in the YAML.

```yaml
name: worktree:add
name: setup:composer
name: deploy:staging
```

### `description` (string, recommended)

Human-readable description displayed in `laracode list` output and help text. Omit only for hidden scripts.

```yaml
description: Create a new git worktree with setup flows
```

### `version` (integer, default: 1)

Schema version. Always `1` for current format.

### `hidden` (boolean, default: false)

When `true`, the script does not appear in `laracode list` or help output. Use for helper scripts invoked by other scripts via `runner: script`.

```yaml
hidden: true
```

### `signature` (object, optional)

Defines CLI arguments and options for the script command.

#### `signature.arguments` (object)

Positional arguments. Each key is the argument name.

```yaml
signature:
  arguments:
    branch:
      description: "Branch name for the worktree"
      required: false
    name:
      description: "Name of the resource"
      required: true
```

Fields:
- `description` (string) — Help text
- `required` (boolean, default: false) — Whether the argument is mandatory

#### `signature.options` (object)

Named options (flags). Each key becomes a `--key` option.

```yaml
signature:
  options:
    folder:
      description: "Folder name for the worktree"
      value_required: true
    force:
      description: "Force removal even if dirty"
    skip-setup:
      description: "Skip running setup flows"
    auto:
      description: "Run setup flows without prompts"
```

Fields:
- `description` (string) — Help text
- `value_required` (boolean, default: false) — When `true`, the option expects a value (`--folder=name`). When `false`, the option is a boolean flag (`--force`).

Options are available as variables with hyphens converted to underscores: `--skip-setup` → `$skip_setup` / `{{skip_setup}}`. Boolean flags have value `"1"` when present, empty string when absent.

## `variables` (object, optional)

Key-value pairs resolved before prompts and steps. Values support interpolation from settings, git, and previously defined variables.

```yaml
variables:
  BASE_PATH: "{{settings.worktrees.basePath}}"
  SHARED_DIRS: "{{settings.worktrees.sharedDirs}}"
  APP_NAME: "my-app"
  FULL_PATH: "{{BASE_PATH}}/{{APP_NAME}}"
```

Resolution order:
1. Context variables passed to the executor (arguments, options, parent script variables)
2. Variables defined earlier in the `variables` block (sequential resolution)
3. Settings values via `{{settings.dotted.path}}`
4. Git values via `{{git.currentBranch}}` and `{{git.defaultBranch}}`

Unresolved placeholders remain as literal `{{...}}` strings. Guard against them in shell:

```yaml
- id: check
  run: |
    if echo "$MY_VAR" | grep -qF '{{'; then
      echo "Not configured"
      exit 0
    fi
```

## `prompts` (list, optional)

Interactive prompts displayed before step execution. Each prompt collects a value stored as a variable with the prompt's `id` as key.

### Common Fields

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `id` | string | yes | Variable name for the response |
| `type` | string | yes | One of: `text`, `confirm`, `select`, `multiselect`, `suggest` |
| `label` | string | yes | Question displayed to user. Supports `{{interpolation}}` |
| `default` | mixed | no | Default value. Supports `{{interpolation}}` including settings/git |
| `required` | boolean | no | For `text` type. Default: `true` |
| `bind` | string | no | CLI binding (see below) |
| `options` | list | no | For `select`, `multiselect`, `suggest` |

### Prompt Types

#### `text`

Free-form text input.

```yaml
- id: BRANCH_NAME
  type: text
  label: "Branch name"
  required: true
  default: "feature/new"
```

#### `confirm`

Yes/no boolean prompt. Returns `true` or `false`.

```yaml
- id: CONFIRM_DELETE
  type: confirm
  label: "Delete worktree {{name}}?"
  default: false
```

#### `select`

Single choice from a list. Options can be strings or `{label, value}` objects.

```yaml
- id: ENV
  type: select
  label: "Target environment"
  options:
    - { label: "Production", value: "prod" }
    - { label: "Staging", value: "staging" }
    - "development"
  default: "development"
```

String options use the same value for both label and value.

#### `multiselect`

Multiple choices. Returns a list of selected values.

```yaml
- id: FEATURES
  type: multiselect
  label: "Enable features"
  options: ["auth", "api", "queue", "websockets"]
  default: ["auth", "api"]
```

#### `suggest`

Text input with autocomplete suggestions.

```yaml
- id: DB_NAME
  type: suggest
  label: "Database name"
  options: ["myapp_dev", "myapp_test", "myapp_staging"]
  default: "myapp_dev"
```

### `bind`

Links a prompt to a CLI argument or option. When the bound value is provided on the command line, the prompt is skipped entirely and the CLI value is used.

```yaml
- id: BRANCH_NAME
  type: text
  label: "Branch name"
  bind: argument.branch     # Skipped if `laracode worktree:add my-branch`

- id: SOURCE_BRANCH
  type: text
  label: "Source branch"
  bind: option.source       # Skipped if `laracode worktree:add --source=main`
```

Binding checks:
- `argument.<name>` — Uses value if non-null and non-empty
- `option.<name>` — Uses value if non-null, non-false, and non-empty

### Default Interpolation

Prompt defaults support all interpolation sources:

```yaml
- id: SOURCE
  type: text
  label: "Source branch"
  default: "{{settings.worktrees.defaultSourceBranch}}"

- id: CURRENT
  type: text
  label: "Current branch"
  default: "{{git.currentBranch}}"
```

## `steps` (list, required)

Ordered sequence of execution steps. At least one step is required.

### Step Fields

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `id` | string | recommended | `step_N` | Unique identifier. Shown in progress output |
| `run` | string | yes* | — | Shell command to execute |
| `runner` | string | no | `"shell"` | Runner type: `shell`, `script`, `ai` |
| `capture` | string | no | — | Variable name to store stdout |
| `condition` | string | no | — | Skip step if evaluates to false |
| `on_failure` | string | no | `"abort"` | Failure strategy: `abort`, `warn`, `continue` |
| `variables` | object | no | — | Variable overrides (for `runner: script`) |

*Required for `shell` runner. Other runners use different fields.

### Shell Runner Steps

The default runner. Executes shell commands via `proc_open`.

```yaml
- id: install-deps
  run: composer install --no-interaction
  on_failure: warn

- id: create-dir
  run: 'mkdir -p "$BASE_PATH"'

- id: multiline-script
  run: |
    if [ -f "composer.json" ]; then
      composer install --no-interaction
    else
      echo "No composer.json" >&2
      exit 1
    fi
  on_failure: abort
```

**Variable access in shell**: All variables are passed as environment variables to the shell process. Use `$VAR_NAME` for shell access. The `{{VAR}}` syntax is pre-interpolated before the command is passed to the shell — values are escaped via `escapeshellarg()` by default.

**Working directory**: Defaults to `getcwd()`. If `WORKTREE_PATH` variable is set, uses that instead. This allows nested scripts to operate in a different directory.

**Output**: Stdout and stderr are captured separately. Stdout is stored in `StepResult.output`, stderr in `StepResult.error`. Both are displayed to the user via the output callback (stdout directly, stderr via Termwind).

### Script Runner Steps

Invokes another YAML script by name.

```yaml
- id: shared-setup
  runner: script
  script: worktree:shared-setup
  variables:
    WORKTREE_PATH: "{{TARGET_PATH}}"
    BASE_PATH: "{{BASE_PATH}}"
  condition: "{{skip_setup}} != 1"
  on_failure: warn
```

Fields:
- `runner: script` — Required to select the script runner
- `script` (string) — Name of the script to invoke (must exist in bundled or project scripts)
- `variables` (object) — Override/add variables for the child script. Values support `{{interpolation}}`

The child script:
- Inherits all parent variables
- Gets overrides from the `variables` block
- Runs its own prompts, before, steps, and after phases
- Returns combined stdout from all its steps

Circular call detection: If script A calls B which calls A, a RuntimeException is thrown.

### AI Runner Steps

Launches an AI agent session.

```yaml
- id: review-code
  runner: ai
  prompt: "Review {{FILE_PATH}} for security vulnerabilities"
  mode: plan
  agent: claude
  output_format: json
```

Fields:
- `runner: ai` — Required to select the AI runner
- `prompt` (string) — Prompt text. Supports `{{interpolation}}`
- `mode` (string, default: `"interactive"`) — Agent mode: `plan`, `interactive`, `auto`
- `agent` (string, optional) — Agent name from the registry. Uses default agent if omitted
- `output_format` (string, optional) — Passed as `--output-format` to the agent CLI

## `before` / `after` (list, optional)

Hook steps that run before/after the main `steps` block. Same step format as `steps`.

```yaml
before:
  - id: check-git
    run: |
      if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
        echo "Not a git repository" >&2
        exit 1
      fi
    on_failure: abort

  - id: check-clean
    run: |
      if [ -n "$(git status --porcelain)" ]; then
        echo "Working directory not clean" >&2
        exit 1
      fi
    on_failure: abort

steps:
  - id: do-work
    run: echo "Main work here"

after:
  - id: cleanup
    run: rm -f /tmp/my-lockfile
    on_failure: continue

  - id: notify
    run: echo "Done!"
```

**Before**: If any `before` step fails with `on_failure: abort`, all main steps and after hooks are skipped. The script returns failure.

**After**: Always runs after main steps, regardless of main step success/failure. Useful for cleanup.

## Condition Evaluation

The `condition` field is evaluated by `ConditionEvaluator`. Variables are interpolated first, then the expression is evaluated.

### Comparison Operators

```yaml
condition: "{{ENV}} == production"     # Equals
condition: "{{SKIP}} != 1"            # Not equals
```

Both operands are trimmed of whitespace and quotes before comparison. String comparison only (no numeric).

### Boolean Evaluation

Without an operator, the value is checked for truthiness:

```yaml
condition: "{{ENABLED}}"              # True if non-empty, not "false", not "0"
condition: "{{DEBUG}}"                # True if variable is truthy
```

Falsy values: empty string, `"false"`, `"0"`.

### Unresolved Variables

If a variable in the condition isn't resolved, the `{{...}}` literal remains. This means `"{{UNDEFINED}} == value"` will compare the literal string `"{{UNDEFINED}}"` against `"value"` — which is always false. Use this pattern to detect unconfigured settings:

```yaml
condition: "{{settings.feature.enabled}} == true"
# If settings key doesn't exist, condition is "{{settings.feature.enabled}} == true" → false
```

## Interpolation Details

### `Interpolator.interpolate()`

Standard interpolation used in `variables`, `label`, `default`, step `variables`, and `condition`.

Pattern: `{{KEY}}` or `{{KEY|filter}}`

Supported filters:
- `upper` — UPPERCASE
- `lower` — lowercase
- `snake` — snake_case (splits on non-alphanumeric, inserts underscores at camelCase boundaries)
- `slug` — kebab-case (splits on non-alphanumeric, lowercases)

### `Interpolator.interpolateForShell()`

Used for `run` commands in shell steps. Same pattern matching but values are wrapped in `escapeshellarg()`.

Special filter: `raw` — Bypasses `escapeshellarg()`. Use for paths and values you control:

```yaml
- id: cd-target
  run: 'cd {{TARGET_PATH|raw}} && ls -la'
```

### Settings Interpolation

Pattern: `{{settings.dotted.path}}` — resolved from `.laracode/settings.json`. Nested keys accessed via dots:

```json
{
  "worktrees": {
    "basePath": "../worktrees",
    "sharedDirs": "vendor node_modules"
  }
}
```

```yaml
variables:
  BASE: "{{settings.worktrees.basePath}}"        # → "../worktrees"
  DIRS: "{{settings.worktrees.sharedDirs}}"       # → "vendor node_modules"
```

### Git Interpolation

Two built-in git variables:

| Variable | Description |
|----------|-------------|
| `{{git.currentBranch}}` | Current HEAD branch name |
| `{{git.defaultBranch}}` | Repository default branch (main/master) |

## Failure Handling Strategies

### `abort` (default)

Stop execution immediately. No further steps run. The script returns failure.

```yaml
- id: prerequisite
  run: test -f composer.json
  on_failure: abort   # Stops everything if composer.json missing
```

### `warn`

Mark the step as skipped (success=true, skipped=true), log the error, and continue to the next step.

```yaml
- id: optional-build
  run: npm run build
  on_failure: warn    # Build failure doesn't block the rest
```

### `continue`

Record the failure (success=false) but continue executing subsequent steps.

```yaml
- id: non-critical
  run: echo "might fail"
  on_failure: continue  # Failure recorded but execution continues
```

## Script Discovery

`ScriptLoader` discovers scripts from two directories:

1. **Bundled**: `stubs/scripts/` (relative to Laracode install)
2. **Project**: `.laracode/scripts/` (relative to project root)

Project scripts take precedence over bundled scripts with the same name. This allows users to override bundled behavior.

### Naming Convention

File path → script name:
- `stubs/scripts/worktree/add.yaml` → `worktree:add`
- `stubs/scripts/setup/composer.yaml` → `setup:composer`
- `.laracode/scripts/deploy/staging.yaml` → `deploy:staging`

Both `.yaml` and `.yml` extensions are supported.

## Complete Example

A script that creates a database backup before running migrations:

```yaml
name: db:safe-migrate
description: Backup database then run migrations
version: 1

signature:
  options:
    skip-backup: { description: "Skip backup step" }

variables:
  BACKUP_DIR: "./storage/backups"
  TIMESTAMP: ""

prompts:
  - id: CONFIRM
    type: confirm
    label: "Run migrations on {{git.currentBranch}}?"
    default: true

steps:
  - id: check-artisan
    run: test -f artisan
    on_failure: abort

  - id: get-timestamp
    run: date +%Y%m%d_%H%M%S
    capture: TIMESTAMP

  - id: backup
    run: |
      mkdir -p "$BACKUP_DIR"
      php artisan db:dump --path="$BACKUP_DIR/backup_$TIMESTAMP.sql"
    condition: "{{skip_backup}} != 1"
    on_failure: warn

  - id: migrate
    run: php artisan migrate --no-interaction
    on_failure: abort

after:
  - id: status
    run: php artisan migrate:status
    on_failure: continue
```

## Anti-Patterns

1. **Don't use `{{VAR}}` for values with spaces in shell `run` blocks** — Use `$VAR` instead. Shell handles quoting via environment variables. `{{VAR}}` with spaces gets escaped as a single argument which may not be what you want.

2. **Don't hardcode paths** — Use `{{settings.x.y}}` or `capture` from a resolution step. Makes scripts portable.

3. **Don't skip the `id` field** — Auto-generated IDs (`step_0`, `step_1`) are unhelpful in progress output and error messages.

4. **Don't use `on_failure: abort` for cleanup steps** — Cleanup should always run. Use `continue` or `warn`.

5. **Don't nest scripts more than 2 levels deep** — Deeply nested scripts are hard to debug. Flatten when possible.

6. **Don't ignore unresolved `{{settings.*}}`** — Always guard with `grep -qF '{{'` or a condition check. Unresolved placeholders passed to shell commands cause unexpected behavior.
