<?php
declare(strict_types=1);

namespace Kalix;

use RuntimeException;

final class DbConfig
{
    private array $connections;



    /*
     * Construct db config.
     *
     * Stores one or more named database connections.
     */

    public function __construct(array $connections)
    {
        $this->connections = $this->normalize($connections);
        if ($this->connections === []) {
            throw new RuntimeException('Database configuration not found.');
        }
    }



    /*
     * Build from registry.
     *
     * Loads database connections from shared framework registry.
     */

    public static function fromRegistry(): self
    {
        $loaded = Registry::get('/cfg/db', []);
        return new self(is_array($loaded) ? $loaded : []);
    }



    /*
     * Resolve connection.
     *
     * Returns a concrete connection by name or fallback default.
     */

    public function connection(string $name = 'default'): array
    {
        if (isset($this->connections[$name])) {
            return $this->connections[$name];
        }

        if (isset($this->connections['default'])) {
            return $this->connections['default'];
        }

        $first = array_key_first($this->connections);
        if ($first === null) {
            throw new RuntimeException('Database configuration not found.');
        }

        return $this->connections[$first];
    }



    /*
     * Normalize connections.
     *
     * Normalizes single-connection and multi-connection payloads.
     */

    private function normalize(array $connections): array
    {
        if ($this->isSingleConnection($connections)) {
            return ['default' => $connections];
        }

        $normalized = [];
        foreach ($connections as $name => $connection) {
            if (!is_string($name) || !is_array($connection)) {
                continue;
            }

            $normalized[$name] = $connection;
        }

        return $normalized;
    }



    /*
     * Detect single connection.
     *
     * Returns true when array looks like one DB connection payload.
     */

    private function isSingleConnection(array $connections): bool
    {
        $keys = ['host', 'user', 'pass', 'name', 'port', 'socket', 'sock', 'charset'];
        foreach ($keys as $key) {
            if (array_key_exists($key, $connections)) {
                return true;
            }
        }

        return false;
    }
}
