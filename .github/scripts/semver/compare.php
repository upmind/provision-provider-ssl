<?php

/**
 * Compare two contract snapshots from snapshot.php and report changes that can break consumers,
 * as GitHub Actions annotations and a Markdown report.
 *
 * Usage: php compare.php --autoload=<composer/semver autoload.php> [options] <baseline.json> <head.json>
 *
 * --fail            Report breaking findings as errors and exit 1. Otherwise report them as warnings.
 * --override        Report possibly-breaking findings as warnings, even with --fail.
 * --breaking-entry  Report a `**Breaking:**` changelog entry as a breaking finding.
 * --baseline=<tag>  Name the baseline in the report.
 * --report=<path>   Write the Markdown report to this file.
 */

declare(strict_types=1);

use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;

const BREAKING = 'breaking';
const POSSIBLY = 'possibly breaking';

/**
 * @var array<int, array{level: string, file: string, message: string, error?: bool}> $findings
 */
$findings = [];

function finding(string $level, string $file, string $message): void
{
    global $findings;
    $findings[] = ['level' => $level, 'file' => $file, 'message' => $message];
}

/**
 * @return array<string, mixed>
 */
function loadSnapshot(string $path): array
{
    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data)) {
        fwrite(STDERR, "Invalid snapshot: $path\n");
        exit(2);
    }

    return $data;
}

function datasetFile(string $class): string
{
    return 'src/' . str_replace('\\', '/', $class) . '.php';
}

/**
 * @param string[] $rules
 */
function isRequired(array $rules): bool
{
    foreach ($rules as $rule) {
        if ($rule === 'required' || strpos($rule, 'required_') === 0) {
            return true;
        }
    }

    return false;
}

/**
 * @param string[] $rules
 *
 * @return string[]|null Allowed values, or null without an `in:` rule
 */
function allowedValues(array $rules): ?array
{
    foreach ($rules as $rule) {
        if (strpos($rule, 'in:') === 0) {
            return explode(',', substr($rule, 3));
        }
    }

    return null;
}

/**
 * Rules other than requiredness, nullability and `in:`.
 *
 * @param string[] $rules
 *
 * @return string[]
 */
function otherRules(array $rules): array
{
    return array_values(array_filter($rules, function (string $rule): bool {
        return !isRequired([$rule]) && $rule !== 'nullable' && $rule !== 'sometimes' && strpos($rule, 'in:') !== 0;
    }));
}

/**
 * @param string[] $old
 * @param string[] $new
 */
function compareField(string $class, string $field, array $old, array $new, bool $isInput): void
{
    $file = datasetFile($class);
    $name = "$class::$field";

    $wasRequired = isRequired($old);
    $isRequired = isRequired($new);
    $requiredRules = function (array $rules): array {
        return array_values(array_filter($rules, function (string $rule): bool {
            return isRequired([$rule]);
        }));
    };
    // a plain `required` field cannot get stricter
    if ($isInput && !in_array('required', $old, true) && array_diff($requiredRules($new), $requiredRules($old))) {
        finding(BREAKING, $file, "Input field $name is now required ("
            . implode(', ', $requiredRules($new)) . ')');
    }
    if (!$isInput && $wasRequired && !$isRequired) {
        finding(BREAKING, $file, "Result field $name is no longer required");
    }
    if (!$isInput && !in_array('nullable', $old, true) && in_array('nullable', $new, true)) {
        finding(BREAKING, $file, "Result field $name is now nullable");
    }

    $oldValues = allowedValues($old);
    $newValues = allowedValues($new);
    if ($oldValues !== null || $newValues !== null) {
        if ($isInput && $newValues !== null && ($oldValues === null || array_diff($oldValues, $newValues))) {
            $removed = $oldValues === null ? 'any value' : implode(', ', array_diff($oldValues, $newValues));
            finding(BREAKING, $file, "Input field $name no longer accepts: $removed");
        }
        if (!$isInput && $oldValues !== null && ($newValues === null || array_diff($newValues, $oldValues))) {
            $added = $newValues === null ? 'any value' : implode(', ', array_diff($newValues, $oldValues));
            finding(BREAKING, $file, "Result field $name can now return: $added");
        }
    }

    // a tighter input or a looser result can break consumers
    $oldOther = otherRules($old);
    $newOther = otherRules($new);
    $risky = $isInput ? array_diff($newOther, $oldOther) : array_diff($oldOther, $newOther);
    if ($risky) {
        finding(POSSIBLY, $file, sprintf(
            '%s field %s rules changed from [%s] to [%s]',
            $isInput ? 'Input' : 'Result',
            $name,
            implode(', ', $oldOther),
            implode(', ', $newOther)
        ));
    }
}

/**
 * @param array<string, array<string, string[]>> $old
 * @param array<string, array<string, string[]>> $new
 */
function compareDatasets(array $old, array $new): void
{
    foreach ($old as $class => $oldFields) {
        $file = datasetFile($class);
        if (!isset($new[$class])) {
            finding(POSSIBLY, $file, "DataSet $class was removed");
            continue;
        }

        $isInput = substr($class, -strlen('Result')) !== 'Result';

        foreach ($oldFields as $field => $oldRules) {
            if (!array_key_exists($field, $new[$class])) {
                finding(POSSIBLY, $file, ($isInput ? 'Input' : 'Result') . " field $class::$field was removed");
                continue;
            }
            compareField($class, (string)$field, $oldRules, $new[$class][$field], $isInput);
        }

        foreach (array_diff_key($new[$class], $oldFields) as $field => $newRules) {
            // a new required field under a new optional parent only applies when the parent is given
            $parent = substr((string)$field, 0, (int)strrpos((string)$field, '.'));
            $parentIsNew = $parent !== '' && !array_key_exists($parent, $oldFields);
            if ($isInput && isRequired($newRules) && !$parentIsNew) {
                finding(BREAKING, $file, "New input field $class::$field is required");
            }
        }
    }
}

/**
 * @param array<string, string> $old
 * @param array<string, string> $new
 */
function compareCategory(array $old, array $new): void
{
    foreach ($old as $method => $signature) {
        if (!isset($new[$method])) {
            finding(BREAKING, 'src/Category.php', "Category::$method() was removed");
        } elseif ($new[$method] !== $signature) {
            finding(BREAKING, 'src/Category.php', "Category::$signature changed to Category::{$new[$method]}");
        }
    }
}

/**
 * @param array<string, string> $old
 * @param array<string, string> $new
 */
function compareRequire(array $old, array $new): void
{
    $parser = new VersionParser();

    foreach ($new as $package => $constraint) {
        if (!isset($old[$package])) {
            finding(POSSIBLY, 'composer.json', "New requirement $package: $constraint");
            continue;
        }
        if ($old[$package] === $constraint) {
            continue;
        }

        $wasAllowed = $parser->parseConstraints($old[$package]);
        $isAllowed = $parser->parseConstraints($constraint);
        if (!Intervals::isSubsetOf($wasAllowed, $isAllowed)) {
            finding(BREAKING, 'composer.json', "Requirement $package narrowed from {$old[$package]} to $constraint");
        }
    }
}

/**
 * @param array<int, array{level: string, file: string, message: string, error: bool}> $findings
 */
function report(array $findings, string $baseline, bool $fail, bool $override): string
{
    // the PR comment step finds its earlier comment by this marker
    $lines = ['<!-- semver-check -->', '### Semver check', ''];

    if (!$findings) {
        $lines[] = "No contract changes that can break consumers since `$baseline`.";

        return implode("\n", $lines) . "\n";
    }

    $lines[] = "Contract changes since `$baseline` that can break consumers. " . ($fail
        ? 'This branch needs a new major version for breaking changes, so errors fail the check.'
        : 'Errors would need a new major version; on this branch they are warnings.');
    $lines[] = '';
    $lines[] = '| Result | Level | File | Change |';
    $lines[] = '|---|---|---|---|';
    foreach ($findings as $finding) {
        $lines[] = sprintf(
            '| %s | %s | `%s` | %s |',
            $finding['error'] ? 'Error' : 'Warning',
            ucfirst($finding['level']),
            $finding['file'],
            str_replace('|', '\\|', $finding['message'])
        );
    }

    if ($fail && !$override && array_filter($findings, function (array $finding): bool {
        return $finding['level'] === POSSIBLY;
    })) {
        $lines[] = '';
        $lines[] = 'If the possibly-breaking changes are safe, a maintainer can add the `semver: not breaking` '
            . 'label to make them warnings.';
    }

    return implode("\n", $lines) . "\n";
}

$options = getopt('', ['autoload:', 'fail', 'override', 'breaking-entry', 'baseline:', 'report:'], $optind);
$paths = array_slice($argv, $optind);
if (count($paths) !== 2 || empty($options['autoload'])) {
    fwrite(STDERR, "Usage: php compare.php --autoload=<path> [options] <baseline.json> <head.json>\n");
    exit(2);
}
require $options['autoload'];

$baseline = loadSnapshot($paths[0]);
$head = loadSnapshot($paths[1]);

compareDatasets($baseline['datasets'] ?? [], $head['datasets'] ?? []);
compareCategory($baseline['category'] ?? [], $head['category'] ?? []);
compareRequire($baseline['require'] ?? [], $head['require'] ?? []);
if (isset($options['breaking-entry'])) {
    finding(BREAKING, 'CHANGELOG.md', 'A changelog entry is marked **Breaking:**');
}

$fail = isset($options['fail']);
$override = isset($options['override']);
$errors = 0;

foreach ($findings as &$finding) {
    $finding['error'] = $fail && ($finding['level'] === BREAKING || !$override);
    $errors += (int)$finding['error'];

    // escape for the workflow command format
    $message = strtr(
        ucfirst($finding['level']) . ': ' . $finding['message'],
        ['%' => '%25', "\r" => '%0D', "\n" => '%0A']
    );
    printf("::%s file=%s,title=Semver::%s\n", $finding['error'] ? 'error' : 'warning', $finding['file'], $message);
}
unset($finding);

if (!empty($options['report'])) {
    file_put_contents($options['report'], report($findings, $options['baseline'] ?? 'the baseline', $fail, $override));
}

printf("%d finding(s), %d error(s)\n", count($findings), $errors);
exit($errors > 0 ? 1 : 0);
