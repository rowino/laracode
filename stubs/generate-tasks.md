# Generate Tasks from Feature Description

**IMPORTANT: Execute immediately. Do NOT enter plan mode. Do NOT ask for approval. Just do the work.**

Generate a structured tasks.json file from the current conversation context or a spec file.

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
1. **Database** (priority 1-10): Migrations, seeders
2. **Models** (priority 11-20): Eloquent models, relationships
3. **Services** (priority 21-40): Business logic, service classes
4. **Controllers/Actions** (priority 41-60): HTTP layer
5. **Views/Components** (priority 61-75): Frontend (if applicable)
6. **Tests** (priority 81-95): Feature and unit tests
7. **Documentation** (priority 96-99): README updates, API docs

**Priority Assignment:**
- Lower number = higher priority = executed first
- Setup tasks: 1-10
- Core implementation: 11-80
- Tests: 81-95
- Documentation: 96-99

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
      "priority": 1,
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
  Setup: [N] tasks (priority 1-10)
  Core: [N] tasks (priority 11-80)
  Tests: [N] tasks (priority 81-95)
  Docs: [N] tasks (priority 96-99)

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
      "priority": 11,
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
      "priority": 25,
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
