<?php

namespace App\Agents;

final class SchemaValidator
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    public function errors(array $data, array $schema): array
    {
        return $this->validateValue($data, $schema, '$');
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    public function definitionErrors(array $schema): array
    {
        return $this->validateSchemaDefinition($schema, '$schema');
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $schema
     */
    public function validate(array $data, array $schema): void
    {
        $validationErrors = $this->errors($data, $schema);

        if ($validationErrors !== []) {
            throw new \InvalidArgumentException('Schema validation failed: '.implode('; ', $validationErrors));
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function validateValue(mixed $value, array $schema, string $path): array
    {
        $validationErrors = [];
        $type = $schema['type'] ?? null;

        if (is_string($type) && ! $this->matchesType($value, $type)) {
            return ["{$path} must be of type {$type}."];
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $validationErrors[] = "{$path} must match one of the allowed values.";
        }

        if (is_string($value) && isset($schema['minLength']) && mb_strlen($value) < $schema['minLength']) {
            $validationErrors[] = "{$path} must contain at least {$schema['minLength']} characters.";
        }

        if (is_array($value) && $this->isObject($value)) {
            $requiredProperties = $schema['required'] ?? [];
            $properties = $schema['properties'] ?? [];

            foreach ($requiredProperties as $requiredProperty) {
                if (! array_key_exists($requiredProperty, $value)) {
                    $validationErrors[] = "{$path}.{$requiredProperty} is required.";
                }
            }

            foreach ($properties as $propertyName => $propertySchema) {
                if (array_key_exists($propertyName, $value) && is_array($propertySchema)) {
                    $propertyPath = "{$path}.{$propertyName}";
                    $validationErrors = [
                        ...$validationErrors,
                        ...$this->validateValue($value[$propertyName], $propertySchema, $propertyPath),
                    ];
                }
            }

            if (($schema['additionalProperties'] ?? true) === false) {
                foreach (array_keys($value) as $propertyName) {
                    if (! array_key_exists($propertyName, $properties)) {
                        $validationErrors[] = "{$path}.{$propertyName} is not allowed.";
                    }
                }
            }
        }

        if (is_array($value) && array_is_list($value) && isset($schema['items']) && is_array($schema['items'])) {
            foreach ($value as $itemIndex => $item) {
                $itemPath = "{$path}[{$itemIndex}]";
                $validationErrors = [
                    ...$validationErrors,
                    ...$this->validateValue($item, $schema['items'], $itemPath),
                ];
            }
        }

        return $validationErrors;
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'object' => is_array($value) && $this->isObject($value),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function validateSchemaDefinition(array $schema, string $path): array
    {
        $validationErrors = [];
        $supportedTypes = ['object', 'array', 'string', 'integer', 'number', 'boolean', 'null'];
        $type = $schema['type'] ?? null;

        if (! is_string($type) || ! in_array($type, $supportedTypes, true)) {
            $validationErrors[] = "{$path}.type must be a supported JSON schema type.";
        }

        $properties = $schema['properties'] ?? [];
        if (! is_array($properties)) {
            $validationErrors[] = "{$path}.properties must be an object.";
            $properties = [];
        }

        foreach ($properties as $propertyName => $propertySchema) {
            if (! is_string($propertyName) || ! is_array($propertySchema)) {
                $validationErrors[] = "{$path}.properties must contain named schema objects.";

                continue;
            }

            $validationErrors = [
                ...$validationErrors,
                ...$this->validateSchemaDefinition($propertySchema, "{$path}.properties.{$propertyName}"),
            ];
        }

        $requiredProperties = $schema['required'] ?? [];
        if (! is_array($requiredProperties) || ! array_is_list($requiredProperties)) {
            $validationErrors[] = "{$path}.required must be a list of property names.";
        } else {
            foreach ($requiredProperties as $requiredProperty) {
                if (! is_string($requiredProperty) || ! array_key_exists($requiredProperty, $properties)) {
                    $validationErrors[] = "{$path}.required contains an unknown property.";
                }
            }
        }

        if (isset($schema['items'])) {
            if (! is_array($schema['items'])) {
                $validationErrors[] = "{$path}.items must be a schema object.";
            } else {
                $validationErrors = [
                    ...$validationErrors,
                    ...$this->validateSchemaDefinition($schema['items'], "{$path}.items"),
                ];
            }
        }

        if (isset($schema['enum']) && (! is_array($schema['enum']) || ! array_is_list($schema['enum']))) {
            $validationErrors[] = "{$path}.enum must be a list.";
        }

        return $validationErrors;
    }

    /**
     * @param  array<mixed>  $value
     */
    private function isObject(array $value): bool
    {
        return $value === [] || ! array_is_list($value);
    }
}
