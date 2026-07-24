<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\LLM;

use RuntimeException;

/**
 * Thrown by an LLM adapter on misconfiguration, a network failure, a non-2xx provider
 * response, or a provider response that can't be parsed as the shape the adapter expects.
 *
 * @package Monad\Clarity\Services\LLM
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class LLMException extends RuntimeException
{
}
