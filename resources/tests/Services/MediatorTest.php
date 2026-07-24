<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services;

use Monad\Clarity\Services\Mediator;
use Monad\Clarity\Services\Request;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class MediatorTest extends TestCase
{
    #[After]
    public function resetMediator(): void
    {
        Mediator::reset();
    }

    public function testDevRendererIncludesAllEightElements(): void
    {
        Mediator::configure(debug: true);

        $request = Request::fromArrays(server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/boom']);

        try {
            self::throwWithMessage('Something went wrong');
        } catch (RuntimeException $inner) {
            $response = Mediator::handleException(new RuntimeException('Outer failure', 0, $inner), $request);
        }

        $html = $response->content();

        self::assertSame(500, $response->status());
        self::assertStringContainsString(RuntimeException::class, $html); // 1. exception class
        self::assertStringContainsString('Outer failure', $html); // 2. clear message
        self::assertStringContainsString(__FILE__, $html); // 3. file
        self::assertStringContainsString('excerpt-line current', $html); // 4. source excerpt
        self::assertStringContainsString('<ol class="trace">', $html); // 5. ordered stack frames
        self::assertMatchesRegularExpression('/Request ID: <code>[0-9a-f]{8}<\/code>/', $html); // 6. request id
        self::assertStringContainsString('GET /boom', $html); // 7. request summary
        self::assertStringContainsString('Something went wrong', $html); // 8. previous exception chain
    }

    public function testDevRendererWorksWithoutARequest(): void
    {
        Mediator::configure(debug: true);

        $response = Mediator::handleException(new RuntimeException('no request available'));

        self::assertStringContainsString('Request: <code>n/a</code>', $response->content());
    }

    public function testProdRendererHidesInternals(): void
    {
        Mediator::configure(debug: false);

        $response = Mediator::handleException(new RuntimeException('Sensitive internal detail: db password is hunter2'));

        self::assertStringNotContainsString('hunter2', $response->content());
        self::assertStringNotContainsString('Sensitive internal detail', $response->content());
    }

    public function testProdRendererReturnsAppropriateStatusAndIncidentId(): void
    {
        Mediator::configure(debug: false);

        $response = Mediator::handleException(new RuntimeException('boom'));
        $data = json_decode($response->content(), true);

        self::assertSame(500, $response->status());
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $data['incident_id']);
    }

    public function testProdRendererLogsFullExceptionViaLogger(): void
    {
        $logger = new class implements LoggerInterface {
            public array $records = [];

            public function emergency($message, array $context = []): void
            {
                $this->log('emergency', $message, $context);
            }

            public function alert($message, array $context = []): void
            {
                $this->log('alert', $message, $context);
            }

            public function critical($message, array $context = []): void
            {
                $this->log('critical', $message, $context);
            }

            public function error($message, array $context = []): void
            {
                $this->log('error', $message, $context);
            }

            public function warning($message, array $context = []): void
            {
                $this->log('warning', $message, $context);
            }

            public function notice($message, array $context = []): void
            {
                $this->log('notice', $message, $context);
            }

            public function info($message, array $context = []): void
            {
                $this->log('info', $message, $context);
            }

            public function debug($message, array $context = []): void
            {
                $this->log('debug', $message, $context);
            }

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = compact('level', 'message', 'context');
            }
        };

        Mediator::configure(debug: false, logger: $logger);

        $exception = new RuntimeException('logged failure');
        $response = Mediator::handleException($exception);
        $incidentId = json_decode($response->content(), true)['incident_id'];

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame('logged failure', $logger->records[0]['message']);
        self::assertSame($exception, $logger->records[0]['context']['exception']);
        self::assertSame($incidentId, $logger->records[0]['context']['request_id']);
    }

    public function testHandleErrorConvertsToErrorExceptionWhenNotSuppressed(): void
    {
        $previousReporting = error_reporting(E_ALL);

        try {
            $this->expectException(\ErrorException::class);
            $this->expectExceptionMessage('deliberate warning');

            Mediator::handleError(E_WARNING, 'deliberate warning', __FILE__, __LINE__);
        } finally {
            error_reporting($previousReporting);
        }
    }

    public function testHandleErrorReturnsFalseWhenSeverityIsSuppressed(): void
    {
        $previousReporting = error_reporting(E_ALL & ~E_WARNING);

        try {
            self::assertFalse(Mediator::handleError(E_WARNING, 'suppressed', __FILE__, __LINE__));
        } finally {
            error_reporting($previousReporting);
        }
    }

    private static function throwWithMessage(string $message): never
    {
        throw new RuntimeException($message);
    }
}
