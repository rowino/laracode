# LaraCode

An autonomous build system for Laravel projects using Claude AI. LaraCode breaks down features into structured tasks and executes them sequentially with dependency resolution.

## Installation

```bash
composer global require laracode/laracode
```

## Quick Start

### 1. Initialize a Project

```bash
cd your-laravel-project
laracode init
```

### 2. Create a Plan

Use plan mode to design your feature. When done, press `esc` and run:

```
/generate-tasks
```

### 3. Run the Build

```bash
laracode build .laracode/specs/my-feature/tasks.json --mode=yolo
```

### 4. Start Watch Mode

```bash
laracode watch
```

### 5. Review and Refine

Add `@ai` comments in your code to request changes:

```php
// @ai Refactor this method to use dependency injection
// @ai Add proper error handling
```

### 6. Submit for Processing

Add `ai!` when ready to trigger Claude:

```php
// @ai Refactor this method to use dependency injection
// @ai Add proper error handling
// ai!
```

## Task Generation

The `/generate-tasks` skill generates structured task files from conversations or spec files.

### What It Creates

1. **spec.md** - Feature specification with requirements and acceptance criteria
2. **tasks.json** - Structured task breakdown with dependencies

### Task Granularity

| Complexity | Task Count | Example |
|------------|------------|---------|
| Simple | 5-8 tasks | Login form, settings page |
| Medium | 10-20 tasks | User auth system, CRUD module |
| Complex | 30-100+ tasks | Multi-tenant platform |

## Build Command

Runs the autonomous build loop using a tasks.json file.

```bash
laracode build <path-to-tasks.json> [options]
```

### Options

| Option | Default | Description |
|--------|---------|-------------|
| `--iterations` | 100 | Maximum number of tasks to execute |
| `--delay` | 3 | Seconds between tasks |
| `--mode` | default | Permission mode: `yolo`, `accept`, or `default` |

### Examples

```bash
laracode build .laracode/specs/feature/tasks.json
laracode build .laracode/specs/feature/tasks.json --mode=yolo
laracode build .laracode/specs/feature/tasks.json --iterations=10
```

## Watch Mode

Monitors files for `@ai` comments and triggers Claude processing.

```bash
laracode watch [options]
```

### Options

| Option | Default | Description |
|--------|---------|-------------|
| `--paths` | app/, routes/, resources/ | Directories to watch |
| `--search-word` | @ai | Comment marker to search for |
| `--stop-word` | ai! | Trigger word to start processing |
| `--mode` | interactive | Permission mode: `yolo`, `accept`, `interactive` |

### Setup

Watch mode requires Node.js with chokidar:

```bash
npm install chokidar
```

### Configuration

Create `.laracode/watch.json` for persistent settings:

```json
{
    "paths": ["app/", "routes/", "resources/"],
    "searchWord": "@ai",
    "stopWord": "ai!",
    "mode": "interactive",
    "excludePatterns": ["**/vendor/**", "**/node_modules/**"]
}
```
