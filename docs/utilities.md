# Utilities

This document covers useful utility classes and patterns in Winter-Boot.

## bombok\Data Trait

The `Data` trait provides automatic getter and setter methods similar to Java Lombok. Simply use the trait in your class and access properties via `getFieldName()` and `setFieldName()` methods.

**Example:**

```php
use dev\winterframework\bombok\Data;

/**
 * @method setId(string|int $val): void
 * @method string|int getId()
 *
 * @method setTitle(string $val): void
 * @method getTitle(): string
 */
class Option {
    use Data;
    
    private string|int $id;
    private string $title;
}

$obj = new Option();
$obj->setId('123');
$obj->setTitle('Example');
echo $obj->getId();   // '123'
echo $obj->getTitle(); // 'Example'
```

**Type Validation:** Setters automatically validate argument types match the property's type declaration.

---

## Objects::hash()

A simple wrapper around PHP's `spl_object_hash()` for consistent object hashing.

```php
use dev\winterframework\type\Objects;

$hash = Objects::hash($object);
```

---

## Typed Lists

Winter-Boot provides typed list implementations for type-safe collections:

### ArrayList

Base class for typed lists with `ArrayAccess`, `Countable`, and `IteratorAggregate` support.

```php
use dev\winterframework\type\ArrayList;

$list = ArrayList::ofValues(1, 2, 3);
$list[] = 4;
count($list); // 4
```

### StringList

A list specifically for strings with type validation.

```php
use dev\winterframework\type\StringList;

$list = new StringList();
$list[] = 'hello';
// $list[] = 123; // Would throw TypeError
```

**Static factory methods:**
- `ofValues(...$values)` - Create from variadic arguments
- `ofArray(array $values)` - Create from array
- `emptyList()` - Get singleton empty list

---

## ImmutableMap

A read-only map implementation implementing `ImmutableCollection`.

```php
use dev\winterframework\type\ImmutableMap;

$map = ImmutableMap::of([
    'key1' => 'value1',
    'key2' => 'value2'
]);

echo $map->get('key1');           // 'value1'
echo $map->getOrDefault('key3', 'default'); // 'default'
$map->contains('value1');         // true
$map->containsIndex('key1');      // true
$map->keys();                     // ['key1', 'key2']
$map->values();                   // ['value1', 'value2']
$map->count();                    // 2
$map->isEmpty();                  // false
```

---

## IntegerMinHeap

A min-heap implementation for PHP using `SplHeap`.

```php
use dev\winterframework\type\IntegerMinHeap;

$heap = new IntegerMinHeap();
$heap->insert(5);
$heap->insert(1);
$heap->insert(3);

while (!$heap->isEmpty()) {
    echo $heap->extract(); // 1, 3, 5 (ascending order)
}
```

---

## TypeAssert

Highly reusable type validation methods:

```php
use dev\winterframework\type\TypeAssert;

TypeAssert::string($value);
TypeAssert::integer($value);
TypeAssert::boolean($value);
TypeAssert::float($value);
TypeAssert::scalar($value);
TypeAssert::positiveInteger($value);
TypeAssert::positiveNoZeroInteger($value);
TypeAssert::negativeInteger($value);
TypeAssert::negativeNoZeroInteger($value);
TypeAssert::callable($value);
TypeAssert::array($value);
TypeAssert::stringArray($value);
TypeAssert::intArray($value);
TypeAssert::object($value);
TypeAssert::null($value);
TypeAssert::typeOf($value, 'ClassName1', 'ClassName2');
TypeAssert::objectOf($value, 'ClassName');
TypeAssert::objectOfIsA($value, 'ClassName');
TypeAssert::notEmpty($name, $value);
TypeAssert::arrayItemNotEmpty($array, $key);
TypeAssert::arrayItemNotSet($array, $key);
TypeAssert::notNull($name, $value);
TypeAssert::state($expression, $message);
```

---

## MurmurHash3Provider

MurmurHash3 hash implementation for consistent non-cryptographic hashing.

```php
use dev\winterframework\util\hash\MurmurHash3Provider;

$provider = new MurmurHash3Provider(seed: 42);
$hashInt = $provider->getHashInt('some value');
$hash = $provider->getHash('some value');
```

The provider implements the `HashProvider` interface for swapable hash strategies.
