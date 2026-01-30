---
name: process-comments
description: Process @claude comments extracted from watched files and generate implementation plan
version: 1.1.0
usage: /process-comments [comments-json-path]
arguments:
  - name: comments-json-path
    required: false
    description: Path to comments.json file. If not provided, looks in current directory.
outputs:
  - .laracode/specs/{feature-slug}/spec.md
  - .laracode/specs/{feature-slug}/tasks.json
tags:
  - watch-mode
  - comment-processing
  - automation
  - task-generation
execution_mode: plan-first
---

# Process @claude Comments

## Overview

This skill processes `@claude` comments extracted by the watch command and:
1. **Uses plan mode** to explore the codebase and understand context
2. Analyzes comments to understand the requested changes
3. Groups related comments into coherent feature requests
4. Creates a structured implementation plan for user approval
5. After approval, generates `spec.md` and `tasks.json` files
6. Coordinates with the watch command via notify signals

**Invoked by:** The `laracode watch` command when stop word is detected (e.g., `@claude!`)

**What it produces:**
- `.laracode/specs/{feature-slug}/spec.md` - Feature specification from comments
- `.laracode/specs/{feature-slug}/tasks.json` - Structured task breakdown

## Input Detection

### Step 1: Read Comments JSON

Look for comments.json in this order:
1. Path provided as argument: `$ARGUMENTS.comments-json-path`
2. `.laracode/watch/comments.json` in current directory
3. Environment variable `LARACODE_COMMENTS_PATH`

The comments.json structure:
```json
{
  "comments": [
    {
      "file": "app/Models/User.php",
      "line": 42,
      "text": "@claude add email verification functionality"
    }
  ],
  "metadata": {
    "stopWordFound": true,
    "stopWordFile": "app/Models/User.php",
    "filesScanned": 15,
    "timestamp": "2026-01-20T15:30:00Z"
  }
}
```

### Step 2: Detect Mode

Check for mode indicator (set by WatchService):
- Environment variable `LARACODE_MODE` (interactive, yolo, or accept)
- Default to `interactive` if not set

Mode behaviors:
- **interactive**: Use plan mode, wait for approval, then generate tasks
- **yolo**: Skip plan mode, create plan and tasks automatically, auto-start build
- **accept**: Use plan mode but auto-approve, pause for edits review

## Process

### Step 3: Display Comments

Show the user what comments were found:

```
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Processing @claude Comments
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Found [N] comments in [M] files:

  app/Models/User.php:42
    @claude add email verification functionality

  app/Http/Controllers/AuthController.php:15
    @claude implement password reset flow

  resources/views/auth/verify.blade.php:1
    @claude! create this verification view
```

### Step 4: Enter Plan Mode (Interactive/Accept Modes)

**IMPORTANT: For interactive and accept modes, use `EnterPlanMode` tool to begin planning.**

In plan mode:
1. **Explore the codebase** using Glob, Grep, and Read tools
2. **Understand existing patterns** - models, controllers, services, tests
3. **Identify related files** that may need modification
4. **Map dependencies** between the requested changes

### Step 5: Analyze and Group Comments

Group related comments into logical feature units:
- Comments in the same file or related files
- Comments describing parts of the same feature
- Comments with shared domain concepts

Generate a feature slug from the primary comment (e.g., "email-verification" from "add email verification functionality").

### Step 6: Write Plan to Plan File

Write the implementation plan to `.claude/plans/{feature-slug}.md`:

```markdown
# Implementation Plan: [Feature Name]

## Source Comments
| File | Line | Comment |
|------|------|---------|
| app/Models/User.php | 42 | @claude add email verification functionality |
| ... | ... | ... |

## Codebase Analysis

### Relevant Existing Files
- `app/Models/User.php` - User model, needs verification fields
- `app/Http/Controllers/AuthController.php` - Auth logic location
- ...

### Existing Patterns Identified
- [Pattern 1: e.g., "Uses form requests for validation"]
- [Pattern 2: e.g., "Services handle business logic"]
- ...

## Implementation Approach

### Overview
[2-3 sentence summary of the implementation strategy]

### Files to Create
1. `path/to/new/file.php` - [Purpose]
2. ...

### Files to Modify
1. `path/to/existing/file.php` - [What changes needed]
2. ...

### Key Technical Decisions
- [Decision 1 with rationale]
- [Decision 2 with rationale]

## Requirements
- [Requirement derived from comment 1]
- [Requirement derived from comment 2]
...

## Acceptance Criteria
- [ ] [Testable criterion 1]
- [ ] [Testable criterion 2]
...

## Task Breakdown Preview
1. [Task 1 - brief description]
2. [Task 2 - brief description]
...
```

### Step 7: Exit Plan Mode for Approval

**Use `ExitPlanMode` tool** to request user approval of the plan.

The user will review:
- The implementation approach
- Files to be created/modified
- Technical decisions made

If rejected:
- Note feedback
- Call `laracode notify plan-rejected '{"reason":"User feedback"}'`
- Exit - watch loop continues monitoring

### Step 8: Generate spec.md (After Approval)

After plan approval, create the formal specification:

```markdown
# [Feature Name from Comments]

## Overview
[2-3 sentence summary synthesized from all comments]

## Source Comments
| File | Line | Comment |
|------|------|---------|
| app/Models/User.php | 42 | @claude add email verification functionality |
| ... | ... | ... |

## Requirements
- [Requirement derived from comment 1]
- [Requirement derived from comment 2]
...

## Architecture
[Key technical decisions based on existing codebase patterns]

## Acceptance Criteria
- [ ] [Testable criterion 1]
- [ ] [Testable criterion 2]
...
```

Save to: `.laracode/specs/[feature-slug]/spec.md`

### Step 9: Generate Tasks

Follow the same task generation logic as `/generate-tasks`:

**Task Granularity:**
- Each task completable in a single Claude session
- Combine related changes to the same file
- Separate different files into different tasks

**Priority Levels:**
- **1 (Critical)**: Migrations, core configuration
- **2 (High)**: Models, relationships, domain entities
- **3 (Medium)**: Controllers, services, API endpoints
- **4 (Low)**: Views, components, styling
- **5 (Lowest)**: Tests, documentation

Generate `tasks.json` with proper dependencies based on the spec.

Save to: `.laracode/specs/[feature-slug]/tasks.json`

### Step 10: Notify Tasks Ready

Signal that tasks are generated and ready:

```bash
laracode notify tasks-ready '{"tasksPath":".laracode/specs/feature-slug/tasks.json","taskCount":N}' --mode=[mode]
```

In **interactive** mode: Offers user choice to start build or continue watching
In **yolo** mode: Auto-continues to build loop

### Step 11: Output Summary

Display completion summary:

```
✓ Processed comments for: [Feature Name]

Directory: .laracode/specs/[feature-slug]/
Files:
  - spec.md (created)
  - tasks.json (created)

Comments Processed: [N] from [M] files

Task Breakdown:
  Total: [N] tasks
  Critical: [N] tasks (priority 1)
  High: [N] tasks (priority 2)
  Medium: [N] tasks (priority 3)
  Low: [N] tasks (priority 4)
  Lowest: [N] tasks (priority 5)
```

### Step 12: Signal Watch Complete

After all processing, signal completion:

```bash
laracode notify watch-complete '{"filesProcessed":M,"commentsFound":N,"tasksCompleted":0}' --message="Ready for build"
```

### Step 13: Exit Claude Instance

**CRITICAL: After completing all steps, exit the Claude instance to return control to watch mode.**

```bash
laracode exit
```

This allows the watch command to:
- Resume file monitoring
- Start the build loop if tasks are ready
- Handle user's next actions

## Mode-Specific Behavior

### Interactive Mode (Default)
1. Read comments.json
2. Display found comments
3. **Enter plan mode** with `EnterPlanMode`
4. Explore codebase, write plan to `.claude/plans/{slug}.md`
5. **Exit plan mode** with `ExitPlanMode` for user approval
6. After approval: generate spec.md and tasks.json
7. Notify tasks-ready, wait for user decision
8. Notify watch-complete
9. **Exit Claude** with `laracode exit`

### Yolo Mode
1. Read comments.json
2. Display found comments
3. **Skip plan mode** - analyze directly
4. Generate spec.md and tasks.json immediately
5. Notify tasks-ready (no wait)
6. Notify watch-complete
7. **Exit Claude** with `laracode exit`
8. Watch auto-starts build loop

### Accept Mode
1. Read comments.json
2. Display found comments
3. **Enter plan mode** with `EnterPlanMode`
4. Explore codebase, write plan
5. **Auto-approve** plan (skip ExitPlanMode wait)
6. Generate spec.md and tasks.json
7. Notify tasks-ready, pause for edits review
8. Notify watch-complete
9. **Exit Claude** with `laracode exit`

## Critical Rules

- **Interactive/Accept modes**: MUST use `EnterPlanMode` to explore codebase first
- **Yolo mode**: Skip plan mode, execute directly
- Read comments.json before anything else
- Display found comments clearly with file:line format
- Write plan to `.claude/plans/{feature-slug}.md`
- Use `ExitPlanMode` to get user approval (interactive mode)
- Create spec.md capturing intent from all comments
- Generate realistic task counts based on comment complexity
- Use `laracode notify` for all coordination signals
- Always output the summary before exiting
- **ALWAYS call `laracode exit` as the final step** to return control to watch mode

## Differences from /generate-tasks

| Aspect | /generate-tasks | /process-comments |
|--------|-----------------|-------------------|
| Input | Conversation or spec file | comments.json from watch |
| Trigger | Manual user invocation | Automatic from watch command |
| Planning | Uses plan mode | Uses plan mode (except yolo) |
| Notifications | None | Uses laracode notify signals |
| Source tracking | User conversation | @claude comments with file:line |
| Integration | Standalone | Part of watch → process → build pipeline |

## Example Flow

### Interactive Mode

```
1. Watch detects @claude! stop word
2. WatchService extracts comments to comments.json
3. WatchService invokes Claude with /process-comments
4. Claude reads comments.json, displays comments
5. Claude calls EnterPlanMode
6. Claude explores codebase (Glob, Grep, Read)
7. Claude writes plan to .claude/plans/{slug}.md
8. Claude calls ExitPlanMode
9. User reviews and approves plan
10. Claude generates spec.md and tasks.json
11. Calls: laracode notify tasks-ready ... --mode=interactive
12. User chooses to start build
13. Calls: laracode notify watch-complete ...
14. Calls: laracode exit
15. Watch command resumes, starts build loop
```

### Yolo Mode

```
1. Watch detects @claude! stop word
2. WatchService extracts comments to comments.json
3. WatchService invokes Claude with /process-comments
4. Claude reads comments.json, displays comments
5. Claude analyzes directly (no plan mode)
6. Claude creates spec.md and tasks.json
7. Calls: laracode notify tasks-ready ... --mode=yolo
8. Calls: laracode notify watch-complete ...
9. Calls: laracode exit
10. Watch command resumes, auto-starts build loop
```

## Error Handling

If any error occurs during processing:

```bash
laracode notify error '{"error":"Description of error","file":"path/if/relevant"}' --message="Processing failed"
```

Common errors:
- comments.json not found or invalid
- No @claude comments in the file
- Failed to create spec directory
- Invalid comment format
- Plan mode exploration failed
