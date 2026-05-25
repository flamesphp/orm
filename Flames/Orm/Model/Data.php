<?php

namespace Flames\Orm\Model;

use Flames\Collection\Arr;

/**
 * @internal
 */
class Data
{
    private const __VERSION__ = 10;

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
        ]);

        $reflection = new \ReflectionClass($class);

        foreach ($reflection->getAttributes() as $attribute) {
            $name = $attribute->getName();
            if ($name === \Flames\Orm\Database::class) {
                $args = $attribute->getArguments();
                if (isset($args['name'])) {
                    $data->database = $args['name'];
                }
            } elseif ($name === \Flames\Orm\Table::class) {
                $args = $attribute->getArguments();
                if (isset($args['name'])) {
                    $data->table = $args['name'];
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
                'size'          => null,
                'nullable'      => false,
                'default'       => $property->getDefaultValue(),
                'primary'       => false,
                'index'         => false,
                'unique'        => false,
                'autoIncrement' => false,
            ]);

            $type = $property->getType();
            if ($type instanceof \ReflectionUnionType) {
                foreach ($type->getTypes() as $t) {
                    $tName = $t->getName();
                    if ($tName === 'null') {
                        $column->nullable = true;
                    } elseif ($column->type === null) {
                        $column->type = $tName;
                    }
                }
            } else {
                $column->nullable = $type->allowsNull();
                $column->type     = $type->getName();
            }

            foreach ($property->getAttributes() as $attribute) {
                if ($attribute->getName() !== \Flames\Orm\Column::class) {
                    continue;
                }
                $args = $attribute->getArguments();
                if (isset($args['nullable']))      { $column->nullable      = $args['nullable'];      }
                if (isset($args['type']))          { $column->type          = $args['type'];          }
                if (isset($args['length']))        { $column->size          = $args['length'];        }
                if (isset($args['default']))       { $column->default       = $args['default'];       }
                if (isset($args['name']))          { $column->name          = $args['name'];          }
                if (isset($args['index']))         { $column->index         = $args['index'];         }
                if (isset($args['primary']))       { $column->primary       = $args['primary'];       }
                if (isset($args['autoIncrement'])) { $column->autoIncrement = $args['autoIncrement']; }
                if (isset($args['unique']))        { $column->unique        = $args['unique'];        }
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

            $column->type = strtolower($column->type);
            $data->column[$propName] = $column;
        }

        return $data;
    }
}
