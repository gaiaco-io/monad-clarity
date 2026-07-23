<?php

declare(strict_types=1);

namespace Gaia\Clarity\Tests\Services;

use Gaia\Clarity\Services\Request;
use Gaia\Clarity\Services\Response;
use Gaia\Clarity\Services\Route;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

final class RouteTest extends TestCase
{
    #[After]
    public function resetRoutes(): void
    {
        Route::reset();
    }

    private static function request(string $method, string $path): Request
    {
        return Request::fromArrays(server: ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path]);
    }

    public function testMatchesGetRouteAndReturnsControllerResponse(): void
    {
        Route::get('/hello', fn () => Response::text('world'));

        $response = Route::dispatch(self::request('GET', '/hello'));

        self::assertSame(200, $response->status());
        self::assertSame('world', $response->content());
    }

    public function testArrayReturnValueIsConvertedToJson(): void
    {
        Route::get('/users', fn () => ['id' => 1]);

        $response = Route::dispatch(self::request('GET', '/users'));

        self::assertSame('application/json', $response->header('Content-Type'));
        self::assertSame(['id' => 1], json_decode($response->content(), true));
    }

    public function testRouteActionReceivesRequestAsLastArgument(): void
    {
        Route::get('/whoami', fn (Request $request) => Response::text($request->path()));

        $response = Route::dispatch(self::request('GET', '/whoami'));

        self::assertSame('/whoami', $response->content());
    }

    public function testUntypedParameterMatchesAnySegment(): void
    {
        Route::get('/user/{id}', fn (string $id) => Response::text("user-$id"));

        $response = Route::dispatch(self::request('GET', '/user/abc'));

        self::assertSame('user-abc', $response->content());
    }

    public function testTypedIntParameterRejectsNonDigits(): void
    {
        Route::get('/user/{id:int}', fn (string $id) => Response::text("user-$id"));

        self::assertSame(404, Route::dispatch(self::request('GET', '/user/abc'))->status());
        self::assertSame('user-42', Route::dispatch(self::request('GET', '/user/42'))->content());
    }

    public function testOptionalParameterOmittedLeavesTrailingArgumentsInPosition(): void
    {
        Route::get(
            '/user/{id}/{role?}',
            fn (string $id, ?string $role, Request $request) => Response::json([
                'id' => $id,
                'role' => $role,
                'path' => $request->path(),
            ])
        );

        $response = Route::dispatch(self::request('GET', '/user/5'));
        $data = json_decode($response->content(), true);

        self::assertSame('5', $data['id']);
        self::assertNull($data['role']);
        self::assertSame('/user/5', $data['path']);
    }

    public function testOptionalParameterPresent(): void
    {
        Route::get(
            '/user/{id}/{role?}',
            fn (string $id, ?string $role) => Response::json(['id' => $id, 'role' => $role])
        );

        $data = json_decode(Route::dispatch(self::request('GET', '/user/5/admin'))->content(), true);

        self::assertSame('admin', $data['role']);
    }

    public function testWhereOverridesParameterConstraint(): void
    {
        Route::get('/code/{value}', fn (string $value) => Response::text($value))->where('value', '[a-z]{3}');

        self::assertSame(404, Route::dispatch(self::request('GET', '/code/12'))->status());
        self::assertSame('abc', Route::dispatch(self::request('GET', '/code/abc'))->content());
    }

    public function testGroupAppliesPrefixToNestedRoutes(): void
    {
        Route::group(['prefix' => 'admin'], function () {
            Route::get('/dashboard', fn () => Response::text('admin-dashboard'));
        });

        self::assertSame(200, Route::dispatch(self::request('GET', '/admin/dashboard'))->status());
        self::assertSame(404, Route::dispatch(self::request('GET', '/dashboard'))->status());
    }

    public function testNestedGroupsCombinePrefixesAndMiddleware(): void
    {
        RecordingMiddlewareStub::$log = [];

        Route::group(['prefix' => 'api', 'middleware' => RecordingMiddlewareStub::class], function () {
            Route::group(['prefix' => 'v1'], function () {
                Route::get('/ping', fn () => Response::text('pong'));
            });
        });

        $response = Route::dispatch(self::request('GET', '/api/v1/ping'));

        self::assertSame(200, $response->status());
        self::assertSame('pong', $response->content());
        self::assertSame(['recorded'], RecordingMiddlewareStub::$log);
    }

    public function testMiddlewarePipelineRunsInRegistrationOrderAndCanShortCircuit(): void
    {
        LoggingMiddlewareStub::$log = [];

        Route::get('/protected', fn () => Response::text('controller'))
            ->middleware([LoggingMiddlewareStub::class, ShortCircuitMiddlewareStub::class]);

        $response = Route::dispatch(self::request('GET', '/protected'));

        // ShortCircuitMiddlewareStub runs second (registration order) and never calls
        // $next, so the controller and any middleware after it never execute.
        self::assertSame(['outer-before'], LoggingMiddlewareStub::$log);
        self::assertSame('short-circuited', $response->content());
    }

    public function testMiddlewareCanModifyRequestSeenByController(): void
    {
        Route::get('/echo-json', fn (Request $request) => Response::json(['from' => $request->json('from')]))
            ->middleware(InjectJsonBagMiddlewareStub::class);

        $response = Route::dispatch(self::request('GET', '/echo-json'));

        self::assertSame(['from' => 'middleware'], json_decode($response->content(), true));
    }

    public function testDistinguishes404FromMethodNotAllowed(): void
    {
        Route::post('/orders', fn () => Response::text('created'));

        self::assertSame(405, Route::dispatch(self::request('GET', '/orders'))->status());
        self::assertSame(404, Route::dispatch(self::request('GET', '/no-such-route'))->status());
    }

    public function testFallbackHandlesUnmatchedRoutes(): void
    {
        Route::fallback(fn (Request $request) => Response::text('fallback:' . $request->path(), 404));

        $response = Route::dispatch(self::request('GET', '/anything'));

        self::assertSame(404, $response->status());
        self::assertSame('fallback:/anything', $response->content());
    }

    public function testFirstMatchingRouteWins(): void
    {
        Route::get('/x', fn () => Response::text('first'));
        Route::get('/x', fn () => Response::text('second'));

        self::assertSame('first', Route::dispatch(self::request('GET', '/x'))->content());
    }

    public function testControllerMustReturnResponseOrArray(): void
    {
        Route::get('/bad', fn () => 'a plain string is not allowed');

        $this->expectException(InvalidArgumentException::class);

        Route::dispatch(self::request('GET', '/bad'));
    }
}

final class LoggingMiddlewareStub
{
    /** @var list<string> */
    public static array $log = [];

    public function __invoke(Request $request, callable $next): Response
    {
        self::$log[] = 'outer-before';

        return $next($request);
    }
}

final class ShortCircuitMiddlewareStub
{
    public function __invoke(Request $request, callable $next): Response
    {
        return Response::text('short-circuited');
    }
}

final class RecordingMiddlewareStub
{
    /** @var list<string> */
    public static array $log = [];

    public function __invoke(Request $request, callable $next): Response
    {
        self::$log[] = 'recorded';

        return $next($request);
    }
}

final class InjectJsonBagMiddlewareStub
{
    public function __invoke(Request $request, callable $next): Response
    {
        return $next($request->withJsonBag(['from' => 'middleware']));
    }
}
