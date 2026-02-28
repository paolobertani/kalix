<?php
declare(strict_types=1);

namespace Kalix;

use mysqli;
use RuntimeException;

final class Db
{
    private static array $pool = [];



    /*
     * Get connection.
     *
     * Returns a shared mysqli connection for the requested database key.
     */

    public static function connection(string $name = 'default'): mysqli
    {
        if (!extension_loaded('mysqli')) {
            throw new RuntimeException('mysqli extension is required.');
        }

        $config = self::resolveConfig($name);
        $hash = md5((string)json_encode($config));

        if (isset(self::$pool[$hash])) {
            return self::$pool[$hash];
        }

        $mysqli = mysqli_init();
        if ($mysqli === false) {
            throw new RuntimeException('Unable to initialize mysqli.');
        }

        if (defined('MYSQLI_OPT_INT_AND_FLOAT_NATIVE')) {
            $mysqli->options((int)MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);
        }

        $host = (string)$config['host'];
        $user = (string)$config['user'];
        $pass = (string)($config['pass'] ?? '');
        $db = (string)$config['name'];
        $port = (int)($config['port'] ?? 3306);
        $socket = isset($config['socket']) ? (string)$config['socket'] : null;

        $connected = $mysqli->real_connect($host, $user, $pass, $db, $port, $socket);
        if ($connected !== true) {
            throw new RuntimeException('Database connection failed: ' . $mysqli->connect_error);
        }

        $charset = (string)($config['charset'] ?? 'utf8mb4');
        $mysqli->set_charset($charset);

        self::$pool[$hash] = $mysqli;
        return $mysqli;
    }



    /*
     * Close connections.
     *
     * Closes every pooled mysqli connection.
     */

    public static function closeAll(): void
    {
        foreach (self::$pool as $connection) {
            if ($connection instanceof mysqli) {
                $connection->close();
            }
        }

        self::$pool = [];
    }



    /*
     * Resolve config.
     *
     * Resolves the target database configuration from the shared registry.
     */

    private static function resolveConfig(string $name): array
    {
        $all = Registry::get('/cfg/db', []);

        if (!is_array($all) || $all === []) {
            throw new RuntimeException('Database configuration not found.');
        }

        if (isset($all[$name]) && is_array($all[$name])) {
            $config = $all[$name];
        } else {
            $config = $all;
        }

        $required = ['host', 'user', 'name'];
        foreach ($required as $key) {
            if (!array_key_exists($key, $config)) {
                throw new RuntimeException('Database configuration key missing: ' . $key);
            }
        }

        return $config;
    }
}
