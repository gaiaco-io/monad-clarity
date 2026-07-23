<?php

namespace Gaia\Clarity\Services;

use Closure;
use Gaia\Clarity\Services\Request;

/**
 * Route service class. The HTTP methods handler in this class respects the standard
 * definition of HTTP methods:
 * - POST: Storing resources on the server. All HTML forms MUST be submitted using the POST method.
 * - GET: Retrieving resources from the server.
 * - PUT: Updating resources on the server - NOT IMPLEMENTED AT THE MOMENT
 * - DELETE: Deleting resources from the server. - NOT IMPLEMENTED AT THE MOMENT
 *
 * The Route service class also handles URI parameters in the following structure:
 * - /user/{id}
 * - /user/{id}/{role_id}
 * - /user/{id}/role/{role_id}
 *
 * There are no imposed limits on the number of URI parameters. Each parameter is
 * uniquely identified -- hence; do not use duplicate parameter names -- and automatically
 * extracted from the URI and passed to the action callable.
 *
 * @package Gaia\Clarity\Services
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */

abstract class Route
{
    /**
     * Track if any route has been matched in this request.
     * 
     * @var bool
     */
    private static bool $routeMatched = false;

    /**
     * Check if a route was matched in this request.
     * 
     * @return bool
     */
    public static function hasMatched(): bool
    {
        return self::$routeMatched;
    }

    /**
     * Reset route matched flag (useful for testing).
     * 
     * @return void
     */
    public static function resetMatched(): void
    {
        self::$routeMatched = false;
    }

    /**
     * Handle POST requests.
     * 
     * @param string $uri The URI to handle.
     * @param array|callable|Closure $action The action to handle.
     * @return void
     */
    public static function post(string $uri, array|callable|Closure $action): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return; // Silently return if method doesn't match
        }

        // If a route has already been matched, don't process further routes
        if (self::$routeMatched) {
            return;
        }

        $parameters = self::extractUriParameters($uri, $_SERVER['REQUEST_URI']);

        if ($parameters !== null) {
            self::$routeMatched = true;
            (new Request())->assign($_POST);
            call_user_func_array($action, $parameters);
        }
    }

    /**
     * Handle GET requests.
     * 
     * @param string $uri The URI to handle.
     * @param array|callable|Closure $action The action to handle.
     * @return void
     */
    public static function get(string $uri, array|callable|Closure $action): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return; // Silently return if method doesn't match
        }

        // If a route has already been matched, don't process further routes
        if (self::$routeMatched) {
            return;
        }

        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $parameters = self::extractUriParameters($uri, $request_uri);

        if ($parameters !== null) {
            self::$routeMatched = true;
            try {
                call_user_func_array($action, $parameters);
            } catch (\Throwable $e) {
                throw $e;
            }
        }
    }

    /**
     * Handle PUT requests.
     * 
     * @param string $uri The URI to handle.
     * @param array|callable|Closure $action The action to handle.
     * @return void
     */
    public static function put(string $uri, array|callable|Closure $action): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            return; // Silently return if method doesn't match
        }

        // If a route has already been matched, don't process further routes
        if (self::$routeMatched) {
            return;
        }

        $parameters = self::extractUriParameters($uri, $_SERVER['REQUEST_URI']);

        if ($parameters !== null) {
            self::$routeMatched = true;
            call_user_func_array($action, $parameters);
        }
    }

    /**
     * Handle DELETE requests.
     * 
     * @param string $uri The URI to handle.
     * @param array|callable|Closure $action The action to handle.
     * @return void
     */
    public static function delete(string $uri, array|callable|Closure $action): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            return; // Silently return if method doesn't match
        }

        // If a route has already been matched, don't process further routes
        if (self::$routeMatched) {
            return;
        }

        $parameters = self::extractUriParameters($uri, $_SERVER['REQUEST_URI']);

        if ($parameters !== null) {
            self::$routeMatched = true;
            call_user_func_array($action, $parameters);
        }
    }

    /**
     * Sanitize URI parameter value
     *
     * @param string $value The parameter value to sanitize.
     * @return string The sanitized parameter value.
     */
    private static function sanitizeUriParameter(string $value): string
    {
        // Remove any potentially dangerous characters, keep alphanumeric, hyphens, underscores, and dots
        // This prevents path traversal and injection attacks
        return preg_replace('/[^a-zA-Z0-9\-_.]/', '', $value);
    }

    /**
     * Extracts URI parameters from the given URI string.
     *
     * @param string $pattern The URI pattern to match against.
     * @param string $request_uri The request URI to extract parameters from.
     * @return array|null An array of URI parameter values, or null if the pattern does not match.
     */
    private static function extractUriParameters(string $pattern, string $request_uri): ?array
    {
        $pattern = trim($pattern, '/');
        $request_uri = trim(strtok($request_uri, '?'), '/');

        $pattern_parts = $pattern === '' ? [] : explode('/', $pattern);
        $uri_parts = $request_uri === '' ? [] : explode('/', $request_uri);

        // Determine the minimum number of URI segments required (non-optional parts)
        $required_segments = 0;
        foreach ($pattern_parts as $part) {
            if (preg_match('/^\{(.+)\}$/', $part, $matches)) {
                $param = $matches[1];
                $is_optional = substr($param, -1) === '?';
                if (!$is_optional) {
                    $required_segments++;
                }
            } else {
                $required_segments++;
            }
        }

        if (count($uri_parts) < $required_segments || count($uri_parts) > count($pattern_parts)) {
            return null;
        }

        $parameters = [];
        $uri_index = 0;

        foreach ($pattern_parts as $part) {
            if (preg_match('/^\{(.+)\}$/', $part, $matches)) {
                $param = $matches[1];
                $is_optional = substr($param, -1) === '?';

                if (isset($uri_parts[$uri_index])) {
                    $parameters[] = self::sanitizeUriParameter($uri_parts[$uri_index]);
                    $uri_index++;
                } elseif ($is_optional) {
                    $parameters[] = null;
                } else {
                    return null;
                }
            } else {
                if (!isset($uri_parts[$uri_index]) || $part !== $uri_parts[$uri_index]) {
                    return null;
                }
                $uri_index++;
            }
        }

        if ($uri_index !== count($uri_parts)) {
            return null;
        }

        return $parameters;
    }
}
