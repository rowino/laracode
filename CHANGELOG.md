# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2025-01-22

### Added

- **Build Command** - Autonomous build loop with task dependency resolution
  - Execute tasks from `tasks.json` files sequentially
  - Smart dependency resolution ensures correct execution order
  - Blocked task detection and progress tracking
  - Permission modes: `yolo`, `accept`, or `default`
  - Cliff notes accumulation for context continuity

- **Init Command** - Project initialization
  - Creates `.claude/commands/` with build-next and generate-tasks skills
  - Sets up `.claude/settings.json` configuration
  - Generates example task file in `.laracode/specs/`

- **Watch Command** - File monitoring for automatic task execution
  - Monitors `tasks.json` files for changes
  - Triggers build loop on task file updates

- **Stop Command** - Process management
  - Gracefully stops running LaraCode processes
  - Lock file management for process tracking

- **Task Generation** - AI-powered task breakdown via Claude skills
  - `/generate-tasks` skill for conversation-based task generation
  - `/build-next` skill for task execution
  - Smart task granularity based on complexity
  - Priority-based execution ordering

[0.1.0]: https://github.com/rowino/laracode/releases/tag/v0.1.0
