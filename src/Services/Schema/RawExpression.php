<?php

declare(strict_types=1);

namespace Gaia\Clarity\Services\Schema;

/**
 * Wraps a literal SQL fragment (e.g. `CURRENT_TIMESTAMP`) so Blueprint's compiler emits
 * it unquoted, distinguishing it from an ordinary string default that must be quoted.
 *
 * @package Gaia\Clarity\Services\Schema
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final readonly class RawExpression
{
    public function __construct(public string $sql)
    {
    }
}
