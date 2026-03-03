#!/usr/bin/env php
<?php
declare(strict_types=1);

use Kalix\Autoload;
use Kalix\Db;
use Kalix\Registry;

$rootPath = rtrim(dirname(__DIR__), '/');
$kalixPath = $rootPath . '/Kalix';

require_once $kalixPath . '/Registry.php';
require_once $kalixPath . '/Autoload.php';
require_once $kalixPath . '/Db.php';
require_once $kalixPath . '/Mapper.php';

ensureMyDumpSymlink(__DIR__);

$appPath = resolveAppPath($argv, $rootPath);
Autoload::register($kalixPath, $appPath);

$connectionName = (string)resolveOption($argv, '--db', 'default');
$useInteractive = hasFlag($argv, '-i') || hasFlag($argv, '--interactive');
$useSsh = hasFlag($argv, '--ssh');
$schemaDir = resolveSchemaDir($argv, $appPath);
$schemaJsonPath = $schemaDir . '/schema.json';
$schemaXlsxPath = $schemaDir . '/schema.xlsx';

try {
    dumpSchemaWithMyDump(
        __DIR__,
        $appPath,
        $connectionName,
        $useInteractive,
        $useSsh,
        $schemaJsonPath,
        $schemaXlsxPath
    );
    $tables = loadTablesFromSchemaJson($schemaJsonPath);
} catch (\Throwable $e) {
    fwrite(STDERR, 'MakeMappers failed: ' . $e->getMessage() . "\n");
    exit(1);
}

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
    $tableMeta = $table['table'];
    $columns = $table['columns'];

    $tableName = (string)$tableMeta['TABLE_NAME'];
    $tableType = strtoupper((string)$tableMeta['TABLE_TYPE']);
    $isView = $tableType === 'VIEW';

    $primaryKey = (string)$table['primary_key'];

    $className = normalizeIdentifier($tableName);
    $schemaComment = buildSchemaComment($tableMeta, $columns);
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
echo 'Schema JSON: ' . $schemaJsonPath . "\n";
echo 'Schema XLSX: ' . $schemaXlsxPath . "\n";

if ($warnings !== []) {
    echo "\nType warnings:\n";
    foreach ($warnings as $warning) {
        echo '- ' . $warning . "\n";
    }
}

/*
 * Ensure myDump symlink.
 *
 * Checks whether `myDump.php` symlink exists in the TOOLS directory.
 * If missing, asks the user for the script path and creates the symlink.
 */

function ensureMyDumpSymlink(string $toolsPath): void
{
    $linkPath = rtrim($toolsPath, '/') . '/myDump.php';

    if (is_link($linkPath)) {
        return;
    }

    if (file_exists($linkPath)) {
        fwrite(STDOUT, "Notice: {$linkPath} exists but is not a symlink. Skipping symlink creation.\n");
        return;
    }

    fwrite(STDOUT, "Missing symlink: {$linkPath}\n");

    while (true) {
        fwrite(STDOUT, 'Locate myDump.php script path: ');
        $line = fgets(STDIN);

        if ($line === false) {
            throw new RuntimeException('Cannot read script path from STDIN.');
        }

        $target = normalizeUserPath(rtrim($line, "\r\n"));
        if ($target === '') {
            fwrite(STDOUT, "Path is required.\n");
            continue;
        }

        if (!is_file($target)) {
            fwrite(STDOUT, "File not found: {$target}\n");
            continue;
        }

        if (!@symlink($target, $linkPath)) {
            $error = error_get_last();
            $message = is_array($error) && isset($error['message']) ? (string)$error['message'] : 'unknown error';
            throw new RuntimeException("Failed to create symlink {$linkPath}: {$message}");
        }

        fwrite(STDOUT, "Symlink created: {$linkPath} -> {$target}\n");
        return;
    }
}



/*
 * Normalize user path.
 *
 * Resolves `~`, relative paths, and real path when available.
 */

function normalizeUserPath(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }

    if ($path === '~' || str_starts_with($path, '~/')) {
        $home = (string)getenv('HOME');
        if ($home !== '') {
            $path = rtrim($home, '/') . ($path === '~' ? '' : substr($path, 1));
        }
    }

    if (!str_starts_with($path, '/')) {
        $cwd = getcwd();
        if (is_string($cwd) && $cwd !== '') {
            $path = rtrim($cwd, '/') . '/' . $path;
        }
    }

    $realPath = realpath($path);
    return $realPath !== false ? $realPath : $path;
}



/*
 * Resolve schema directory.
 *
 * Resolves where `schema.json` and `schema.xlsx` are stored.
 */

function resolveSchemaDir(array $argv, string $appPath): string
{
    $fromArg = resolveOption($argv, '--schema-dir');
    if (is_string($fromArg) && trim($fromArg) !== '') {
        return rtrim(normalizeUserPath($fromArg), '/');
    }

    return rtrim($appPath, '/') . '/database';
}



/*
 * Dump schema with myDump.
 *
 * Executes `myDump.php` so schema files become the source of truth.
 */

function dumpSchemaWithMyDump(
    string $toolsPath,
    string $appPath,
    string $connectionName,
    bool $useInteractive,
    bool $useSsh,
    string $schemaJsonPath,
    string $schemaXlsxPath
): void {
    $schemaDir = dirname($schemaJsonPath);
    if (!is_dir($schemaDir) && !mkdir($schemaDir, 0777, true) && !is_dir($schemaDir)) {
        throw new RuntimeException('Cannot create schema directory: ' . $schemaDir);
    }

    $myDumpPath = rtrim($toolsPath, '/') . '/myDump.php';
    if (!is_file($myDumpPath) && !is_link($myDumpPath)) {
        throw new RuntimeException('myDump script not found: ' . $myDumpPath);
    }

    $php = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $flags = [];
    if ($useInteractive) {
        $flags[] = '-i';
    }
    if ($useSsh) {
        $flags[] = '--ssh';
    }

    $commands = [
        array_merge([$php, $myDumpPath, '--app=' . $appPath, '--db=' . $connectionName], $flags),
    ];

    if (!$useInteractive) {
        $commands[] = array_merge([$php, $myDumpPath, '--db=' . $connectionName], $flags);
        $commands[] = array_merge([$php, $myDumpPath], $flags);
    }

    fwrite(STDOUT, "Refreshing schema files with myDump...\n");

    $cwd = getcwd();
    if (!is_string($cwd) || $cwd === '') {
        throw new RuntimeException('Cannot resolve current working directory.');
    }

    if (!@chdir($schemaDir)) {
        throw new RuntimeException('Cannot enter schema directory: ' . $schemaDir);
    }

    $lastError = 'myDump did not run.';
    try {
        foreach ($commands as $index => $command) {
            fwrite(STDOUT, 'myDump attempt ' . ($index + 1) . ' of ' . count($commands) . "...\n");
            [$exitCode, $output] = runCommandWithOutput($command);
            if ($exitCode !== 0) {
                $suffix = $output === '' ? '' : "\nmyDump output:\n" . $output;
                $lastError = 'myDump failed with exit code ' . $exitCode . '.' . $suffix;
                continue;
            }

            if (!is_file($schemaJsonPath)) {
                $lastError = 'myDump completed but schema.json was not generated at: ' . $schemaJsonPath;
                continue;
            }

            if (!is_file($schemaXlsxPath)) {
                $lastError = 'myDump completed but schema.xlsx was not generated at: ' . $schemaXlsxPath;
                continue;
            }

            return;
        }
    } finally {
        @chdir($cwd);
    }

    throw new RuntimeException($lastError);
}



/*
 * Load tables from schema JSON.
 *
 * Reads `schema.json` and normalizes table metadata for mapper generation.
 */

function loadTablesFromSchemaJson(string $schemaJsonPath): array
{
    if (!is_file($schemaJsonPath)) {
        throw new RuntimeException('Schema JSON not found: ' . $schemaJsonPath);
    }

    $raw = file_get_contents($schemaJsonPath);
    if ($raw === false) {
        throw new RuntimeException('Cannot read schema JSON: ' . $schemaJsonPath);
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid schema JSON format in: ' . $schemaJsonPath);
    }

    $tables = extractSchemaTables($decoded);
    if ($tables === []) {
        throw new RuntimeException('No tables found in schema JSON: ' . $schemaJsonPath);
    }

    usort(
        $tables,
        static fn (array $a, array $b): int => strcmp(
            (string)$a['table']['TABLE_NAME'],
            (string)$b['table']['TABLE_NAME']
        )
    );

    return $tables;
}



/*
 * Extract schema tables.
 *
 * Supports common schema.json shapes (`tables`, nested `schema.tables`, or map/list roots).
 */

function extractSchemaTables(array $schema): array
{
    $candidates = [];
    foreach (
        [
            ['tables'],
            ['schema', 'tables'],
            ['database', 'tables'],
            ['dump', 'tables'],
            ['entities'],
        ] as $path
    ) {
        $value = valueFromPath($schema, $path);
        if (is_array($value)) {
            $candidates = $value;
            break;
        }
    }

    if ($candidates === []) {
        if (isSequentialArray($schema)) {
            $candidates = $schema;
        } else {
            $allChildrenArrays = true;
            foreach ($schema as $value) {
                if (!is_array($value)) {
                    $allChildrenArrays = false;
                    break;
                }
            }

            if ($allChildrenArrays) {
                $candidates = $schema;
            }
        }
    }

    $tables = [];
    foreach ($candidates as $key => $rawTable) {
        $fallbackName = is_string($key) ? $key : null;
        $normalized = normalizeSchemaTableEntry($rawTable, $fallbackName);
        if ($normalized === null) {
            continue;
        }

        $tables[] = $normalized;
    }

    return $tables;
}



/*
 * Normalize schema table entry.
 *
 * Converts one raw table payload into mapper-ready metadata.
 */

function normalizeSchemaTableEntry(mixed $rawTable, ?string $fallbackName): ?array
{
    if (!is_array($rawTable)) {
        return null;
    }

    $tableName = resolveSchemaTableName($rawTable, $fallbackName);
    if ($tableName === '') {
        return null;
    }

    $tableTypeRaw = (string)valueFromKeys($rawTable, ['TABLE_TYPE', 'table_type', 'type', 'kind'], 'BASE TABLE');
    $tableType = stripos($tableTypeRaw, 'view') !== false ? 'VIEW' : 'BASE TABLE';
    $tableComment = (string)valueFromKeys($rawTable, ['TABLE_COMMENT', 'table_comment', 'comment', 'description'], '');

    $columns = normalizeSchemaColumns(extractSchemaColumns($rawTable));
    if ($columns === []) {
        return null;
    }

    return [
        'table' => [
            'TABLE_NAME' => $tableName,
            'TABLE_TYPE' => $tableType,
            'TABLE_COMMENT' => $tableComment,
        ],
        'columns' => $columns,
        'primary_key' => resolveSchemaPrimaryKey($rawTable, $columns),
    ];
}



/*
 * Resolve schema table name.
 *
 * Resolves table name from common keys or uses associative key fallback.
 */

function resolveSchemaTableName(array $rawTable, ?string $fallbackName): string
{
    $name = trim((string)valueFromKeys($rawTable, ['TABLE_NAME', 'table_name', 'name', 'table', 'entity'], ''));
    if ($name !== '') {
        return $name;
    }

    if (is_string($fallbackName) && $fallbackName !== '' && !ctype_digit($fallbackName)) {
        return $fallbackName;
    }

    return '';
}



/*
 * Extract schema columns.
 *
 * Resolves columns list/map from common keys.
 */

function extractSchemaColumns(array $rawTable): array
{
    foreach (['columns', 'COLUMNS', 'fields', 'FIELDS'] as $key) {
        if (isset($rawTable[$key]) && is_array($rawTable[$key])) {
            return $rawTable[$key];
        }
    }

    if (isset($rawTable['schema']) && is_array($rawTable['schema'])) {
        foreach (['columns', 'COLUMNS', 'fields', 'FIELDS'] as $key) {
            if (isset($rawTable['schema'][$key]) && is_array($rawTable['schema'][$key])) {
                return $rawTable['schema'][$key];
            }
        }
    }

    return [];
}



/*
 * Normalize schema columns.
 *
 * Converts raw columns payload to information_schema-like rows.
 */

function normalizeSchemaColumns(array $rawColumns): array
{
    $columns = [];
    $position = 1;

    foreach ($rawColumns as $key => $rawColumn) {
        $fallbackName = is_string($key) && $key !== '' && !ctype_digit($key) ? $key : null;
        $column = normalizeSchemaColumn($rawColumn, $fallbackName, $position);
        if ($column === null) {
            continue;
        }

        $columns[] = $column;
        $position++;
    }

    usort(
        $columns,
        static fn (array $a, array $b): int => (int)$a['ORDINAL_POSITION'] <=> (int)$b['ORDINAL_POSITION']
    );

    return $columns;
}



/*
 * Normalize schema column.
 *
 * Builds a normalized column row from loose input fields.
 */

function normalizeSchemaColumn(mixed $rawColumn, ?string $fallbackName, int $position): ?array
{
    if (!is_array($rawColumn)) {
        return null;
    }

    $name = trim((string)valueFromKeys($rawColumn, ['COLUMN_NAME', 'column_name', 'name', 'column', 'field'], $fallbackName ?? ''));
    if ($name === '') {
        return null;
    }

    $columnType = trim((string)valueFromKeys($rawColumn, ['COLUMN_TYPE', 'column_type', 'type', 'full_type', 'definition'], ''));
    $dataType = strtolower(trim((string)valueFromKeys($rawColumn, ['DATA_TYPE', 'data_type', 'mysql_type'], '')));

    if ($dataType === '' && $columnType !== '') {
        $dataType = extractColumnDataTypeFromType($columnType);
    }

    if ($columnType === '') {
        $columnType = $dataType !== '' ? $dataType : 'varchar(255)';
    }

    if ($dataType === '') {
        $dataType = extractColumnDataTypeFromType($columnType);
    }

    if ($dataType === '') {
        $dataType = 'varchar';
    }

    $nullable = normalizeNullableValue(valueFromKeys($rawColumn, ['IS_NULLABLE', 'is_nullable', 'nullable'], 'NO')) ? 'YES' : 'NO';
    $default = valueFromKeys($rawColumn, ['COLUMN_DEFAULT', 'column_default', 'default'], null);
    $extra = (string)valueFromKeys($rawColumn, ['EXTRA', 'extra'], '');
    $comment = (string)valueFromKeys($rawColumn, ['COLUMN_COMMENT', 'column_comment', 'comment'], '');
    $ordinal = (int)valueFromKeys($rawColumn, ['ORDINAL_POSITION', 'ordinal_position', 'position', 'ordinal'], $position);
    if ($ordinal <= 0) {
        $ordinal = $position;
    }

    $isPrimary = normalizeBooleanValue(valueFromKeys($rawColumn, ['is_primary', 'primary', 'pk'], false));
    $columnKey = strtoupper(trim((string)valueFromKeys($rawColumn, ['COLUMN_KEY', 'column_key', 'key'], '')));
    if ($columnKey === 'PRI') {
        $isPrimary = true;
    }

    return [
        'COLUMN_NAME' => $name,
        'DATA_TYPE' => $dataType,
        'COLUMN_TYPE' => $columnType,
        'IS_NULLABLE' => $nullable,
        'COLUMN_DEFAULT' => $default,
        'EXTRA' => $extra,
        'COLUMN_COMMENT' => $comment,
        'ORDINAL_POSITION' => $ordinal,
        'IS_PRIMARY' => $isPrimary,
    ];
}



/*
 * Extract data type from column type.
 *
 * Converts `varchar(255)` to `varchar`, etc.
 */

function extractColumnDataTypeFromType(string $columnType): string
{
    $columnType = strtolower(trim($columnType));
    if ($columnType === '') {
        return '';
    }

    if (preg_match('/^[a-z]+/', $columnType, $match) === 1) {
        return (string)$match[0];
    }

    return $columnType;
}



/*
 * Resolve schema primary key.
 *
 * Returns one primary key column name for mapper generation.
 */

function resolveSchemaPrimaryKey(array $rawTable, array $columns): string
{
    $primary = valueFromKeys($rawTable, ['primary_key', 'primaryKey', 'pk'], null);
    if (is_string($primary) && trim($primary) !== '') {
        return trim($primary);
    }

    if (is_array($primary)) {
        foreach ($primary as $column) {
            if (is_string($column) && trim($column) !== '') {
                return trim($column);
            }
        }
    }

    $primaryList = valueFromKeys($rawTable, ['primary_keys', 'primaryKeys'], null);
    if (is_array($primaryList)) {
        foreach ($primaryList as $column) {
            if (is_string($column) && trim($column) !== '') {
                return trim($column);
            }
        }
    }

    foreach ($columns as $column) {
        if (!empty($column['IS_PRIMARY'])) {
            return (string)$column['COLUMN_NAME'];
        }
    }

    foreach ($columns as $column) {
        if (strtolower((string)$column['COLUMN_NAME']) === 'id') {
            return 'id';
        }
    }

    return isset($columns[0]['COLUMN_NAME']) ? (string)$columns[0]['COLUMN_NAME'] : 'id';
}



/*
 * Resolve value by keys.
 *
 * Returns the first present value from the provided keys.
 */

function valueFromKeys(array $row, array $keys, mixed $default = null): mixed
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row)) {
            return $row[$key];
        }
    }

    return $default;
}



/*
 * Resolve nested path value.
 *
 * Returns a nested array value by path, or null.
 */

function valueFromPath(array $data, array $path): mixed
{
    $value = $data;
    foreach ($path as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return null;
        }

        $value = $value[$segment];
    }

    return $value;
}



/*
 * Check sequential array.
 *
 * Returns true when array keys are 0..N in order.
 */

function isSequentialArray(array $data): bool
{
    $index = 0;
    foreach (array_keys($data) as $key) {
        if ($key !== $index) {
            return false;
        }

        $index++;
    }

    return true;
}



/*
 * Normalize nullable value.
 *
 * Converts mixed nullable flags into bool.
 */

function normalizeNullableValue(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value) || is_float($value)) {
        return (int)$value !== 0;
    }

    $text = strtoupper(trim((string)$value));
    return in_array($text, ['1', 'Y', 'YES', 'TRUE'], true);
}



/*
 * Normalize boolean value.
 *
 * Converts mixed booleans into strict bool.
 */

function normalizeBooleanValue(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value) || is_float($value)) {
        return (int)$value !== 0;
    }

    $text = strtolower(trim((string)$value));
    return in_array($text, ['1', 'y', 'yes', 'true', 'on'], true);
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
 * Check CLI flag.
 *
 * Returns true when one exact option flag exists in argv.
 */

function hasFlag(array $argv, string $flag): bool
{
    foreach ($argv as $arg) {
        if ($arg === $flag) {
            return true;
        }
    }

    return false;
}



/*
 * Resolve app path.
 *
 * Resolves app path from CLI option or current project defaults.
 */

function resolveAppPath(array $argv, string $rootPath): string
{
    $fromArg = resolveOption($argv, '--app');
    if (is_string($fromArg) && $fromArg !== '') {
        return rtrim($fromArg, '/');
    }

    return rtrim($rootPath . '/app', '/');
}



/*
 * Load database config interactively.
 *
 * Prompts DB settings and stores the resulting connection in Registry.
 */

function loadDatabaseConfigInteractive(string $appPath, string $rootPath, string $connectionName): string
{
    $config = readDatabaseConfigFile($appPath);
    $merged = mergeSecretsIntoDbConfig($config, $rootPath);
    $current = resolveConnectionConfig($merged, $connectionName);
    $secrets = readSecretsDbFallback($rootPath);

    $defaults = $current;
    foreach (['host', 'user', 'pass', 'name', 'port', 'socket', 'charset'] as $key) {
        if (hasDbValue($secrets, $key)) {
            $defaults[$key] = $secrets[$key];
        }
    }

    fwrite(STDOUT, "Interactive mode enabled.\n");
    fwrite(STDOUT, "Press ENTER to use the value shown in brackets.\n\n");

    $connectionName = promptDatabaseValue('Connection name', $connectionName);
    if ($connectionName === '') {
        $connectionName = 'default';
    }

    $connection = [
        'host' => promptDatabaseValue('Host', (string)($defaults['host'] ?? '')),
        'user' => promptDatabaseValue('User', (string)($defaults['user'] ?? '')),
        'pass' => promptDatabaseValue('Pass', (string)($defaults['pass'] ?? '')),
        'name' => promptDatabaseValue('Database', (string)($defaults['name'] ?? '')),
        'port' => promptDatabaseValue('Port', (string)($defaults['port'] ?? '3306')),
        'socket' => promptDatabaseValue('Socket', (string)($defaults['socket'] ?? '')),
        'charset' => promptDatabaseValue('Charset', (string)($defaults['charset'] ?? 'utf8mb4')),
    ];

    $connection = normalizeDbConnection($connection);
    Registry::set('/cfg/db', [$connectionName => $connection]);

    fwrite(STDOUT, "\nUsing connection '{$connectionName}'.\n");
    return $connectionName;
}



/*
 * Prompt DB value.
 *
 * Prompts one DB field and falls back to the provided default on ENTER.
 */

function promptDatabaseValue(string $label, string $default): string
{
    fwrite(STDOUT, $label . ' [' . $default . ']: ');
    $line = fgets(STDIN);
    if ($line === false) {
        return $default;
    }

    $line = rtrim($line, "\r\n");
    return $line === '' ? $default : $line;
}



/*
 * Read database config file.
 *
 * Reads `app/config/database.php` and returns it as array when valid.
 */

function readDatabaseConfigFile(string $appPath): array
{
    $file = $appPath . '/config/database.php';
    if (!is_file($file)) {
        return [];
    }

    $loaded = require $file;
    return is_array($loaded) ? $loaded : [];
}



/*
 * Resolve named connection.
 *
 * Returns one connection array from mixed DB config structures.
 */

function resolveConnectionConfig(array $cfg, string $connectionName): array
{
    if ($cfg === []) {
        return [];
    }

    if (isSingleDbConfig($cfg)) {
        return normalizeDbConnection($cfg);
    }

    if (isset($cfg[$connectionName]) && is_array($cfg[$connectionName])) {
        return normalizeDbConnection($cfg[$connectionName]);
    }

    foreach ($cfg as $connection) {
        if (is_array($connection)) {
            return normalizeDbConnection($connection);
        }
    }

    return [];
}



/*
 * Resolve SSH config interactively.
 *
 * Prompts SSH values and returns one validated SSH tunnel config.
 */

function resolveSshConfigInteractive(array $argv, string $rootPath, string $connectionName): array
{
    $defaults = resolveSshDefaults($argv, $rootPath, $connectionName);

    fwrite(STDOUT, "\nSSH mode enabled.\n");
    fwrite(STDOUT, "Press ENTER to use the value shown in brackets.\n\n");

    $ssh = [
        'host' => promptDatabaseValue('SSH host', (string)($defaults['host'] ?? '')),
        'user' => promptDatabaseValue('SSH user', (string)($defaults['user'] ?? '')),
        'port' => promptDatabaseValue('SSH port', (string)($defaults['port'] ?? '22')),
        'key' => promptDatabaseValue('SSH key path', (string)($defaults['key'] ?? '')),
        'remote_db_host' => promptDatabaseValue('Remote DB host', (string)($defaults['remote_db_host'] ?? '127.0.0.1')),
        'remote_db_port' => promptDatabaseValue('Remote DB port', (string)($defaults['remote_db_port'] ?? '3306')),
        'local_port' => promptDatabaseValue('Local forwarded port', (string)($defaults['local_port'] ?? '13306')),
    ];

    return ensureSshConfig($ssh);
}



/*
 * Resolve SSH config.
 *
 * Builds SSH config from CLI options and secrets defaults.
 */

function resolveSshConfig(array $argv, string $rootPath, string $connectionName): array
{
    $defaults = resolveSshDefaults($argv, $rootPath, $connectionName);

    $ssh = [
        'host' => (string)resolveOption($argv, '--ssh-host', (string)($defaults['host'] ?? '')),
        'user' => (string)resolveOption($argv, '--ssh-user', (string)($defaults['user'] ?? '')),
        'port' => (string)resolveOption($argv, '--ssh-port', (string)($defaults['port'] ?? '22')),
        'key' => (string)resolveOption($argv, '--ssh-key', (string)($defaults['key'] ?? '')),
        'remote_db_host' => (string)resolveOption($argv, '--ssh-db-host', (string)($defaults['remote_db_host'] ?? '127.0.0.1')),
        'remote_db_port' => (string)resolveOption($argv, '--ssh-db-port', (string)($defaults['remote_db_port'] ?? '3306')),
        'local_port' => (string)resolveOption($argv, '--ssh-local-port', (string)($defaults['local_port'] ?? '13306')),
    ];

    return ensureSshConfig($ssh);
}



/*
 * Resolve SSH defaults.
 *
 * Resolves SSH defaults from secrets and the selected DB connection.
 */

function resolveSshDefaults(array $argv, string $rootPath, string $connectionName): array
{
    $secrets = readSecretsSshFallback($rootPath);
    $cfg = Registry::get('/cfg/db', []);
    $connection = is_array($cfg) ? resolveConnectionConfig($cfg, $connectionName) : [];

    $remoteDbHost = (string)($secrets['remote_db_host'] ?? ($connection['host'] ?? '127.0.0.1'));
    $remoteDbPort = (int)($secrets['remote_db_port'] ?? ($connection['port'] ?? 3306));
    if ($remoteDbPort <= 0) {
        $remoteDbPort = 3306;
    }

    $localPort = (int)($secrets['local_port'] ?? 13306);
    if ($localPort <= 0) {
        $localPort = 13306;
    }

    return [
        'host' => (string)($secrets['host'] ?? ''),
        'user' => (string)($secrets['user'] ?? ''),
        'port' => (int)($secrets['port'] ?? 22),
        'key' => (string)($secrets['key'] ?? ''),
        'remote_db_host' => $remoteDbHost,
        'remote_db_port' => $remoteDbPort,
        'local_port' => $localPort,
    ];
}



/*
 * Ensure SSH config.
 *
 * Validates and normalizes one SSH configuration payload.
 */

function ensureSshConfig(array $ssh): array
{
    $ssh['host'] = trim((string)($ssh['host'] ?? ''));
    $ssh['user'] = trim((string)($ssh['user'] ?? ''));
    $ssh['key'] = trim((string)($ssh['key'] ?? ''));
    $ssh['remote_db_host'] = trim((string)($ssh['remote_db_host'] ?? '127.0.0.1'));
    $ssh['port'] = (int)($ssh['port'] ?? 22);
    $ssh['remote_db_port'] = (int)($ssh['remote_db_port'] ?? 3306);
    $ssh['local_port'] = (int)($ssh['local_port'] ?? 13306);

    if ($ssh['host'] === '') {
        throw new RuntimeException('Missing SSH host. Set SSH_HOST in PRIVATE/secrets.php or pass --ssh-host=...');
    }

    if ($ssh['user'] === '') {
        throw new RuntimeException('Missing SSH user. Set SSH_USER in PRIVATE/secrets.php or pass --ssh-user=...');
    }

    if ($ssh['port'] <= 0) {
        $ssh['port'] = 22;
    }

    if ($ssh['remote_db_host'] === '') {
        $ssh['remote_db_host'] = '127.0.0.1';
    }

    if ($ssh['remote_db_port'] <= 0) {
        $ssh['remote_db_port'] = 3306;
    }

    if ($ssh['local_port'] <= 0) {
        $ssh['local_port'] = 13306;
    }

    return $ssh;
}



/*
 * Apply SSH tunnel to DB config.
 *
 * Rewrites selected DB connection host/port so mysqli uses the tunnel.
 */

function applySshTunnelToDbConfig(string $connectionName, int $localPort): void
{
    $cfg = Registry::get('/cfg/db', []);
    if (!is_array($cfg) || $cfg === []) {
        throw new RuntimeException('Cannot apply SSH tunnel: DB config not loaded.');
    }

    $connection = resolveConnectionConfig($cfg, $connectionName);
    if ($connection === []) {
        throw new RuntimeException('Cannot apply SSH tunnel: DB connection not found.');
    }

    $connection['host'] = '127.0.0.1';
    $connection['port'] = $localPort;
    $connection['socket'] = null;

    Registry::set('/cfg/db', [$connectionName => normalizeDbConnection($connection)]);
}



/*
 * Start SSH tunnel.
 *
 * Opens a local SSH forwarding tunnel and returns runtime metadata.
 */

function startSshTunnel(array $ssh): array
{
    $controlPath = sys_get_temp_dir() . '/kalix_ssh_' . (string)random_int(100000, 999999) . '.sock';
    $target = (string)$ssh['user'] . '@' . (string)$ssh['host'];

    $cmd = [
        'ssh',
        '-o',
        'ExitOnForwardFailure=yes',
        '-o',
        'ControlMaster=yes',
        '-o',
        'ControlPersist=yes',
        '-o',
        'ControlPath=' . $controlPath,
        '-o',
        'ServerAliveInterval=30',
        '-o',
        'ServerAliveCountMax=3',
        '-fN',
        '-L',
        '127.0.0.1:' . (int)$ssh['local_port'] . ':' . (string)$ssh['remote_db_host'] . ':' . (int)$ssh['remote_db_port'],
        '-p',
        (string)(int)$ssh['port'],
    ];

    if ((string)$ssh['key'] !== '') {
        $cmd[] = '-i';
        $cmd[] = (string)$ssh['key'];
    }

    $cmd[] = $target;

    $exitCode = runCommand($cmd);
    if ($exitCode !== 0) {
        throw new RuntimeException('SSH connection failed. Please verify SSH settings and credentials.');
    }

    return [
        'control_path' => $controlPath,
        'target' => $target,
        'port' => (int)$ssh['port'],
    ];
}



/*
 * Close SSH tunnel.
 *
 * Closes a previously opened SSH control master tunnel.
 */

function closeSshTunnel(array $runtime): void
{
    $cmd = [
        'ssh',
        '-S',
        (string)$runtime['control_path'],
        '-O',
        'exit',
        '-p',
        (string)(int)$runtime['port'],
        (string)$runtime['target'],
    ];

    runCommand($cmd);
    @unlink((string)$runtime['control_path']);
}



/*
 * Run command.
 *
 * Runs a shell command array and returns exit code plus combined output.
 */

function runCommandWithOutput(array $parts): array
{
    $escaped = array_map(static fn (string $part): string => escapeshellarg($part), $parts);
    $command = implode(' ', $escaped);

    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);

    return [$exitCode, implode("\n", $output)];
}



/*
 * Run command.
 *
 * Runs a shell command array and returns only its exit code.
 */

function runCommand(array $parts): int
{
    [$exitCode] = runCommandWithOutput($parts);
    return $exitCode;
}



/*
 * Load database config.
 *
 * Loads app database configuration and stores it in Registry.
 */

function loadDatabaseConfig(string $appPath): void
{
    $cfg = readDatabaseConfigFile($appPath);

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
 * Read SSH secrets fallback.
 *
 * Loads SSH fallback values from constants in `PRIVATE/secrets.php`.
 */

function readSecretsSshFallback(string $rootPath): array
{
    $secretsFile = rtrim($rootPath, '/') . '/PRIVATE/secrets.php';
    if (!is_file($secretsFile)) {
        return [];
    }

    require_once $secretsFile;

    $map = [
        'host' => 'SSH_HOST',
        'user' => 'SSH_USER',
        'port' => 'SSH_PORT',
        'key' => 'SSH_KEY',
        'remote_db_host' => 'SSH_DB_HOST',
        'remote_db_port' => 'SSH_DB_PORT',
        'local_port' => 'SSH_LOCAL_PORT',
    ];

    $fallback = [];
    foreach ($map as $key => $constant) {
        if (!defined($constant)) {
            continue;
        }

        $fallback[$key] = constant($constant);
    }

    if (!isset($fallback['remote_db_host']) && defined('DB_HOST')) {
        $fallback['remote_db_host'] = (string)constant('DB_HOST');
    }

    if (!isset($fallback['remote_db_port']) && defined('DB_PORT')) {
        $fallback['remote_db_port'] = (int)constant('DB_PORT');
    }

    return $fallback;
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
        " * Auto-generated from schema.json by Kalix MakeMappers.php.\n" .
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
