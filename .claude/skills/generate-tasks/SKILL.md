---
name: generate-tasks
description: This skill should be used when the user asks to "generate tasks", "create tasks from spec", "break down feature into tasks", "plan implementation", "create task breakdown", "generate tasks.json", or mentions converting requirements into actionable tasks.
argument-hint: [spec-file-path]
---

# Generate Tasks from Feature Description

**Execute immediately. NO plan mode. NO approval requests.**

## Quick Reference

- **Input**: Spec file path OR conversation context
- **Output**: `.laracode/specs/{feature-slug}/` containing `spec.md`, `tasks.json`, `ref-*.md`
- **Templates**: See `./templates/` folder for file formats

## Process

### 1. Understand the Feature
- Extract requirements from spec file or conversation
- Determine complexity: Simple (5-8 tasks), Medium (10-20), Complex (30-100+)

### 2. Generate spec.md
Use template: `./templates/spec.md`

Save to: `.laracode/specs/{feature-slug}/spec.md`

**Important**: If user provided a plan, preserve it verbatim under "## User's Original Plan"

### 3. Collect Conversation Refs
Scan conversation for valuable context (web search results, Context7 docs, code exploration findings).

Use template: `./templates/ref-template.md`

Save as: `.laracode/specs/{feature-slug}/ref-{topic}.md`

### 4. Break Down into Tasks

**Task Granularity:**
- Each task completable in single session
- Combine related changes to same file
- Group logically (e.g., "Create User model with all fields" not separate tasks per field)

**Priority Levels:**
1. **Critical**: Migrations, core config
2. **High**: Models, relationships, domain entities
3. **Medium**: Services, controllers, API endpoints
4. **Low**: Views, components, frontend
5. **Lowest**: Tests, documentation

### 5. Generate tasks.json
Use template: `./templates/tasks.json`

**Schema fields**: `id`, `title`, `description`, `steps`, `status`, `dependencies`, `priority`, `refs`, `acceptance`

Save to: `.laracode/specs/{feature-slug}/tasks.json`

### 6. Preview Task Tree & Get Feedback

Display tree view (see `./templates/tree-preview.txt`) and ask user:
- **Approve** to save and continue
- **Suggest changes** (add/remove/modify tasks)
- **Regenerate** with different granularity

**Do NOT save until user approves.**

### 7. Validate
- Unique IDs (positive integers)
- Valid dependency references
- No circular dependencies
- Every task has steps and acceptance criteria

### 8. Output Summary
```
✓ Generated tasks for: [Feature Name]
Directory: .laracode/specs/{feature-slug}/
Files: spec.md, tasks.json, ref-*.md
Task Breakdown: Total [N] | Critical [N] | High [N] | Medium [N] | Low [N] | Lowest [N]

Run `laracode build .laracode/specs/{feature-slug}/tasks.json` to start.
```

## Critical Rules

- **NO PLANNING MODE** - Execute directly
- **PRESERVE USER'S PLAN** - Save verbatim in spec.md
- **GET TREE APPROVAL** - Show preview, wait for user approval before saving
- Combine related file changes into single tasks
- Validate before saving
