<?php
declare(strict_types=1);

namespace Kalix;

use DateTimeImmutable;
use DateTimeInterface;
use mysqli;
use mysqli_stmt;
use RuntimeException;

abstract class Mapper
{
    protected static string $table = '';
    protected static string $primaryKey = 'id';
    protected static bool $readOnly = false;
    protected static array $types = [];
    protected ConnectionProvider $connections;
    protected mysqli $db;



    /*
     * Construct mapper.
     *
     * Creates a mapper and resolves the shared database connection.
     */

    public function __construct(string $connectionName = 'default', ?ConnectionProvider $connections = null)
    {
        $this->connections = $connections ?? new DbConnectionProvider();
        $this->db = $this->connections->connection($connectionName);
    }



    /*
     * Fetch all rows.
     *
     * Returns all table rows, optionally sorted.
     */

    public function all(array $orderBy = []): array
    {
        return $this->where([], null, 0, $orderBy);
    }



    /*
     * Find by primary key.
     *
     * Returns a single row for the given id.
     */

    public function find(int|string $id): ?array
    {
        $rows = $this->where([static::$primaryKey => $id], 1);
        return $rows[0] ?? null;
    }



    /*
     * Query with conditions.
     *
     * Builds an equality-based query using prepared statements.
     */

    public function where(array $conditions = [], ?int $limit = null, int $offset = 0, array $orderBy = []): array
    {
        $table = $this->tableName();
        [$whereSql, $types, $values] = $this->buildWhere($conditions);

        $orderSql = $this->buildOrderBy($orderBy);
        $limitSql = $this->buildLimit($limit, $offset);

        $sql = 'SELECT * FROM ' . $table . $whereSql . $orderSql . $limitSql;
        $stmt = $this->prepare($sql);

        if ($types !== '') {
            $this->bind($stmt, $types, $values);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            throw new RuntimeException('Query execution failed.');
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $this->castRecord($row);
        }

        $stmt->close();
        return $rows;
    }



    /*
     * Insert row.
     *
     * Inserts a new row and returns generated id when available.
     */

    public function insert(array $data): int|string
    {
        $this->assertWritable();

        if ($data === []) {
            throw new RuntimeException('Insert data cannot be empty.');
        }

        $table = $this->tableName();
        $normalized = $this->normalizeForDatabase($data);

        $columns = array_keys($normalized);
        $quoted = array_map(fn (string $column): string => $this->quoteIdentifier($column), $columns);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $types = $this->bindTypes(array_values($normalized));

        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $quoted) . ') VALUES (' . $placeholders . ')';
        $stmt = $this->prepare($sql);
        $values = array_values($normalized);

        $this->bind($stmt, $types, $values);
        $stmt->execute();
        $stmt->close();

        return $this->db->insert_id > 0 ? $this->db->insert_id : 0;
    }



    /*
     * Update by primary key.
     *
     * Updates an existing row and returns true when rows were affected.
     */

    public function updateById(int|string $id, array $data): bool
    {
        $this->assertWritable();

        if ($data === []) {
            throw new RuntimeException('Update data cannot be empty.');
        }

        $normalized = $this->normalizeForDatabase($data);
        $setSql = [];
        foreach (array_keys($normalized) as $column) {
            $setSql[] = $this->quoteIdentifier($column) . ' = ?';
        }

        $pk = static::$primaryKey;
        $sql = 'UPDATE ' . $this->tableName() . ' SET ' . implode(', ', $setSql) .
            ' WHERE ' . $this->quoteIdentifier($pk) . ' = ?';

        $values = array_values($normalized);
        $values[] = $id;
        $types = $this->bindTypes($values);

        $stmt = $this->prepare($sql);
        $this->bind($stmt, $types, $values);
        $stmt->execute();
        $changed = $stmt->affected_rows > 0;
        $stmt->close();

        return $changed;
    }



    /*
     * Delete by primary key.
     *
     * Deletes a row by id and returns true when rows were affected.
     */

    public function deleteById(int|string $id): bool
    {
        $this->assertWritable();

        $pk = static::$primaryKey;
        $sql = 'DELETE FROM ' . $this->tableName() . ' WHERE ' . $this->quoteIdentifier($pk) . ' = ?';
        $stmt = $this->prepare($sql);

        $values = [$id];
        $types = $this->bindTypes($values);

        $this->bind($stmt, $types, $values);
        $stmt->execute();
        $deleted = $stmt->affected_rows > 0;
        $stmt->close();

        return $deleted;
    }



    /*
     * Build where clause.
     *
     * Creates SQL/values tuples from equality conditions.
     */

    private function buildWhere(array $conditions): array
    {
        if ($conditions === []) {
            return ['', '', []];
        }

        $parts = [];
        $values = [];
        $types = '';

        foreach ($conditions as $column => $value) {
            $quoted = $this->quoteIdentifier((string)$column);
            if ($value === null) {
                $parts[] = $quoted . ' IS NULL';
                continue;
            }

            $parts[] = $quoted . ' = ?';
            $values[] = $value;
            $types .= $this->singleBindType($value);
        }

        return [' WHERE ' . implode(' AND ', $parts), $types, $values];
    }



    /*
     * Build order by clause.
     *
     * Returns a SQL `ORDER BY` clause from safe column directions.
     */

    private function buildOrderBy(array $orderBy): string
    {
        if ($orderBy === []) {
            return '';
        }

        $parts = [];
        foreach ($orderBy as $column => $direction) {
            $dir = strtoupper((string)$direction);
            $dir = $dir === 'DESC' ? 'DESC' : 'ASC';
            $parts[] = $this->quoteIdentifier((string)$column) . ' ' . $dir;
        }

        return ' ORDER BY ' . implode(', ', $parts);
    }



    /*
     * Build limit clause.
     *
     * Returns a SQL `LIMIT/OFFSET` clause.
     */

    private function buildLimit(?int $limit, int $offset): string
    {
        if ($limit === null) {
            return '';
        }

        $limit = max(0, $limit);
        $offset = max(0, $offset);

        if ($offset > 0) {
            return ' LIMIT ' . $offset . ', ' . $limit;
        }

        return ' LIMIT ' . $limit;
    }



    /*
     * Cast record.
     *
     * Casts raw SQL row values using mapper type metadata.
     */

    private function castRecord(array $record): array
    {
        foreach ($record as $field => $value) {
            if (!isset(static::$types[$field])) {
                continue;
            }

            if ($value === null) {
                $record[$field] = null;
                continue;
            }

            $type = static::$types[$field];
            $record[$field] = match ($type) {
                'int' => (int)$value,
                'float' => (float)$value,
                'bool' => (bool)$value,
                'json' => $this->decodeJson((string)$value),
                'date' => DateTimeImmutable::createFromFormat('Y-m-d', (string)$value) ?: null,
                'datetime' => new DateTimeImmutable((string)$value),
                default => (string)$value,
            };
        }

        return $record;
    }



    /*
     * Normalize payload.
     *
     * Converts app values to SQL-bindable scalars.
     */

    private function normalizeForDatabase(array $data): array
    {
        $normalized = [];

        foreach ($data as $field => $value) {
            if ($value instanceof DateTimeInterface) {
                $type = static::$types[$field] ?? 'datetime';
                $normalized[$field] = $type === 'date'
                    ? $value->format('Y-m-d')
                    : $value->format('Y-m-d H:i:s');
                continue;
            }

            if (is_bool($value)) {
                $normalized[$field] = $value ? 1 : 0;
                continue;
            }

            if (is_array($value) || is_object($value)) {
                $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
                $normalized[$field] = $encoded === false ? '[]' : $encoded;
                continue;
            }

            $normalized[$field] = $value;
        }

        return $normalized;
    }



    /*
     * Bind statement params.
     *
     * Binds a typed list of values to a prepared statement.
     */

    private function bind(mysqli_stmt $stmt, string $types, array $values): void
    {
        if ($types === '') {
            return;
        }

        $refs = [];
        foreach ($values as $index => $value) {
            $refs[$index] = $value;
        }

        $bind = [$types];
        foreach ($refs as $index => $value) {
            $bind[] = &$refs[$index];
        }

        $stmt->bind_param(...$bind);
    }



    /*
     * Build bind types.
     *
     * Returns the mysqli bind type string for a values array.
     */

    private function bindTypes(array $values): string
    {
        $types = '';
        foreach ($values as $value) {
            $types .= $this->singleBindType($value);
        }

        return $types;
    }



    /*
     * Resolve single bind type.
     *
     * Resolves a mysqli bind type char from one value.
     */

    private function singleBindType(mixed $value): string
    {
        if (is_int($value) || is_bool($value)) {
            return 'i';
        }

        if (is_float($value)) {
            return 'd';
        }

        return 's';
    }



    /*
     * Decode json.
     *
     * Decodes JSON safely into associative arrays.
     */

    private function decodeJson(string $value): array
    {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }



    /*
     * Prepare statement.
     *
     * Prepares SQL and throws on failure.
     */

    private function prepare(string $sql): mysqli_stmt
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt instanceof mysqli_stmt) {
            throw new RuntimeException('SQL prepare failed: ' . $this->db->error);
        }

        return $stmt;
    }



    /*
     * Quote identifier.
     *
     * Wraps SQL identifiers in backticks to prevent syntax issues.
     */

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }



    /*
     * Resolve table name.
     *
     * Returns quoted table name and validates mapper metadata.
     */

    private function tableName(): string
    {
        if (static::$table === '') {
            throw new RuntimeException('Mapper table name is not configured.');
        }

        return $this->quoteIdentifier(static::$table);
    }



    /*
     * Assert writable mapper.
     *
     * Blocks mutations for read-only mappers (e.g. SQL views).
     */

    private function assertWritable(): void
    {
        if (static::$readOnly) {
            throw new RuntimeException('This mapper is read-only.');
        }
    }
}
