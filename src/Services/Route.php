<?php

declare(strict_types=1);

namespace Monad\Clarity\Services;

use InvalidArgumentException;

/**
 * Route registration and dispatch. Registration (get/post/.../group) builds a table;
 * dispatch() matches the current request against that table exactly once, which is what
 * makes a real 404-vs-405 distinction possible (§22.2) — a match-and-call-immediately
 * router structurally cannot tell "no route matched this path" from "a route matched
 * this path but not this method," because it never sees the routes it didn't try yet.
 *
 * Named parameters: `{id}` (any non-slash segment), `{id:int}` (`\d+`), `{slug:alpha}`
 * (`[A-Za-z]+`), `{id:uuid}`, and `{name?}` / `{id:int?}` for optional. `->where()`
 * overrides the pattern for a specific parameter.
 *
 * @package Monad\Clarity\Services
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class Route
{
    private const TYPE_PATTERNS = [
        'int' => '\d+',
        'alpha' => '[A-Za-z]+',
        'uuid' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}',
    ];

    private const DEFAULT_PATTERN = '[^/]+';

    /**
     * @var list<array{
     *     method: string,
     *     pattern: string,
     *     action: callable|array,
     *     name: ?string,
     *     middleware: list<string|callable>,
     *     wheres: array<string, string>,
     * }>
     */
    private static array $routes = [];

    /** @var list<array{prefix: string, middleware: list<string|callable>}> */
    private static array $groupStack = [];

    /** @var callable|array|null */
    private static $fallback = null;

    private function __construct(private readonly int $index)
    {
    }

    public static function get(string $uri, callable|array $action): self
    {
        return self::register('GET', $uri, $action);
    }

    public static function post(string $uri, callable|array $action): self
    {
        return self::register('POST', $uri, $action);
    }

    public static function put(string $uri, callable|array $action): self
    {
        return self::register('PUT', $uri, $action);
    }

    public static function patch(string $uri, callable|array $action): self
    {
        return self::register('PATCH', $uri, $action);
    }

    public static function delete(string $uri, callable|array $action): self
    {
        return self::register('DELETE', $uri, $action);
    }

    public static function options(string $uri, callable|array $action): self
    {
        return self::register('OPTIONS', $uri, $action);
    }

    /**
     * @param array{prefix?: string, middleware?: string|callable|list<string|callable>} $attributes
     */
    public static function group(array $attributes, callable $callback): void
    {
        $parentPrefix = self::$groupStack === [] ? '' : end(self::$groupStack)['prefix'];
        $parentMiddleware = self::$groupStack === [] ? [] : end(self::$groupStack)['middleware'];

        self::$groupStack[] = [
            'prefix' => rtrim($parentPrefix . '/' . trim((string) ($attributes['prefix'] ?? ''), '/'), '/'),
            'middleware' => [...$parentMiddleware, ...self::normalizeMiddleware($attributes['middleware'] ?? [])],
        ];

        try {
            $callback();
        } finally {
            array_pop(self::$groupStack);
        }
    }

    public static function fallback(callable|array $action): void
    {
        self::$fallback = $action;
    }

    public function name(string $name): self
    {
        self::$routes[$this->index]['name'] = $name;

        return $this;
    }

    public function middleware(string|callable|array $middleware): self
    {
        self::$routes[$this->index]['middleware'] = [
            ...self::$routes[$this->index]['middleware'],
            ...self::normalizeMiddleware($middleware),
        ];

        return $this;
    }

    /**
     * Normalise a single string/callable or a list of either into always a list.
     * Deliberately not a plain `(array)` cast: casting a Closure to array happens to
     * wrap it as a single-element list, but casting a plain invokable *object*
     * (anything with `__invoke()` that isn't a Closure) instead extracts its
     * properties — silently producing an empty array for an object with none, which
     * would drop the middleware entirely rather than registering it.
     *
     * @return list<string|callable>
     */
    private static function normalizeMiddleware(mixed $middleware): array
    {
        return is_array($middleware) ? $middleware : [$middleware];
    }

    public function where(string $parameter, string $pattern): self
    {
        self::$routes[$this->index]['wheres'][$parameter] = $pattern;

        return $this;
    }

    /**
     * Match $request against the registered routes and invoke the winning action (running
     * its middleware pipeline first). Returns 405 if some route matched the path but not
     * the method, 404 if no route matched the path at all (falling back to fallback() if set).
     */
    public static function dispatch(Request $request): Response
    {
        $path = $request->path();
        $method = $request->method();
        $pathMatchedSomeMethod = false;

        foreach (self::$routes as $route) {
            $parameters = self::matchPattern($route['pattern'], $route['wheres'], $path);

            if ($parameters === null) {
                continue;
            }

            $pathMatchedSomeMethod = true;

            if ($route['method'] !== $method) {
                continue;
            }

            return self::runPipeline(
                $route['middleware'],
                $request,
                function (Request $request) use ($route, $parameters) {
                    return self::toResponse(
                        self::invoke($route['action'], [...array_values($parameters), $request])
                    );
                }
            );
        }

        if ($pathMatchedSomeMethod) {
            return Response::text('Method Not Allowed', 405);
        }

        if (self::$fallback !== null) {
            return self::toResponse(self::invoke(self::$fallback, [$request]));
        }

        return Response::text('Not Found', 404);
    }

    /**
     * Remove every registered route, group, and fallback. For test isolation between
     * requests — registration is otherwise process-lifetime static state.
     */
    public static function reset(): void
    {
        self::$routes = [];
        self::$groupStack = [];
        self::$fallback = null;
    }

    private static function register(string $method, string $uri, callable|array $action): self
    {
        $group = self::$groupStack === [] ? ['prefix' => '', 'middleware' => []] : end(self::$groupStack);
        $pattern = rtrim($group['prefix'] . '/' . trim($uri, '/'), '/');

        self::$routes[] = [
            'method' => $method,
            'pattern' => $pattern === '' ? '/' : $pattern,
            'action' => $action,
            'name' => null,
            'middleware' => $group['middleware'],
            'wheres' => [],
        ];

        return new self(array_key_last(self::$routes));
    }

    /**
     * @param array<string, string> $wheres
     * @return array<string, string>|null Matched parameter values keyed by name, or null.
     */
    private static function matchPattern(string $pattern, array $wheres, string $path): ?array
    {
        $regex = self::compilePattern($pattern, $wheres);

        // PREG_UNMATCHED_AS_NULL guarantees every named group is present in $matches —
        // including an optional parameter that didn't participate — as null rather than
        // being silently omitted. Without it, a missing trailing optional parameter would
        // shift every argument after it (including the trailing Request) out of position.
        if (preg_match($regex, $path, $matches, PREG_UNMATCHED_AS_NULL) !== 1) {
            return null;
        }

        $parameters = [];

        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $parameters[$key] = $value;
            }
        }

        return $parameters;
    }

    /**
     * The separating slash before an optional segment must be part of that segment's own
     * optional group — otherwise the pattern still demands the same number of slashes as
     * segments, and a path that legitimately omits a trailing optional parameter (fewer
     * slashes) simply never matches at all.
     *
     * @param array<string, string> $wheres
     */
    private static function compilePattern(string $pattern, array $wheres): string
    {
        $segments = $pattern === '/' ? [] : explode('/', trim($pattern, '/'));
        $regex = '';

        foreach ($segments as $segment) {
            [$optional, $segmentRegex] = self::compileSegment($segment, $wheres);
            $regex .= $optional ? '(?:/' . $segmentRegex . ')?' : '/' . $segmentRegex;
        }

        return '#^' . ($regex === '' ? '/' : $regex) . '$#';
    }

    /**
     * @param array<string, string> $wheres
     * @return array{0: bool, 1: string} Whether the segment is optional, and its regex.
     */
    private static function compileSegment(string $segment, array $wheres): array
    {
        if (!preg_match('/^\{(.+)\}$/', $segment, $matches)) {
            return [false, preg_quote($segment, '#')];
        }

        $definition = $matches[1];
        $optional = str_ends_with($definition, '?');
        $definition = $optional ? substr($definition, 0, -1) : $definition;

        [$name, $type] = str_contains($definition, ':')
            ? explode(':', $definition, 2)
            : [$definition, null];

        $constraint = $wheres[$name] ?? ($type !== null ? self::TYPE_PATTERNS[$type] ?? null : null) ?? self::DEFAULT_PATTERN;

        return [$optional, '(?P<' . $name . '>' . $constraint . ')'];
    }

    /**
     * @param callable|array $action
     * @return Response|array|string
     */
    private static function invoke(callable|array $action, array $arguments): mixed
    {
        if (!is_callable($action)) {
            throw new InvalidArgumentException('Route action is not callable.');
        }

        return call_user_func_array($action, $arguments);
    }

    /**
     * Build the middleware chain innermost-out, so the last middleware registered runs
     * closest to $destination. Every layer — including $destination — receives whatever
     * Request the layer before it passed to $next(), so a middleware that calls
     * $next($request->withSomething(...)) actually reaches the controller.
     *
     * A middleware entry is either a class-string (instantiated with no constructor
     * arguments — the reason Csrf/RateLimiter/Authentication/RBAC are meant to be
     * registered via a thin `app/middlewares/` subclass supplying their real config, per
     * CrossRepoContracts.md §5) or an already-callable value (a closure or invokable
     * object) used as-is — e.g. RBAC::guard()'s return value, which closes over its own
     * configuration and has no zero-argument form to be instantiated from a string.
     *
     * @param list<string|callable> $middlewareNames
     */
    private static function runPipeline(array $middlewareNames, Request $request, callable $destination): Response
    {
        $pipeline = array_reduce(
            array_reverse($middlewareNames),
            static function (callable $next, string|callable $middlewareName): callable {
                return static function (Request $request) use ($middlewareName, $next): Response {
                    $middleware = is_callable($middlewareName) ? $middlewareName : new $middlewareName();

                    return $middleware($request, $next);
                };
            },
            static fn (Request $request): Response => $destination($request)
        );

        return $pipeline($request);
    }

    private static function toResponse(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if (is_array($result)) {
            return Response::json($result);
        }

        throw new InvalidArgumentException(
            sprintf('Route action must return a Response or array, got %s.', get_debug_type($result))
        );
    }
}
