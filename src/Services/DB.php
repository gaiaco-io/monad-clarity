<?php

namespace Gaia\Clarity\Services;

use PDO;
use PDOException;
use PDOStatement;
use Ramsey\Uuid\Uuid;

/**
 * Database abstraction layer with support for multi-database contexts.
 * Supports both single DB (backward compatible) and multi-DB (context-aware) applications.
 *
 * Contexts supported:
 * - 'app' or null: App-specific database (default, backward compatible)
 * - 'kerberos' or 'session': Kerberos database (for sessions, auth)
 * - 'shared': Shared Core database (for CRM data)
 * - 'subscription': Subscription database (for plan entitlements)
 *
 * @package Gaia\Clarity\Services
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */

abstract class DB
{
    // Connection pool for multi-database support
    private static array $connections = [];
    private static ?PDO $pdo = null; // Default connection for backward compatibility
    private static ?PDOStatement $stmt = null;
    private static string $last_insert_id = '';
    private static int $row_count = 0;
    private static ?string $last_error = null;
    private static ?string $current_context = null;

    const ID_TYPE_INT = 1;
    const ID_TYPE_UUID = 2;

    private static array $db_options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    /**
     * Private clone method prevents cloning of the instance.
     */
    private function __clone() {}

    /**
     * Connect to database. Supports both single DB (backward compatible) and multi-DB (context-aware).
     * 
     * If context is provided, uses context-aware connection pool.
     * If no context, uses default connection (backward compatible).
     *
     * @param array $options Optional PDO options.
     * @param string|null $context Database context ('app', 'kerberos', 'session', 'shared', 'subscription')
     * @return PDO The PDO connection instance.
     */
    public static function connect(array $options = [PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY], ?string $context = null): PDO
    {
        if (!empty($options)) {
            self::$db_options = array_merge(self::$db_options, $options);
        }

        // Normalize context
        if ($context === 'session') {
            $context = 'kerberos';
        }
        if ($context === null || $context === 'app') {
            $context = 'default';
        }

        // Use context-aware connection if context is specified
        if ($context !== 'default') {
            if (!isset(self::$connections[$context])) {
                try {
                    $config = self::getDBConfig($context);
                    $dsn = self::buildDsnFromConfig($config);
                    self::$connections[$context] = new PDO(
                        $dsn,
                        $config['username'],
                        $config['password'],
                        self::$db_options
                    );
                } catch (PDOException $e) {
                    self::handlePdoException($e);
                    throw $e;
                }
            }
            return self::$connections[$context];
        }

        // Default connection for backward compatibility
        if (self::$pdo === null) {
            try {
                $dsn = self::buildDsn();
                self::$pdo = new PDO(
                    $dsn,
                    DB['username'],
                    DB['password'],
                    self::$db_options
                );
            } catch (PDOException $e) {
                self::handlePdoException($e);
                throw $e;
            }
        }

        return self::$pdo;
    }

    /**
     * Explicitly disconnect from the database.
     * 
     * @param string|null $context Database context to disconnect. If null, disconnects default connection.
     * @return bool True if the disconnection was successful, false otherwise.
     */
    public static function disconnect(?string $context = null): bool
    {
        try {
            if ($context === null || $context === 'app' || $context === 'default') {
                if (self::$pdo !== null) {
                    unset(self::$pdo);
                    self::$pdo = null;
                }
            } else {
                if ($context === 'session') {
                    $context = 'kerberos';
                }
                if (isset(self::$connections[$context])) {
                    unset(self::$connections[$context]);
                }
            }

            return true;
        } catch (PDOException $e) {
            \Gaia\Clarity\Services\Mediator::handleException($e);
            return false;
        }
    }

    /**
     * Begin a transaction
     */
    public function begin(): bool
    {
        try {
            self::$pdo->beginTransaction();
            return true;
        } catch (PDOException $e) {
            \Gaia\Clarity\Services\Mediator::handleException($e);
            return false;
        }
    }

    /**
     * Commit a transaction
     */
    public function commit(): bool
    {
        try {
            self::$pdo->commit();
            return true;
        } catch (PDOException $e) {
            \Gaia\Clarity\Services\Mediator::handleException($e);
            return false;
        }
    }

    /**
     * Roll back a transaction
     */
    public function rollBack(): bool
    {
        try {
            self::$pdo->rollBack();
            return true;
        } catch (PDOException $e) {
            \Gaia\Clarity\Services\Mediator::handleException($e);
            return false;
        }
    }

    /**
     * Validate table name to prevent SQL injection
     *
     * @param string $table The table name to validate.
     * @return void
     * @throws \InvalidArgumentException If table name is invalid.
     */
    private static function validateTableName(string $table): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException("Invalid table name: {$table}");
        }
    }

    /**
     * Determine if stack traces should be displayed (development mode)
     */
    private static function isDebugMode(): bool
    {
        $env = defined('ENV_MODE') ? ENV_MODE : (getenv('ENV_MODE') ?: '');
        return $env === '0' || $env === 'development';
    }

    /**
     * Log database errors to the error log directory
     */
    private static function logError(string $message): void
    {
        $log_dir = defined('PATH') && isset(PATH['error_log'])
            ? PATH['error_log']
            : dirname(__DIR__, 2) . '/storage/logs/error/';

        if (!is_dir($log_dir)) {
            @mkdir($log_dir, 0775, true);
        }

        $log_file = rtrim($log_dir, '/') . '/db.log';
        $timestamp = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        @file_put_contents($log_file, "[{$timestamp}] {$message}\n", FILE_APPEND);
    }

    /**
     * Centralized PDO exception handler
     */
    private static function handlePdoException(PDOException $e): void
    {
        self::$stmt = null;
        self::$last_error = $e->getMessage();
        self::logError($e->getMessage());

        if (self::isDebugMode()) {
            Mediator::handleException($e);
        }
    }

    /**
     * Insert statement facade
     *
     * @param string $table The table name.
     * @param array $data The data to insert.
     * @param int $id_type The ID type.
     * @param string|null $context Database context for multi-DB support.
     * @return string|false The inserted ID or false on failure.
     */
    public static function insert(string $table, array $data, int $id_type = self::ID_TYPE_UUID, ?string $context = null): string|false
    {
        self::validateTableName($table);

        if ($id_type === self::ID_TYPE_UUID) {
            $data['id'] = Uuid::uuid4()->toString();
        }

        if (!self::run(
            '
            INSERT INTO ' . $table . ' (' . implode(', ', array_keys($data)) . ')
            VALUES (' . implode(', ', array_fill(0, count($data), '?')) . ')',
            array_values($data),
            $context
        )) {
            return false;
        }

        $pdo = self::connect([], $context);
        if ($id_type == self::ID_TYPE_INT) {
            self::$last_insert_id = $pdo->lastInsertId();
        } else {
            self::$last_insert_id = $data['id'];
        }

        return self::$last_insert_id;
    }

    /**
     * Build WHERE clause from array conditions with support for advanced operators.
     *
     * Supported formats:
     * - Simple equality: ['id' => '123'] → WHERE id = ?
     * - Operators: ['age' => ['>', 18]] → WHERE age > ?
     * - IN: ['id' => ['IN', [1, 2, 3]]] → WHERE id IN (?, ?, ?)
     * - NOT IN: ['id' => ['NOT IN', [1, 2, 3]]] → WHERE id NOT IN (?, ?, ?)
     * - LIKE: ['name' => ['LIKE', '%value%']] → WHERE name LIKE ?
     * - BETWEEN: ['age' => ['BETWEEN', [18, 65]]] → WHERE age BETWEEN ? AND ?
     * - IS NULL: ['deleted' => ['IS', 'NULL']] → WHERE deleted IS NULL
     * - IS NOT NULL: ['deleted' => ['IS NOT', 'NULL']] → WHERE deleted IS NOT NULL
     * - Multiple conditions: ['id' => '123', 'status' => 'active'] → WHERE id = ? AND status = ?
     *
     * @param array $where Array of WHERE conditions.
     * @return array Returns ['clause' => string, 'params' => array]
     */
    private static function buildWhereClause(array $where): array
    {
        $conditions = [];
        $params = [];

        foreach ($where as $column => $value) {
            // Validate column name to prevent SQL injection
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
                throw new \InvalidArgumentException("Invalid column name: {$column}");
            }

            // Handle operator-based conditions
            if (is_array($value) && count($value) >= 2) {
                $operator = strtoupper(trim($value[0]));
                $operatorValue = $value[1];

                switch ($operator) {
                    case 'IN':
                        if (!is_array($operatorValue) || empty($operatorValue)) {
                            throw new \InvalidArgumentException("IN operator requires a non-empty array");
                        }
                        $placeholders = implode(', ', array_fill(0, count($operatorValue), '?'));
                        $conditions[] = "{$column} IN ({$placeholders})";
                        $params = array_merge($params, $operatorValue);
                        break;

                    case 'NOT IN':
                        if (!is_array($operatorValue) || empty($operatorValue)) {
                            throw new \InvalidArgumentException("NOT IN operator requires a non-empty array");
                        }
                        $placeholders = implode(', ', array_fill(0, count($operatorValue), '?'));
                        $conditions[] = "{$column} NOT IN ({$placeholders})";
                        $params = array_merge($params, $operatorValue);
                        break;

                    case 'BETWEEN':
                        if (!is_array($operatorValue) || count($operatorValue) !== 2) {
                            throw new \InvalidArgumentException("BETWEEN operator requires an array with exactly 2 values");
                        }
                        $conditions[] = "{$column} BETWEEN ? AND ?";
                        $params = array_merge($params, $operatorValue);
                        break;

                    case 'IS':
                        if (strtoupper($operatorValue) === 'NULL') {
                            $conditions[] = "{$column} IS NULL";
                        } else {
                            throw new \InvalidArgumentException("IS operator only supports NULL value");
                        }
                        break;

                    case 'IS NOT':
                        if (strtoupper($operatorValue) === 'NULL') {
                            $conditions[] = "{$column} IS NOT NULL";
                        } else {
                            throw new \InvalidArgumentException("IS NOT operator only supports NULL value");
                        }
                        break;

                    case 'LIKE':
                        $conditions[] = "{$column} LIKE ?";
                        $params[] = $operatorValue;
                        break;

                    case '>':
                    case '<':
                    case '>=':
                    case '<=':
                    case '!=':
                    case '<>':
                        $conditions[] = "{$column} {$operator} ?";
                        $params[] = $operatorValue;
                        break;

                    default:
                        throw new \InvalidArgumentException("Unsupported operator: {$operator}");
                }
            } else {
                // Simple equality
                $conditions[] = "{$column} = ?";
                $params[] = $value;
            }
        }

        return [
            'clause' => !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '',
            'params' => $params
        ];
    }

    /**
     * Update statement facade
     *
     * @param string $table The table name.
     * @param array $data The data to update.
     * @param array|null $where Array of WHERE conditions. See buildWhereClause() for supported formats.
     * @param string|null $context Database context for multi-DB support.
     * @return bool True on success, false on failure.
     */
    public static function update(string $table, array $data, ?array $where = null, ?string $context = null): bool
    {
        self::validateTableName($table);
        $set = [];

        foreach ($data as $key => $value) {
            // Validate column name to prevent SQL injection
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) {
                throw new \InvalidArgumentException("Invalid column name: {$key}");
            }
            $set[] = $key . ' = ?';
        }

        $whereClause = '';
        $whereParams = [];

        if ($where !== null && !empty($where)) {
            $whereResult = self::buildWhereClause($where);
            $whereClause = $whereResult['clause'];
            $whereParams = $whereResult['params'];
        }

        if (!self::run(
            '
            UPDATE ' . $table . '
            SET ' . implode(', ', $set) . '
            ' . $whereClause . ';',
            array_merge(array_values($data), $whereParams),
            $context
        )) {
            return false;
        }

        return true;
    }

    /**
     * Delete statement facade
     *
     * @param string $table The table name.
     * @param array|null $where Array of WHERE conditions. See buildWhereClause() for supported formats.
     * @param string|null $context Database context for multi-DB support.
     * @return bool True on success, false on failure.
     */
    public static function delete(string $table, ?array $where = null, ?string $context = null): bool
    {
        self::validateTableName($table);
        $whereClause = '';
        $whereParams = [];

        if ($where !== null && !empty($where)) {
            $whereResult = self::buildWhereClause($where);
            $whereClause = $whereResult['clause'];
            $whereParams = $whereResult['params'];
        }

        if (!self::run(
            'DELETE FROM ' . $table . '
            ' . $whereClause . ';',
            $whereParams,
            $context
        )) {
            return false;
        }

        return true;
    }

    /**
     * Execute a query and return the PDOStatement object.
     * Supports context-aware database selection for multi-DB applications.
     *
     * @param string $query The SQL query to execute.
     * @param array $params The parameters to bind to the query.
     * @param string|null $context Database context ('app', 'kerberos', 'session', 'shared', 'subscription'). 
     *                             If null, uses default connection (backward compatible).
     * @return PDOStatement|null The result set or null on failure.
     */
    public static function run(string $query, array $params = [], ?string $context = null): PDOStatement|null
    {
        try {
            $pdo = self::connect([], $context);
            self::$current_context = $context;

            self::$stmt = $pdo->prepare($query);
            self::$stmt->execute($params);
            self::$row_count = self::$stmt->rowCount();
            return self::$stmt;
        } catch (PDOException $e) {
            self::handlePdoException($e);
            return null;
        }
    }

    /**
     * Fetch a row from the result set.
     *
     * @param int $fetch_mode The fetch mode to use.
     * @return array Empty array if no query executed or no rows available, otherwise the fetched row.
     */
    public static function fetch(int $fetch_mode = PDO::FETCH_ASSOC): array
    {
        if (self::$stmt === null) {
            return [];
        }

        try {
            $result = self::$stmt->fetch($fetch_mode);
            return $result !== false ? $result : [];
        } catch (PDOException $e) {
            self::handlePdoException($e);
            return [];
        }
    }

    /**
     * Fetch all rows from the result set.
     *
     * @param int $fetch_mode The fetch mode to use.
     * @return array Empty array if no query executed or no rows available, otherwise all fetched rows.
     */
    public static function fetchAll(int $fetch_mode = PDO::FETCH_ASSOC): array
    {
        if (self::$stmt === null) {
            return [];
        }

        try {
            $result = self::$stmt->fetchAll($fetch_mode);
            return $result !== false ? $result : [];
        } catch (PDOException $e) {
            self::handlePdoException($e);
            return [];
        }
    }

    /**
     * Get the last inserted ID.
     *
     * @return string The last inserted ID.
     */
    public function getLastInsertId(): string
    {
        return self::$last_insert_id;
    }

    /**
     * Get the number of rows affected by the last query.
     *
     * @return int The number of rows affected.
     */
    public function getRowCount(): int
    {
        return self::$row_count;
    }

    /**
     * Get the last database error message (if any).
     */
    public static function getLastError(): ?string
    {
        return self::$last_error;
    }

    /**
     * Build DSN with optional port + charset (backward compatible, uses DB constant)
     */
    private static function buildDsn(): string
    {
        $driver = DB['driver'] ?? 'mysql';
        $host = DB['host'] ?? '127.0.0.1';
        $port = DB['port'] ?? '';
        $database = DB['database'] ?? '';
        $charset = DB['charset'] ?? 'utf8mb4';

        if ($database === '') {
            throw new \InvalidArgumentException('Database name is not configured. Set DB_DATABASE in .env.');
        }

        return self::buildDsnFromConfig([
            'driver' => $driver,
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'charset' => $charset
        ]);
    }

    /**
     * Build DSN from configuration array
     */
    private static function buildDsnFromConfig(array $config): string
    {
        $driver = $config['driver'] ?? 'mysql';
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? '';
        $database = $config['database'] ?? '';
        $charset = $config['charset'] ?? 'utf8mb4';

        if ($database === '') {
            throw new \InvalidArgumentException('Database name is not configured in database config.');
        }

        $segments = [
            "{$driver}:host={$host}"
        ];

        if (!empty($port)) {
            $segments[] = "port={$port}";
        }

        $segments[] = "dbname={$database}";

        if (!empty($charset) && strtolower($driver) === 'mysql') {
            $segments[] = "charset={$charset}";
        }

        return implode(';', $segments);
    }

    /**
     * Get database configuration for a specific context.
     * Uses getDBConfig() function from config/database.php
     *
     * @param string $context Database context
     * @return array Database configuration array
     */
    private static function getDBConfig(string $context): array
    {
        if (function_exists('getDBConfig')) {
            return getDBConfig($context);
        }

        // Fallback if getDBConfig is not available
        throw new \RuntimeException('getDBConfig() function not found. Ensure config/database.php is loaded.');
    }
}
