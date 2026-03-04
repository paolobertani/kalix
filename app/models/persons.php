<?php
declare(strict_types=1);

namespace models;

use Kalix\ConnectionProvider;

final class persons extends \mappers\persons
{


    /*
     * Construct model.
     *
     * Accepts an optional connection provider for DI/testing.
     */

    public function __construct(?ConnectionProvider $connections = null, string $connectionName = 'default')
    {
        parent::__construct($connectionName, $connections);
    }



    /*
     * Safe list.
     *
     * Returns all rows, or an empty list when DB is unavailable.
     */

    public function allSafe(): array
    {
        try {
            return $this->all(['id' => 'ASC']);
        } catch (\Throwable) {
            return [];
        }
    }



    /*
     * Safe find.
     *
     * Returns one row by id, or null when DB is unavailable.
     */

    public function findSafe(int|string $id): ?array
    {
        try {
            return $this->find($id);
        } catch (\Throwable) {
            return null;
        }
    }
}
