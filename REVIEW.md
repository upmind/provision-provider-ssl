# Review instructions

These rules apply to pull request reviews only. The project rules and the category contract are
included below.

@AGENTS.md

@WORKFLOW.md

## Important

Report these as Important:

- A breach of the "Public repository" rules in `AGENTS.md`, in any changed text: code, comments,
  docs, tests, fixtures or the changelog.
- A breach of the category contract or the acceptance criteria in `WORKFLOW.md`.
- A private key in a log, a message, result debug data or error data.
- A result or error message that names the reseller platform, a class, a file or a credential.
- PHP 8-only syntax. The package supports PHP 7.4.
- A user-facing change under `src/` with no entry under `## Unreleased` in `CHANGELOG.md`.
- A change that can break consumers with no `**Breaking:**` prefix on its changelog entry.
- A change to `src/Category.php`, `src/Data/` or `composer.json` `require` that the PR body does
  not justify.

## Do not report

CI checks these. Do not report them:

- Formatting and coding standards (PHP-CS-Fixer).
- Type errors (PHPStan).
- DataSet rule, `Category` signature and `require` constraint changes (Semver job).
- Differences between released changelog entries and GitHub release notes (Changelog job).

## Release pull requests

A `release/vX.Y.Z` branch only moves the `## Unreleased` entries under a new version heading.
Check that the heading, date and entries are correct. Do not review code that the PR does not
change.

## Nits

Report at most five Nits per review.
