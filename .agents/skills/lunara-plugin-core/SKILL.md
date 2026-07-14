```markdown
# lunara-plugin-core Development Patterns

> Auto-generated skill from repository analysis

## Overview

This skill teaches you the core development patterns and conventions used in the `lunara-plugin-core` TypeScript codebase. You'll learn how to structure files, write imports/exports, and follow the main workflow for implementing features alongside regression tests. This guide is ideal for contributors looking to maintain consistency and reliability in the codebase.

## Coding Conventions

### File Naming

- Use **kebab-case** for all file names.

  **Example:**
  ```
  debrief-suggestions-regression.test.ts
  lunara-debrief-suggestions.ts
  ```

### Import Style

- Use **relative imports** for modules within the repository.

  **Example:**
  ```typescript
  import { getSuggestions } from './lunara-debrief-suggestions';
  ```

### Export Style

- Use **named exports** for all exported functions, classes, or constants.

  **Example:**
  ```typescript
  export function getSuggestions(input: string): Suggestion[] { ... }
  ```

## Workflows

### Feature Implementation with Regression Test

**Trigger:** When you want to add a new feature or improve existing logic and verify it with regression testing.  
**Command:** `/implement-feature-with-test`

1. **Edit or add implementation**  
   Update or create the relevant logic in `includes/class-lunara-debrief-suggestions.php`.

2. **Update or add corresponding test**  
   Modify or create a regression test in `tests/debrief-suggestions-regression.php` to cover the new or changed functionality.

3. **Commit both implementation and test changes together**  
   Ensure both the feature and its test are included in the same commit for traceability.

**Example:**

_Edit implementation:_
```php
// includes/class-lunara-debrief-suggestions.php
public function get_suggestions($input) {
    // New or improved logic here
}
```

_Add regression test:_
```php
// tests/debrief-suggestions-regression.php
public function test_get_suggestions_returns_expected_output() {
    // Test for the new or improved logic
}
```

_Commit message:_
```
Add new suggestion logic and regression test
```

## Testing Patterns

- Test files follow the pattern `*.test.*` (e.g., `debrief-suggestions-regression.test.ts`).
- The specific testing framework is **unknown**, but tests are colocated in a `tests/` directory or use the `.test.` file naming convention.
- When adding or updating features, always include or update a corresponding regression test.

## Commands

| Command                        | Purpose                                                         |
|--------------------------------|-----------------------------------------------------------------|
| /implement-feature-with-test   | Implements or enhances a feature and adds/updates regression test|
```
