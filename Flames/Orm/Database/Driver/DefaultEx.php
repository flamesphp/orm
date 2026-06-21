<?php
declare(strict_types=1);


namespace Flames\Orm\Database\Driver;

/**
 * @internal
 */
class DefaultEx
{
    /** Driver type configured in .env (mysql, mariadb, meilisearch, …). */
    public string $name = '';

    /** Connection key from the model #[Database('…')] attribute. */
    public string $database = '';

    public function migrate($data) {}

    protected function __migrationHash(object $data): string
    {
        $path = ROOT_PATH . str_replace('\\', '/', $data->class) . '.php';

        return sha1(strval(@filemtime($path) ?: 0) . '|' . strval($data->version ?? 0));
    }

    /** @return list<string> */
    protected function __modelColumnNames(object $data): array
    {
        return array_column((array) $data->column, 'name');
    }

    /** @param list<string> $current @param list<string> $expected */
    protected function __sameColumnSet(array $current, array $expected): bool
    {
        if (count($current) !== count($expected)) {
            return false;
        }

        $a = $current;
        $b = $expected;
        sort($a);
        sort($b);

        return $a === $b;
    }
}
