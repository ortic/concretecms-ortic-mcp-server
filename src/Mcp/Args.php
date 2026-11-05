<?php

namespace Ortic\McpServer\Mcp;

/**
 * Typed access to tool arguments. Invalid values raise a ToolError the model can correct.
 */
final class Args
{
    public function __construct(private array $values)
    {
    }

    public function all(): array
    {
        return $this->values;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values) && $this->values[$name] !== null;
    }

    public function string(string $name, ?string $default = null, bool $required = false): ?string
    {
        if (!$this->has($name)) {
            return $this->missing($name, $default, $required);
        }
        $value = $this->values[$name];
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            throw new ToolError(sprintf('Argument "%s" must be a string.', $name));
        }

        return $value;
    }

    public function requireString(string $name): string
    {
        $value = $this->string($name, null, true);
        if (trim($value) === '') {
            throw new ToolError(sprintf('Argument "%s" must not be empty.', $name));
        }

        return $value;
    }

    public function int(string $name, ?int $default = null, bool $required = false): ?int
    {
        if (!$this->has($name)) {
            return $this->missing($name, $default, $required);
        }
        $value = $this->values[$name];
        if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            $value = (int) $value;
        }
        if (is_float($value) && floor($value) === $value) {
            $value = (int) $value;
        }
        if (!is_int($value)) {
            throw new ToolError(sprintf('Argument "%s" must be an integer.', $name));
        }

        return $value;
    }

    public function requireInt(string $name): int
    {
        return $this->int($name, null, true);
    }

    public function bool(string $name, bool $default = false): bool
    {
        if (!$this->has($name)) {
            return $default;
        }
        $value = $this->values[$name];
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 'true' || $value === 1 || $value === '1') {
            return true;
        }
        if ($value === 'false' || $value === 0 || $value === '0') {
            return false;
        }
        throw new ToolError(sprintf('Argument "%s" must be a boolean.', $name));
    }

    public function object(string $name, ?array $default = null, bool $required = false): ?array
    {
        if (!$this->has($name)) {
            return $this->missing($name, $default, $required);
        }
        $value = $this->values[$name];
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new ToolError(sprintf('Argument "%s" must be an object.', $name));
        }

        return $value;
    }

    public function list(string $name, ?array $default = null): ?array
    {
        if (!$this->has($name)) {
            return $default;
        }
        $value = $this->values[$name];
        if (is_string($value)) {
            $value = array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'));
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new ToolError(sprintf('Argument "%s" must be an array.', $name));
        }

        return $value;
    }

    public function enum(string $name, array $allowed, ?string $default = null): ?string
    {
        $value = $this->string($name, $default);
        if ($value !== null && !in_array($value, $allowed, true)) {
            throw new ToolError(sprintf('Argument "%s" must be one of: %s.', $name, implode(', ', $allowed)));
        }

        return $value;
    }

    public function limit(string $name = 'limit', int $default = 25, int $max = 100): int
    {
        $value = $this->int($name, $default);

        return max(1, min($max, $value));
    }

    private function missing(string $name, $default, bool $required)
    {
        if ($required) {
            throw new ToolError(sprintf('Missing required argument "%s".', $name));
        }

        return $default;
    }
}
