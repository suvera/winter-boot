<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\reflection\ObjectCreator;
use dev\winterframework\stereotype\JsonProperty;
use dev\winterframework\type\FloatList;
use dev\winterframework\type\IntegerList;
use dev\winterframework\type\StringList;
use winterBootTests\Support\TestCase;

class ListBindingDto {
    public StringList $csvRow;
    public IntegerList $ids;
    public FloatList $scores;
}

class ListBindingJsonPropertyDto {
    #[JsonProperty(name: 'csvRow')]
    public StringList $csvRow;
}

class ListBindingPlainDto {
    /** @var string[] */
    public array $tags = [];
    public ListBindingChild $child;
}

class ListBindingChild {
    public string $name = '';
}

final class ObjectCreatorListTest extends TestCase {

    public function testStringListFromStrings(): void {
        $o = ObjectCreator::createObject(ListBindingDto::class, [
            'csvRow' => ['a', 'b'],
            'ids' => [1],
        ]);
        $this->assertTrue($o->csvRow instanceof StringList);
        $this->assertSame(['a', 'b'], $o->csvRow->getArray());
    }

    public function testStringListCoercesNumbers(): void {
        $o = ObjectCreator::createObject(ListBindingDto::class, [
            'csvRow' => ['a', 1, 2.5],
            'ids' => [1],
        ]);
        $this->assertSame(['a', '1', '2.5'], $o->csvRow->getArray());
    }

    public function testIntegerListFromIntsAndNumericStrings(): void {
        $o = ObjectCreator::createObject(ListBindingDto::class, [
            'csvRow' => ['a'],
            'ids' => [1, '2', '-3'],
        ]);
        $this->assertTrue($o->ids instanceof IntegerList);
        $this->assertSame([1, 2, -3], $o->ids->getArray());
    }

    public function testIntegerListRejectsNonIntegers(): void {
        $this->assertThrows(\TypeError::class, function (): void {
            ObjectCreator::createObject(ListBindingDto::class, [
                'csvRow' => ['a'],
                'ids' => ['1.5'],
            ]);
        });
        $this->assertThrows(\TypeError::class, function (): void {
            ObjectCreator::createObject(ListBindingDto::class, [
                'csvRow' => ['a'],
                'ids' => [true],
            ]);
        });
    }

    public function testIntegerListRejectsFloats(): void {
        foreach ([[1.5], [2.0], ['2.0']] as $ids) {
            $this->assertThrows(\TypeError::class, function () use ($ids): void {
                ObjectCreator::createObject(ListBindingDto::class, [
                    'csvRow' => ['a'],
                    'ids' => $ids,
                ]);
            }, 'ids ' . json_encode($ids) . ' must be rejected');
        }
    }

    public function testStringListRejectsNonScalars(): void {
        $this->assertThrows(\TypeError::class, function (): void {
            ObjectCreator::createObject(ListBindingDto::class, [
                'csvRow' => [true],
                'ids' => [1],
            ]);
        });
        $this->assertThrows(\TypeError::class, function (): void {
            ObjectCreator::createObject(ListBindingDto::class, [
                'csvRow' => [['nested']],
                'ids' => [1],
            ]);
        });
    }

    public function testFloatListFromMixedNumerics(): void {
        $o = ObjectCreator::createObject(ListBindingDto::class, [
            'csvRow' => ['a'],
            'ids' => [1],
            'scores' => [1.5, 2, '3.25'],
        ]);
        $this->assertTrue($o->scores instanceof FloatList);
        $this->assertSame([1.5, 2.0, 3.25], $o->scores->getArray());
    }

    public function testFloatListRejectsNonNumerics(): void {
        $this->assertThrows(\TypeError::class, function (): void {
            ObjectCreator::createObject(ListBindingDto::class, [
                'csvRow' => ['a'],
                'ids' => [1],
                'scores' => [true],
            ]);
        });
        $this->assertThrows(\TypeError::class, function (): void {
            ObjectCreator::createObject(ListBindingDto::class, [
                'csvRow' => ['a'],
                'ids' => [1],
                'scores' => ['abc'],
            ]);
        });
    }

    public function testEmptyArrayProducesEmptyList(): void {
        $o = ObjectCreator::createObject(ListBindingDto::class, [
            'csvRow' => [],
            'ids' => [],
        ]);
        $this->assertSame([], $o->csvRow->getArray());
        $this->assertSame([], $o->ids->getArray());
    }

    public function testDirectCreateList(): void {
        $o = ObjectCreator::createObject(StringList::class, ['x', 'y']);
        $this->assertTrue($o instanceof StringList);
        $this->assertSame(['x', 'y'], $o->getArray());
    }

    public function testJsonPropertyAnnotatedList(): void {
        $o = ObjectCreator::createObject(ListBindingJsonPropertyDto::class, [
            'csvRow' => ['a', 'b'],
        ]);
        $this->assertTrue($o->csvRow instanceof StringList);
        $this->assertSame(['a', 'b'], $o->csvRow->getArray());
    }

    public function testArrayAndNestedObjectBindingUnchanged(): void {
        $o = ObjectCreator::createObject(ListBindingPlainDto::class, [
            'tags' => ['x', 'y'],
            'child' => ['name' => 'bob'],
        ]);
        $this->assertSame(['x', 'y'], $o->tags);
        $this->assertTrue($o->child instanceof ListBindingChild);
        $this->assertSame('bob', $o->child->name);
    }
}
