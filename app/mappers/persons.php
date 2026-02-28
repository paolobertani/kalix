<?php
declare(strict_types=1);

namespace mappers;

/*
 * Mapper metadata
 *
 * Table: persons
 * Kind: BASE TABLE
 * Notes: replace this file by running Kalix MakeMappers.php against your DB.
 */
class persons extends \Kalix\Mapper
{
    protected static string $table = 'persons';
    protected static string $primaryKey = 'id';
    protected static bool $readOnly = false;

    protected static array $types = [
        'id' => 'int',
        'name' => 'string',
        'email' => 'string',
        'active' => 'bool',
        'created_at' => 'datetime',
    ];
}
