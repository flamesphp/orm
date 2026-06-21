<?php

declare(strict_types=1);

namespace Flames\Orm;

use Error;
use Flames\Collection\Arr;
use Flames\Collection\ArrImmutable;
use Flames\Orm\Model\Data;
use Flames\Orm\Database\Cast\Support\ArrValue;

/**
 * The Model class is an abstract class that serves as the base for all model classes in the application.
 */
abstract class Model
{
    private static array $__setup = [];
    private static array $_driver = [];
    private static array $_data = [];
    private static array $_cast = [];
    private static array $_connection = [];

    private array|null $__changed = null;

    /** @var array<string, string>|null */
    private array|null $__snapshot = null;

    private bool $__destroyed = false;

    public function save(): void
    {
        $this->__assertNotDestroyed();

        $class = static::class;
        static::__constructStatic();

        $indexColumn = self::resolveIndexColumn($class);

        $data = $this->toArray();

        if ($data[$indexColumn->property] === null) {
            self::_applyTimestamps($this, true);
            $data = $this->toArray();

            self::_verifyConnection($class);

            /** @var Database\QueryBuilder\DefaultEx $queryBuilder */
            $queryBuilder = self::$_driver[self::$_data[$class]->database]->getQueryBuilder($class);
            $queryBuilder->setModel(static::class);

            $insert = $queryBuilder->insert($data);

            if ($insert instanceof Arr) {
                foreach ($insert->toArray() as $key => $value) {
                    $this->set((string) $key, $value);
                }
            } elseif (is_array($insert)) {
                foreach ($insert as $key => $value) {
                    $this->set((string) $key, $value);
                }
            } elseif ($insert !== null && $insert !== false && $insert !== '') {
                $this->set($indexColumn->property, $insert);
            }

            $this->__changed  = null;
            $this->__snapshot = $this->__columnSnapshot();
            return;
        }

        self::_applyTimestamps($this, false);

        $this->__syncChangedFromSnapshot();

        if ($this->__changed === null || count($this->__changed) === 0) {
            return;
        }

        $data = $this->toArray();

        foreach ($data as $key => $_) {
            if (in_array($key, $this->__changed) === false) {
                unset($data[$key]);
            }
        }

        $data[$indexColumn->property] = $this->{$indexColumn->property};

        self::_verifyConnection($class);

        /** @var Database\QueryBuilder\DefaultEx $queryBuilder */
        $queryBuilder = self::$_driver[self::$_data[$class]->database]->getQueryBuilder($class);
        $queryBuilder->setModel(static::class);
        $queryBuilder->suppressModifiedIdsTracking();
        $queryBuilder->where($indexColumn->property, $this->{$indexColumn->property});
        $queryBuilder->update($data);
        $this->__changed  = null;
        $this->__snapshot = $this->__columnSnapshot();
    }

    public function destroy(): void
    {
        $this->__assertNotDestroyed();

        $class = static::class;
        static::__constructStatic();

        $indexColumn = self::resolveIndexColumn($class);
        $primaryKey  = $this->{$indexColumn->property};

        if ($primaryKey === null) {
            throw new Error('Cannot destroy a model without a primary key.');
        }

        self::_verifyConnection($class);

        self::newQueryBuilder($class)
            ->where($indexColumn->property, $primaryKey)
            ->limit(1)
            ->delete();

        $this->__invalidateInstance();
    }

    public static function newQueryBuilder(?string $class = null): Database\QueryBuilder\DefaultEx
    {
        $class ??= static::class;
        static::__constructStatic();
        self::_verifyConnection($class);

        /** @var Database\QueryBuilder\DefaultEx $queryBuilder */
        $queryBuilder = self::$_driver[self::$_data[$class]->database]->getQueryBuilder($class);

        return $queryBuilder->setModel($class);
    }

    public static function resolveIndexColumn(string $class): object
    {
        if (isset(self::$_data[$class]) === false) {
            $class::__constructStatic();
        }

        foreach (self::$_data[$class]->column as $column) {
            if ($column->primary === true || $column->autoIncrement === true) {
                return $column;
            }
        }

        foreach (self::$_data[$class]->column as $column) {
            if ($column->unique === true) {
                return $column;
            }
        }

        throw new Error('Missing primary or unique column in table ' . self::getTable() . ' using class ' . $class . '.');
    }

    protected function __persistSnapshot(): void
    {
        $this->__changed  = null;
        $this->__snapshot = $this->__columnSnapshot();
    }

    private function __assertNotDestroyed(): void
    {
        if ($this->__destroyed) {
            throw new Error('Model instance was destroyed and can no longer be used.');
        }
    }

    private function __invalidateInstance(): void
    {
        $class = static::class;

        foreach (self::$_data[$class]->column as $column) {
            $property = $column->property;

            try {
                $this->{$property} = null;
            } catch (\Throwable) {
            }
        }

        $this->__changed   = null;
        $this->__snapshot  = null;
        $this->__destroyed = true;
    }

    private static function _usesSoftDeletes(string $class): bool
    {
        return (self::$_data[$class]->usesSoftDeletes ?? false) === true;
    }

    private static function _usesTimestamps(string $class): bool
    {
        return (self::$_data[$class]->usesTimestamps ?? false) === true;
    }

    private static function _applyTimestamps(self $model, bool $isInsert): void
    {
        $class = $model::class;

        if (self::_usesTimestamps($class) === false) {
            return;
        }

        $now = \Flames\Date\DateTimeImmutable::now();

        $createdAt = (string) (self::$_data[$class]->createdAtColumn ?? 'createdAt');
        $updatedAt = (string) (self::$_data[$class]->updatedAtColumn ?? 'updatedAt');

        if ($isInsert) {
            if ($model->{$createdAt} === null) {
                $model->set($createdAt, $now);
            }

            $model->set($updatedAt, $now);

            return;
        }

        $model->set($updatedAt, $now);
    }

    public function touch(string ...$keys): static
    {
        if ($keys === []) {
            return $this;
        }

        $class = static::class;

        foreach ($keys as $key) {
            if (isset(self::$_data[$class]->column[$key]) === false) {
                continue;
            }

            $column = self::$_data[$class]->column[$key];

            if (in_array($column->type, ['date', 'time', 'datetime', 'timestamp'], true)) {
                $this->set($key, 'now');
                continue;
            }

            $this->__markChanged($key);
        }

        return $this;
    }

    public function getChanged(bool $onlyKeys = true): Arr|null
    {
        if ($this->__changed === null) {
            return null;
        }

        if ($onlyKeys === true) {
            return Arr($this->__changed);
        }

        $data = Arr();
        foreach ($this->__changed as $key) {
            $data[$key] = $this->{$key};
        }

        return $data;
    }

    public function toArr(): Arr
    {
        return Arr($this->toArray());
    }

    public function toArray(): array
    {
        $class = static::class;
        $data = [];

        foreach (self::$_data[$class]->column as $column) {
            try {
                $data[$column->property] = $this->{$column->property};
            } catch (\Error $_) {
                $data[$column->property] = null;
            }
        }

        return $data;
    }

    public static function getTable(): string|null
    {
        $class = static::class;
        if (isset(self::$_data[$class]) === false) {
            return null;
        }

        return self::$_data[$class]->table;
    }

    public static function getDatabase(): string|null
    {
        $class = static::class;
        if (isset(self::$_data[$class]) === false) {
            return null;
        }

        return self::$_data[$class]->database;
    }

    public static function __constructStatic(): void
    {
        $class = static::class;
        if (isset(static::$__setup[$class]) === true && static::$__setup[$class] === true) {
            return;
        }

        self::__setup(Data::mountData(static::class));
        static::$__setup[$class] = true;
    }

    private static function __setup(Arr $data): void
    {
        $class = static::class;
        self::$_data[$class] = $data;
        if (self::$_data[$class]->column->length === 0) {
            throw new \Exception('Model ' . static::class . 'need at least one column.');
        }
    }

    public function __construct(Arr|array|null $data = null, bool $ignoreChanged = false)
    {
        if ($data instanceof Arr) {
            $data = (array) $data;
        }

        if (is_array($data) === true) {
            foreach ($data as $key => $value) {
                try {
                    $this->__set($key, $value);
                } catch (\TypeError $_) {
                }
            }
        }

        $data = $this->toArray();
        foreach ($data as $key => $value) {
            $this->__set($key, $value);
        }

        if ($ignoreChanged === true) {
            $this->__changed  = null;
            $this->__snapshot = $this->__columnSnapshot();
        }
    }

    public function __set(string $key, mixed $value)
    {
        $this->__assertNotDestroyed();

        $class = static::class;

        if (isset(self::$_data[$class]->column[$key]) === true) {
            $value = self::castToProperty($key, $value);

            try {
                $this->{$key} = $value;
            } catch (\TypeError) {
            }

            if ($this->__changed === null) {
                $this->__changed = [];
            }

            if (in_array($key, $this->__changed) === false) {
                $this->__changed[] = $key;
            }
        }
    }

    public function set(string $key, mixed $value): void
    {
        $this->__set($key, $value);
    }

    public function __get(string $key)
    {
        if ($this->__destroyed) {
            return null;
        }

        if (isset($this->{$key}) === true) {
            return $this->{$key};
        }

        return null;
    }

    public function get(string $key)
    {
        return $this->__get($key);
    }

    public static function cast(string $key, mixed $value = null): mixed
    {
        $class = static::class;

        if (isset(self::$_data[$class]->column[$key]) === false) {
            throw new \Exception('Model key ' . $key . ' not found in class ' . $class);
        }

        self::_verifyCast($class);

        return self::$_cast[$class]::pos(self::$_data[$class]->column[$key], $value);
    }

    public static function castToProperty(string $key, mixed $value): mixed
    {
        $class  = static::class;
        $column = self::$_data[$class]->column[$key];
        $value  = self::cast($key, $value);

        if (in_array($column->type, ['json', 'jsonb', 'set'], true)) {
            return ArrValue::fit($column, $value);
        }

        return $value;
    }

    public static function getDriver(): mixed
    {
        $class = static::class;

        $database = self::$_data[$class]->database;
        if ($database === null) {
            throw new \RuntimeException('Database is not configured for model ' . $class . '.');
        }

        if (isset(self::$_driver[$database]) === false || self::$_driver[$database] === null) {
            $_driver = new static();
            $_driver::_verifyConnection($class);
        }

        return self::$_driver[$database];
    }

    private static function _verifyConnection(string $class): void
    {
        if (isset(self::$_connection[$class]) === false || self::$_connection[$class] === false) {
            self::$_connection[$class] = false;

            $database = self::$_data[$class]->database;
            if ($database === null) {
                throw new \RuntimeException('Database is not configured for model ' . $class . '.');
            }

            $driver = Database\Driver::getByConfigAndDatabase(
                Database\DataFactory::getConfigByDatabase($database),
                self::$_data[$class]->database
            );

            $driver->migrate(self::$_data[$class]);
            self::$_driver[$database] = $driver;
        }
    }

    private static function _verifyCast(string $class): void
    {
        if (isset(self::$_cast[$class]) === false || self::$_cast[$class] === false) {
            self::$_cast[$class] = Database\Cast\Factory::getByDatabaseType(
                Database\DataFactory::getConfigByDatabase(self::$_data[$class]->database)->type
            );
        }
    }

    public static function getMetadata($verifyConnection = false)
    {
        $class = static::class;

        if ($verifyConnection === true) {
            self::_verifyConnection($class);
            return true;
        }

        return self::$_data[$class];
    }

    private function __syncChangedFromSnapshot(): void
    {
        if ($this->__snapshot === null) {
            return;
        }

        foreach ($this->__detectChangedColumns() as $key) {
            $this->__markChanged($key);
        }
    }

    private function __markChanged(string ...$keys): void
    {
        if ($keys === []) {
            return;
        }

        if ($this->__changed === null) {
            $this->__changed = [];
        }

        foreach ($keys as $key) {
            if (in_array($key, $this->__changed, true) === false) {
                $this->__changed[] = $key;
            }
        }
    }

    /**
     * @return list<string>
     */
    private function __detectChangedColumns(): array
    {
        $class   = static::class;
        $changed = [];

        foreach (self::$_data[$class]->column as $column) {
            $property = $column->property;
            $current  = $this->__normalizeForCompare($this->{$property} ?? null, $column);
            $original = $this->__snapshot[$property] ?? null;

            if ($current !== $original) {
                $changed[] = $property;
            }
        }

        return $changed;
    }

    /**
     * @return array<string, string>
     */
    private function __columnSnapshot(): array
    {
        $class    = static::class;
        $snapshot = [];

        foreach (self::$_data[$class]->column as $column) {
            $property = $column->property;

            try {
                $snapshot[$property] = $this->__normalizeForCompare($this->{$property}, $column);
            } catch (\Error|\JsonException) {
                $snapshot[$property] = $this->__normalizeForCompare(null, $column);
            }
        }

        return $snapshot;
    }

    private function __normalizeForCompare(mixed $value, object $column): string
    {
        if ($value === null) {
            return 'null';
        }

        if ($value instanceof ArrImmutable) {
            $value = $value->toArray();
        }

        if ($value instanceof Arr) {
            $value = $value->toArray();
        }

        if ($value instanceof \UnitEnum) {
            $value = $value instanceof \BackedEnum ? $value->value : $value->name;
        }

        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d H:i:s.u');
        }

        if (is_object($value) && method_exists($value, 'toWkt')) {
            return 'wkt:' . $value->toWkt();
        }

        if ($this->__isBinaryColumn($column) && is_string($value)) {
            return 'b64:' . base64_encode($value);
        }

        if (is_string($value) && mb_check_encoding($value, 'UTF-8') === false) {
            return 'b64:' . base64_encode($value);
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            $serialized = $value->toArray();
            if (is_array($serialized)) {
                $value = $serialized;
            }
        }

        if (is_object($value)) {
            if ($value instanceof \JsonSerializable) {
                $value = $value->jsonSerialize();
            } elseif (method_exists($value, '__toString')) {
                return (string) $value;
            } else {
                throw new \JsonException('Cannot normalize object of type ' . $value::class . ' for snapshot compare.');
            }
        }

        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            if (is_string($value)) {
                return 'b64:' . base64_encode($value);
            }

            throw new \JsonException('Cannot normalize value for snapshot compare on column ' . $column->property . '.');
        }
    }

    private function __isBinaryColumn(object $column): bool
    {
        return in_array($column->type, [
            'binary',
            'varbinary',
            'blob',
            'tinyblob',
            'mediumblob',
            'longblob',
        ], true);
    }
}

class_alias(Model::class, 'Flames\\Model');
