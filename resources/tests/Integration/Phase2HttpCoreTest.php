<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Integration;

use Monad\Clarity\Services\Mediator;
use Monad\Clarity\Services\Request;
use Monad\Clarity\Services\Response;
use Monad\Clarity\Services\Route;
use Monad\Clarity\Services\View;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Phase 2 exit criteria (GapAnalysis_BuildPlan_26.07.md): "a request can be routed
 * through middleware to a controller, rendered via View or returned as JSON, with dev
 * and prod exception rendering working end to end under the built-in server." Per-class
 * unit suites (RequestTest, ResponseTest, RouteTest, ViewTest, MediatorTest) each pass in
 * isolation without proving the seam between them is wired correctly — this does that,
 * by explicitly running the same dispatch-then-catch glue a real public/index.php would.
 */
final class Phase2HttpCoreTest extends TestCase
{
    #[After]
    public function resetSharedState(): void
    {
        Route::reset();
        View::reset();
        Mediator::reset();
    }

    private static function dispatch(Request $request): Response
    {
        try {
            return Route::dispatch($request);
        } catch (Throwable $exception) {
            return Mediator::handleException($exception, $request);
        }
    }

    public function testRequestRoutedThroughMiddlewareToControllerRenderedViaView(): void
    {
        View::configure(__DIR__ . '/../fixtures/views');

        $order = [];

        Route::get('/greet/{name}', function (string $name) {
            return View::render('hello', ['name' => $name]);
        })->middleware(RecordingMiddleware::class);

        RecordingMiddleware::$order = &$order;

        $request = Request::fromArrays(server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/greet/Marshal']);
        $response = self::dispatch($request);

        self::assertSame(['middleware-ran'], $order);
        self::assertSame(200, $response->status());
        self::assertSame('text/html; charset=utf-8', $response->header('Content-Type'));
        self::assertStringContainsString('Hello, Marshal!', $response->content());
    }

    public function testRequestRoutedToControllerReturningArrayBecomesJson(): void
    {
        Route::get('/users/{id:int}', fn (string $id) => ['id' => (int) $id, 'name' => 'Marshal']);

        $request = Request::fromArrays(server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/users/7']);
        $response = self::dispatch($request);

        self::assertSame('application/json', $response->header('Content-Type'));
        self::assertSame(['id' => 7, 'name' => 'Marshal'], json_decode($response->content(), true));
    }

    public function testUnhandledControllerExceptionRendersViaMediatorInDevMode(): void
    {
        Mediator::configure(debug: true);

        Route::get('/boom', function () {
            throw new RuntimeException('controller exploded');
        });

        $request = Request::fromArrays(server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/boom']);
        $response = self::dispatch($request);

        self::assertSame(500, $response->status());
        self::assertStringContainsString('controller exploded', $response->content());
        self::assertStringContainsString('GET /boom', $response->content());
    }

    public function testUnhandledControllerExceptionRendersViaMediatorInProdMode(): void
    {
        Mediator::configure(debug: false);

        Route::get('/boom', function () {
            throw new RuntimeException('leaked db credentials: hunter2');
        });

        $request = Request::fromArrays(server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/boom']);
        $response = self::dispatch($request);

        self::assertSame(500, $response->status());
        self::assertStringNotContainsString('hunter2', $response->content());

        $data = json_decode($response->content(), true);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $data['incident_id']);
    }

    public function testNotFoundAndMethodNotAllowedFlowThroughTheSameDispatchPath(): void
    {
        Route::post('/orders', fn () => Response::text('created'));

        $wrongMethod = self::dispatch(
            Request::fromArrays(server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/orders'])
        );
        $noRoute = self::dispatch(
            Request::fromArrays(server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/nope'])
        );

        self::assertSame(405, $wrongMethod->status());
        self::assertSame(404, $noRoute->status());
    }
}

final class RecordingMiddleware
{
    /** @var list<string>|null */
    public static ?array $order = null;

    public function __invoke(Request $request, callable $next): Response
    {
        self::$order[] = 'middleware-ran';

        return $next($request);
    }
}
