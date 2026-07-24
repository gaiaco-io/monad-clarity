<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Schema;

use InvalidArgumentException;

/**
 * Collects a dialect-agnostic description of a table's columns and keys. Blueprint
 * itself knows nothing about MySQL/PostgreSQL/SQLite syntax — Schema's per-dialect
 * compiler turns this description into the actual CREATE/ALTER TABLE statement.
 *
 * @package Monad\Clarity\Services\Schema
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class Blueprint
{
    /** @var list<array{name: string, type: string, length: ?int, nullable: bool, default: mixed, autoIncrement: bool, autoUpdate: bool}> */
    private array $columns = [];

    /** @var list<string> */
    private array $primaryKey = [];

    /** @var list<array{name: ?string, columns: list<string>, unique: bool}> */
    private array $indexes = [];

    /**
     * UUID primary key named $name — Architecture.md §9's default primary key shape.
     */
    public function id(string $name = 'id'): self
    {
        $this->uuid($name);
        $this->primary($name);

        return $this;
    }

    /**
     * Auto-incrementing integer primary key — the "configurable integer option" for
     * primary keys (ReleaseNotes §10.5). Implies primary(); SQLite in particular requires
     * an auto-increment column to be declared PRIMARY KEY inline, not as a separate
     * table-level constraint.
     */
    public function autoIncrementId(string $name = 'id'): self
    {
        $this->addColumn($name, 'integer', autoIncrement: true);
        $this->primaryKey = [$name];

        return $this;
    }

    public function uuid(string $name, bool $nullable = false, mixed $default = null): self
    {
        return $this->addColumn($name, 'uuid', nullable: $nullable, default: $default);
    }

    public function string(string $name, int $length = 255, bool $nullable = false, mixed $default = null): self
    {
        return $this->addColumn($name, 'string', length: $length, nullable: $nullable, default: $default);
    }

    public function text(string $name, bool $nullable = false): self
    {
        return $this->addColumn($name, 'text', nullable: $nullable);
    }

    public function integer(string $name, bool $nullable = false, mixed $default = null): self
    {
        return $this->addColumn($name, 'integer', nullable: $nullable, default: $default);
    }

    public function bigInteger(string $name, bool $nullable = false, mixed $default = null): self
    {
        return $this->addColumn($name, 'bigInteger', nullable: $nullable, default: $default);
    }

    public function boolean(string $name, bool $nullable = false, ?bool $default = null): self
    {
        return $this->addColumn($name, 'boolean', nullable: $nullable, default: $default);
    }

    public function json(string $name, bool $nullable = false): self
    {
        return $this->addColumn($name, 'json', nullable: $nullable);
    }

    /**
     * $autoUpdate (auto-set to CURRENT_TIMESTAMP on every row UPDATE) is MySQL-only —
     * PostgreSQL and SQLite have no equivalent column-level clause and need a trigger,
     * which Schema does not generate. On those dialects, set the column explicitly on
     * update, or add a trigger yourself in a raw migration statement.
     */
    public function datetime(string $name, bool $nullable = false, mixed $default = null, bool $autoUpdate = false): self
    {
        return $this->addColumn($name, 'datetime', nullable: $nullable, default: $default, autoUpdate: $autoUpdate);
    }

    /**
     * $length null means "unbounded" (LONGBLOB / BYTEA / BLOB, per dialect).
     */
    public function binary(string $name, ?int $length = null, bool $nullable = false): self
    {
        return $this->addColumn($name, 'binary', length: $length, nullable: $nullable);
    }

    /**
     * @param string|list<string> $columns
     */
    public function primary(string|array $columns): self
    {
        $this->primaryKey = (array) $columns;

        return $this;
    }

    /**
     * @param string|list<string> $columns
     */
    public function unique(string|array $columns, ?string $name = null): self
    {
        $this->indexes[] = ['name' => $name, 'columns' => (array) $columns, 'unique' => true];

        return $this;
    }

    /**
     * @param string|list<string> $columns
     */
    public function index(string|array $columns, ?string $name = null): self
    {
        $this->indexes[] = ['name' => $name, 'columns' => (array) $columns, 'unique' => false];

        return $this;
    }

    /**
     * @return list<array{name: string, type: string, length: ?int, nullable: bool, default: mixed, autoIncrement: bool, autoUpdate: bool}>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * @return list<string>
     */
    public function primaryKeyColumns(): array
    {
        return $this->primaryKey;
    }

    /**
     * @return list<array{name: ?string, columns: list<string>, unique: bool}>
     */
    public function indexes(): array
    {
        return $this->indexes;
    }

    private function addColumn(
        string $name,
        string $type,
        ?int $length = null,
        bool $nullable = false,
        mixed $default = null,
        bool $autoIncrement = false,
        bool $autoUpdate = false,
    ): self {
        if (in_array($name, array_column($this->columns, 'name'), true)) {
            throw new InvalidArgumentException(sprintf('Column "%s" is already defined on this table.', $name));
        }

        $this->columns[] = [
            'name' => $name,
            'type' => $type,
            'length' => $length,
            'nullable' => $nullable,
            'default' => $default,
            'autoIncrement' => $autoIncrement,
            'autoUpdate' => $autoUpdate,
        ];

        return $this;
    }
}
