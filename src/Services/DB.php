<?php

declare(strict_types=1);

namespace Gaia\Clarity\Services;

use InvalidArgumentException;
use PDO;
use PDOStatement;
use Ramsey\Uuid\Uuid;

/**
 * PDO-based query and connection layer, with named-context support for applications
 * that talk to more than one database. Dialect-specific DDL (CREATE/ALTER TABLE
 * differences between MySQL/PostgreSQL/SQLite) belongs to Services\Schema, not here —
 * DB only ever runs parameterised SQL a caller (or Schema/Migration) hands it.
 *
 * PDO is configured with ERRMODE_EXCEPTION, and DB does not fight that: PDOException
 * propagates rather than being caught and turned into a bool/null return. A caller that
 * wants to handle a specific failure catches PDOException itself; anything uncaught
 * reaches Mediator's global exception handler. DB has no dependency on Mediator.
 *
 * @package Gaia\Clarity\Services
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
abstract class DB
{
    public const ID_TYPE_INT = 1;
    public const ID_TYPE_UUID = 2;

    private const DEFAULT_CONTEXT = 'default';

    private const BASE_OPTIONS = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    /** @var array<string, array{dsn: string, username: ?string, password: ?string, options: array<int, mixed>}> */
    private static array $configs = [];

    /** @var array<string, PDO> */
    private static array $connections = [];

    private static ?PDOStatement $lastStatement = null;
    private static string $lastInsertId = '';
    private static int $lastRowCount = 0;

    /**
     * Register connection configuration for a named context. The "default" context
     * (or omitting $context entirely elsewhere) is used when no context is specified.
     *
     * @param array{dsn: string, username?: ?string, password?: ?string, options?: array<int, mixed>} $config
     */
    public static function configure(string $context, array $config): void
    {
        self::$configs[$context] = [
            'dsn' => $config['dsn'],
            'username' => $config['username'] ?? null,
            'password' => $config['password'] ?? null,
            'options' => $config['options'] ?? [],
        ];
    }

    /**
     * Register an already-open PDO connection under a context directly — for tests
     * (in-memory SQLite) or an application that constructs its own PDO instance.
     */
    public static function useConnection(PDO $pdo, string $context = self::DEFAULT_CONTEXT): void
    {
        self::$connections[$context] = $pdo;
    }

    public static function connect(?string $context = null): PDO
    {
        $context ??= self::DEFAULT_CONTEXT;

        if (isset(self::$connections[$context])) {
            return self::$connections[$context];
        }

        if (!isset(self::$configs[$context])) {
            throw new InvalidArgumentException(
                sprintf('No database configuration registered for context "%s".', $context)
            );
        }

        $config = self::$configs[$context];

        return self::$connections[$context] = new PDO(
            $config['dsn'],
            $config['username'],
            $config['password'],
            [...self::BASE_OPTIONS, ...$config['options']]
        );
    }

    public static function disconnect(?string $context = null): void
    {
        unset(self::$connections[$context ?? self::DEFAULT_CONTEXT]);
    }

    public static function beginTransaction(?string $context = null): bool
    {
        return self::connect($context)->beginTransaction();
    }

    public static function commit(?string $context = null): bool
    {
        return self::connect($context)->commit();
    }

    public static function rollBack(?string $context = null): bool
    {
        return self::connect($context)->rollBack();
    }

    /**
     * @param list<mixed> $params
     */
    public static function run(string $query, array $params = [], ?string $context = null): PDOStatement
    {
        $statement = self::connect($context)->prepare($query);
        $statement->execute($params);

        self::$lastStatement = $statement;
        self::$lastRowCount = $statement->rowCount();

        return $statement;
    }

    public static function fetch(int $fetchMode = PDO::FETCH_ASSOC): array
    {
        if (self::$lastStatement === null) {
            return [];
        }

        $row = self::$lastStatement->fetch($fetchMode);

        return $row === false ? [] : $row;
    }

    public static function fetchAll(int $fetchMode = PDO::FETCH_ASSOC): array
    {
        return self::$lastStatement?->fetchAll($fetchMode) ?? [];
    }

    /**
     * @param array<string, mixed> $data
     * @return string The inserted row's ID (generated UUID, or the driver's lastInsertId).
     */
    public static function insert(string $table, array $data, int $idType = self::ID_TYPE_UUID, ?string $context = null): string
    {
        self::assertIdentifier($table);

        if ($idType === self::ID_TYPE_UUID && !array_key_exists('id', $data)) {
            $data['id'] = Uuid::uuid4()->toString();
        }

        foreach (array_keys($data) as $column) {
            self::assertIdentifier((string) $column);
        }

        self::run(
            sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $table,
                implode(', ', array_keys($data)),
                implode(', ', array_fill(0, count($data), '?'))
            ),
            array_values($data),
            $context
        );

        self::$lastInsertId = $idType === self::ID_TYPE_INT
            ? self::connect($context)->lastInsertId()
            : (string) $data['id'];

        return self::$lastInsertId;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where See buildWhereClause() for supported operator formats.
     * @return int Number of rows updated.
     */
    public static function update(string $table, array $data, array $where = [], ?string $context = null): int
    {
        self::assertIdentifier($table);

        $set = [];

        foreach (array_keys($data) as $column) {
            self::assertIdentifier((string) $column);
            $set[] = "{$column} = ?";
        }

        $whereBuilt = self::buildWhereClause($where);

        $statement = self::run(
            sprintf('UPDATE %s SET %s %s', $table, implode(', ', $set), $whereBuilt['clause']),
            [...array_values($data), ...$whereBuilt['params']],
            $context
        );

        return $statement->rowCount();
    }

    /**
     * @param array<string, mixed> $where See buildWhereClause() for supported operator formats.
     * @return int Number of rows deleted.
     */
    public static function delete(string $table, array $where = [], ?string $context = null): int
    {
        self::assertIdentifier($table);

        $whereBuilt = self::buildWhereClause($where);

        $statement = self::run(
            sprintf('DELETE FROM %s %s', $table, $whereBuilt['clause']),
            $whereBuilt['params'],
            $context
        );

        return $statement->rowCount();
    }

    public static function lastInsertId(): string
    {
        return self::$lastInsertId;
    }

    public static function rowCount(): int
    {
        return self::$lastRowCount;
    }

    /**
     * Build a WHERE clause from array conditions with support for common operators.
     *
     * Supported formats:
     * - Simple equality: ['id' => '123'] -> WHERE id = ?
     * - Operators: ['age' => ['>', 18]] -> WHERE age > ?
     * - IN / NOT IN: ['id' => ['IN', [1, 2, 3]]]
     * - LIKE: ['name' => ['LIKE', '%value%']]
     * - BETWEEN: ['age' => ['BETWEEN', [18, 65]]]
     * - IS NULL / IS NOT NULL: ['deleted_at' => ['IS', 'NULL']]
     *
     * @param array<string, mixed> $where
     * @return array{clause: string, params: list<mixed>}
     */
    private static function buildWhereClause(array $where): array
    {
        $conditions = [];
        $params = [];

        foreach ($where as $column => $value) {
            self::assertIdentifier((string) $column);

            if (!is_array($value) || count($value) < 2) {
                $conditions[] = "{$column} = ?";
                $params[] = $value;

                continue;
            }

            $operator = strtoupper(trim((string) $value[0]));
            $operatorValue = $value[1];

            switch ($operator) {
                case 'IN':
                case 'NOT IN':
                    self::appendInClause($conditions, $params, $column, $operator, $operatorValue);
                    break;
                case 'BETWEEN':
                    self::appendBetweenClause($conditions, $params, $column, $operatorValue);
                    break;
                case 'IS':
                    self::appendIsNullClause($conditions, $column, $operatorValue, not: false);
                    break;
                case 'IS NOT':
                    self::appendIsNullClause($conditions, $column, $operatorValue, not: true);
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
                    throw new InvalidArgumentException("Unsupported operator: {$operator}");
            }
        }

        return [
            'clause' => $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '',
            'params' => $params,
        ];
    }

    /**
     * @param list<string> $conditions
     * @param list<mixed> $params
     */
    private static function appendInClause(array &$conditions, array &$params, string $column, string $operator, mixed $value): void
    {
        if (!is_array($value) || $value === []) {
            throw new InvalidArgumentException("{$operator} operator requires a non-empty array");
        }

        $placeholders = implode(', ', array_fill(0, count($value), '?'));
        $conditions[] = "{$column} {$operator} ({$placeholders})";
        array_push($params, ...array_values($value));
    }

    /**
     * @param list<string> $conditions
     * @param list<mixed> $params
     */
    private static function appendBetweenClause(array &$conditions, array &$params, string $column, mixed $value): void
    {
        if (!is_array($value) || count($value) !== 2) {
            throw new InvalidArgumentException('BETWEEN operator requires an array with exactly 2 values');
        }

        $conditions[] = "{$column} BETWEEN ? AND ?";
        array_push($params, ...array_values($value));
    }

    /**
     * @param list<string> $conditions
     */
    private static function appendIsNullClause(array &$conditions, string $column, mixed $value, bool $not): void
    {
        if (strtoupper((string) $value) !== 'NULL') {
            throw new InvalidArgumentException(($not ? 'IS NOT' : 'IS') . ' operator only supports NULL value');
        }

        $conditions[] = "{$column} IS " . ($not ? 'NOT NULL' : 'NULL');
    }

    private static function assertIdentifier(string $identifier): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new InvalidArgumentException(sprintf('Invalid identifier: "%s".', $identifier));
        }
    }

    /**
     * Clear all configuration and connections. For test isolation between requests —
     * process-lifetime static state otherwise.
     */
    public static function reset(): void
    {
        self::$configs = [];
        self::$connections = [];
        self::$lastStatement = null;
        self::$lastInsertId = '';
        self::$lastRowCount = 0;
    }
}
