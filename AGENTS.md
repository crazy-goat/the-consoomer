# AGENTS.md — the-consoomer

Symfony Messenger AMQP transport that consumes instead of polling with `get`.
PHP 8.4+, `ext-amqp`. Everything in this repository is written in English.

The development process is in [docs/workflow.md](docs/workflow.md), the release
process in [docs/release-workflow.md](docs/release-workflow.md). Contributor
rules (branches, commits, PRs) are in the
[organization CONTRIBUTING.md](https://github.com/crazy-goat/.github/blob/main/CONTRIBUTING.md).

## Commands

| Command | Description |
|---------|-------------|
| `composer test` | Run all tests |
| `composer test-unit` | Run unit tests only (fast) |
| `composer test-e2e` | Run E2E tests (assumes RabbitMQ is already running) |
| `composer test-e2e-full` | Start RabbitMQ, run E2E tests, stop RabbitMQ |
| `composer test-coverage` | Generate an HTML coverage report into `coverage/` |
| `composer coverage` | Generate a Clover report and enforce the 90% line floor (CI) |
| `composer lint` | Check everything: phpstan, rector (dry-run), php-cs-fixer (dry-run) |
| `composer lint:fix` | Apply fixes: rector, then php-cs-fixer |
| `composer phpstan` | Static analysis |
| `composer rector` | Rector rules (dry-run) |
| `composer phpcsfixer` | Coding style (dry-run) |
| `composer run-rabbitmq` | Throwaway RabbitMQ container in the foreground |
| `composer rabbitmq-start` / `rabbitmq-stop` / `rabbitmq-wait` | Manage the docker-compose RabbitMQ test stack |

Before opening a PR, `composer lint` and `composer test` must pass.

## CI

`.github/workflows/ci.yml` runs `lint`, `test` (PHP 8.4/8.5 × Symfony 6.4/7.4/8.0,
with RabbitMQ) and `coverage` (90% line floor). The final job `ci-ok` depends on all
of them and is the only required check.

## Rules for agents

- Do not pick an issue on your own initiative: run `bin/pick-issue.sh`, show the
  candidates and wait for the user to choose.
- Wait for CI (`gh run watch`) before you ask for a merge.
- The user reviews the PR and approves the merge. Never merge on your own initiative
  and never use `--admin` or another bypass flag.
- Fix review comments in new commits (never amend a pushed commit).
- Use subagents for implementation and review where possible. A reviewer reports
  only defects that change behaviour, not style.
- After a squash merge, review the merged change for follow-up findings. Check for an
  existing issue first (`gh issue list --search "<keywords>"`), and open a new one only
  when nothing covers it. This is step 8 of [docs/workflow.md](docs/workflow.md).
