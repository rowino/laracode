Generate a worktree setup flow based on this description: {{ $description }}

Output ONLY valid JSON with no other text. The response must be a single JSON object matching this exact schema:

{
  "id": "unique-flow-id",
  "name": "Human Readable Flow Name",
  "enabled": true,
  "prompts": [
    {
      "id": "variable_name",
      "type": "text|confirm|select|multiselect",
      "label": "What the user sees",
      "default": "default value",
      "options": [
        {"label": "Human readable option", "value": "machine_value"}
      ]
    }
  ],
  "steps": [
    {
      "id": "step-id",
      "command": "command to execute",
      "condition": "optional: variable_name == 'value'"
    }
  ]
}

AVAILABLE VARIABLES in commands (use @{{VARIABLE}} syntax):
- BRANCH_NAME: The git branch name
- FOLDER_NAME: The worktree folder name
- WORKTREE_PATH: Full path to the new worktree
- SOURCE_BRANCH: Branch the worktree was created from
- PROJECT_PATH: Path to the main project
- PROJECT_NAME: Name of the project (from folder)
- Any prompt id values (e.g., @{{db_name}})

AVAILABLE FILTERS (use @{{VARIABLE|filter}}):
- snake: converts to snake_case
- slug: converts to slug-format
- upper: UPPERCASE
- lower: lowercase

AVAILABLE PROMPT TYPES:
- text: Free text input
- confirm: Yes/No question (returns true/false)
- select: Single choice from options array (use label/value format)
- multiselect: Multiple choices from options array (use label/value format)

OPTIONS FORMAT (for select and multiselect):
- Always use objects with "label" (displayed to user) and "value" (used in variables/conditions)
- Example: {"label": "MySQL Database", "value": "mysql"}
- The "value" is what gets stored in the variable and used in conditions

CONDITIONS:
- Use == or != operators
- Reference prompt variable values
- Example: "condition": "run_migrations == true"

IMPORTANT:
- prompts array is OPTIONAL, only include if user input is needed
- condition field is OPTIONAL, only include for conditional steps
- All commands run in WORKTREE_PATH by default
- Keep flow focused on the described task
- Use meaningful ids and names
@if($previousFlow !== null && $feedback !== null)

--- PREVIOUS GENERATED FLOW ---
{!! json_encode($previousFlow, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) !!}

--- USER FEEDBACK ---
{{ $feedback }}

Please modify the flow based on the user's feedback. Output only the updated JSON.
@endif
