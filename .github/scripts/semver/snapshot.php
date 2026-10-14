<?php

/**
 * Print the package contract of the checkout in the working directory as JSON:
 * - datasets: rules of every DataSet in src/Data and src/<Provider>/Data, nested DataSets flattened to dotted fields
 * - category: signatures of the public methods Category declares
 * - require: composer.json require constraints
 *
 * Usage: php snapshot.php (run from the package root, after composer install)
 */

declare(strict_types=1);

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionProviders\SslCertificates\Category;

// keep stdout valid JSON; older dependencies raise deprecations on newer PHP
ini_set('display_errors', 'stderr');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

$root = getcwd();
require $root . '/vendor/autoload.php';

const PACKAGE_NAMESPACE = 'Upmind\\ProvisionProviders\\SslCertificates\\';

/**
 * @param class-string<DataSet> $class
 *
 * @return array<string, string[]>
 */
function datasetFields(string $class, string $prefix = ''): array
{
    $fields = [];

    foreach ($class::rules()->raw() as $field => $rules) {
        $rules = is_array($rules) ? $rules : explode('|', (string)$rules);
        $fields[$prefix . $field] = array_map(function ($rule): string {
            return is_string($rule) ? $rule : get_class($rule);
        }, array_values($rules));

        foreach ($rules as $rule) {
            if (is_string($rule) && class_exists($rule) && is_subclass_of($rule, DataSet::class)) {
                $fields += datasetFields($rule, $prefix . $field . '.');
            }
        }
    }

    return $fields;
}

function typeName(?ReflectionType $type): string
{
    if ($type === null) {
        return 'mixed';
    }

    $name = $type instanceof ReflectionNamedType ? $type->getName() : (string)$type;

    return ($type->allowsNull() && $name !== 'mixed' && $name !== 'null' ? '?' : '') . $name;
}

function methodSignature(ReflectionMethod $method): string
{
    $params = array_map(function (ReflectionParameter $param): string {
        return typeName($param->getType()) . ' $' . $param->getName() . ($param->isOptional() ? ' = …' : '');
    }, $method->getParameters());

    return $method->getName() . '(' . implode(', ', $params) . '): ' . typeName($method->getReturnType());
}

// load only DataSet directories, so the snapshot does not depend on provider classes
$datasets = [];
$files = array_merge(glob($root . '/src/Data/*.php') ?: [], glob($root . '/src/*/Data/*.php') ?: []);
foreach ($files as $file) {
    $relative = substr($file, strlen($root . '/src/'), -strlen('.php'));
    $class = PACKAGE_NAMESPACE . str_replace('/', '\\', $relative);
    if (!class_exists($class)) {
        continue;
    }

    $reflection = new ReflectionClass($class);
    if (!$reflection->isAbstract() && $reflection->isSubclassOf(DataSet::class)) {
        $datasets[substr($class, strlen(PACKAGE_NAMESPACE))] = datasetFields($class);
    }
}
ksort($datasets);

$category = [];
foreach ((new ReflectionClass(Category::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
    if ($method->getDeclaringClass()->getName() === Category::class) {
        $category[$method->getName()] = methodSignature($method);
    }
}
ksort($category);

$composer = json_decode((string)file_get_contents($root . '/composer.json'), true);

echo json_encode([
    'datasets' => $datasets,
    'category' => $category,
    'require' => $composer['require'] ?? [],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
