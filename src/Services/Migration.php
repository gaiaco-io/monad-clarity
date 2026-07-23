<?php

declare(strict_types=1);

namespace Gaia\Clarity\Services;

use DateTimeImmutable;
use Gaia\Clarity\Services\Schema\Blueprint;
use InvalidArgumentException;
use RuntimeException;

/**
 * Orchestrates migration files (create/drop database/table/index are Schema's job —
 * a migration's up()/down() calls Schema directly; Migration tracks what's been applied,
 * runs it in order, rolls it back, and handles the adjacent CLI operations: raw SQL
 * scripts, seed scripts, and DDL export/import (ReleaseNotes §12).
 *
 * A migration file returns an object with up()/down() methods:
 *
 *     <?php
 *     return new class {
 *         public function up(): void
 *         {
 *             Schema::createTable('users', function (Blueprint $table) {
 *                 $table->id();
 *                 $table->string('email');
 *             });
 *         }
 *         public function down(): void
 *         {
 *             Schema::dropTable('users');
 *         }
 *     };
 *
 * Applied migrations are tracked in a `clarity_migrations` table (not one of the two
 * setup-owned tables in CrossRepoContracts.md §8 — this one's internal bookkeeping, free
 * to change in any release), created on first use.
 *
 * @package Gaia\Clarity\Services
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
abstract class Migration
{
    private const TRACKING_TABLE = 'clarity_migrations';

    /**
     * Run every migration file in $migrationsPath not yet recorded as applied, in
     * filename order (hence the conventional `YYYYMMDDHHMMSS_description.php` naming).
     *
     * @return list<string> Names of migrations newly applied by this call.
     */
    public static function migrate(string $migrationsPath, ?string $context = null): array
    {
        self::ensureTrackingTable($context);
        $applied = self::appliedMigrationNames($context);
        $newlyApplied = [];

        foreach (self::discoverMigrationFiles($migrationsPath) as $name => $file) {
            if (in_array($name, $applied, true)) {
                continue;
            }

            self::loadMigration($file)->up();

            DB::insert(self::TRACKING_TABLE, [
                'migration' => $name,
                'applied_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ], DB::ID_TYPE_INT, $context);

            $newlyApplied[] = $name;
        }

        return $newlyApplied;
    }

    /**
     * Roll back the last $steps applied migrations, most-recently-applied first, calling
     * each one's down(). The migration file must still exist at $migrationsPath.
     *
     * @return list<string> Names of migrations rolled back, in the order applied.
     */
    public static function rollback(string $migrationsPath, int $steps = 1, ?string $context = null): array
    {
        self::ensureTrackingTable($context);

        // $steps is a PHP int by type declaration, never attacker-controlled input, so
        // interpolating it directly avoids the "LIMIT ?" placeholder-binding pitfall some
        // PDO driver/emulation combinations have with non-emulated prepares.
        DB::run(
            'SELECT migration FROM ' . self::TRACKING_TABLE . ' ORDER BY id DESC LIMIT ' . max(0, $steps),
            [],
            $context
        );
        $toRollBack = array_column(DB::fetchAll(), 'migration');

        $files = self::discoverMigrationFiles($migrationsPath);
        $rolledBack = [];

        foreach ($toRollBack as $name) {
            if (!isset($files[$name])) {
                throw new RuntimeException(
                    sprintf('Cannot roll back "%s": its migration file no longer exists at %s.', $name, $migrationsPath)
                );
            }

            self::loadMigration($files[$name])->down();
            DB::delete(self::TRACKING_TABLE, ['migration' => $name], $context);
            $rolledBack[] = $name;
        }

        return $rolledBack;
    }

    /**
     * @return array<string, bool> Migration name => whether it has been applied, in file order.
     */
    public static function status(string $migrationsPath, ?string $context = null): array
    {
        self::ensureTrackingTable($context);
        $applied = self::appliedMigrationNames($context);

        $result = [];

        foreach (array_keys(self::discoverMigrationFiles($migrationsPath)) as $name) {
            $result[$name] = in_array($name, $applied, true);
        }

        return $result;
    }

    /**
     * Execute a raw .sql file. Statements are split on semicolons after stripping `--`
     * line comments — a deliberately simple splitter, not a SQL parser: a semicolon
     * inside a string literal will be (incorrectly) treated as a statement boundary.
     * Fine for typical DDL/seed scripts; not a substitute for a real SQL client on
     * anything containing string literals with embedded semicolons.
     */
    public static function runSqlScript(string $path, ?string $context = null): void
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException(sprintf('SQL script not found: %s', $path));
        }

        $connection = DB::connect($context);

        foreach (self::splitStatements((string) file_get_contents($path)) as $statement) {
            $connection->exec($statement);
        }
    }

    /**
     * @return list<string>
     */
    private static function splitStatements(string $sql): array
    {
        $withoutComments = (string) preg_replace('/--.*$/m', '', $sql);

        return array_values(array_filter(array_map('trim', explode(';', $withoutComments))));
    }

    /**
     * Run a seed file: a PHP file that returns a callable, invoked with no arguments.
     */
    public static function runSeed(string $path, ?string $context = null): void
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException(sprintf('Seed file not found: %s', $path));
        }

        $seed = (static function (string $__path) {
            return require $__path;
        })($path);

        if (!is_callable($seed)) {
            throw new RuntimeException(sprintf('Seed file "%s" must return a callable.', $path));
        }

        $seed();
    }

    /**
     * Export the current schema as idempotent (safe to re-run) CREATE TABLE statements
     * (§12.8). SQLite and MySQL reproduce the live schema exactly (both expose the
     * original CREATE TABLE text natively). PostgreSQL has no such facility — its export
     * reconstructs columns from information_schema only; primary keys, unique
     * constraints, and indexes are NOT captured. Re-declare those via Schema after
     * importing, or use `pg_dump` directly for a complete Postgres dump.
     */
    public static function exportDdl(?string $context = null): string
    {
        return match (Schema::dialect($context)) {
            'sqlite' => self::exportSqliteDdl($context),
            'mysql' => self::exportMysqlDdl($context),
            'pgsql' => self::exportPostgresDdl($context),
            default => throw new RuntimeException('Unsupported dialect for exportDdl().'),
        };
    }

    private static function exportSqliteDdl(?string $context): string
    {
        DB::run(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND sql IS NOT NULL AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\'",
            [],
            $context
        );

        // sqlite_master stores the canonical table structure, not the literal submitted
        // DDL — it does NOT preserve "IF NOT EXISTS" even though the original
        // Schema::createTable() call included it. Re-inject it so the export is actually
        // idempotent, same as exportMysqlDdl() has to for SHOW CREATE TABLE.
        $statements = array_map(
            static fn (string $sql) => preg_replace('/^CREATE TABLE/', 'CREATE TABLE IF NOT EXISTS', $sql, 1),
            array_column(DB::fetchAll(), 'sql')
        );

        return self::joinStatements($statements);
    }

    private static function exportMysqlDdl(?string $context): string
    {
        DB::run('SHOW TABLES', [], $context);
        $tables = array_map(static fn (array $row) => array_values($row)[0], DB::fetchAll());

        $statements = [];

        foreach ($tables as $table) {
            self::assertIdentifier($table);
            DB::run('SHOW CREATE TABLE ' . $table, [], $context);
            $createStatement = (string) (DB::fetch()['Create Table'] ?? '');
            $statements[] = preg_replace('/^CREATE TABLE/', 'CREATE TABLE IF NOT EXISTS', $createStatement, 1);
        }

        return self::joinStatements($statements);
    }

    private static function exportPostgresDdl(?string $context): string
    {
        DB::run(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'",
            [],
            $context
        );
        $tables = array_column(DB::fetchAll(), 'table_name');

        return self::joinStatements(array_map(
            fn (string $table) => self::reconstructPostgresTable($table, $context),
            $tables
        ));
    }

    private static function reconstructPostgresTable(string $table, ?string $context): string
    {
        self::assertIdentifier($table);

        DB::run(
            'SELECT column_name, data_type, is_nullable, column_default, character_maximum_length
             FROM information_schema.columns
             WHERE table_schema = current_schema() AND table_name = ?
             ORDER BY ordinal_position',
            [$table],
            $context
        );

        $lines = array_map(static function (array $column): string {
            $type = strtoupper($column['data_type']);

            if ($column['character_maximum_length'] !== null) {
                $type .= '(' . $column['character_maximum_length'] . ')';
            }

            $line = $column['column_name'] . ' ' . $type . ' ' . ($column['is_nullable'] === 'NO' ? 'NOT NULL' : 'NULL');

            if ($column['column_default'] !== null) {
                $line .= ' DEFAULT ' . $column['column_default'];
            }

            return $line;
        }, DB::fetchAll());

        return sprintf("CREATE TABLE IF NOT EXISTS %s (\n    %s\n)", $table, implode(",\n    ", $lines));
    }

    /**
     * @param list<string> $statements
     */
    private static function joinStatements(array $statements): string
    {
        return $statements === [] ? '' : implode(";\n\n", $statements) . ';';
    }

    private static function ensureTrackingTable(?string $context): void
    {
        if (Schema::hasTable(self::TRACKING_TABLE, $context)) {
            return;
        }

        Schema::createTable(self::TRACKING_TABLE, function (Blueprint $table) {
            $table->autoIncrementId();
            $table->string('migration', 255);
            $table->datetime('applied_at');
            $table->unique('migration');
        }, $context);
    }

    /**
     * @return list<string>
     */
    private static function appliedMigrationNames(?string $context): array
    {
        DB::run('SELECT migration FROM ' . self::TRACKING_TABLE, [], $context);

        return array_column(DB::fetchAll(), 'migration');
    }

    /**
     * @return array<string, string> Migration name => absolute file path, sorted by name.
     */
    private static function discoverMigrationFiles(string $migrationsPath): array
    {
        $files = glob(rtrim($migrationsPath, '/') . '/*.php') ?: [];
        sort($files);

        $discovered = [];

        foreach ($files as $file) {
            $discovered[pathinfo($file, PATHINFO_FILENAME)] = $file;
        }

        return $discovered;
    }

    private static function loadMigration(string $path): object
    {
        $migration = (static function (string $__path) {
            return require $__path;
        })($path);

        if (!is_object($migration) || !method_exists($migration, 'up') || !method_exists($migration, 'down')) {
            throw new RuntimeException(sprintf('Migration file "%s" must return an object with up() and down() methods.', $path));
        }

        return $migration;
    }

    private static function assertIdentifier(string $identifier): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new InvalidArgumentException(sprintf('Invalid identifier: "%s".', $identifier));
        }
    }
}
