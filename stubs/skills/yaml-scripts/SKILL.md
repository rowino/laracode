---
name: yaml-scripts
description: This skill should be used when the user asks to "create a script", "add a setup script", "write a YAML script", "make a laracode script", "add a worktree script", "create an automation script", "define a new command script", or mentions creating, editing, or debugging YAML-based automation scripts for Laracode.
---

# YAML Script Authoring

Create and edit Laracode YAML scripts that automate shell tasks, prompt users for input, call nested scripts, and invoke AI agents.

## Overview

Laracode YAML scripts are declarative automation files that define a sequence of steps. Each script becomes an Artisan command accessible via `laracode <name>`. Scripts live in two locations:

- **Bundled stubs**: `stubs/scripts/` (shipped with Laracode, installed during init)
- **Project scripts**: `.laracode/scripts/` (per-project customizations)

Project scripts override bundled scripts with the same name. Script names derive from directory structure using `:` as separator (e.g., `worktree/add.yaml` becomes `worktree:add`).

## File Structure

Every script YAML file has these top-level keys:

```yaml
name: namespace:action          # Required. Colon-separated, matches file path
description: What this does     # Required for visible scripts
version: 1                      # Schema version (always 1)
hidden: true                    # Optional. Hides from `laracode list`

signature:                      # Optional. CLI arguments/options
  arguments:
    argName: { description: "...", required: false }
  options:
    optName: { description: "...", value_required: true }

variables:                      # Optional. Pre-resolved key-value pairs
  KEY: "literal or {{interpolation}}"

prompts:                        # Optional. Interactive user prompts
  - id: VAR_NAME
    type: text|confirm|select|multiselect|suggest
    label: "Question for user"
    default: "fallback"
    required: true
    bind: argument.argName      # Skip prompt if CLI arg provided

steps:                          # Required. Ordered execution steps
  - id: step-name
    run: "shell command"        # Shell runner (default)
    capture: VAR_NAME           # Store stdout in variable
    condition: "{{X}} == value" # Skip if false
    on_failure: abort|warn|continue
```

## Execution Lifecycle

1. **Variables resolved**: `variables` block interpolated with context
2. **Settings injected**: `{{settings.path.key}}` resolved from `.laracode/settings.json`
3. **Git values injected**: `{{git.currentBranch}}`, `{{git.defaultBranch}}`
4. **Bound prompts filtered**: Prompts with `bind` matching CLI args skip interaction
5. **Prompts displayed**: Remaining prompts shown to user, responses stored as variables
6. **Before hooks**: `before` steps run (abort all if any fail)
7. **Main steps**: `steps` run sequentially
8. **After hooks**: `after` steps run regardless of main outcome

## Variable Interpolation

Use `{{VAR_NAME}}` anywhere in `run`, `condition`, `label`, `default`, and step `variables` blocks.

### Sources

| Syntax | Source | Example |
|--------|--------|---------|
| `{{VAR}}` | Context/prompt/capture | `{{BRANCH_NAME}}` |
| `{{settings.x.y}}` | `.laracode/settings.json` | `{{settings.worktrees.basePath}}` |
| `{{git.currentBranch}}` | Git runtime | Current branch name |
| `{{git.defaultBranch}}` | Git runtime | `main` or `master` |

### Filters

Append `|filter` to transform values: `{{NAME|upper}}`, `{{NAME|lower}}`, `{{NAME|snake}}`, `{{NAME|slug}}`.

### Shell Safety

In shell steps, interpolated values are automatically escaped via `escapeshellarg()`. Use `{{VAR|raw}}` to bypass escaping when the value is trusted (e.g., paths from capture).

## Step Types

### Shell Steps (default runner)

```yaml
- id: install-deps
  run: composer install --no-interaction
  on_failure: warn
```

Shell commands execute via `proc_open` with all variables available as environment variables. Both `$VAR` (shell env) and `{{VAR}}` (pre-interpolated) work in `run` blocks. Prefer `$VAR` for values that might contain spaces or special characters since shell handles quoting naturally.

### Nested Script Steps

```yaml
- id: run-shared-setup
  runner: script
  script: worktree:shared-setup
  variables:
    WORKTREE_PATH: "{{TARGET_PATH}}"
  condition: "{{skip_setup}} != 1"
  on_failure: warn
```

Invokes another YAML script by name. Child inherits parent variables plus any overrides in `variables`. Circular calls detected and throw RuntimeException.

### AI Agent Steps

```yaml
- id: analyze-code
  runner: ai
  prompt: "Review the code in {{FILE_PATH}} for security issues"
  mode: plan                    # plan|interactive|auto (default: interactive)
  agent: claude                 # Optional. Agent name from registry
  output_format: json           # Optional. Passed as --output-format
```

Launches an AI agent session. The prompt is interpolated with all available variables. Output captured in StepResult for use with `capture`.

## Prompts

Interactive prompts collect user input before steps execute. Each prompt type maps to a Laravel Prompts function.

```yaml
prompts:
  - id: BRANCH_NAME
    type: text
    label: "Branch name"
    required: true
    bind: argument.branch       # Pre-fill from CLI argument

  - id: CONFIRM_DELETE
    type: confirm
    label: "Delete worktree {{name}}?"
    default: false

  - id: ENV_TYPE
    type: select
    label: "Environment"
    options:
      - { label: "Production", value: "prod" }
      - { label: "Staging", value: "staging" }
      - "development"           # String shorthand (value = label)
    default: "development"

  - id: FEATURES
    type: multiselect
    label: "Enable features"
    options: ["auth", "api", "queue"]
    default: ["auth"]

  - id: DB_NAME
    type: suggest
    label: "Database name"
    options: ["myapp_dev", "myapp_test"]
    default: "myapp_dev"
```

### Bind

The `bind` key links a prompt to a CLI argument or option. If the bound value is present when the command runs, the prompt is skipped and the value used directly.

- `bind: argument.name` — binds to positional argument
- `bind: option.source` — binds to `--source` option

## Conditions

Steps can be conditionally skipped with the `condition` key.

```yaml
condition: "{{SKIP_SETUP}} != 1"
condition: "{{ENV}} == production"
condition: "{{ENABLED}}"            # Truthy check (non-empty, not "false"/"0")
```

Operators: `==` (equals), `!=` (not equals). Both sides trimmed of quotes. Without an operator, the value is evaluated as boolean.

## Capture

Store a step's stdout in a variable for use by later steps.

```yaml
- id: detect-manager
  run: |
    if [ -f "pnpm-lock.yaml" ]; then echo "pnpm"
    elif [ -f "yarn.lock" ]; then echo "yarn"
    else echo "npm"
    fi
  capture: PKG_MANAGER

- id: install
  run: '$PKG_MANAGER install'
```

Captured values are trimmed. The variable is available to all subsequent steps both as `{{PKG_MANAGER}}` (interpolated) and `$PKG_MANAGER` (shell env).

## Failure Handling

Each step has an `on_failure` strategy:

| Value | Behavior |
|-------|----------|
| `abort` | Stop execution immediately (default) |
| `warn` | Log warning, mark step as skipped, continue |
| `continue` | Record failure but keep going |

## Before / After Hooks

```yaml
before:
  - id: check-git
    run: git rev-parse --is-inside-work-tree
    on_failure: abort

steps:
  - id: main-work
    run: echo "doing work"

after:
  - id: cleanup
    run: rm -f /tmp/lockfile
```

`before` runs first; if any step fails with `abort`, main steps and after hooks are skipped. `after` runs after main steps regardless of success/failure.

## Settings Integration

Scripts read from `.laracode/settings.json` using dotted paths:

```yaml
variables:
  BASE_PATH: "{{settings.worktrees.basePath}}"
  SHARED_DIRS: "{{settings.worktrees.sharedDirs}}"
  SETUP_SCRIPTS: "{{settings.worktrees.setupScripts}}"
```

If a settings key doesn't exist, the `{{settings.x.y}}` placeholder remains as-is. Use shell guards to detect unresolved placeholders:

```yaml
- id: resolve-dirs
  run: |
    if echo "$SHARED_DIRS" | grep -qF '{{'; then
      echo ""
    else
      echo "$SHARED_DIRS"
    fi
  capture: RESOLVED_DIRS
```

## Bundled Script Stubs

These stubs ship with Laracode in `stubs/scripts/`:

### Worktree Management (`worktree/`)
- **worktree:add** — Create worktree, run shared setup + configured setup scripts
- **worktree:delete** — Remove worktree with optional branch cleanup
- **worktree:list** — List worktrees with colored status display
- **worktree:shared-setup** — Symlink shared dirs (vendor, node_modules) across worktrees (hidden)

### Setup Scripts (`setup/`)
- **setup:composer** — Run `composer install` (hidden)
- **setup:node** — Auto-detect pnpm/yarn/npm, install + build (hidden)
- **setup:env-copy** — Copy and configure `.env` files per settings (hidden)
- **setup:migrate** — Run `php artisan migrate` (hidden)

Setup scripts are designed to be referenced by name in `settings.worktrees.setupScripts` and called by `worktree:add` after worktree creation.

## Authoring Guidelines

1. **Use descriptive step IDs** — `install-deps` not `step-1`. IDs appear in progress output.
2. **Guard unresolved settings** — Check for `{{` in values before using them.
3. **Use `on_failure: warn`** for non-critical steps (setup, cleanup).
4. **Use `on_failure: abort`** for prerequisites (git check, file existence).
5. **Prefer `$VAR` in shell** — Shell environment variables handle quoting naturally. Use `{{VAR}}` for conditions, labels, and non-shell contexts.
6. **Mark helper scripts `hidden: true`** — Scripts called by others shouldn't appear in `laracode list`.
7. **Capture early, use late** — Resolve computed values in early steps with `capture`, reference in later steps.
8. **Keep steps atomic** — One concern per step. Easier to debug, skip, and reorder.
9. **Use nested scripts** for reusable workflows — Extract common logic into hidden scripts called with `runner: script`.

## Complete Reference

See `./references/yaml-script-format.md` for the exhaustive field-by-field specification with all edge cases and advanced patterns.
