<?php
declare(strict_types=1);

namespace Kalix;

use mysqli;

final class DbConnectionProvider implements ConnectionProvider
{
    private DbConfig $dbConfig;



    /*
     * Construct provider.
     *
     * Uses registry-backed DB config when no explicit config is injected.
     */

    public function __construct(?DbConfig $dbConfig = null)
    {
        $this->dbConfig = $dbConfig ?? DbConfig::fromRegistry();
    }



    /*
     * Resolve connection.
     *
     * Builds a Db handle from config and returns pooled mysqli.
     */

    public function connection(string $name = 'default'): mysqli
    {
        $connection = $this->dbConfig->connection($name);
        return (new Db($name, $connection))->mysqli();
    }
}
