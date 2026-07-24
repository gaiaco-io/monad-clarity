<?php

declare(strict_types=1);

namespace Monad\Clarity\Console;

use Closure;
use Monad\Clarity\Services\Console;
use Monad\Clarity\Services\Schema;
use Monad\Clarity\Services\Schema\Blueprint;

/**
 * `php mitosis setup` — creates the two setup-owned tables (`sessions`, `caches`) from
 * DDL.sql (CrossRepoContracts.md §8). sessionsBlueprint()/cachesBlueprint() are the
 * single canonical definition of that compatibility surface — SchemaTest's
 * cross-dialect DDL assertions exercise these same closures rather than a second,
 * hand-maintained copy that could silently drift from what this command actually emits.
 *
 * @package Monad\Clarity\Console
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class Setup implements Command
{
    public function __invoke(Arguments $arguments): int
    {
        $context = $arguments->option('context');
        $context = is_string($context) ? $context : null;

        $dialect = Schema::dialect($context);

        Schema::createTable('sessions', self::sessionsBlueprint($dialect), $context);
        Schema::createTable('caches', self::cachesBlueprint(), $context);

        Console::success('Setup complete: sessions and caches tables ready.');

        return 0;
    }

    /**
     * The `id` column's DB-level `(uuid())` default is MySQL-only syntax — PostgreSQL has
     * no built-in equivalent guaranteed across versions and SQLite has none at all.
     * Application code always goes through DB::insert(), which generates the UUID
     * PHP-side regardless (Architecture.md §9); the DB-level default only matters for
     * direct SQL bypassing that layer, so it's applied where the dialect actually
     * supports it and simply omitted elsewhere rather than papering over the gap.
     *
     * @return Closure(Blueprint): void
     */
    public static function sessionsBlueprint(string $dialect): Closure
    {
        return static function (Blueprint $table) use ($dialect) {
            $table->uuid('id', default: $dialect === 'mysql' ? Schema::raw('(uuid())') : null);
            $table->uuid('user_id', nullable: true);
            $table->string('digest', 255);
            $table->string('ip_address', 39);
            $table->text('user_agent', nullable: true);
            $table->json('payload');
            $table->datetime('created_at', default: Schema::raw('CURRENT_TIMESTAMP'));
            $table->datetime('updated_at', default: Schema::raw('CURRENT_TIMESTAMP'), autoUpdate: true);
            $table->datetime('expire_at');
            $table->datetime('revoked_at', nullable: true);
            $table->primary('id');
            $table->unique('digest');
            $table->index('user_id');
        };
    }

    /**
     * @return Closure(Blueprint): void
     */
    public static function cachesBlueprint(): Closure
    {
        return static function (Blueprint $table) {
            $table->binary('key_hash', 32);
            $table->string('cache_key', 512);
            $table->binary('cache_value');
            $table->string('encoding', 20, default: 'serialize');
            $table->datetime('expires_at', nullable: true);
            $table->datetime('created_at', default: Schema::raw('CURRENT_TIMESTAMP'));
            $table->datetime('updated_at', default: Schema::raw('CURRENT_TIMESTAMP'), autoUpdate: true);
            $table->primary('key_hash');
            $table->index('expires_at');
        };
    }
}
