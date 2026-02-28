<?php
declare(strict_types=1);

namespace models;

final class persons extends \mappers\persons
{


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
