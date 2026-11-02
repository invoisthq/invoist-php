<?php

/**
 * Generates src/Generated from the API's OpenAPI description.
 *
 *     php scripts/generate.php           write src/Generated
 *     php scripts/generate.php --check   fail if src/Generated is out of date
 *
 * The spec is testdata/openapi.yaml, or the file named by $INVOIST_OPENAPI.
 *
 * Every object schema becomes a final readonly class with a fromArray()
 * decoder and jsonSerialize(); every named string enum becomes a class of
 * constants; every operation whose JSON response is an inline object gets a
 * class too. Objects nested inside a schema are named after their parent and
 * property (BillsStats.top_senders -> BillsStatsTopSender).
 *
 * The output depends only on the spec, so running it twice gives the same
 * bytes, which is what the drift check relies on.
 */

declare(strict_types=1);

namespace Invoist\Scripts;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$check = in_array('--check', array_slice(Y::strs($_SERVER['argv'] ?? []), 1), true);

$specPath = (string) getenv('INVOIST_OPENAPI');
$specPath = $specPath !== '' ? $specPath : 'testdata/openapi.yaml';
if (!str_starts_with($specPath, '/')) {
    $specPath = $root . '/' . $specPath;
}
if (!is_file($specPath)) {
    fwrite(STDERR, "generate: no OpenAPI spec at $specPath (set INVOIST_OPENAPI)\n");
    exit(2);
}

$files = (new ModelGenerator(Y::map(Yaml::parseFile($specPath))))->run();

$outDir = $root . '/src/Generated';
$existing = is_dir($outDir) ? glob($outDir . '/*.php') : [];
$existing = $existing === false ? [] : $existing;

if ($check) {
    $stale = [];
    foreach ($files as $name => $contents) {
        $path = "$outDir/$name";
        if (!is_file($path) || file_get_contents($path) !== $contents) {
            $stale[] = $name;
        }
    }
    foreach ($existing as $path) {
        if (!isset($files[basename($path)])) {
            $stale[] = basename($path) . ' (no longer generated)';
        }
    }
    if ($stale !== []) {
        fwrite(STDERR, "generate: src/Generated is out of date; run `composer generate`:\n  " . implode("\n  ", $stale) . "\n");
        exit(1);
    }
    echo 'generate: src/Generated is up to date (' . count($files) . " files)\n";
    exit(0);
}

if (!is_dir($outDir) && !mkdir($outDir, 0o755, true)) {
    fwrite(STDERR, "generate: cannot create $outDir\n");
    exit(2);
}
foreach ($existing as $path) {
    if (!isset($files[basename($path)])) {
        unlink($path);
    }
}
foreach ($files as $name => $contents) {
    file_put_contents("$outDir/$name", $contents);
}
echo 'generate: wrote ' . count($files) . " files to src/Generated\n";

/** Typed reads from the parsed YAML, which is all `mixed`. */
final class Y
{
    /** @return array<string, mixed> */
    public static function map(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public static function maps(mixed $value): array
    {
        return array_map(self::map(...), is_array($value) ? array_values($value) : []);
    }

    public static function str(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';
    }

    /**
     * A list of strings; nulls (as in an enum's `null`) are left out.
     *
     * @return list<string>
     */
    public static function strs(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [$value] as $item) {
            if ($item !== null) {
                $out[] = self::str($item);
            }
        }

        return $out;
    }

    /**
     * A schema's `type`, which may be a string or a list (`[string, "null"]`).
     *
     * @param array<string, mixed> $schema
     * @return list<string>
     */
    public static function types(array $schema): array
    {
        return isset($schema['type']) ? self::strs($schema['type']) : [];
    }
}

/** One property of a generated class, resolved to its PHP type and decoder. */
final readonly class Prop
{
    public function __construct(
        public string $name,
        public string $phpType,
        public string $docType,
        public bool $nullable,
        public string $decoder,
        public string $description,
    ) {
    }
}

/** A resolved schema type: the PHP and PHPDoc types, the Decode method, and words for the docblock. */
final readonly class Type
{
    public function __construct(
        public string $php,
        public string $doc,
        public bool $nullable,
        public string $method,
        public string $arg = '',
        public string $note = '',
    ) {
    }

    public function orNull(bool $nullable): self
    {
        return new self($this->php, $this->doc, $this->nullable || $nullable, $this->method, $this->arg, $this->note);
    }
}

final class ModelGenerator
{
    private const string NAMESPACE_NAME = 'Invoist\\Generated';

    private const string HEADER = '// Generated by scripts/generate.php from openapi.yaml. Do not edit by hand.';

    /** Names for inline operation responses where `<OperationId>Response` would read worse. */
    private const array RESPONSE_NAMES = [
        'extractDocument' => 'ExtractResult',
        'extractDocumentBatch' => 'BatchResult',
        'getDocument' => 'DocumentDetail',
        'listWebhookEndpoints' => 'WebhookEndpointList',
    ];

    /** @var array<string, array<string, mixed>> */
    private array $schemas = [];

    /** @var array<string, array{description: string, props: list<Prop>, source: string}> */
    private array $classes = [];

    /** @var array<string, array{description: string, values: list<string>}> */
    private array $enums = [];

    /** @param array<string, mixed> $spec */
    public function __construct(private readonly array $spec)
    {
        foreach (Y::map(Y::map($spec['components'] ?? [])['schemas'] ?? []) as $name => $schema) {
            $this->schemas[$name] = Y::map($schema);
        }
    }

    /** @return array<string, string> file name => contents, sorted by name */
    public function run(): array
    {
        foreach ($this->schemas as $name => $schema) {
            if ($this->isEnum($schema)) {
                $this->enums[$name] = ['description' => Y::str($schema['description'] ?? ''), 'values' => Y::strs($schema['enum'])];
            }
        }
        foreach ($this->schemas as $name => $schema) {
            if (!$this->isEnum($schema) && $this->isObject($schema)) {
                $this->addClass($name, $schema, "components.schemas.$name");
            }
        }
        foreach (Y::map($this->spec['paths'] ?? []) as $path => $item) {
            foreach (Y::map($item) as $method => $operation) {
                $operation = Y::map($operation);
                if (isset($operation['operationId'])) {
                    $this->addOperation($operation, strtoupper($method) . " $path");
                }
            }
        }

        $files = [];
        foreach ($this->classes as $name => $class) {
            $files["$name.php"] = $this->renderClass($name, $class);
        }
        foreach ($this->enums as $name => $enum) {
            $files["$name.php"] = $this->renderEnum($name, $enum);
        }
        ksort($files);

        return $files;
    }

    /** @param array<string, mixed> $operation */
    private function addOperation(array $operation, string $label): void
    {
        $bodies = [];
        foreach (Y::map($operation['responses'] ?? []) as $status => $response) {
            $schema = Y::map(Y::map(Y::map(Y::map($response)['content'] ?? [])['application/json'] ?? [])['schema'] ?? []);
            // "$status": PHP turns the key "200" into the int 200.
            if (!str_starts_with("$status", '2') || $schema === [] || isset($schema['$ref'])) {
                continue; // no JSON body, or a named schema that is already a class
            }
            $bodies[] = $schema;
        }
        if ($bodies === []) {
            return;
        }
        $id = Y::str($operation['operationId']);
        $name = self::RESPONSE_NAMES[$id] ?? ucfirst($id) . 'Response';
        $schema = count($bodies) === 1 ? $bodies[0] : $this->mergeResponses($bodies);
        $summary = Y::str($operation['summary'] ?? '');
        $schema['description'] = "The response of `$label`" . ($summary !== '' ? ": $summary." : '.');
        $this->addClass($name, $schema, $label);
    }

    /**
     * Several success responses (POST /v1/extract answers 200 for a duplicate
     * and 201 for a new document) become one class: every property any of
     * them has, required only when all of them require it.
     *
     * @param list<array<string, mixed>> $bodies
     * @return array<string, mixed>
     */
    private function mergeResponses(array $bodies): array
    {
        $properties = [];
        $required = null;
        foreach ($bodies as $body) {
            foreach (Y::map($body['properties'] ?? []) as $prop => $schema) {
                $schema = Y::map($schema);
                unset($schema['const']);
                $properties[$prop] ??= $schema;
            }
            $these = Y::strs($body['required'] ?? []);
            $required = $required === null ? $these : array_values(array_intersect($required, $these));
        }

        return ['type' => 'object', 'properties' => $properties, 'required' => $required ?? []];
    }

    /** @param array<string, mixed> $schema */
    private function addClass(string $name, array $schema, string $source): void
    {
        if (isset($this->classes[$name]) || isset($this->enums[$name])) {
            throw new RuntimeException("generate: two schemas want the class name $name");
        }
        // Reserve the name before resolving properties, so a nested class can't take it.
        $this->classes[$name] = ['description' => '', 'props' => [], 'source' => $source];

        [$properties, $required, $description] = $this->flatten($schema);
        $props = [];
        foreach ($properties as $prop => $propSchema) {
            $props[] = $this->resolveProp($name, $prop, $propSchema, in_array($prop, $required, true));
        }
        $this->classes[$name] = ['description' => $description, 'props' => $props, 'source' => $source];
    }

    /**
     * Collapses allOf into one property list.
     *
     * @param array<string, mixed> $schema
     * @return array{0: array<string, array<string, mixed>>, 1: list<string>, 2: string}
     */
    private function flatten(array $schema): array
    {
        $properties = [];
        $required = [];
        $description = Y::str($schema['description'] ?? '');
        foreach (Y::maps($schema['allOf'] ?? []) as $part) {
            [$p, $r, $d] = $this->flatten(isset($part['$ref']) ? $this->schemas[$this->refName($part['$ref'])] : $part);
            $properties = array_merge($properties, $p);
            $required = array_merge($required, $r);
            $description = $description !== '' ? $description : $d;
        }
        foreach (Y::map($schema['properties'] ?? []) as $prop => $propSchema) {
            $properties[$prop] = Y::map($propSchema);
        }
        $required = array_values(array_unique(array_merge($required, Y::strs($schema['required'] ?? []))));

        return [$properties, $required, $description];
    }

    /** @param array<string, mixed> $schema */
    private function resolveProp(string $class, string $prop, array $schema, bool $required): Prop
    {
        $type = $this->resolveType($class, $prop, $schema);
        $description = trim(Y::str($schema['description'] ?? '') . ' ' . $type->note);
        $nullable = $type->nullable || !$required;
        if (!$required) {
            $description = trim($description . ' Absent when the API leaves it out.');
        }
        // Optional and nullable fields use the opt* variant, which accepts null and a missing key.
        $method = $nullable ? 'opt' . ucfirst($type->method) : $type->method;
        $args = var_export($prop, true) . ($type->arg !== '' ? ", {$type->arg}" : '');

        return new Prop(
            $prop,
            $nullable ? '?' . $type->php : $type->php,
            $nullable ? $type->doc . '|null' : $type->doc,
            $nullable,
            "\$d->$method($args)",
            $description,
        );
    }

    /** @param array<string, mixed> $schema */
    private function resolveType(string $class, string $prop, array $schema): Type
    {
        if (isset($schema['$ref'])) {
            $target = $this->refName($schema['$ref']);
            $nullable = in_array('null', Y::types($this->schemas[$target]), true);
            if (isset($this->enums[$target])) {
                return new Type('string', 'string', $nullable, 'string', note: "One of the $target constants.");
            }
            if (!$this->isObject($this->schemas[$target])) {
                throw new RuntimeException("generate: $class.$prop refers to $target, which is neither an object nor an enum");
            }

            return new Type($target, $target, $nullable, 'object', "$target::fromArray(...)");
        }
        foreach (['oneOf', 'anyOf'] as $key) {
            if (isset($schema[$key])) {
                $all = Y::maps($schema[$key]);
                $options = array_values(array_filter($all, static fn (array $s): bool => Y::types($s) !== ['null']));
                if (count($options) !== 1) {
                    throw new RuntimeException("generate: $class.$prop has a $key this generator can't express");
                }

                return $this->resolveType($class, $prop, $options[0])->orNull(count($options) < count($all));
            }
        }
        if (isset($schema['allOf'])) {
            $parts = Y::maps($schema['allOf']);
            if (count($parts) !== 1) {
                throw new RuntimeException("generate: $class.$prop has an allOf of several schemas");
            }

            return $this->resolveType($class, $prop, $parts[0]);
        }

        $types = Y::types($schema);
        $nullable = in_array('null', $types, true);
        $types = array_values(array_diff($types, ['null']));
        if (count($types) !== 1) {
            throw new RuntimeException("generate: $class.$prop has no single type");
        }
        $note = isset($schema['enum']) ? 'One of: ' . $this->quoted(Y::strs($schema['enum'])) . '.' : '';
        $format = Y::str($schema['format'] ?? '');

        return match ($types[0]) {
            'string' => new Type(
                'string',
                'string', $nullable, 'string', note: $note !== '' ? $note : match ($format) {
                'date' => 'A `YYYY-MM-DD` date.',
                'date-time' => 'An RFC 3339 timestamp, in UTC.',
                default => '',
                }
            ),
            'integer' => new Type('int', 'int', $nullable, 'int', note: $note),
            'number' => new Type('float', 'float', $nullable, 'float', note: $note),
            'boolean' => new Type('bool', 'bool', $nullable, 'bool', note: $note),
            'array' => $this->resolveArray($class, $prop, Y::map($schema['items'] ?? []), $nullable),
            'object' => $this->resolveObject($class, $prop, $schema, $nullable),
            default => throw new RuntimeException("generate: $class.$prop has the unsupported type {$types[0]}"),
        };
    }

    /** @param array<string, mixed> $schema */
    private function resolveObject(string $class, string $prop, array $schema, bool $nullable): Type
    {
        if (isset($schema['properties'])) {
            $nested = $class . $this->pascal($prop);
            $this->addClass($nested, $schema, "$class.$prop");

            return new Type($nested, $nested, $nullable, 'object', "$nested::fromArray(...)");
        }
        $additional = Y::map($schema['additionalProperties'] ?? []);
        if (Y::types($additional) === ['number']) {
            return new Type('array', 'array<string, float>', $nullable, 'floatMap');
        }

        return new Type('array', 'array<string, mixed>', $nullable, 'map');
    }

    /** @param array<string, mixed> $items */
    private function resolveArray(string $class, string $prop, array $items, bool $nullable): Type
    {
        if (isset($items['$ref'])) {
            $target = $this->refName($items['$ref']);
            if (isset($this->enums[$target])) {
                return new Type('array', 'list<string>', $nullable, 'strings', note: "Each one of the $target constants.");
            }

            return new Type('array', "list<$target>", $nullable, 'list', "$target::fromArray(...)");
        }
        $type = Y::types($items)[0] ?? 'string';
        if ($type === 'object') {
            $nested = $class . $this->pascal($this->singular($prop));
            $this->addClass($nested, $items, "$class.{$prop}[]");

            return new Type('array', "list<$nested>", $nullable, 'list', "$nested::fromArray(...)");
        }
        if ($type === 'string') {
            $note = isset($items['enum']) ? 'Each one of: ' . $this->quoted(Y::strs($items['enum'])) . '.' : '';

            return new Type('array', 'list<string>', $nullable, 'strings', note: $note);
        }

        throw new RuntimeException("generate: $class.$prop is a list of $type, which this generator can't express");
    }

    /** @param array{description: string, props: list<Prop>, source: string} $class */
    private function renderClass(string $name, array $class): string
    {
        // Required, non-null properties first: PHP deprecates optional parameters before required ones.
        $props = $class['props'];
        usort($props, static fn (Prop $a, Prop $b): int => $a->nullable <=> $b->nullable);

        $out = "<?php\n\n" . self::HEADER . "\n\ndeclare(strict_types=1);\n\nnamespace " . self::NAMESPACE_NAME . ";\n\n";
        $out .= "use Invoist\\Internal\\Decode;\n\n/**\n";
        if (trim($class['description']) !== '') {
            $out .= $this->docText($class['description'], ' * ') . " *\n";
        }
        $out .= " * From `{$class['source']}` in openapi.yaml. Field names are the API's own.\n";
        $out .= " */\nfinal readonly class $name implements \\JsonSerializable\n{\n";

        $out .= "    public function __construct(\n";
        foreach ($props as $prop) {
            // A @var only where it says more than the native type (the element type of an array).
            $needsVar = str_contains($prop->docType, '<');
            if ($prop->description !== '' || $needsVar) {
                $out .= "        /**\n";
                $out .= $prop->description !== '' ? $this->docText($prop->description, '         * ') : '';
                $out .= $needsVar ? "         * @var {$prop->docType}\n" : '';
                $out .= "         */\n";
            }
            $out .= "        public {$prop->phpType} \${$prop->name}" . ($prop->nullable ? ' = null' : '') . ",\n";
        }
        $out .= "    ) {\n    }\n\n";

        $out .= "    /**\n     * Decodes the API's JSON (as an associative array). Unknown fields are ignored.\n     *\n";
        $out .= "     * @param array<array-key, mixed> \$data\n";
        $out .= "     * @throws \\UnexpectedValueException when a field is missing or has the wrong type\n     */\n";
        $out .= "    public static function fromArray(array \$data): self\n    {\n";
        $out .= "        \$d = new Decode(\$data, '$name');\n\n        return new self(\n";
        foreach ($props as $prop) {
            $out .= "            {$prop->name}: {$prop->decoder},\n";
        }
        $out .= "        );\n    }\n\n";

        $out .= "    /** @return array<string, mixed> the fields under the API's names, for json_encode() */\n";
        $out .= "    public function jsonSerialize(): array\n    {\n        return [\n";
        foreach ($class['props'] as $prop) {
            $out .= "            '{$prop->name}' => \$this->{$prop->name},\n";
        }

        return $out . "        ];\n    }\n}\n";
    }

    /** @param array{description: string, values: list<string>} $enum */
    private function renderEnum(string $name, array $enum): string
    {
        $out = "<?php\n\n" . self::HEADER . "\n\ndeclare(strict_types=1);\n\nnamespace " . self::NAMESPACE_NAME . ";\n\n/**\n";
        if (trim($enum['description']) !== '') {
            $out .= $this->docText($enum['description'], ' * ') . " *\n";
        }
        $out .= " * The values of `$name` in openapi.yaml, as constants. Fields carrying them are\n";
        $out .= " * plain strings, so a value added to the API later still decodes: compare against\n";
        $out .= " * these rather than assuming the list is complete.\n */\nfinal class $name\n{\n";
        foreach ($enum['values'] as $value) {
            $const = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '_', $value));
            $out .= "    public const string $const = " . var_export($value, true) . ";\n";
        }
        $out .= "\n    /** Every value the spec lists. */\n    public const array ALL = [\n";
        foreach ($enum['values'] as $value) {
            $out .= '        ' . var_export($value, true) . ",\n";
        }

        return $out . "    ];\n\n    private function __construct()\n    {\n    }\n}\n";
    }

    /** Wraps prose into docblock lines, keeping paragraphs and escaping the comment terminator. */
    private function docText(string $text, string $prefix): string
    {
        $text = str_replace('*/', '*&#47;', trim($text));
        $width = max(40, 100 - strlen($prefix));
        $out = [];
        $paragraphs = preg_split('/\n\s*\n/', $text);
        foreach ($paragraphs === false ? [] : $paragraphs as $paragraph) {
            if ($out !== []) {
                $out[] = rtrim($prefix);
            }
            $paragraph = (string) preg_replace('/\s+/', ' ', trim($paragraph));
            foreach (explode("\n", wordwrap($paragraph, $width)) as $line) {
                $out[] = $prefix . $line;
            }
        }

        return implode("\n", $out) . "\n";
    }

    /** @param list<string> $values */
    private function quoted(array $values): string
    {
        return implode(', ', array_map(static fn (string $v): string => "`$v`", $values));
    }

    /** @param array<string, mixed> $schema */
    private function isEnum(array $schema): bool
    {
        return isset($schema['enum']) && in_array('string', Y::types($schema), true);
    }

    /** @param array<string, mixed> $schema */
    private function isObject(array $schema): bool
    {
        return in_array('object', Y::types($schema), true) || isset($schema['allOf']);
    }

    private function refName(mixed $ref): string
    {
        $ref = Y::str($ref);
        $prefix = '#/components/schemas/';
        $name = substr($ref, strlen($prefix));
        if (!str_starts_with($ref, $prefix) || !isset($this->schemas[$name])) {
            throw new RuntimeException("generate: unsupported or dangling \$ref \"$ref\"");
        }

        return $name;
    }

    private function pascal(string $snake): string
    {
        return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $snake)));
    }

    private function singular(string $word): string
    {
        return str_ends_with($word, 's') && !str_ends_with($word, 'ss') ? substr($word, 0, -1) : $word;
    }
}
