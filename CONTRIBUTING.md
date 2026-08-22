# Contributing to the-consoomer

## Development Workflow

This guide describes the workflow for fixing issues and implementing features.

### 1. Pick an Issue

**Find the lowest-version open milestone:**

```bash
# List all open milestones - pick the lowest version manually
gh api repos/crazy-goat/the-consoomer/milestones --jq '.[] | select(.state == "open") | "\(.title) ( #\(.number) )"'
# Example output:
#   0.2.0 ( #2 )
#   0.3.0 ( #3 )
# Use the lowest version number in next command
```

Get issues for that milestone:

```bash
gh api "repos/crazy-goat/the-consoomer/issues?milestone=<NUMBER>&state=open" --jq '.[] | "#\(.number): \(.title)"'
```

**Issue table format:**

| # | Title | Description | Why it matters |
|---|------|------------|--------------|
| | | | |

**Wait for the user to choose an issue number. Never pick an issue yourself.**

### 2. Start Working on an Issue

When the user provides an issue number:

1. **Ensure clean state and latest code:**

```bash
git checkout main
git pull origin main
git status  # Should show "nothing to commit, working tree clean"
```

2. **Create a feature branch:**

```bash
git checkout -b feature/issue-<NUMBER>-<slug>
```

2. **Read the issue details** and implement the solution.

3. **Run tests** to verify your implementation:

```bash
# All tests
composer test

# Unit tests only (fast)
composer test-unit

# E2E tests (requires RabbitMQ)
composer test-e2e-full
```

4. **Run linters** to ensure code quality:

```bash
composer lint
```

This runs: `phpstan`, `rector`, `php-cs-fixer`.

**Auto-fix lint issues:**

```bash
composer lint:fix
```

This runs: `rector`, `php-cs-fixer` (these can fix some issues automatically).

5. **Fix any issues** found by tests or linters.

### 3. Create a Pull Request

When tests and lint pass:

```bash
git add -A
git commit -m "feat: <description>"
git push -u origin feature/issue-<NUMBER>-<slug>
gh pr create --title "#<NUMBER> <title>" --body "Closes #<NUMBER>"
```

### 4. CI Loop

**Always wait for CI to complete before proceeding.** Use `gh run watch` to monitor:

```bash
gh run watch
```

**Never merge or ask the user to merge while CI is still running.**

**If CI fails:**

1. Get the errors:

```bash
gh run view <run-number> --log
```

2. **Fix the issues** in a new commit:

```bash
git add -A
git commit -m "fix: <what was fixed>"
git push
```

3. Wait for CI again.

### 5. Code Review

**The user must review the PR before merging.** The user can:
- Add comments in the GitHub PR
- Or just type the feedback in the terminal

**You must wait for explicit user approval before merging.** Do not merge on your own initiative.

**If there are review comments:**

1. Fix all problems.
2. Create a new commit (never amend).
3. Push and wait for CI again.

### 6. Merge

**Only merge when:**
- CI passes ✅
- The user explicitly approves the PR ✅

**Never use `--admin` or any bypass flag to merge.** Respect branch protection rules.

1. **Squash and merge** the PR via GitHub UI.
2. The PR will auto-close the linked issue.

3. **Clean up locally:**

```bash
git checkout main
git pull origin main
git branch -d feature/issue-<NUMBER>-<slug>
```

## Commands Reference

| Command | Description |
|---------|-------------|
| `composer test` | Run all tests |
| `composer test-unit` | Run unit tests only |
| `composer test-e2e-full` | Run E2E tests with RabbitMQ |
| `composer lint` | Run phpstan, rector, php-cs-fixer |
| `composer phpstan` | Run static analysis |
| `composer rector` | Run automatic code fixes |
## Subagent Workflow

**Prefer implementing and reviewing changes with subagents.** Each subagent
runs in its own context window and returns only its conclusion, which keeps the
main conversation focused on decisions rather than raw file contents.

### Code Write

Use an `implementer` subagent for the actual implementation of an issue:

```
subagent(task="Implement issue #205: <description>. Files: src/CircuitBreaker.php, tests/Unit/CircuitBreakerTest.php. Return the list of changed files and a summary of the change.")
```

- State what to do, which paths to read/write, and what to return.
- The subagent sees ONLY the task text — no conversation history. Include
  everything it needs (issue description, file paths, acceptance criteria).
- For parallel work, pass `tasks=[...]` in one call. Parallel subagents that
  write shared paths must lock them (`lock`/`unlock`); prefer
  `isolation="worktree"` so each writes in its own checkout.

### Code Review

After implementation (before or alongside CI), use a `reviewer` subagent to
review the diff and report **defects that change behavior**:

```
subagent(agent="reviewer", task="Review the diff on branch feature/issue-205-... against main. Report behavior-changing defects only: logic bugs, missing edge cases, broken contracts. Do not report style. Return findings as a numbered list with file:line and severity.")
```

- The reviewer returns **findings worth fixing**, not prose.
- Fix real defects in a new commit, push, wait for CI again.

## Post-Merge Finding Review

**After the PR is squash-merged and the branch is cleaned up, run a finding
review on the merged change.** The goal is to catch issues the PR introduced or
left open that are worth tracking as future work.

```
subagent(agent="reviewer", task="Review the squash-merged change for issue #205 (commit <sha> on main). Report any findings worth tracking as future GitHub issues: regressions, incomplete fixes, new risks, follow-up gaps. Return each finding with a title, severity, and file:line.")
```

For each finding the reviewer reports:

1. **Check for an existing issue first** — never create duplicates:

```bash
gh issue list --search "<finding keywords>" --json number,title,state --jq '.[] | "#\(.number): \(.title) [\(.state)]"'
```

2. **If no existing issue covers it**, create one:

```bash
gh issue create --title "<finding title>" --body "<finding body with severity, location, impact, and a link back to the merged PR>" --milestone <lowest-open-milestone-number>
```

3. If an existing open issue already covers the finding, leave a comment
   pointing to the merged PR rather than creating a new issue.

This keeps the issue tracker honest: new findings become tracked work, and
duplicates are avoided by checking before creating.
