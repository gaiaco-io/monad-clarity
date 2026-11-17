<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services;

use ErrorException;
use Monad\Clarity\Services\Checkout;
use Monad\Clarity\Services\Checkout\CallbackEvent;
use Monad\Clarity\Services\Checkout\CheckoutException;
use Monad\Clarity\Services\Checkout\CheckoutRequest;
use Monad\Clarity\Services\Checkout\CheckoutSession;
use Monad\Clarity\Services\Checkout\RefundRequest;
use Monad\Clarity\Services\Checkout\RefundResult;
use Monad\Clarity\Services\Checkout\TransactionSnapshot;
use Monad\Clarity\Services\HttpClient;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * What a failed gateway call is allowed to say. Exception messages land in application
 * logs, so a declined Stripe card's PaymentIntent (client_secret, billing details, card
 * fingerprint) must never ride along.
 */
final class CheckoutTest extends TestCase
{
    public function testADeclinedPaymentIntentsClientSecretNeverReachesTheMessage(): void
    {
        $message = $this->failureMessage(402, [
            'error' => [
                'type' => 'card_error',
                'code' => 'card_declined',
                'decline_code' => 'generic_decline',
                'message' => 'Your card was declined.',
                'payment_intent' => [
                    'id' => 'pi_123',
                    'client_secret' => 'pi_123_secret_abc',
                    'payment_method' => [
                        'billing_details' => ['email' => 'jane@example.com', 'name' => 'Jane Doe'],
                        'card' => ['fingerprint' => 'FpRiNt9876'],
                    ],
                ],
                'payment_method' => [
                    'billing_details' => ['email' => 'jane@example.com'],
                    'card' => ['fingerprint' => 'FpRiNt9876'],
                ],
            ],
        ]);

        self::assertStringStartsWith('fake request failed with HTTP 402: ', $message);
        self::assertStringContainsString('card_declined', $message);
        self::assertStringContainsString('generic_decline', $message);
        self::assertStringContainsString('Your card was declined.', $message);
        self::assertStringNotContainsString('pi_123_secret_abc', $message);
        self::assertStringNotContainsString('_secret_', $message);
        self::assertStringNotContainsString('jane@example.com', $message);
        self::assertStringNotContainsString('FpRiNt9876', $message);
        self::assertStringNotContainsString("\n", $message);
    }

    public function testPaddleValidationErrorsAreSummarised(): void
    {
        $message = $this->failureMessage(400, [
            'error' => [
                'type' => 'request_error',
                'code' => 'invalid_field',
                'detail' => 'Invalid request',
                'documentation_url' => 'https://developer.paddle.com/errors/shared/invalid_field',
                'errors' => [
                    ['field' => 'items[0].price_id', 'message' => 'price_id is required'],
                    ['field' => 'customer_id', 'message' => 'must be a customer id'],
                ],
            ],
            'meta' => ['request_id' => 'req-abc-123'],
        ]);

        foreach (['invalid_field', 'Invalid request', 'items[0].price_id', 'price_id is required', 'customer_id', 'must be a customer id', 'req-abc-123'] as $needle) {
            self::assertStringContainsString($needle, $message);
        }
    }

    public function testAJsonBodyWithoutAnErrorObjectIsRedactedAndTruncated(): void
    {
        $message = $this->failureMessage(500, [
            'client_secret' => 'sekrit-value',
            'nested' => ['access_token' => 'tok-value'],
            'note' => str_repeat('x', 2000),
        ]);

        self::assertStringNotContainsString('sekrit-value', $message);
        self::assertStringNotContainsString('tok-value', $message);
        self::assertStringContainsString('[REDACTED]', $message);
        self::assertStringContainsString('…', $message);
        self::assertLessThan(600, mb_strlen($message));
    }

    public function testANonJsonBodyIsTruncated(): void
    {
        $message = $this->failureMessage(502, '<html>' . str_repeat('bad gateway ', 200) . '</html>');

        self::assertStringStartsWith('fake request failed with HTTP 502: <html>bad gateway', $message);
        self::assertStringContainsString('…', $message);
        self::assertLessThan(600, mb_strlen($message));
    }

    public function testAnEmptyBodyIsNamed(): void
    {
        self::assertSame('fake request failed with HTTP 503: (empty body)', $this->failureMessage(503, ''));
    }

    public function testAMalformedErrorShapeNeverThrowsAnythingButCheckoutException(): void
    {
        $bodies = [
            ['error' => 'plain string'],
            ['error' => ['message' => ['not', 'a', 'string'], 'code' => ['x'], 'type' => null]],
            ['error' => ['errors' => 'nope', 'code' => 'c'], 'meta' => 'nope'],
            ['error' => ['errors' => [['field' => ['a'], 'message' => 7], 'junk', ['message' => 'only message']]], 'meta' => ['request_id' => ['x']]],
            ['error' => []],
            [],
            'null',
            '"a string"',
            '12',
        ];

        set_error_handler(static function (int $no, string $str, string $file, int $line): never {
            throw new ErrorException($str, 0, $no, $file, $line);
        });

        try {
            foreach ($bodies as $body) {
                try {
                    $this->checkout()->fail(new Response(422, [], is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR)));
                    self::fail('Expected a CheckoutException.');
                } catch (CheckoutException $e) {
                    self::assertStringStartsWith('fake request failed with HTTP 422: ', $e->getMessage());
                }
            }
        } finally {
            restore_error_handler();
        }
    }

    private function failureMessage(int $status, array|string $body): string
    {
        $raw = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR);

        try {
            $this->checkout()->fail(new Response($status, [], $raw));
        } catch (CheckoutException $e) {
            return $e->getMessage();
        }

        self::fail('Expected a CheckoutException.');
    }

    private function checkout(): object
    {
        return new class ('key', new HttpClient()) extends Checkout {
            public function fail(ResponseInterface $response): void
            {
                $this->assertSuccessful($response);
            }

            public function createCheckout(CheckoutRequest $request): CheckoutSession
            {
                throw new \LogicException('unused');
            }

            public function retrieveStatus(string $reference, int $timeoutSeconds = 30): TransactionSnapshot
            {
                throw new \LogicException('unused');
            }

            public function parseCallback(string $rawBody, array $headers): CallbackEvent
            {
                throw new \LogicException('unused');
            }

            public function refund(RefundRequest $request): RefundResult
            {
                throw new \LogicException('unused');
            }

            protected function gatewayName(): string
            {
                return 'fake';
            }
        };
    }
}
