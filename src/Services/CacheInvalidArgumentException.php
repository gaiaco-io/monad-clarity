<?php

declare(strict_types=1);

namespace Monad\Clarity\Services;

use InvalidArgumentException;
use Psr\SimpleCache\InvalidArgumentException as PsrInvalidArgumentException;

/**
 * Thrown for a malformed cache key (empty, or containing a PSR-16 reserved character:
 * `{}()/\@:`) — PSR-16 requires this to implement its own InvalidArgumentException
 * marker interface, distinct from a generic one.
 *
 * @package Monad\Clarity\Services
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class CacheInvalidArgumentException extends InvalidArgumentException implements PsrInvalidArgumentException
{
}
