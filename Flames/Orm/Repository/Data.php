<?php
declare(strict_types=1);


namespace Flames\Orm\Repository;

use Flames\Collection\Arr;

/**
 * @internal
 */
class Data
{
    private const __VERSION__ = 1;

    private static array $runtimeCache = [];

    public static function mountData(string $class): Arr
    {
        if (isset(self::$runtimeCache[$class])) {
            return self::$runtimeCache[$class];
        }

        $path        = ROOT_PATH . str_replace('\\', '/', $class) . '.php';
        $basePath    = ROOT_PATH . '.cache/.flames/repository/';
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

        @touch($cachePath, $currentTime);
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
            'model'     => null,
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
            } elseif ($name === \Flames\Orm\Attribute\Model::class) {
                /** @var \Flames\Orm\Attribute\Model $instance */
                $instance = $attribute->newInstance();
                if ($instance->model !== null) {
                    $data->model = $instance->model;
                }
            }
        }

        return $data;
    }
}
