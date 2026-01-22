---
name: generate-tasks
description: Generate a structured tasks.json file from feature descriptions or spec files
version: 1.0.0
usage: /generate-tasks [spec-file-path]
arguments:
  - name: spec-file-path
    required: false
    description: Optional path to spec.md file. If not provided, uses conversation context.
outputs:
  - .laracode/specs/{feature-slug}/spec.md
  - .laracode/specs/{feature-slug}/tasks.json
tags:
  - task-generation
  - planning
  - automation
  - feature-development
execution_mode: immediate
---

# Generate Tasks from Feature Description

**IMPORTANT: Execute immediately. Do NOT enter plan mode. Do NOT ask for approval. Just do the work.**

## Overview

This skill generates a comprehensive implementation plan by:
- Creating a structured `spec.md` file documenting feature requirements
- Breaking down the feature into granular, executable tasks
- Generating a `tasks.json` file with proper dependencies and priorities
- Organizing tasks by complexity level (Simple: 5-8 tasks, Medium: 10-20, Complex: 30-100+)

**When to use:**
- Starting a new feature implementation
- Converting conversation requirements into actionable tasks
- Formalizing a spec file into an execution plan

**What it produces:**
- `.laracode/specs/{feature-slug}/spec.md` - Feature specification document
- `.laracode/specs/{feature-slug}/tasks.json` - Structured task breakdown with dependencies

This skill executes immediately without entering plan mode or requesting approval.

## Input Detection

First, determine the input type:

1. **Spec file path provided:** If the user provides a path to a spec.md file, read it directly
2. **Conversation context:** If no path provided, analyze the conversation to extract the feature requirements

## Process

### Step 1: Understand the Feature

If working from conversation:
- Extract the feature name/title
- Identify all requirements mentioned
- Note any technical constraints or preferences
- Determine the complexity level:
  - **Simple** (5-8 tasks): Single component, minimal dependencies (e.g., login form, settings page)
  - **Medium** (10-20 tasks): Multi-component feature (e.g., user auth system, CRUD module)
  - **Complex** (30-100+ tasks): Large system with many moving parts (e.g., multi-tenant platform)

If working from spec file:
- Read the spec.md file
- Extract all requirements, architecture decisions, and acceptance criteria

### Step 2: Generate spec.md (if needed)

If working from conversation, create a spec.md file with these sections:

```markdown
# [Feature Name]

## Overview
[2-3 sentence summary of what this feature does]

## Requirements
- [Requirement 1]
- [Requirement 2]
...

## Architecture
[Key technical decisions and patterns to follow]

## Acceptance Criteria
- [ ] [Testable criterion 1]
- [ ] [Testable criterion 2]
...
```

Save to: `.laracode/specs/[feature-slug]/spec.md`

### Step 3: Break Down into Tasks

Generate tasks following these guidelines:

**Task Granularity:**
- Each task should be completable in a single Claude session
- Combine related changes to the same file into one task
- Separate changes to different files into different tasks
- Group logically: "Create User model with all attributes" (not separate tasks for each attribute)

**Smart Grouping Examples:**
- GOOD: "Create User model with name, email, password fields"
- BAD: "Add name to User", "Add email to User", "Add password to User"
- GOOD: "Create UserController with index, show, store methods"
- BAD: "Add index method", "Add show method", "Add store method"

**Dependency Analysis:**
Order tasks by natural dependencies:
1. **Critical (priority 1)**: Migrations, seeders, core configuration
2. **High (priority 2)**: Eloquent models, relationships, domain entities
3. **Medium (priority 3)**: Business logic, services, controllers, actions, API endpoints
4. **Low (priority 4)**: Views, components, frontend assets, styling
5. **Lowest (priority 5)**: Tests (feature/unit), documentation (README, API docs)

**Priority Levels:**
Use a 5-level classification where lower number = higher priority:
- **1 (Critical)**: Foundational setup - database migrations, core configuration
- **2 (High)**: Core domain - models, relationships, essential business logic
- **3 (Medium)**: Feature implementation - controllers, services, actions, API endpoints
- **4 (Low)**: Presentation layer - views, components, styling, UI elements
- **5 (Lowest)**: Quality & documentation - tests, README updates, API docs

### Step 4: Generate tasks.json

Create the tasks.json file with this schema:

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
      "priority": 1,  // 1-5: 1=Critical, 2=High, 3=Medium, 4=Low, 5=Lowest
      "acceptance": [
        "Testable acceptance criterion 1",
        "Testable acceptance criterion 2"
      ]
    }
  ],
  "sources": [".laracode/specs/feature-slug/spec.md"]
}
```

**Schema Requirements:**
- `id`: Unique positive integer, sequential starting at 1
- `title`: Short descriptive title (5-10 words)
- `description`: Full context for implementation
- `steps`: Array of specific, actionable steps
- `status`: Always "pending" for new tasks
- `dependencies`: Array of task IDs that must complete first
- `priority`: Integer, lower = higher priority
- `acceptance`: Array of testable criteria

Save to: `.laracode/specs/[feature-slug]/tasks.json`

### Step 5: Validate

Before saving, validate:

1. **Unique IDs:** All task IDs are unique positive integers
2. **Valid dependencies:** All dependency IDs reference existing tasks
3. **No circular dependencies:** A→B→C→A is invalid
4. **Priority ordering:** Lower priority tasks don't depend on higher priority ones
5. **Non-empty steps:** Every task has at least one step
6. **Acceptance criteria:** Every task has at least one acceptance criterion

**Circular Dependency Detection:**
For each task, trace its dependency chain. If you encounter the same task ID twice, there's a cycle.

### Step 6: Output Summary

After generating, display:

```
✓ Generated tasks for: [Feature Name]

Directory: .laracode/specs/[feature-slug]/
Files:
  - spec.md (created/exists)
  - tasks.json (created)

Task Breakdown:
  Total: [N] tasks
  Critical: [N] tasks (priority 1)
  High: [N] tasks (priority 2)
  Medium: [N] tasks (priority 3)
  Low: [N] tasks (priority 4)
  Lowest: [N] tasks (priority 5)

Dependencies:
  [List any tasks with dependencies]

Run `laracode build .laracode/specs/[feature-slug]/tasks.json` to start building.
```

## Critical Rules

- **NO PLANNING MODE** - Execute directly
- **NO APPROVAL REQUESTS** - Just generate the tasks
- Create realistic task counts based on feature complexity
- Combine related file changes into single tasks
- Ensure proper dependency ordering
- Validate before saving
- Always output the summary

## Example Task Breakdown

For a "User Authentication" feature (medium complexity):

```json
{
  "title": "User Authentication",
  "branch": "feature/user-auth",
  "created": "2026-01-12T10:00:00Z",
  "tasks": [
    {
      "id": 1,
      "title": "Create users table migration",
      "description": "Create the users table with all authentication fields",
      "steps": [
        "Run php artisan make:migration create_users_table",
        "Add columns: name, email (unique), password, remember_token, email_verified_at",
        "Add timestamps",
        "Run php artisan migrate"
      ],
      "status": "pending",
      "dependencies": [],
      "priority": 1,
      "acceptance": [
        "Migration file exists",
        "Users table created with all columns",
        "Email column has unique index"
      ]
    },
    {
      "id": 2,
      "title": "Create User model",
      "description": "Create the User Eloquent model with fillable, hidden, and casts",
      "steps": [
        "Create app/Models/User.php",
        "Extend Authenticatable",
        "Define $fillable: name, email, password",
        "Define $hidden: password, remember_token",
        "Define $casts for email_verified_at and password"
      ],
      "status": "pending",
      "dependencies": [1],
      "priority": 2,
      "acceptance": [
        "User model extends Authenticatable",
        "All fillable, hidden, and casts defined",
        "Model can be instantiated"
      ]
    },
    {
      "id": 3,
      "title": "Create AuthService for business logic",
      "description": "Service class handling registration, login, and logout logic",
      "steps": [
        "Create app/Services/AuthService.php",
        "Add register(array $data): User method",
        "Add login(string $email, string $password): bool method",
        "Add logout(): void method",
        "Hash password in register method"
      ],
      "status": "pending",
      "dependencies": [2],
      "priority": 3,
      "acceptance": [
        "AuthService class exists",
        "All methods implemented",
        "Password hashed correctly",
        "Login validates credentials"
      ]
    }
  ],
  "sources": [".laracode/specs/user-auth/spec.md"]
}
```