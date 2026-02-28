<?php
declare(strict_types=1);

use Kalix\Autoload;
use Kalix\Db;
use Kalix\Registry;

require_once __DIR__ . '/Registry.php';
require_once __DIR__ . '/Autoload.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Mapper.php';

$appPath = resolveAppPath($argv);
Autoload::register(__DIR__, $appPath);

loadDatabaseConfig($appPath);

$connectionName = resolveOption($argv, '--db', 'default');
$db = Db::connection($connectionName);
$schema = resolveActiveSchema($db);
$tables = fetchTables($db, $schema);

$mapperDir = $appPath . '/mappers';
$modelDir = $appPath . '/models';

if (!is_dir($mapperDir)) {
    mkdir($mapperDir, 0777, true);
}

if (!is_dir($modelDir)) {
    mkdir($modelDir, 0777, true);
}

$warnings = [];
$generated = 0;

foreach ($tables as $table) {
    $tableName = (string)$table['TABLE_NAME'];
    $tableType = strtoupper((string)$table['TABLE_TYPE']);
    $isView = $tableType === 'VIEW';

    $columns = fetchColumns($db, $schema, $tableName);
    $primaryKey = fetchPrimaryKey($db, $schema, $tableName) ?? 'id';

    $className = normalizeIdentifier($tableName);
    $schemaComment = buildSchemaComment($table, $columns);
    [$types, $typeWarnings] = buildTypeMap($columns, $tableName);
    $warnings = array_merge($warnings, $typeWarnings);

    $mapperCode = buildMapperCode($className, $tableName, $primaryKey, $isView, $types, $schemaComment);
    file_put_contents($mapperDir . '/' . $className . '.php', $mapperCode);

    $modelFile = $modelDir . '/' . $className . '.php';
    if (!is_file($modelFile)) {
        file_put_contents($modelFile, buildModelCode($className));
    }

    $generated++;
}

echo "Generated mappers: {$generated}\n";
echo 'Mapper directory: ' . $mapperDir . "\n";
echo 'Model directory: ' . $modelDir . "\n";

if ($warnings !== []) {
    echo "\nType warnings:\n";
    foreach ($warnings as $warning) {
        echo '- ' . $warning . "\n";
    }
}



/*
 * Resolve option.
 *
 * Reads a `--name=value` option from argv.
 */

function resolveOption(array $argv, string $name, ?string $default = null): ?string
{
    foreach ($argv as $arg) {
        if (!str_starts_with($arg, $name . '=')) {
            continue;
        }

        return trim(substr($arg, strlen($name) + 1));
    }

    return $default;
}



/*
 * Resolve app path.
 *
 * Resolves app path from CLI option or current project defaults.
 */

function resolveAppPath(array $argv): string
{
    $fromArg = resolveOption($argv, '--app');
    if (is_string($fromArg) && $fromArg !== '') {
        return rtrim($fromArg, '/');
    }

    return rtrim(dirname(__DIR__) . '/app', '/');
}



/*
 * Load database config.
 *
 * Loads app database configuration and stores it in Registry.
 */

function loadDatabaseConfig(string $appPath): void
{
    $cfg = [];
    $file = $appPath . '/config/database.php';
    if (is_file($file)) {
        $loaded = require $file;
        if (is_array($loaded)) {
            $cfg = $loaded;
        }
    }

    $cfg = mergeSecretsIntoDbConfig($cfg, dirname($appPath));
    if ($cfg === []) {
        throw new RuntimeException('Database configuration not found in app config or PRIVATE/secrets.php.');
    }

    Registry::set('/cfg/db', $cfg);
}



/*
 * Merge secrets db config.
 *
 * Fills missing DB connection keys using `PRIVATE/secrets.php` constants.
 */

function mergeSecretsIntoDbConfig(array $cfg, string $rootPath): array
{
    $fallback = readSecretsDbFallback($rootPath);
    if ($fallback === []) {
        if ($cfg === []) {
            return [];
        }

        if (isSingleDbConfig($cfg)) {
            return normalizeDbConnection($cfg);
        }

        $normalized = [];
        foreach ($cfg as $name => $connection) {
            if (!is_array($connection)) {
                continue;
            }

            $normalized[$name] = normalizeDbConnection($connection);
        }

        return $normalized;
    }

    if ($cfg === []) {
        return ['default' => $fallback];
    }

    if (isSingleDbConfig($cfg)) {
        return mergeConnectionWithFallback($cfg, $fallback);
    }

    $merged = [];
    foreach ($cfg as $name => $connection) {
        if (!is_array($connection)) {
            continue;
        }

        $merged[$name] = mergeConnectionWithFallback($connection, $fallback);
    }

    return $merged !== [] ? $merged : ['default' => $fallback];
}



/*
 * Read secrets fallback.
 *
 * Loads DB fallback values from constants in `PRIVATE/secrets.php`.
 */

function readSecretsDbFallback(string $rootPath): array
{
    $secretsFile = rtrim($rootPath, '/') . '/PRIVATE/secrets.php';
    if (!is_file($secretsFile)) {
        return [];
    }

    require_once $secretsFile;

    $map = [
        'host' => 'DB_HOST',
        'user' => 'DB_USER',
        'pass' => 'DB_PASS',
        'name' => 'DB_NAME',
        'port' => 'DB_PORT',
        'socket' => 'DB_SOCK',
        'charset' => 'DB_CHARSET',
    ];

    $fallback = [];
    foreach ($map as $key => $constant) {
        if (!defined($constant)) {
            continue;
        }

        $fallback[$key] = constant($constant);
    }

    return normalizeDbConnection($fallback);
}



/*
 * Detect single db config.
 *
 * Returns true when config is one connection instead of named connections.
 */

function isSingleDbConfig(array $cfg): bool
{
    $keys = ['host', 'user', 'pass', 'name', 'port', 'socket', 'sock', 'charset'];
    foreach ($keys as $key) {
        if (array_key_exists($key, $cfg)) {
            return true;
        }
    }

    return false;
}



/*
 * Normalize db connection.
 *
 * Normalizes aliases and scalar types for one DB connection array.
 */

function normalizeDbConnection(array $cfg): array
{
    if (isset($cfg['sock']) && !isset($cfg['socket'])) {
        $cfg['socket'] = $cfg['sock'];
    }

    unset($cfg['sock']);

    if (isset($cfg['port'])) {
        $cfg['port'] = (int)$cfg['port'];
    }

    return $cfg;
}



/*
 * Merge connection with fallback.
 *
 * Applies fallback values only when connection values are unavailable.
 */

function mergeConnectionWithFallback(array $connection, array $fallback): array
{
    $connection = normalizeDbConnection($connection);
    $fallback = normalizeDbConnection($fallback);

    foreach ($fallback as $key => $value) {
        if (!hasDbValue($connection, $key)) {
            $connection[$key] = $value;
        }
    }

    return $connection;
}



/*
 * Check db value availability.
 *
 * Returns true when a DB config key is present and usable.
 */

function hasDbValue(array $connection, string $key): bool
{
    if (!array_key_exists($key, $connection)) {
        return false;
    }

    $value = $connection[$key];
    if ($value === null) {
        return false;
    }

    if ($key === 'port') {
        return (int)$value > 0;
    }

    if (is_string($value)) {
        return trim($value) !== '';
    }

    return true;
}



/*
 * Resolve active schema.
 *
 * Returns the current database/schema name from the active connection.
 */

function resolveActiveSchema(mysqli $db): string
{
    $result = $db->query('SELECT DATABASE() AS db_name');
    $row = $result ? $result->fetch_assoc() : null;
    $schema = (string)($row['db_name'] ?? '');

    if ($schema === '') {
        throw new RuntimeException('Could not resolve current database schema.');
    }

    return $schema;
}



/*
 * Fetch tables.
 *
 * Returns all tables and views for the selected schema.
 */

function fetchTables(mysqli $db, string $schema): array
{
    $sql = 'SELECT TABLE_NAME, TABLE_TYPE, TABLE_COMMENT
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = ?
            ORDER BY TABLE_NAME';

    $stmt = prepareAndBind($db, $sql, 's', [$schema]);
    $stmt->execute();

    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result?->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();
    return $rows;
}



/*
 * Fetch columns.
 *
 * Returns ordered column metadata for one table/view.
 */

function fetchColumns(mysqli $db, string $schema, string $table): array
{
    $sql = 'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT,
                   EXTRA, COLUMN_COMMENT, ORDINAL_POSITION
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
            ORDER BY ORDINAL_POSITION';

    $stmt = prepareAndBind($db, $sql, 'ss', [$schema, $table]);
    $stmt->execute();

    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result?->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();
    return $rows;
}



/*
 * Fetch primary key.
 *
 * Returns primary key column name for one table.
 */

function fetchPrimaryKey(mysqli $db, string $schema, string $table): ?string
{
    $sql = 'SELECT COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?
            ORDER BY ORDINAL_POSITION
            LIMIT 1';

    $stmt = prepareAndBind($db, $sql, 'sss', [$schema, $table, 'PRIMARY']);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!is_array($row)) {
        return null;
    }

    return (string)$row['COLUMN_NAME'];
}



/*
 * Build mapper type map.
 *
 * Maps SQL types to Kalix PHP types and collects recommendation warnings.
 */

function buildTypeMap(array $columns, string $tableName): array
{
    $types = [];
    $warnings = [];

    foreach ($columns as $column) {
        $name = (string)$column['COLUMN_NAME'];
        $dataType = strtolower((string)$column['DATA_TYPE']);
        $phpType = mapMysqlTypeToPhp($dataType);

        $types[$name] = $phpType;
        if (shouldWarnType($dataType, $phpType)) {
            $warnings[] = sprintf(
                '%s.%s uses `%s`; recommended type for `%s` is `%s`.',
                $tableName,
                $name,
                $dataType,
                $phpType,
                recommendedMysqlType($phpType)
            );
        }
    }

    return [$types, $warnings];
}



/*
 * Map MySQL type.
 *
 * Maps low-level MySQL data types to Kalix PHP type labels.
 */

function mapMysqlTypeToPhp(string $dataType): string
{
    return match ($dataType) {
        'bigint', 'int', 'integer', 'smallint', 'mediumint', 'tinyint' => 'int',
        'double', 'float', 'decimal', 'numeric', 'real' => 'float',
        'bit' => 'bool',
        'date' => 'date',
        'datetime', 'timestamp' => 'datetime',
        'json' => 'json',
        default => 'string',
    };
}



/*
 * Determine warning.
 *
 * Returns true when SQL type differs from Kalix recommended MySQL type.
 */

function shouldWarnType(string $dataType, string $phpType): bool
{
    $recommended = match ($phpType) {
        'int' => ['bigint'],
        'float' => ['double'],
        'bool' => ['bit'],
        'string' => ['char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext'],
        'date' => ['date'],
        'datetime' => ['datetime'],
        'json' => ['json'],
        default => [$dataType],
    };

    return !in_array($dataType, $recommended, true);
}



/*
 * Recommend MySQL type.
 *
 * Returns text label for the recommended SQL storage type.
 */

function recommendedMysqlType(string $phpType): string
{
    return match ($phpType) {
        'int' => 'bigint',
        'float' => 'double',
        'bool' => 'bit',
        'string' => 'char or text',
        'date' => 'date',
        'datetime' => 'datetime',
        'json' => 'json',
        default => 'char',
    };
}



/*
 * Build schema comment.
 *
 * Builds a full schema dump comment embedded in generated mapper files.
 */

function buildSchemaComment(array $table, array $columns): string
{
    $lines = [];
    $lines[] = 'Table: ' . (string)$table['TABLE_NAME'];
    $lines[] = 'Type: ' . (string)$table['TABLE_TYPE'];
    $comment = trim((string)($table['TABLE_COMMENT'] ?? ''));
    $lines[] = 'Comment: ' . ($comment === '' ? '(none)' : $comment);
    $lines[] = 'Columns:';

    foreach ($columns as $column) {
        $line = sprintf(
            '- %s %s nullable:%s default:%s extra:%s comment:%s',
            (string)$column['COLUMN_NAME'],
            (string)$column['COLUMN_TYPE'],
            (string)$column['IS_NULLABLE'],
            normalizeSchemaText($column['COLUMN_DEFAULT'] ?? null),
            normalizeSchemaText($column['EXTRA'] ?? null),
            normalizeSchemaText($column['COLUMN_COMMENT'] ?? null)
        );
        $lines[] = $line;
    }

    $output = [];
    foreach ($lines as $line) {
        $output[] = ' * ' . $line;
    }

    return implode("\n", $output);
}



/*
 * Build mapper code.
 *
 * Generates one mapper class source.
 */

function buildMapperCode(
    string $className,
    string $tableName,
    string $primaryKey,
    bool $readOnly,
    array $types,
    string $schemaComment
): string {
    $typesBody = [];
    foreach ($types as $field => $type) {
        $typesBody[] = "        '" . addslashes((string)$field) . "' => '" . addslashes((string)$type) . "',";
    }

    $typesCode = implode("\n", $typesBody);

    return "<?php\n" .
        "declare(strict_types=1);\n\n" .
        "namespace mappers;\n\n" .
        "/*\n" .
        " * Auto-generated by Kalix MakeMappers.php.\n" .
        " *\n" .
        $schemaComment . "\n" .
        " */\n" .
        "final class {$className} extends \\Kalix\\Mapper\n" .
        "{\n" .
        "    protected static string \$table = '" . addslashes($tableName) . "';\n" .
        "    protected static string \$primaryKey = '" . addslashes($primaryKey) . "';\n" .
        '    protected static bool $readOnly = ' . ($readOnly ? 'true' : 'false') . ";\n\n" .
        "    protected static array \$types = [\n" .
        $typesCode . "\n" .
        "    ];\n" .
        "}\n";
}



/*
 * Build model code.
 *
 * Generates one model class source extending generated mapper.
 */

function buildModelCode(string $className): string
{
    return "<?php\n" .
        "declare(strict_types=1);\n\n" .
        "namespace models;\n\n" .
        "final class {$className} extends \\mappers\\{$className}\n" .
        "{\n" .
        "}\n";
}



/*
 * Normalize identifier.
 *
 * Converts SQL table names into safe PHP class/file identifiers.
 */

function normalizeIdentifier(string $raw): string
{
    $id = preg_replace('/[^a-zA-Z0-9_]/', '_', $raw) ?? $raw;
    if ($id === '') {
        $id = 'table_' . time();
    }

    if (preg_match('/^[0-9]/', $id) === 1) {
        $id = '_' . $id;
    }

    return $id;
}



/*
 * Normalize schema text.
 *
 * Converts nullable schema values into readable text labels.
 */

function normalizeSchemaText(mixed $value): string
{
    if ($value === null || $value === '') {
        return '(none)';
    }

    return str_replace("\n", ' ', (string)$value);
}



/*
 * Prepare and bind.
 *
 * Prepares SQL and binds parameters in one helper.
 */

function prepareAndBind(mysqli $db, string $sql, string $types, array $params): mysqli_stmt
{
    $stmt = $db->prepare($sql);
    if (!$stmt instanceof mysqli_stmt) {
        throw new RuntimeException('Failed to prepare SQL: ' . $db->error);
    }

    if ($types !== '') {
        $values = [];
        foreach ($params as $i => $param) {
            $values[$i] = $param;
        }

        $bind = [$types];
        foreach ($values as $i => $value) {
            $bind[] = &$values[$i];
        }

        $stmt->bind_param(...$bind);
    }

    return $stmt;
}
