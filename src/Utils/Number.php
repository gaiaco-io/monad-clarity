<?php

namespace Gaia\Clarity\Utils;

/**
 * Number utility class.
 *
 * @package Gaia\Clarity\Utils
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */

final class Number
{
    /**
     * Shorten a number to a shorter format (e.g. 1000 -> 1K, 1000000 -> 1M).
     * Supports 1K, 1M, 1B, 1T.
     *
     * @param float $number The number to shorten.
     * @param int $precision The precision of the number.
     * @return string The shortened number.
     */
    public static function shorten(float $number, int $precision = 1): string
    {
        $suffixes = ['', 'K', 'M', 'B', 'T'];
        $suffix_index = 0;

        while ($number >= 1000 && $suffix_index < count($suffixes) - 1) {
            $number /= 1000;
            $suffix_index++;
        }

        return round($number, $precision) . $suffixes[$suffix_index];
    }
}
