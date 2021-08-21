<?php /** @noinspection DuplicatedCode */
declare(strict_types=1);

namespace dev\winterframework\ppa;

use DateTime;
use DateTimeZone;
use dev\winterframework\pdbc\core\BindType;
use dev\winterframework\pdbc\core\BindVar;
use dev\winterframework\pdbc\core\BindVars;
use dev\winterframework\pdbc\core\OutBindVar;
use dev\winterframework\pdbc\core\OutBindVars;
use dev\winterframework\pdbc\types\Blob;
use dev\winterframework\pdbc\types\Clob;
use ReflectionObject;

class PpaGenericObjectMapper implements PpaObjectMapper {

    protected static function getEntity(string|object $classOrObj): Entity {
        $class = is_object($classOrObj) ? $classOrObj::class : $classOrObj;
        return EntityRegistry::getEntity($class);
    }

    public function createObject(string $class, array $data): object {
        $entity = self::getEntity($class);

        $cls = $entity->getPpaClass();
        $obj = new $cls();

        return self::mapObject($obj, $data, $entity);
    }

    /**
     * @param PpaEntity $obj
     * @param array $data
     * @param Entity $entity
     * @return object
     * @throws
     */
    public function mapObject(PpaEntity $obj, array $data, Entity $entity): object {

        $ref = new ReflectionObject($obj);
        foreach ($entity->getColumns() as $propName => $column) {
            $name = $column->getName();

            if (!array_key_exists($name, $data)) {
                continue;
            }

            $prop = $ref->getProperty($propName);
            $prop->setAccessible(true);
            if (is_null($data[$name])) {
                /** @noinspection PhpRedundantOptionalArgumentInspection */
                $prop->setValue($obj, null);
                $obj->setNullValue($propName);
                continue;
            }

            $obj->clearNullValue($propName);
            switch ($column->getType()) {
                case BindType::BLOB:
                    $prop->setValue($obj, Blob::valueOf($data[$name]));
                    break;

                case BindType::CLOB:
                    $prop->setValue($obj, Clob::valueOf($data[$name]));
                    break;

                case BindType::BOOL:
                    $prop->setValue($obj, boolval($data[$name]));
                    break;

                case BindType::INTEGER:
                    $prop->setValue($obj, intval($data[$name]));
                    break;

                case BindType::FLOAT:
                    $prop->setValue($obj, floatval($data[$name]));
                    break;

                case BindType::DATE:
                    $prop->setValue($obj, new DateTime($data[$name], new DateTimeZone("UTC")));
                    break;

                default:
                    $prop->setValue($obj, $data[$name]);
                    break;
            }
        }

        return $obj;
    }

    public function generateInsertSql(PpaEntity $obj, Entity $entity): ?SqlObject {
        $sql = 'insert' . ' into ';
        $bindVars = new BindVars();
        $outBindVars = new OutBindVars();

        $sql .= $entity->getTable()->getName();
        $columns = [];
        $bindKeys = [];
        $outColumns = [];
        $outBindKeys = [];

        $ref = new ReflectionObject($obj);

        foreach ($entity->getColumns() as $propName => $column) {
            $name = $column->getName();

            if (!$obj->isCreatable()) {
                continue;
            }

            $bindKey = 'b_' . $propName;
            if (!$column->isId() && $obj->hasNullValue($propName)) {
                $bindKeys[] = ':' . $bindKey;
                $columns[] = $name;
                $bindVars[] = new BindVar($bindKey, null, $column->getType());
                continue;
            }

            $prop = $ref->getProperty($propName);
            $prop->setAccessible(true);
            $value = $prop->getValue($obj);

            if (!isset($value)) {
                if ($entity->hasSequenceGenerator($propName)) {
                    $outBindKey = 'b_' . $name;
                    $outBindKeys[] = ':' . $outBindKey;
                    $columns[] = $entity->getSequenceGenerator($propName)->getSeqName() . '.nextval';
                    $outBindVars[] = new OutBindVar($outBindKey, 64, $column->getType());
                    $outColumns[] = $name;
                }

                continue;
            }

            $bindKeys[] = ':' . $bindKey;
            $columns[] = $name;
            $bindVars[] = new BindVar($bindKey, $value, $column->getType());
        }

        $sql .= ' (';
        $sql .= implode(', ', $columns);
        $sql .= ' ) values (';
        $sql .= implode(', ', $bindKeys);
        $sql .= ') ';

        if ($outColumns) {
            $sql .= ' RETURNING ' . implode(', ', $outColumns);
            $sql .= ' INTO ';
            $sql .= implode(', ', $outBindKeys);
        }

        return new SqlObject($sql, $bindVars, $outBindVars);
    }

    public function generateUpdateSql(PpaEntity $obj, Entity $entity): ?SqlObject {
        $sql = 'update ';
        $bindVars = new BindVars();

        $sql .= $entity->getTable()->getName();
        $sql .= ' set ';
        $columns = [];

        if (!$entity->hasId()) {
            return null;
        }

        $ref = new ReflectionObject($obj);
        foreach ($entity->getColumns() as $propName => $column) {
            $name = $column->getName();

            if (!$obj->isUpdatable()) {
                continue;
            }

            $bindKey = 'b_' . $propName;
            if (!$column->isId() && $obj->hasNullValue($propName)) {
                $columns[] = $name . ' = :' . $bindKey;
                $bindVars[] = new BindVar($bindKey, null, $column->getType());
                continue;
            }

            $prop = $ref->getProperty($propName);
            $prop->setAccessible(true);
            $value = $prop->getValue($obj);

            if (!isset($value)) {
                continue;
            }

            $columns[] = $name . ' = :' . $bindKey;
            $bindVars[] = new BindVar($bindKey, $value, $column->getType());
        }

        if (!$columns) {
            return null;
        }

        $sql .= implode(', ', $columns);
        $sql .= ' where ';

        $where = [];
        foreach ($entity->getIdColumns() as $propName => $column) {
            $prop = $ref->getProperty($propName);
            $prop->setAccessible(true);
            $value = $prop->getValue($obj);

            $bindKey = 'b_id_' . $propName;
            $where[] = $column->getName() . ' = :' . $bindKey;
            $bindVars[] = new BindVar($bindKey, $value, $column->getType());
        }
        $sql .= implode(' and ', $where);

        return new SqlObject($sql, $bindVars);
    }

    public function generateDeleteSql(PpaEntity $obj, Entity $entity): ?SqlObject {
        $sql = 'delete';
        $bindVars = new BindVars();

        $sql .= ' from ';
        $sql .= $entity->getTable()->getName();

        if (!$entity->hasId()) {
            return null;
        }
        $ref = new ReflectionObject($obj);

        $sql .= ' where ';

        $where = [];
        foreach ($entity->getIdColumns() as $propName => $column) {

            $prop = $ref->getProperty($propName);
            $prop->setAccessible(true);
            $value = $prop->getValue($obj);

            $bindKey = 'b_' . $propName;
            $where[] = $column->getName() . ' = :' . $bindKey;
            $bindVars[] = new BindVar($bindKey, $value, $column->getType());
        }
        $sql .= implode(' and ', $where);

        return new SqlObject($sql, $bindVars);
    }
}