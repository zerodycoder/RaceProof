<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

$baselinePath = $root.'/api/public-api.json';
$arguments = apiArguments();

if (! is_file($baselinePath)) {
    fwrite(STDERR, "Public API baseline not found: {$baselinePath}\n");
    exit(1);
}

/** @var array{schema:int, functions:list<string>, types:list<class-string>, snapshot?:list<string>} $baseline */
$baseline = json_decode(
    (string) file_get_contents($baselinePath),
    true,
    512,
    JSON_THROW_ON_ERROR,
);

if (($baseline['schema'] ?? null) !== 1) {
    fwrite(STDERR, "Unsupported public API baseline schema.\n");
    exit(1);
}

$snapshot = [];

foreach ($baseline['functions'] as $function) {
    if (! function_exists($function)) {
        fwrite(STDERR, "Public API function is missing: {$function}\n");
        exit(1);
    }

    $snapshot[] = signature(new ReflectionFunction($function), 'function ');
}

foreach ($baseline['types'] as $type) {
    if (! class_exists($type) && ! interface_exists($type) && ! enum_exists($type)) {
        fwrite(STDERR, "Public API type is missing: {$type}\n");
        exit(1);
    }

    $reflection = new ReflectionClass($type);
    $snapshot[] = typeDeclaration($reflection);

    $constants = array_filter(
        $reflection->getReflectionConstants(),
        static fn (ReflectionClassConstant $constant): bool => $constant->getDeclaringClass()->getName() === $reflection->getName()
            && $constant->isPublic()
            && ! internal($constant->getDocComment()),
    );

    usort($constants, static fn (ReflectionClassConstant $left, ReflectionClassConstant $right): int => $left->getName() <=> $right->getName());

    foreach ($constants as $constant) {
        $snapshot[] = sprintf(
            'constant %s::%s = %s',
            $type,
            $constant->getName(),
            exported($constant->getValue()),
        );
    }

    $properties = array_filter(
        $reflection->getProperties(ReflectionProperty::IS_PUBLIC),
        static fn (ReflectionProperty $property): bool => $property->getDeclaringClass()->getName() === $reflection->getName()
            && ! internal($property->getDocComment()),
    );

    usort($properties, static fn (ReflectionProperty $left, ReflectionProperty $right): int => $left->getName() <=> $right->getName());

    foreach ($properties as $property) {
        $snapshot[] = propertySignature($property);
    }

    $methods = array_filter(
        $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $reflection->getName()
            && ! $method->isConstructor()
            && ! $method->isDestructor()
            && ! internal($method->getDocComment()),
    );

    usort($methods, static fn (ReflectionMethod $left, ReflectionMethod $right): int => $left->getName() <=> $right->getName());

    foreach ($methods as $method) {
        $snapshot[] = signature($method, 'method '.$type.'::');
    }
}

$updatedBaseline = json_encode(
    [...$baseline, 'snapshot' => $snapshot],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
)."\n";

if (in_array('--write', $arguments, true)) {
    file_put_contents($baselinePath, $updatedBaseline, LOCK_EX);
    echo "Updated {$baselinePath} after explicit API review.\n";
    exit(0);
}

if (in_array('--print', $arguments, true)) {
    echo $updatedBaseline;
    exit(0);
}

$expected = $baseline['snapshot'] ?? [];

if ($expected !== $snapshot) {
    fwrite(STDERR, "The frozen public API changed.\n");

    foreach (array_diff($expected, $snapshot) as $missing) {
        fwrite(STDERR, "- {$missing}\n");
    }

    foreach (array_diff($snapshot, $expected) as $added) {
        fwrite(STDERR, "+ {$added}\n");
    }

    fwrite(STDERR, "Review the change against docs/versioning.md, then intentionally refresh api/public-api.json.\n");
    exit(1);
}

echo 'Public API baseline matches '.count($snapshot)." frozen signatures.\n";

/** @param ReflectionClass<object> $reflection */
function typeDeclaration(ReflectionClass $reflection): string
{
    $modifiers = [];

    if ($reflection->isFinal()) {
        $modifiers[] = 'final';
    }

    if ($reflection->isReadOnly()) {
        $modifiers[] = 'readonly';
    }

    $kind = match (true) {
        $reflection->isInterface() => 'interface',
        $reflection->isEnum() => 'enum',
        $reflection->isTrait() => 'trait',
        default => 'class',
    };
    $declaration = trim(implode(' ', [...$modifiers, $kind, $reflection->getName()]));
    $parent = $reflection->getParentClass();

    if ($parent !== false) {
        $declaration .= ' extends '.$parent->getName();
    }

    $interfaces = $reflection->getInterfaceNames();
    sort($interfaces);

    if ($interfaces !== []) {
        $declaration .= ($reflection->isInterface() ? ' extends ' : ' implements ').implode(', ', $interfaces);
    }

    return $declaration;
}

function propertySignature(ReflectionProperty $property): string
{
    $modifiers = ['property'];

    if ($property->isStatic()) {
        $modifiers[] = 'static';
    }

    if ($property->isReadOnly()) {
        $modifiers[] = 'readonly';
    }

    $signature = implode(' ', $modifiers).' '.$property->getDeclaringClass()->getName().'::$'.$property->getName();
    $type = typeName($property->getType());

    return $type === '' ? $signature : $signature.': '.$type;
}

function signature(ReflectionFunctionAbstract $function, string $prefix): string
{
    $declaringClass = $function instanceof ReflectionMethod
        ? $function->getDeclaringClass()->getName()
        : null;
    $signature = $prefix;

    if ($function instanceof ReflectionMethod && $function->isStatic()) {
        $signature .= 'static ';
    }

    $signature .= $function->getName().'(';
    $signature .= implode(', ', array_map(
        static fn (ReflectionParameter $parameter): string => parameterSignature($parameter, $declaringClass),
        $function->getParameters(),
    ));
    $signature .= ')';
    $returnType = typeName($function->getReturnType(), $declaringClass);

    return $returnType === '' ? $signature : $signature.': '.$returnType;
}

function parameterSignature(ReflectionParameter $parameter, ?string $declaringClass = null): string
{
    $signature = '';
    $type = typeName($parameter->getType(), $declaringClass);

    if ($type !== '') {
        $signature .= $type.' ';
    }

    if ($parameter->isPassedByReference()) {
        $signature .= '&';
    }

    if ($parameter->isVariadic()) {
        $signature .= '...';
    }

    $signature .= '$'.$parameter->getName();

    if ($parameter->isDefaultValueAvailable() && ! $parameter->isVariadic()) {
        $signature .= ' = '.exported($parameter->getDefaultValue());
    }

    return $signature;
}

function typeName(?ReflectionType $type, ?string $declaringClass = null): string
{
    if ($type === null) {
        return '';
    }

    if ($type instanceof ReflectionNamedType) {
        $name = $type->getName();

        if ($declaringClass !== null && $name === $declaringClass) {
            $name = 'self';
        }

        return $type->allowsNull() && ! in_array($name, ['mixed', 'null'], true) ? '?'.$name : $name;
    }

    if ($type instanceof ReflectionUnionType) {
        return implode('|', array_map(
            static fn (ReflectionType $member): string => typeName($member, $declaringClass),
            $type->getTypes(),
        ));
    }

    if ($type instanceof ReflectionIntersectionType) {
        return implode('&', array_map(
            static fn (ReflectionType $member): string => typeName($member, $declaringClass),
            $type->getTypes(),
        ));
    }

    return (string) $type;
}

function internal(string|false $docComment): bool
{
    return is_string($docComment) && str_contains($docComment, '@internal');
}

function exported(mixed $value): string
{
    return match (true) {
        is_string($value) => "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'",
        is_array($value) => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        $value === null => 'null',
        $value === true => 'true',
        $value === false => 'false',
        is_int($value), is_float($value) => (string) $value,
        default => throw new RuntimeException('Unsupported public API default value type: '.get_debug_type($value)),
    };
}

/** @return list<string> */
function apiArguments(): array
{
    $arguments = $_SERVER['argv'] ?? [];

    if (! is_array($arguments)) {
        return [];
    }

    return array_values(array_filter($arguments, is_string(...)));
}
