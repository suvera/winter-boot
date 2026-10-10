<?php
declare(strict_types=1);

namespace dev\winterframework\mcp;

use BackedEnum;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use dev\winterframework\mcp\exception\McpToolArgumentException;
use dev\winterframework\mcp\schema\McpSchemaDeriver;
use dev\winterframework\reflection\ObjectCreator;
use JsonSerializable;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionType;
use Throwable;
use Traversable;
use UnitEnum;

/**
 * Converts between JSON values and PHP values for service tools, using
 * the same naming rules as the schema deriver, so a derived schema and
 * the actual data always agree:
 *  - DTOs by their public / #[JsonProperty] properties (JSON names);
 *  - backed enums by value, unit enums by name;
 *  - DateTimeInterface as an ATOM (RFC 3339) string.
 * DTO arguments are built with ObjectCreator, exactly like request bodies.
 */
final class McpValueCodec {

    private const MAX_DEPTH = 64;

    /**
     * JSON argument value to the PHP value a parameter expects. The value
     * already passed schema validation; this only converts.
     * @throws McpToolArgumentException when the value can't be converted
     */
    public static function toPhp(mixed $value, ?ReflectionType $type, string $arg): mixed {
        if ($value === null || !$type instanceof ReflectionNamedType) {
            return $value;
        }
        $name = $type->getName();
        if ($type->isBuiltin()) {
            return match ($name) {
                'int' => is_float($value) ? (int)$value : $value,
                'float' => is_int($value) ? (float)$value : $value,
                default => $value,
            };
        }

        try {
            if (is_a($name, BackedEnum::class, true)) {
                $case = $name::tryFrom($value);
                if ($case === null) {
                    throw new McpToolArgumentException($arg . ': not an allowed value');
                }
                return $case;
            }
            if (is_a($name, UnitEnum::class, true)) {
                foreach ($name::cases() as $case) {
                    if ($case->name === $value) {
                        return $case;
                    }
                }
                throw new McpToolArgumentException($arg . ': not an allowed value');
            }
            if (is_a($name, DateTimeInterface::class, true)) {
                if (!is_string($value)) {
                    throw new McpToolArgumentException($arg . ': expected a date-time string');
                }
                return $name === DateTime::class ? new DateTime($value) : new DateTimeImmutable($value);
            }
            if (!is_array($value)) {
                throw new McpToolArgumentException($arg . ': expected an object');
            }
            return ObjectCreator::createObject($name, $value);
        } catch (McpToolArgumentException $e) {
            throw $e;
        } catch (Throwable $e) {
            // Never echo the value or the binder's message (it may quote input).
            throw new McpToolArgumentException($arg . ': could not be read as ' . (new ReflectionClass($name))->getShortName(), 0, $e);
        }
    }

    /**
     * PHP result to a JSON-encodable value.
     * @throws \UnexpectedValueException for values JSON can't represent
     */
    public static function toJson(mixed $value, int $depth = 0): mixed {
        if ($depth > self::MAX_DEPTH) {
            throw new \UnexpectedValueException('result is nested too deeply (cycle?)');
        }
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }
        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value)) {
                throw new \UnexpectedValueException('result contains NaN or infinity');
            }
            return $value;
        }
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if ($value instanceof UnitEnum) {
            return $value->name;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if ($value instanceof JsonSerializable) {
            return self::toJson($value->jsonSerialize(), $depth + 1);
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::toJson($v, $depth + 1);
            }
            return $out;
        }
        if ($value instanceof Traversable) {
            return self::toJson(iterator_to_array($value, false), $depth + 1);
        }
        if (is_object($value)) {
            $out = [];
            foreach (McpSchemaDeriver::dtoProperties(new ReflectionClass($value)) as $jsonName => $prop) {
                if (!$prop->isInitialized($value)) {
                    continue;
                }
                $out[$jsonName] = self::toJson($prop->getValue($value), $depth + 1);
            }
            // An object with no properties is still a JSON object.
            return $out === [] ? new \stdClass() : $out;
        }
        throw new \UnexpectedValueException('result contains a ' . get_debug_type($value));
    }
}
