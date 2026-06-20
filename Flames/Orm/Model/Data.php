<?php
declare(strict_types=1);


namespace Flames\Orm\Model;

use Flames\Collection\Arr;

/**
 * @internal
 */
class Data
{
    private const __VERSION__ = 19;

    private static array $runtimeCache = [];

    public static function mountData(string $class): Arr
    {
        if (isset(self::$runtimeCache[$class])) {
            return self::$runtimeCache[$class];
        }

        $path        = ROOT_PATH . str_replace('\\', '/', $class) . '.php';
        $basePath    = ROOT_PATH . '.cache/.flames/model/';
        $cachePath   = $basePath . sha1($class);
        $currentTime = filemtime($path);

        if (file_exists($cachePath) === true) {
            $data = unserialize(file_get_contents($cachePath));
            if ($data->version === self::__VERSION__ && $data->timestamp === $currentTime) {
                self::$runtimeCache[$class] = $data;
                return $data;
            }
        }

        $data            = self::__getReflection($class);
        $data->timestamp = $currentTime;

        $written = @file_put_contents($cachePath, serialize($data));
        if ($written === false && !is_dir($basePath)) {
            $mask = umask(0);
            mkdir($basePath, 0777, true);
            umask($mask);
            @file_put_contents($cachePath, serialize($data));
        }

        self::$runtimeCache[$class] = $data;
        return $data;
    }

    private static function __getReflection(string $class): Arr
    {
        $data = Arr([
            'version'   => self::__VERSION__,
            'timestamp' => null,
            'class'     => $class,
            'database'  => null,
            'table'     => null,
            'column'    => Arr(),
            'index'     => Arr(),
        ]);

        $reflection = new \ReflectionClass($class);

        foreach ($reflection->getAttributes() as $attribute) {
            $name = $attribute->getName();
            if ($name === \Flames\Orm\Attribute\Database::class) {
                /** @var \Flames\Orm\Attribute\Database $instance */
                $instance = $attribute->newInstance();
                if ($instance->name !== null) {
                    $data->database = $instance->name;
                }
            } elseif ($name === \Flames\Orm\Attribute\Table::class) {
                /** @var \Flames\Orm\Attribute\Table $instance */
                $instance = $attribute->newInstance();
                if ($instance->name !== null) {
                    $data->table = $instance->name;
                }
            }
        }

        if ($data->table === null) {
            $data->table = str_replace('\\', '_', strtolower(substr($class, 17)));
        }

        foreach ($reflection->getProperties() as $property) {
            $propName = $property->getName();
            if ($propName[0] === '_') {
                continue;
            }

            $column = Arr([
                'property'      => $propName,
                'name'          => null,
                'type'          => null,
                'phpType'       => null,
                'size'          => null,
                'precision'     => null,
                'scale'         => null,
                'values'        => null,
                'enumClass'     => null,
                'srid'          => 0,
                'nullable'      => false,
                'default'       => $property->getDefaultValue(),
                'primary'       => false,
                'index'         => false,
                'unique'        => false,
                'autoIncrement' => false,
                'unsigned'      => false,
            ]);

            $type = $property->getType();
            if ($type instanceof \ReflectionUnionType) {
                foreach ($type->getTypes() as $t) {
                    $tName = $t->getName();
                    if ($tName === 'null') {
                        $column->nullable = true;
                    } elseif ($column->phpType === null) {
                        $column->phpType = $tName;
                        if ($column->type === null) {
                            $column->type = $tName;
                        }
                    }
                }
            } elseif ($type !== null) {
                $column->nullable = $type->allowsNull();
                $column->phpType  = $type->getName();
                $column->type     = $type->getName();
            }

            foreach ($property->getAttributes() as $attribute) {
                if ($attribute->getName() !== \Flames\Orm\Attribute\Column::class) {
                    continue;
                }
                $args = $attribute->getArguments();
                if (isset($args['nullable']))      { $column->nullable      = $args['nullable'];      }
                if (isset($args['type']))          { $column->type          = $args['type'];          }
                if (isset($args['length']))        { $column->size          = $args['length'];        }
                if (isset($args['precision']))     { $column->precision     = $args['precision'];     }
                if (isset($args['scale']))         { $column->scale         = $args['scale'];         }
                if (isset($args['values']))       { $column->values        = $args['values'];        }
                if (isset($args['srid']))          { $column->srid          = $args['srid'];          }
                if (isset($args['default']))       { $column->default       = $args['default'];       }
                if (isset($args['name']))          { $column->name          = $args['name'];          }
                if (isset($args['index']))         { $column->index         = $args['index'];         }
                if (isset($args['primary']))       { $column->primary       = $args['primary'];       }
                if (isset($args['autoIncrement'])) { $column->autoIncrement = $args['autoIncrement']; }
                if (isset($args['unique']))        { $column->unique        = $args['unique'];        }
                if (isset($args['unsigned']))      { $column->unsigned      = $args['unsigned'];      }
            }

            if ($column->name === null) {
                $column->name = $propName;
            }

            if ($column->primary && $column->index) {
                throw new \Exception("Property {$propName} on class {$data->class} can't be primary-key and index together.");
            }
            if ($column->primary && $column->unique) {
                throw new \Exception("Property {$propName} on class {$data->class} can't be primary-key and unique together.");
            }
            if ($column->index && $column->unique) {
                throw new \Exception("Property {$propName} on class {$data->class} can't be index and unique together.");
            }

            if ($column->primary === true) {
                $column->nullable = false;
            }

            $column->type = \Flames\Orm\Database\Type\Kinds::normalize(strtolower((string) $column->type));

            if (\Flames\Orm\Database\Type\Kinds::isSerial($column->type)) {
                $column->autoIncrement = true;
            }

            if (in_array($column->type, ['enum', 'set'], true)) {
                $column->enumClass = \Flames\Orm\Database\Type\EnumValues::resolveClass($column->values, $column->phpType);
                $column->values    = \Flames\Orm\Database\Type\EnumValues::resolve($column->values, $column->phpType);

                if ($column->values === []) {
                    throw new \Exception("Property {$propName} on class {$data->class} requires values or a UnitEnum class for {$column->type} column.");
                }
            }

            $column->default = \Flames\Orm\Database\Type\EnumValues::normalizeDefault($column->default);

            if ($column->unsigned === true && in_array($column->type, \Flames\Orm\Database\Type\Kinds::UNSIGNED, true) === false) {
                throw new \Exception("Property {$propName} on class {$data->class} can't be unsigned with type {$column->type}.");
            }

            if ($column->type === 'vector' && ($column->size === null || $column->size < 1)) {
                throw new \Exception("Property {$propName} on class {$data->class} requires length (dimensions) for vector column.");
            }

            $data->column[$propName] = $column;
        }

        $hasPrimary = false;
        foreach ($data->column as $column) {
            if ($column->primary === true) {
                $hasPrimary = true;
                break;
            }
        }

        if ($hasPrimary === false && isset($data->column['id']) === true) {
            $data->column['id']->primary       = true;
            $data->column['id']->autoIncrement = true;
        }

        foreach ($reflection->getAttributes(\Flames\Orm\Attribute\Index::class) as $attribute) {
            /** @var \Flames\Orm\Attribute\Index $instance */
            $instance = $attribute->newInstance();

            if (count($instance->columns) < 2) {
                throw new \Exception("Index on class {$data->class} requires at least two columns.");
            }

            $resolved = [];
            foreach ($instance->columns as $property) {
                if (isset($data->column[$property]) === false) {
                    throw new \Exception("Index property {$property} not found on class {$data->class}.");
                }

                $resolved[] = $data->column[$property]->name;
            }

            $data->index[] = Arr(['columns' => $resolved]);
        }

        return $data;
    }
}
