<?php
declare(strict_types=1);


namespace Flames\Orm\Database\RawConnection;

use PDO as NativePdo;

/**
 * @internal
 */
class Pdo extends NativePdo
{
    public function __construct(
        string            $dsn,
        string|null       $username    = null,
        string|null       $password    = null,
        array|null        $options     = null,
        private readonly mixed $config      = null,
        private readonly mixed $databaseSid = null,
    ) {
        parent::__construct($dsn, $username, $password, $options);

        $this->setAttribute(NativePdo::ATTR_ERRMODE,            NativePdo::ERRMODE_EXCEPTION);
        $this->setAttribute(NativePdo::ATTR_EMULATE_PREPARES,   false);
        $this->setAttribute(NativePdo::ATTR_DEFAULT_FETCH_MODE, NativePdo::FETCH_ASSOC);
    }

    public function getConfig(): mixed      { return $this->config;      }
    public function getDatabaseSid(): mixed { return $this->databaseSid; }
}
