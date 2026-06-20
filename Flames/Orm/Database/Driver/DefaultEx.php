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
}
