<?php

declare(strict_types=1);

namespace Gaia\Clarity\Tests\Console;

use Gaia\Clarity\Console\Arguments;
use Gaia\Clarity\Console\DBSeed;
use Gaia\Clarity\Services\DB;
use Gaia\Clarity\Services\Schema;
use Gaia\Clarity\Services\Schema\Blueprint;
use PDO;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

final class DBSeedTest extends TestCase
{
    private const SEED_FILE = __DIR__ . '/../fixtures/seeds/widgets_seed.php';

    #[Before]
    public function setUpInMemoryDatabase(): void
    {
        DB::useConnection(new PDO('sqlite::memory:'));
        Schema::createTable('widgets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
    }

    #[After]
    public function resetDB(): void
    {
        DB::reset();
    }

    private function capture(callable $fn): string
    {
        ob_start();
        $fn();

        return (string) ob_get_clean();
    }

    public function testRunsAbsoluteSeedFileAndReportsSuccess(): void
    {
        $output = $this->capture(function () {
            $exitCode = (new DBSeed())(Arguments::parse(['--file=' . self::SEED_FILE]));
            self::assertSame(0, $exitCode);
        });

        self::assertStringContainsString('Seeded:', $output);

        DB::run('SELECT * FROM widgets WHERE id = ?', ['seed-1']);
        self::assertSame('Seeded Widget', DB::fetch()['name']);
    }

    public function testFailsWithoutAFileOption(): void
    {
        self::assertSame(1, (new DBSeed())(Arguments::parse([])));
    }
}
