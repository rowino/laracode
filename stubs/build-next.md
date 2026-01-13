# Build Next Task

**IMPORTANT: Execute immediately. Do NOT enter plan mode. Do NOT ask for approval. Just do the work.**

Implement the next pending task from the tasks.json file, then exit.

## Process

1. **Resolve tasks.json file**
   - If argument provided (`$ARGUMENTS`): use that path directly
   - If no argument:
     - Search `.laracode/specs/*/tasks.json` for files with pending tasks
     - If one file has pending tasks: use it automatically
     - If multiple files have pending tasks: ask user which one to use
     - If no pending tasks found: output "No pending tasks found" and exit

2. **Read cliff-notes.md**
   - Check for `cliff-notes.md` in the same directory as the resolved tasks.json
   - If it exists, read it to understand context from previous iterations
   - These notes contain important context left by previous runs

3. **Select the next task**
   - Find first task with `"status": "pending"` (lowest priority number first, then by id)
   - If no pending tasks, output "All tasks completed!" and exit immediately

4. **Mark task in progress**
   - Update the task's status to `"in_progress"` in tasks.json
   - Save immediately

5. **Implement the task NOW**
   - Read the task description and steps
   - Execute each step immediately
   - Do NOT ask for confirmation - just implement
   - Follow project best practices

6. **On completion**
   - Update task status to `"completed"` in tasks.json
   - **Append to cliff-notes.md** with useful context for the next developer:
     - What was implemented and key decisions made
     - Any blockers, issues, or warnings discovered
     - Files created/modified that the next task should know about
     - Do NOT include obvious info or fluff - only actionable context
   - Output: "✓ Completed: [task description]"

7. **Signal build loop to continue**
   - The lock file is located next to tasks.json: `dirname(tasks.json) + '/index.lock'`
   - Run: `laracode stop <path-to-lock-file>`
   - Example: If tasks.json is at `.laracode/specs/my-feature/tasks.json`, run:
     `laracode stop .laracode/specs/my-feature/index.lock`
   - This signals the build loop to proceed to the next iteration

8. **Exit immediately**
   - Only implement ONE task per invocation
   - Exit after completing - do not continue to next task

## Cliff Notes Format

When appending to cliff-notes.md, use this format:
```
---
## Task #[id]: [description]
- [Key point 1]
- [Key point 2]
```

## Critical Rules

- **NO PLANNING MODE** - Execute directly
- **NO APPROVAL REQUESTS** - Just do the implementation
- Only ONE task per invocation
- Always update tasks.json before and after
- Always read cliff-notes.md at start, append at end
- **Always run `laracode stop` with the lock file path after completion**
- Exit after completing the single task
