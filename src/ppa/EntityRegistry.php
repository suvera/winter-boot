<?php
declare(strict_types=1);

namespace dev\winterframework\ppa;

use dev\winterframework\ppa\exception\PpaException;
use dev\winterframework\reflection\ClassResource;
use dev\winterframework\stereotype\ppa\Column;
use dev\winterframework\stereotype\ppa\SequenceGenerator;
use dev\winterframework\stereotype\ppa\Table;
use dev\winterframework\stereotype\ppa\TableGenerator;

class EntityRegistry {
    protected static array $register = [];

    public static function getEntity(string $entityName): Entity {
        if (!isset(self::$register[$entityName])) {
            throw new PpaException('PPA entity with name ' . $entityName . ' does not exist!');
        }
        return self::$register[$entityName];
    }

    public static function putEntity(Entity|ClassResource $entity): void {
        if ($entity instanceof Entity) {
            self::$register[$entity->getPpaClass()] = $entity;
            return;
        }

        /** @var ClassResource $ref */
        $ref = $entity;
        $ent = new Entity();

        /** @var Table $table */
        $table = $ref->getAttribute(Table::class);
        $ent->setTable($table);

        $ent->setPpaClass($ref->getClass()->getName());

        foreach ($ref->getVariables() as $variable) {
            /** @var Column $column */
            $column = $variable->getAttribute(Column::class);
            if (!$column) {
                continue;
            }
            $ent->addColumn($column);

            /** @var SequenceGenerator $seq */
            $seq = $variable->getAttribute(SequenceGenerator::class);
            if ($seq) {
                $ent->addSequenceGenerator($seq);
            }

            /** @var TableGenerator $tab */
            $tab = $variable->getAttribute(TableGenerator::class);
            if ($tab) {
                $ent->addTableGenerator($tab);
            }
        }
        self::$register[$ent->getPpaClass()] = $ent;
    }

}