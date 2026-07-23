<?php

declare(strict_types=1);

namespace Gaia\Clarity\Services;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * Thrown for transport-level failures (DNS resolution, connection refused, timeout) —
 * anything cURL itself failed on before a response was received.
 *
 * @package Gaia\Clarity\Services
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class HttpClientException extends RuntimeException implements NetworkExceptionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        string $message,
        int $code = 0,
    ) {
        parent::__construct($message, $code);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
