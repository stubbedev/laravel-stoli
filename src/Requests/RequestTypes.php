<?php

declare(strict_types=1);

namespace StubbeDev\LaravelStoli\Requests;

use Illuminate\Contracts\Container\Container;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Spatie\LaravelData\Attributes\Validation\ValidationAttribute;
use Spatie\LaravelData\Support\DataConfig;
use Spatie\LaravelData\Support\DataProperty;
use Spatie\LaravelData\Support\Validation\RuleDenormalizer;
use Spatie\LaravelData\Support\Validation\ValidationPath;
use StubbeDev\LaravelStoli\Items\DataType;
use StubbeDev\LaravelStoli\TransformedTypes;
use ReflectionAttribute;
use ReflectionProperty;
use Throwable;

use function class_basename;

/**
 * The types requests take, declared by name in the route files that need them, e.g.
 * `StoreUserDataInput`, so a form can be typed by them too.
 *
 * A Data object takes in what the type generated for what it sends says, read by its
 * input names, with properties that may be left out optional, computed ones left out,
 * nested Data objects taken in as their own input types (however deep, cycles
 * included), and what its validation attributes narrow a property to. A FormRequest
 * takes in what its rules() say.
 */
final class RequestTypes
{
    private const SUFFIX = 'Input';

    /**
     * Class => its input type: the name it is declared by, the declaration, and the
     * classes whose input types the declaration refers to. A null declaration is one
     * still being built; false is a class that has none.
     *
     * @var array<string, array{name: string, declaration: DataType|null, uses: list<string>}|false>
     */
    private array $types = [];

    /**
     * Declared name => the class it was given to.
     *
     * @var array<string, string>
     */
    private array $names = [];

    public function __construct(
        private readonly TransformedTypes $transformed,
        private readonly DataConfig $data,
        private readonly RuleTypes $rules,
        private readonly Container $container,
    ) {}

    /**
     * The input type of a Data class, or null when the transformer did not transform it.
     */
    public function data(string $class): ?DataType
    {
        return $this->buildData($class) ? $this->reference($class) : null;
    }

    /**
     * The input type of a FormRequest, a subclass of one's subclass included, from its
     * rules(); null when they cannot be read.
     */
    public function formRequest(string $class): ?DataType
    {
        if (! isset($this->types[$class])) {
            $rules = self::isFormRequest($class) ? $this->formRequestRules($class) : null;
            $type = $rules === null ? null : $this->tree($rules);
            $name = $type === null ? '' : $this->name($class);

            $this->types[$class] = $type === null ? false : [
                'name' => $name,
                'declaration' => new DataType("export type {$name} = {$type->type};", $type->imports),
                'uses' => [],
            ];
        }

        return $this->types[$class] === false ? null : $this->reference($class);
    }

    public static function isFormRequest(string $class): bool
    {
        return is_subclass_of($class, FormRequest::class);
    }

    private function buildData(string $class): bool
    {
        if (isset($this->types[$class])) {
            return $this->types[$class] !== false;
        }

        $output = $this->transformed->find($class);

        try {
            $properties = $output === null ? null : $this->data->getDataClass($class)->properties->all();
        } catch (Throwable) {
            $properties = null;
        }

        if ($output === null || $properties === null) {
            $this->types[$class] = false;

            return false;
        }

        // Registered before its properties are, so a cycle back to it ends at its name.
        $name = $this->name($class);
        $this->types[$class] = ['name' => $name, 'declaration' => null, 'uses' => []];

        $fields = [];
        $optional = [];
        $overrides = [];
        $extra = [];
        $uses = [];

        foreach ($properties as $property) {
            $input = $property->inputMappedName ?? $property->name;
            $rules = $this->rules->field($this->attributeRules($property), $input);

            if ($property->computed || $rules->prohibited) {
                continue;
            }

            $outputName = $property->outputMappedName ?? $property->name;
            $nested = $this->nested($property, $uses, $rules->notNull);
            $override = $nested ?? ($rules->narrowing ? $rules->type : null);

            // `required` fails on null, so a nullable property it guards takes no null.
            if ($override === null && $rules->notNull && $property->type->isNullable) {
                $override = new DataType("NonNullable<{$output->at($outputName)->type}>", $output->imports);
            }
            // Only a default, null or Optional can stand in for a property that is left out:
            // without one the object cannot be built, whatever its rules let through. A
            // `required` rule takes even that away.
            $leftOut = ($property->hasDefaultValue || $property->type->isNullable || $property->type->isOptional)
                && $rules->required !== true;

            $fields[$outputName] = DataType::literal($input);

            if ($leftOut) {
                $optional[] = DataType::literal($input);
            }

            if ($override !== null) {
                $overrides[$outputName] = $rules->nullable && $nested === null ? $override->nullable() : $override;
            }

            if ($rules->confirmation !== null) {
                $extra[$rules->confirmation] = $override ?? $output->at($outputName);
            }
        }

        $base = DataType::runtime('DataInput')->withArguments(
            $output,
            DataType::object($fields),
            $optional === [] ? new DataType('never') : DataType::union(...$optional),
            // An empty map has to be {}, which adds nothing.
            $overrides === [] ? new DataType('{}') : DataType::object($overrides),
            ...($extra === [] ? [] : [DataType::object($extra)]),
        );

        $this->types[$class] = [
            'name' => $name,
            'declaration' => new DataType("export interface {$name} extends {$base->type} {}", $base->imports),
            'uses' => array_values(array_unique($uses)),
        ];

        return true;
    }

    /**
     * The input type a property holding Data objects takes them in as: the nested
     * class's own, as an array for a collection of them.
     *
     * @param  list<string>  $uses  the classes referred to, which the class is added to
     * @param  bool  $notNull  whether its rules fail on null
     */
    private function nested(DataProperty $property, array &$uses, bool $notNull): ?DataType
    {
        $class = $property->type->dataClass;

        if ($class === null || ! $property->type->kind->isDataRelated() || ! $this->buildData($class)) {
            return null;
        }

        $uses[] = $class;
        $type = new DataType($this->nameOf($class));
        $type = $property->type->kind->isDataObject() ? $type : $type->list();

        return $property->type->isNullable && ! $notNull ? $type->nullable() : $type;
    }

    /**
     * The rules a property's validation attributes stand for.
     *
     * @return list<mixed>
     */
    private function attributeRules(DataProperty $property): array
    {
        try {
            $attributes = class_exists($property->className) ? (new ReflectionProperty($property->className, $property->name))->getAttributes(ValidationAttribute::class, ReflectionAttribute::IS_INSTANCEOF) : [];
        } catch (Throwable) {
            return [];
        }

        $rules = [];
        $denormalizer = $this->container->make(RuleDenormalizer::class);

        foreach ($attributes as $attribute) {
            try {
                $rules = [...$rules, ...array_values($denormalizer->execute($attribute->newInstance(), ValidationPath::create()))];
            } catch (Throwable) {
                // A rule that cannot be built here, e.g. one reading the request, says nothing.
            }
        }

        return $rules;
    }

    /**
     * @return array<mixed>|null
     */
    private function formRequestRules(string $class): ?array
    {
        try {
            $request = $class::createFrom(Request::create('/'));

            if (! $request instanceof FormRequest) {
                return null;
            }

            $request->setContainer($this->container);
            $rules = method_exists($request, 'rules') ? $this->container->call($request->rules(...)) : [];
        } catch (Throwable) {
            return null;
        }

        return is_array($rules) ? $rules : null;
    }

    /**
     * The object type rules keyed by field paths describe: `address.street` nests,
     * `items.*` is an item of a list.
     *
     * @param  array<mixed>  $rules
     */
    private function tree(array $rules): DataType
    {
        $root = new RuleNode;

        foreach ($rules as $path => $fieldRules) {
            $node = $root;

            foreach (explode('.', (string) $path) as $segment) {
                $node = $segment === '*' ? ($node->item ??= new RuleNode) : ($node->children[$segment] ??= new RuleNode);
            }

            $node->rules = [...$node->rules, ...(is_array($fieldRules) ? array_values($fieldRules) : [$fieldRules])];
        }

        return $this->object($root);
    }

    private function object(RuleNode $node): DataType
    {
        /** @var array<string, DataType> $properties */
        $properties = [];
        $optional = [];

        foreach ($node->children as $name => $child) {
            $rules = $this->rules->field($child->rules, $name);

            if ($rules->prohibited) {
                continue;
            }

            $properties[$name] = $this->value($child, $rules);

            if ($rules->required !== true) {
                $optional[] = $name;
            }

            if ($rules->confirmation !== null) {
                $properties[$rules->confirmation] = $properties[$name];

                if ($rules->required !== true) {
                    $optional[] = $rules->confirmation;
                }
            }
        }

        return DataType::object($properties, $optional);
    }

    private function value(RuleNode $node, FieldRules $rules): DataType
    {
        $type = match (true) {
            $node->item !== null => $this->value($node->item, $this->rules->field($node->item->rules, '*'))->list(),
            $node->children !== [] => $this->object($node),
            default => $rules->type ?? new DataType($rules->list ? 'unknown[]' : 'unknown'),
        };

        return $rules->nullable ? $type->nullable() : $type;
    }

    /**
     * The input type of $class, with the declarations of every input type it refers to.
     */
    private function reference(string $class): DataType
    {
        $declared = [];
        $pending = [$class];

        while ($pending !== []) {
            $current = array_pop($pending);
            $type = $this->types[$current] ?? false;

            if ($type === false || $type['declaration'] === null || isset($declared[$type['name']])) {
                continue;
            }

            $declared[$type['name']] = DataType::declared($type['name'], $type['declaration']);
            $pending = [...$pending, ...$type['uses']];
        }

        $parts = array_values($declared);

        return new DataType($this->nameOf($class), DataType::merge(...$parts), DataType::declarations(...$parts));
    }

    private function nameOf(string $class): string
    {
        $type = $this->types[$class] ?? false;

        return $type === false ? 'unknown' : $type['name'];
    }

    /**
     * A name for the input type of $class: its basename, taking in namespace segments
     * while another class has the name.
     */
    private function name(string $class): string
    {
        $segments = explode('\\', $class);
        $name = class_basename($class).self::SUFFIX;
        array_pop($segments);

        while (isset($this->names[$name]) && $this->names[$name] !== $class && $segments !== []) {
            $name = array_pop($segments).$name;
        }

        $candidate = $name;

        for ($suffix = 2; isset($this->names[$candidate]) && $this->names[$candidate] !== $class; $suffix++) {
            $candidate = $name.$suffix;
        }

        $this->names[$candidate] = $class;

        return $candidate;
    }
}
