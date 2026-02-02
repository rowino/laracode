# LaraCode

An autonomous build system for Laravel projects using Claude AI. LaraCode breaks down features into structured tasks and executes them sequentially with dependency resolution.

## Features

- **Task Generation**: AI-powered breakdown of feature requirements into structured tasks
- **Dependency Resolution**: Executes tasks in correct order based on dependencies
- **Progress Tracking**: Visual progress display with blocked task detection
- **Cliff Notes**: Accumulated context passed between task executions
- **Watch Mode**: Real-time file monitoring for `@ai` comments with automatic Claude processing
- **Multiple Permission Modes**: yolo (auto-approve), accept (interactive), or default

## Installation

```bash
composer global require laracode/laracode
```

Or clone and install locally:

```bash
git clone https://github.com/rowino/laracode.git
cd laracode/laracode-cli
composer install
```

## Quick Start

### 1. Initialize a Project

```bash
cd your-laravel-project
laracode init
```

This creates:
- `.laracode/` directory
- `.laracode/specs/example/tasks.json` - Sample task file
- `.laracode/watch.json` - Watch configuration
- `.claude/commands/build-next.md` - Task execution command
- `.claude/commands/process-comments.md` - Comment processing command
- `.claude/skills/generate-tasks/SKILL.md` - Task generation skill
- `.claude/scripts/statusline.php` - Status display script
- `.claude/hooks/session-start.php` - Session hook
- `.claude/settings.local.json` - Claude configuration

## Watch Mode

Monitor files for `@ai` comments and automatically trigger Claude processing.

### Installation

Watch mode requires Node.js with chokidar:

```bash
npm install chokidar
```

### Usage

```bash
laracode watch
```

Options:
- `--paths=*` - Directories to watch (default: app/, routes/, resources/)
- `--search-word=@ai` - Comment marker to search for
- `--stop-word=ai!` - Trigger word to start processing
- `--mode=interactive` - Permission mode: `yolo`, `accept`, `interactive`
- `--config=` - Path to watch.json config
- `--exclude=*` - Patterns to exclude

### How It Works

1. Add `@ai` comments in your code describing what you need
2. Include the stop word `ai!` when ready to process
3. LaraCode extracts all comments and invokes Claude

### Example

```php
// @ai Create a method to validate email addresses
// @ai Should return boolean and throw InvalidEmailException
// ai!
```

### Configuration

Create `.laracode/watch.json` for persistent settings (config values override CLI defaults):

```json
{
    "paths": ["app/", "routes/", "resources/"],
    "searchWord": "@ai",
    "stopWord": "ai!",
    "mode": "interactive",
    "excludePatterns": ["**/vendor/**", "**/node_modules/**"]
}
```

### Supported File Types

PHP, JavaScript/TypeScript, Python, Ruby, HTML, Vue, Svelte, CSS/SCSS, SQL, Shell/Bash, YAML, Go, Rust, Java, Kotlin, Scala, C/C++, Blade templates

## Quick Start (continued)

### 2. Generate Tasks

Use the `/generate-tasks` command in Claude to generate tasks from a conversation:

```
/generate-tasks
```

Or provide a spec file path:

```
/generate-tasks .laracode/specs/my-feature/spec.md
```

### 3. Run the Build Loop

```bash
laracode build .laracode/specs/my-feature/tasks.json
```

Options:
- `--iterations=100` - Maximum number of tasks to execute (default: 100)
- `--delay=3` - Seconds between tasks (default: 3)
- `--mode=yolo` - Permission mode: `yolo`, `accept`, or `default`

## Commands

### `laracode init`

Initializes LaraCode in the current project, creating necessary files and directories.

### `laracode watch`

Monitors files for `@ai` comments and triggers Claude processing when the stop word is detected.

```bash
# Watch with default settings
laracode watch

# Watch specific paths
laracode watch --paths=app/Models --paths=app/Services

# Use custom markers
laracode watch --search-word=@claude --stop-word=claude!

# Auto-approve all Claude actions
laracode watch --mode=yolo

# Use config file
laracode watch --config=.laracode/watch.json
```

### `laracode build <path>`

Runs the autonomous build loop using the specified tasks.json file.

```bash
# Run with default settings
laracode build .laracode/specs/feature/tasks.json

# Auto-approve all Claude actions
laracode build .laracode/specs/feature/tasks.json --mode=yolo

# Interactive approval for edits only
laracode build .laracode/specs/feature/tasks.json --mode=accept

# Limit to 10 tasks
laracode build .laracode/specs/feature/tasks.json --iterations=10
```

## Task Generation with `/generate-tasks`

The `/generate-tasks` skill generates structured task files from conversations or spec files.

### Usage

In Claude, describe your feature and run:

```
I need a user authentication system with:
- Registration with email verification
- Login/logout
- Password reset
- Remember me functionality

/generate-tasks
```

### What It Creates

1. **spec.md** - Feature specification with requirements and acceptance criteria
2. **tasks.json** - Structured task breakdown with dependencies

### Task Granularity

The skill adjusts task count based on complexity:

| Complexity | Task Count | Example |
|------------|------------|---------|
| Simple | 5-8 tasks | Login form, settings page |
| Medium | 10-20 tasks | User auth system, CRUD module |
| Complex | 30-100+ tasks | Multi-tenant platform |

### Smart Grouping

Related changes to the same file are combined:

**Good:**
```
"Create User model with name, email, password fields"
```

**Bad (too granular):**
```
"Add name to User"
"Add email to User"
"Add password to User"
```

## tasks.json Schema

```json
{
  "title": "Feature Name",
  "branch": "feature/feature-slug",
  "created": "2026-01-12T10:00:00Z",
  "tasks": [
    {
      "id": 1,
      "title": "Short task title",
      "description": "Detailed description of what needs to be done",
      "steps": [
        "Specific step 1",
        "Specific step 2"
      ],
      "status": "pending",
      "dependencies": [],
      "priority": 1,
      "acceptance": [
        "Testable criterion 1",
        "Testable criterion 2"
      ]
    }
  ],
  "sources": [".laracode/specs/feature/spec.md"]
}
```

### Schema Fields

| Field | Type | Description |
|-------|------|-------------|
| `title` | string | Feature name displayed in progress |
| `branch` | string | Suggested git branch name |
| `created` | string | ISO 8601 timestamp |
| `tasks` | array | List of task objects |
| `sources` | array | Reference files (spec, PRs, docs) |

### Task Fields

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Unique task identifier (sequential, starting at 1) |
| `title` | string | Short descriptive title (5-10 words) |
| `description` | string | Full context for implementation |
| `steps` | array | Specific, actionable steps |
| `status` | string | `pending`, `in_progress`, or `completed` |
| `dependencies` | array | Task IDs that must complete first |
| `priority` | integer | Execution order (lower = higher priority) |
| `acceptance` | array | Testable completion criteria |

## Priority System

Tasks are executed based on priority (lower number = higher priority):

| Priority Range | Category | Examples |
|---------------|----------|----------|
| 1-10 | Setup | Migrations, config, seeders |
| 11-20 | Models | Eloquent models, relationships |
| 21-40 | Services | Business logic, service classes |
| 41-60 | Controllers | HTTP layer, actions |
| 61-75 | Views | Frontend, components |
| 81-95 | Tests | Feature and unit tests |
| 96-99 | Documentation | README, API docs |

## Dependency Resolution

Tasks with dependencies wait until all dependencies are completed:

```json
{
  "id": 3,
  "title": "Create AuthService",
  "dependencies": [1, 2],
  "priority": 25
}
```

Task #3 will only execute after tasks #1 and #2 are completed, regardless of priority.

### How It Works

1. Find all pending tasks
2. Filter tasks where all dependencies are completed
3. Sort remaining by priority (lowest first)
4. Execute the first available task

### Blocked Tasks

Tasks with unsatisfied dependencies are "blocked". The build progress shows:

```
Progress: [████████████░░░░░░░░] 60%
Tasks: 6/10 completed | 2 pending | 2 blocked
```

### Circular Dependency Detection

The system validates that no circular dependencies exist (A→B→C→A).

## Cliff Notes

After each task completion, context is appended to `cliff-notes.md`:

```markdown
---
## Task #3: Create AuthService
- Created app/Services/AuthService.php with register, login, logout methods
- Used Hash facade for password hashing
- Login returns boolean, throws exception on invalid credentials

### Next Steps
- Task #4 (AuthController) is now unblocked
- Task #7 (Auth tests) depends on tasks #3-6
```

The next task execution reads these notes for context continuity.

## Example Workflow

### 1. Describe Your Feature

```
I need to add a blog module with:
- Posts with title, content, slug, published_at
- Categories with many-to-many relationship
- Markdown support in content
- Author relationship to User
```

### 2. Generate Tasks

```
/generate-tasks
```

Output:
```
✓ Generated tasks for: Blog Module

Directory: .laracode/specs/blog-module/
Files:
  - spec.md (created)
  - tasks.json (created)

Task Breakdown:
  Total: 12 tasks
  Setup: 2 tasks (priority 1-10)
  Core: 6 tasks (priority 11-80)
  Tests: 3 tasks (priority 81-95)
  Docs: 1 task (priority 96-99)

Run `laracode build .laracode/specs/blog-module/tasks.json` to start building.
```

### 3. Run Build

```bash
laracode build .laracode/specs/blog-module/tasks.json --mode=yolo
```

### 4. Monitor Progress

```
Feature: Blog Module
Branch:  feature/blog-module
Created: 2026-01-12 10:30
Progress: [████████░░░░░░░░░░░░] 40%
Tasks: 4/10 completed | 4 pending | 2 blocked

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Iteration 5/100
Next Task: #5 - Create PostController with CRUD actions
Running: claude --dangerously-skip-permissions /build-next
```

## Project Structure

```
your-project/
├── .claude/
│   ├── commands/
│   │   ├── build-next.md         # Task execution command
│   │   └── process-comments.md   # Comment processing command
│   ├── skills/
│   │   └── generate-tasks/
│   │       └── SKILL.md          # Task generation skill
│   ├── scripts/
│   │   └── statusline.php        # Status display script
│   ├── hooks/
│   │   └── session-start.php     # Session hook
│   └── settings.local.json       # Claude configuration
└── .laracode/
    ├── watch.json                # Watch configuration
    ├── watch.lock                # Process lock file
    ├── comments.json             # Extracted comments (temporary)
    └── specs/
        └── feature-name/
            ├── spec.md           # Feature specification
            ├── tasks.json        # Task definitions
            └── cliff-notes.md    # Accumulated context
```

## Requirements

- PHP 8.2+
- Claude CLI (`claude` command available)
- Composer
- Node.js (for watch mode - requires chokidar)

## License

MIT License
