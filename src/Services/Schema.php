<?php

declare(strict_types=1);

namespace Monad\Clarity\Services;

use Closure;
use Monad\Clarity\Services\Schema\Blueprint;
use Monad\Clarity\Services\Schema\RawExpression;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * PDO-based dialect abstraction over DB's connections. MySQL is the default; PostgreSQL
 * and SQLite are built-in supported dialects (ReleaseNotes §10). Primary keys default to
 * UUID (`Blueprint::id()`), with a configurable auto-increment integer option
 * (`Blueprint::autoIncrementId()`), per Architecture.md §9.
 *
 * Dialect differences this class does NOT paper over (rather than silently degrading):
 * - `datetime(..., autoUpdate: true)` (auto-set to CURRENT_TIMESTAMP on every UPDATE) only
 *   compiles on MySQL. PostgreSQL/SQLite have no column-level equivalent — it needs a
 *   trigger, which Schema does not generate. Set the column explicitly on those dialects.
 * - SQLite has no CREATE/DROP DATABASE (a file or `:memory:` connection already is one).
 * - PostgreSQL's `CREATE DATABASE` has no `IF NOT EXISTS`; a second call throws, same as
 *   the underlying driver would.
 *
 * @package Monad\Clarity\Services
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
abstract class Schema
{
    public static function raw(string $sql): RawExpression
    {
        return new RawExpression($sql);
    }

    public static function dialect(?string $context = null): string
    {
        return DB::connect($context)->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public static function createTable(string $table, Closure $callback, ?string $context = null): void
    {
        self::assertIdentifier($table);

        $blueprint = new Blueprint();
        $callback($blueprint);

        $dialect = self::dialect($context);

        DB::run(self::compileCreateTable($dialect, $table, $blueprint), [], $context);

        foreach ($blueprint->indexes() as $index) {
            self::executeCreateIndex($dialect, $table, $index['columns'], $index['name'], $index['unique'], $context);
        }
    }

    /**
     * Adds every column defined in the callback's Blueprint to $table. Column
     * modification/renaming is not supported — cross-dialect ALTER COLUMN semantics
     * differ too much for a generic abstraction; drop and recreate the column instead.
     */
    public static function alterTable(string $table, Closure $callback, ?string $context = null): void
    {
        self::assertIdentifier($table);

        $blueprint = new Blueprint();
        $callback($blueprint);

        $dialect = self::dialect($context);

        foreach ($blueprint->columns() as $column) {
            DB::run(
                sprintf('ALTER TABLE %s ADD COLUMN %s', $table, self::compileColumnLine($dialect, $column)),
                [],
                $context
            );
        }
    }

    public static function dropColumn(string $table, string $column, ?string $context = null): void
    {
        self::assertIdentifier($table);
        self::assertIdentifier($column);

        DB::run(sprintf('ALTER TABLE %s DROP COLUMN %s', $table, $column), [], $context);
    }

    public static function dropTable(string $table, bool $ifExists = true, ?string $context = null): void
    {
        self::assertIdentifier($table);

        DB::run(sprintf('DROP TABLE %s%s', $ifExists ? 'IF EXISTS ' : '', $table), [], $context);
    }

    /**
     * @param string|list<string> $columns
     */
    public static function createIndex(
        string $table,
        string|array $columns,
        ?string $name = null,
        bool $unique = false,
        ?string $context = null
    ): void {
        self::assertIdentifier($table);

        self::executeCreateIndex(self::dialect($context), $table, (array) $columns, $name, $unique, $context);
    }

    /**
     * MySQL does not honour $ifExists — `DROP INDEX ... IF EXISTS` requires MySQL
     * 8.0.29+, which isn't universal, so a missing index throws there just as the
     * driver would. PostgreSQL and SQLite both support IF EXISTS unconditionally.
     */
    public static function dropIndex(string $table, string $name, bool $ifExists = true, ?string $context = null): void
    {
        self::assertIdentifier($table);
        self::assertIdentifier($name);

        $dialect = self::dialect($context);

        $sql = $dialect === 'mysql'
            ? sprintf('DROP INDEX %s ON %s', $name, $table)
            : sprintf('DROP INDEX %s%s', $ifExists ? 'IF EXISTS ' : '', $name);

        DB::run($sql, [], $context);
    }

    public static function createDatabase(string $name, ?string $context = null): void
    {
        self::assertIdentifier($name);

        $sql = match (self::dialect($context)) {
            'mysql' => "CREATE DATABASE IF NOT EXISTS {$name}",
            'pgsql' => "CREATE DATABASE {$name}",
            'sqlite' => throw new RuntimeException(
                'SQLite has no CREATE DATABASE — a file or ":memory:" connection is already its own database.'
            ),
            default => throw new RuntimeException('Unsupported dialect for createDatabase().'),
        };

        DB::run($sql, [], $context);
    }

    public static function dropDatabase(string $name, bool $ifExists = true, ?string $context = null): void
    {
        self::assertIdentifier($name);
        $dialect = self::dialect($context);

        $sql = match ($dialect) {
            'mysql', 'pgsql' => sprintf('DROP DATABASE %s%s', $ifExists ? 'IF EXISTS ' : '', $name),
            'sqlite' => throw new RuntimeException(
                'SQLite has no DROP DATABASE — delete the database file instead.'
            ),
            default => throw new RuntimeException('Unsupported dialect for dropDatabase().'),
        };

        DB::run($sql, [], $context);
    }

    public static function hasTable(string $table, ?string $context = null): bool
    {
        $dialect = self::dialect($context);

        $sql = match ($dialect) {
            'mysql' => 'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            'pgsql' => 'SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?',
            'sqlite' => "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?",
            default => throw new RuntimeException('Unsupported dialect for hasTable().'),
        };

        // closeCursor() matters here specifically for SQLite: a lingering open statement
        // handle on this table (kept alive by DB::$lastStatement) blocks a subsequent DDL
        // statement — e.g. dropTable() right after hasTable() — with "table is locked".
        $statement = DB::run($sql, [$table], $context);
        $exists = $statement->fetchColumn() !== false;
        $statement->closeCursor();

        return $exists;
    }

    public static function hasColumn(string $table, string $column, ?string $context = null): bool
    {
        $dialect = self::dialect($context);

        if ($dialect === 'sqlite') {
            $statement = DB::run('PRAGMA table_info(' . $table . ')', [], $context);
            $columns = $statement->fetchAll(PDO::FETCH_ASSOC);
            $statement->closeCursor();

            return in_array($column, array_column($columns, 'name'), true);
        }

        $sql = $dialect === 'mysql'
            ? 'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
            : 'SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?';

        $statement = DB::run($sql, [$table, $column], $context);
        $exists = $statement->fetchColumn() !== false;
        $statement->closeCursor();

        return $exists;
    }

    private static function compileCreateTable(string $dialect, string $table, Blueprint $blueprint): string
    {
        $primary = $blueprint->primaryKeyColumns();
        $autoIncrementColumn = self::soleAutoIncrementColumnName($blueprint);

        $lines = array_map(
            fn (array $column): string => self::compileColumnLine($dialect, $column),
            $blueprint->columns()
        );

        $sqliteHandlesPrimaryKeyInline = $dialect === 'sqlite'
            && $autoIncrementColumn !== null
            && $primary === [$autoIncrementColumn];

        if ($primary !== [] && !$sqliteHandlesPrimaryKeyInline) {
            $lines[] = 'PRIMARY KEY (' . implode(', ', $primary) . ')';
        }

        return sprintf("CREATE TABLE IF NOT EXISTS %s (\n    %s\n)", $table, implode(",\n    ", $lines));
    }

    private static function soleAutoIncrementColumnName(Blueprint $blueprint): ?string
    {
        foreach ($blueprint->columns() as $column) {
            if ($column['autoIncrement']) {
                return $column['name'];
            }
        }

        return null;
    }

    /**
     * @param array{name: string, type: string, length: ?int, nullable: bool, default: mixed, autoIncrement: bool, autoUpdate: bool} $column
     */
    private static function compileColumnLine(string $dialect, array $column): string
    {
        if ($dialect === 'sqlite' && $column['autoIncrement']) {
            return $column['name'] . ' INTEGER PRIMARY KEY AUTOINCREMENT';
        }

        $parts = [$column['name'], self::compileColumnType($dialect, $column)];

        if ($column['autoIncrement']) {
            $identityClause = match ($dialect) {
                'mysql' => 'AUTO_INCREMENT',
                'pgsql' => 'GENERATED ALWAYS AS IDENTITY',
                default => '',
            };

            if ($identityClause !== '') {
                $parts[] = $identityClause;
            }
        }

        $parts[] = $column['nullable'] ? 'NULL' : 'NOT NULL';

        if ($column['default'] !== null) {
            $parts[] = 'DEFAULT ' . self::compileDefault($dialect, $column['default']);
        }

        if ($column['autoUpdate'] && $dialect === 'mysql') {
            $parts[] = 'ON UPDATE CURRENT_TIMESTAMP';
        }

        return implode(' ', $parts);
    }

    /**
     * @param array{type: string, length: ?int} $column
     */
    private static function compileColumnType(string $dialect, array $column): string
    {
        return match ($column['type']) {
            'uuid' => 'CHAR(36)',
            'string' => 'VARCHAR(' . $column['length'] . ')',
            'text' => 'TEXT',
            'integer' => $dialect === 'pgsql' ? 'INTEGER' : 'INT',
            'bigInteger' => $dialect === 'sqlite' ? 'INTEGER' : 'BIGINT',
            'boolean' => match ($dialect) {
                'mysql' => 'TINYINT(1)',
                'sqlite' => 'INTEGER',
                default => 'BOOLEAN',
            },
            'json' => match ($dialect) {
                'pgsql' => 'JSONB',
                'sqlite' => 'TEXT',
                default => 'JSON',
            },
            'datetime' => $dialect === 'pgsql' ? 'TIMESTAMP' : 'DATETIME',
            'binary' => match ($dialect) {
                'mysql' => $column['length'] !== null ? "BINARY({$column['length']})" : 'LONGBLOB',
                'pgsql' => 'BYTEA',
                'sqlite' => 'BLOB',
                default => throw new RuntimeException('Unsupported dialect for binary column.'),
            },
            default => throw new InvalidArgumentException(sprintf('Unknown column type "%s".', $column['type'])),
        };
    }

    private static function compileDefault(string $dialect, mixed $default): string
    {
        if ($default instanceof RawExpression) {
            return $default->sql;
        }

        if (is_bool($default)) {
            return $dialect === 'pgsql' ? ($default ? 'TRUE' : 'FALSE') : ($default ? '1' : '0');
        }

        if (is_int($default) || is_float($default)) {
            return (string) $default;
        }

        return "'" . str_replace("'", "''", (string) $default) . "'";
    }

    /**
     * @param list<string> $columns
     */
    private static function executeCreateIndex(
        string $dialect,
        string $table,
        array $columns,
        ?string $name,
        bool $unique,
        ?string $context
    ): void {
        $indexName = $name ?? self::defaultIndexName($table, $columns, $unique);

        DB::run(
            sprintf('CREATE %sINDEX %s ON %s (%s)', $unique ? 'UNIQUE ' : '', $indexName, $table, implode(', ', $columns)),
            [],
            $context
        );
    }

    /**
     * @param list<string> $columns
     */
    private static function defaultIndexName(string $table, array $columns, bool $unique): string
    {
        return ($unique ? 'uq_' : 'idx_') . $table . '_' . implode('_', $columns);
    }

    private static function assertIdentifier(string $identifier): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new InvalidArgumentException(sprintf('Invalid identifier: "%s".', $identifier));
        }
    }
}
