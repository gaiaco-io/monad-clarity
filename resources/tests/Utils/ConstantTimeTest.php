<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Utils;

use Monad\Clarity\Utils\ConstantTime;
use PHPUnit\Framework\TestCase;

final class ConstantTimeTest extends TestCase
{
    public function testEqualStringsReturnTrue(): void
    {
        self::assertTrue(ConstantTime::equals('same-secret', 'same-secret'));
    }

    public function testDifferentStringsReturnFalse(): void
    {
        self::assertFalse(ConstantTime::equals('same-secret', 'different-secret'));
    }

    public function testDifferentLengthStringsReturnFalse(): void
    {
        self::assertFalse(ConstantTime::equals('short', 'a-much-longer-string'));
    }

    public function testEmptyStringsAreEqual(): void
    {
        self::assertTrue(ConstantTime::equals('', ''));
    }

    /**
     * Regression guard, not a precision timing proof: a naive `===`/loop-with-early-exit
     * comparator returns much faster when strings differ near the start than near the
     * end. hash_equals() should show no such gap. Large sample + generous tolerance to
     * avoid CI-runner flakiness while still catching a gross implementation regression.
     */
    public function testComparisonTimeDoesNotLeakPositionOfFirstDifference(): void
    {
        $length = 5000;
        $known = str_repeat('a', $length);
        $diffAtStart = 'b' . str_repeat('a', $length - 1);
        $diffAtEnd = str_repeat('a', $length - 1) . 'b';
        $iterations = 3000;

        for ($i = 0; $i < 200; $i++) {
            ConstantTime::equals($known, $diffAtStart);
            ConstantTime::equals($known, $diffAtEnd);
        }

        $startElapsed = self::timeIterations(fn () => ConstantTime::equals($known, $diffAtStart), $iterations);
        $endElapsed = self::timeIterations(fn () => ConstantTime::equals($known, $diffAtEnd), $iterations);

        $ratio = max($startElapsed, $endElapsed) / max(1, min($startElapsed, $endElapsed));

        self::assertLessThan(
            3.0,
            $ratio,
            'Comparison time should not vary meaningfully based on where two strings differ.'
        );
    }

    private static function timeIterations(callable $comparison, int $iterations): int
    {
        $start = hrtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $comparison();
        }

        return hrtime(true) - $start;
    }
}
