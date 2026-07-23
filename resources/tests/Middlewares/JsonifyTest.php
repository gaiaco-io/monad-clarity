<?php

declare(strict_types=1);

namespace Gaia\Clarity\Tests\Middlewares;

use Gaia\Clarity\Middlewares\Jsonify;
use Gaia\Clarity\Services\Request;
use Gaia\Clarity\Services\Response;
use Gaia\Clarity\Services\Route;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

final class JsonifyTest extends TestCase
{
    #[After]
    public function resetRoutes(): void
    {
        Route::reset();
    }

    private function next(): callable
    {
        return static fn (Request $request): Response => Response::json(['received' => $request->json()]);
    }

    private static function request(string $method, string $body, string $contentType = 'application/json'): Request
    {
        // Content-Type/Content-Length are exposed by PHP/CGI as $_SERVER['CONTENT_TYPE']
        // directly, unlike every other header — no HTTP_ prefix — which is exactly what
        // Request::header() special-cases for these two.
        return Request::fromArrays(
            server: ['REQUEST_METHOD' => $method, 'CONTENT_TYPE' => $contentType],
            rawBody: $body,
        );
    }

    public function testNonBodyCarryingMethodPassesThroughUntouched(): void
    {
        $jsonify = new Jsonify();
        $response = $jsonify(self::request('GET', '{"a":1}'), $this->next());

        self::assertSame(['received' => ['a' => 1]], json_decode($response->content(), true));
    }

    public function testEmptyBodyPassesThroughUntouched(): void
    {
        $jsonify = new Jsonify();
        $response = $jsonify(self::request('POST', ''), $this->next());

        self::assertSame(['received' => null], json_decode($response->content(), true));
    }

    public function testValidJsonObjectBodyPopulatesTheRequestJsonBag(): void
    {
        $jsonify = new Jsonify();
        $response = $jsonify(self::request('POST', '{"customer":{"name":"Marshal"}}'), $this->next());

        self::assertSame(['received' => ['customer' => ['name' => 'Marshal']]], json_decode($response->content(), true));
    }

    public function testValidJsonArrayBodyPopulatesTheBag(): void
    {
        $jsonify = new Jsonify();
        $response = $jsonify(self::request('POST', '[1,2,3]'), $this->next());

        self::assertSame(['received' => [1, 2, 3]], json_decode($response->content(), true));
    }

    public function testValidBareScalarBodyPopulatesTheBag(): void
    {
        $jsonify = new Jsonify();
        $response = $jsonify(self::request('POST', '"hello"'), $this->next());

        self::assertSame(['received' => 'hello'], json_decode($response->content(), true));
    }

    public function testParsesOnEveryBodyCarryingMethodNotJustPost(): void
    {
        $jsonify = new Jsonify();

        foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
            $response = $jsonify(self::request($method, '{"ok":true}'), $this->next());
            self::assertSame(['received' => ['ok' => true]], json_decode($response->content(), true), $method);
        }
    }

    public function testMalformedJsonReturns400WithoutReachingController(): void
    {
        $reached = false;
        $jsonify = new Jsonify();

        $response = $jsonify(self::request('POST', '{not valid'), static function (Request $r) use (&$reached): Response {
            $reached = true;

            return Response::text('ok');
        });

        self::assertFalse($reached);
        self::assertSame(400, $response->status());
    }

    public function testBodyExceedingTheSizeLimitReturns400(): void
    {
        $jsonify = new Jsonify(maxBodyBytes: 10);
        $response = $jsonify(self::request('POST', '{"padding":"' . str_repeat('x', 50) . '"}'), $this->next());

        self::assertSame(400, $response->status());
    }

    public function testNonJsonContentTypePassesThroughWhenNotRequired(): void
    {
        $reached = false;
        $jsonify = new Jsonify(requireJsonContentType: false);

        $jsonify(
            self::request('POST', 'name=Marshal', 'application/x-www-form-urlencoded'),
            static function (Request $r) use (&$reached): Response {
                $reached = true;

                return Response::text('ok');
            }
        );

        self::assertTrue($reached);
    }

    public function testNonJsonContentTypeReturns415WhenRequired(): void
    {
        $reached = false;
        $jsonify = new Jsonify(requireJsonContentType: true);

        $response = $jsonify(
            self::request('POST', 'name=Marshal', 'application/x-www-form-urlencoded'),
            static function (Request $r) use (&$reached): Response {
                $reached = true;

                return Response::text('ok');
            }
        );

        self::assertFalse($reached);
        self::assertSame(415, $response->status());
    }

    public function testAcceptsContentTypeWithCharsetParameter(): void
    {
        $jsonify = new Jsonify(requireJsonContentType: true);
        $response = $jsonify(self::request('POST', '{"a":1}', 'application/json; charset=utf-8'), $this->next());

        self::assertSame(['received' => ['a' => 1]], json_decode($response->content(), true));
    }

    public function testAcceptsJsonPlusSuffixVendorMediaTypes(): void
    {
        $jsonify = new Jsonify(requireJsonContentType: true);
        $response = $jsonify(self::request('POST', '{"a":1}', 'application/vnd.api+json'), $this->next());

        self::assertSame(['received' => ['a' => 1]], json_decode($response->content(), true));
    }

    public function testRequireObjectTopLevelRejectsAnArrayBody(): void
    {
        $jsonify = new Jsonify(requireObjectTopLevel: true);
        $response = $jsonify(self::request('POST', '[1,2,3]'), $this->next());

        self::assertSame(400, $response->status());
    }

    public function testRequireObjectTopLevelRejectsABareScalarBody(): void
    {
        $jsonify = new Jsonify(requireObjectTopLevel: true);
        $response = $jsonify(self::request('POST', '"hello"'), $this->next());

        self::assertSame(400, $response->status());
    }

    public function testRequireObjectTopLevelAllowsAnObjectBody(): void
    {
        $jsonify = new Jsonify(requireObjectTopLevel: true);
        $response = $jsonify(self::request('POST', '{"a":1}'), $this->next());

        self::assertSame(['received' => ['a' => 1]], json_decode($response->content(), true));
    }

    public function testRequireObjectTopLevelAllowsAnEmptyObjectBody(): void
    {
        $jsonify = new Jsonify(requireObjectTopLevel: true);
        $response = $jsonify(self::request('POST', '{}'), $this->next());

        self::assertSame(['received' => []], json_decode($response->content(), true));
    }

    public function testResponsesAreOverridableViaSubclass(): void
    {
        $jsonify = new class () extends Jsonify {
            protected function badRequestResponse(string $message): Response
            {
                return Response::text('custom-bad-request', 422);
            }
        };

        $response = $jsonify(self::request('POST', '{not valid'), $this->next());

        self::assertSame(422, $response->status());
        self::assertSame('custom-bad-request', $response->content());
    }

    /**
     * Exercises the real Route::dispatch() pipeline end to end, proving the
     * CrossRepoContracts.md §6 contract holds through the actual controller call, not
     * just a direct closure invocation.
     */
    public function testIntegratesWithRouteDispatchAndTheControllerSeesTheParsedBody(): void
    {
        Route::post('/widgets', fn (Request $request) => Response::json(['name' => $request->json('name')]))
            ->middleware([new Jsonify()]);

        $response = Route::dispatch(Request::fromArrays(
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/widgets', 'CONTENT_TYPE' => 'application/json'],
            rawBody: '{"name":"Widget"}',
        ));

        self::assertSame(['name' => 'Widget'], json_decode($response->content(), true));
    }
}
