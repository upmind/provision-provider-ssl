# Contributing

Contributions are **welcome** and will be fully **credited**.

Please read and understand the following contribution guide before creating an issue or pull request.

## Development Workflow

For information on how to develop new provision providers, please read the [WORKFLOW](WORKFLOW.md) guide.

## Etiquette

This project is open source, and as such, the maintainers give their free time to build and maintain the source code
held within. They make the code freely available in the hope that it will be of use to other developers. It would be
extremely unfair for them to suffer abuse or anger for their hard work.

Please be considerate towards maintainers when raising issues or presenting pull requests. Let's show the
world that developers are civilized and selfless people.

It's the duty of the maintainer to ensure that all submissions to the project are of sufficient
quality to benefit the project. Many developers have different skillsets, strengths, and weaknesses. Respect the maintainer's decision, and do not be upset or abusive if your submission is not used.

## Viability

When requesting or submitting new features, first consider whether it might be useful to others. Open
source projects are used by many developers, who may have entirely different needs to your own. Think about
whether or not your feature is likely to be used by other users of the project.

## Procedure

Before filing an issue:

- Attempt to replicate the problem, to ensure that it wasn't a coincidental incident.
- Check to make sure your feature suggestion isn't already present within the project.
- Check the pull requests tab to ensure that the feature/fix isn't already in progress.

Before submitting a pull request:

- Check the codebase to ensure that your feature doesn't already exist.
- Check the pull requests to ensure that another person hasn't already submitted the feature or fix.

## Requirements

If the project maintainer has any additional requirements, you will find them listed here.

- **[PSR-12 Coding Standard](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-12-coding-style-guide.md)** - The easiest way to apply the conventions is to run `make coding-standards`, or `./fix_formatting.sh` outside of docker.

- **Support PHP 7.4** - This package supports `^7.4 || ^8.0`, so avoid syntax introduced in PHP 8 (union types, constructor promotion, enums, `match`, nullsafe operator). CI runs static analysis on 7.4 and 8.1-8.3.

- **Document any change in behaviour** - Make sure the `README.md` and any other relevant documentation are kept up-to-date.

- **Consider our release cycle** - We try to follow [SemVer v2.0.0](http://semver.org/). Randomly breaking public APIs is not an option.

- **Add a changelog entry** - Describe each user-facing change under `## Unreleased` in `CHANGELOG.md`. Start the entry with `**Breaking:**` if the change can break consumers, e.g. a behaviour change.

- **One pull request per feature** - If you want to do more than one thing, send multiple pull requests.

- **Send coherent history** - Make sure each individual commit in your pull request is meaningful. If you had to make multiple intermediate commits while developing, please [squash them](http://www.git-scm.com/book/en/v2/Git-Tools-Rewriting-History#Changing-Multiple-Commit-Messages) before submitting.

## Semver check

The **Semver** CI job compares the package contract with the latest tag on the target branch's
release line:

- **DataSet rules** of every `Params`, `Result` and provider `Configuration` DataSet. A new required
  input field, a narrower input rule or a new result value is breaking. A removed field or another
  rule change is possibly breaking.
- **`Category` method signatures.**
- **`composer.json` `require` constraints.** A constraint that no longer allows every version the
  old one did is breaking. A new requirement is possibly breaking.
- **`**Breaking:**` changelog entries.**

Breaking findings fail pull requests into maintenance branches and release pull requests that do not
bump the major version. On other pull requests into `main` they are warnings. A maintainer can add
the `semver: not breaking` label to a pull request to turn possibly-breaking findings into warnings.

Findings appear as annotations, in the job summary and in a pull request comment that updates on
each run. Pull requests from forks get no comment.

## Changelog check

The **Changelog** CI job compares each released version's entries in `CHANGELOG.md` with the
entries at that version's tag. Released notes do not change, so a difference fails CI. It catches
a merge of a branch that predates a release: Git can put its Unreleased entries under the new
version heading without a conflict. A pull request must be up to date with its target branch before
it merges, so the check runs against the latest release.

## Releasing

Maintainers release from the Actions tab:

1. Run the **Prepare release** workflow on the release branch with the new version, e.g. `1.2.0`.
   It moves the `## Unreleased` entries in `CHANGELOG.md` under a `## [1.2.0]` heading and opens a
   `Release v1.2.0` pull request.
2. On the pull request, click **Approve workflows to run**. GitHub holds CI on a pull request that a
   workflow opens until a maintainer approves it. Review the pull request. Edit `CHANGELOG.md` on its
   branch to change the notes or the date.
3. Merge the pull request. The **Release** workflow tags the merge commit `v1.2.0` and creates the
   GitHub release from the changelog entries. Packagist picks up the tag.

The version must be `MAJOR.MINOR.PATCH` and `## Unreleased` must have entries. The release branch
sets which versions it may release:

| Branch | Versions | Must be greater than |
|---|---|---|
| `main` | any | the latest tag |
| `vN-dev`, e.g. `v1-dev` | `N.*.*` | the latest `vN.*` tag |
| `vN.M-dev`, e.g. `v1.2-dev` | `N.M.*` | the latest `vN.M.*` tag |

A maintenance release becomes the GitHub "Latest" release only if it is the highest version. Each
branch keeps its own `CHANGELOG.md`; copy an entry to `main` in a separate pull request if it
belongs there too. Cut a maintenance branch from a tag that includes the release workflows, or add
them to the branch first.

**Happy coding**!
