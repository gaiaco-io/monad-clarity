<?php

declare(strict_types=1);

namespace Gaia\Clarity\Middlewares;

use Gaia\Clarity\Services\Request;
use Gaia\Clarity\Services\Response;
use JsonException;

/**
 * Parses a JSON request body into Request's separate JSON data bag before the
 * controller sees it (ReleaseNotes_26.07.md §31). Full contract with
 * Services\Request::json() is CrossRepoContracts.md §6: behaviour must be
 * indistinguishable to the caller whether or not Jsonify ran, except that Jsonify
 * additionally enforces media-type detection, a body-size limit, structured 400s on
 * parse failure, and 415 for JSON-required routes — so this uses the exact same
 * json_decode() flags/defaults Request's own lazy path does (JSON_THROW_ON_ERROR,
 * JSON_BIGINT_AS_STRING, associative arrays, a 512-deep default) rather than a second,
 * independently-drifting set of choices.
 *
 * Not limited to POST (§31.2.4) — any body-carrying method is parsed.
 *
 * Not `final` — per CrossRepoContracts.md §5, extended by `app/middlewares/` with a
 * zero-argument constructor supplying the app's actual config. `badRequestResponse()`
 * and `unsupportedMediaTypeResponse()` are `protected` extension points.
 *
 * @package Gaia\Clarity\Middlewares
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
class Jsonify
{
    private const BODY_CARRYING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];
    private const DEFAULT_MAX_DEPTH = 512;
    private const DEFAULT_MAX_BODY_BYTES = 1_048_576;

    /**
     * @param bool $requireJsonContentType When true, a body-carrying request whose
     *     Content-Type isn't JSON gets a 415 (§31.2.13 — "JSON-required routes"). When
     *     false (default), a non-JSON body is simply left unparsed, matching how a
     *     route serving both JSON and form-encoded submissions would want to behave.
     * @param bool $requireObjectTopLevel Application API profiles that want to
     *     restrict bodies to `{...}` rather than accepting any JSON value (§31.2.3).
     */
    public function __construct(
        private readonly bool $requireJsonContentType = false,
        private readonly bool $requireObjectTopLevel = false,
        private readonly int $maxBodyBytes = self::DEFAULT_MAX_BODY_BYTES,
        private readonly int $maxDepth = self::DEFAULT_MAX_DEPTH,
    ) {
    }

    public function __invoke(Request $request, callable $next): Response
    {
        if (!in_array($request->method(), self::BODY_CARRYING_METHODS, true)) {
            return $next($request);
        }

        $rawBody = $request->rawBody();

        if (trim($rawBody) === '') {
            return $next($request);
        }

        if (!$this->isJsonMediaType($request)) {
            return $this->requireJsonContentType ? $this->unsupportedMediaTypeResponse() : $next($request);
        }

        if (strlen($rawBody) > $this->maxBodyBytes) {
            return $this->badRequestResponse('Request body exceeds the maximum allowed size.');
        }

        try {
            $decoded = json_decode(
                $rawBody,
                associative: true,
                depth: $this->maxDepth,
                flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
            );
        } catch (JsonException $e) {
            return $this->badRequestResponse('Request body is not valid JSON: ' . $e->getMessage());
        }

        if ($this->requireObjectTopLevel && !self::isJsonObjectShaped($rawBody, $decoded)) {
            return $this->badRequestResponse('Request body must be a JSON object.');
        }

        return $next($request->withJsonBag($decoded));
    }

    protected function isJsonMediaType(Request $request): bool
    {
        // Media types are case-insensitive per RFC 9110 §8.3.1 (e.g. a client sending
        // "Application/JSON" is conforming, not malformed).
        $contentType = strtolower($request->header('Content-Type') ?? '');

        return $contentType === 'application/json' || str_starts_with($contentType, 'application/json;')
            || (bool) preg_match('#^application/[\w.+-]+\+json(;|$)#', $contentType);
    }

    protected function badRequestResponse(string $message): Response
    {
        return Response::json(['error' => $message], 400);
    }

    protected function unsupportedMediaTypeResponse(): Response
    {
        return Response::json(['error' => 'This route requires a JSON request body.'], 415);
    }

    /**
     * A decoded PHP array is ambiguous — json_decode(..., associative: true) turns both
     * `{}`/`{"a":1}` (a JSON object) and `[]`/`[1,2]` (a JSON array) into a PHP array,
     * so the array shape alone can't tell them apart. The raw body's first
     * non-whitespace character can: only an object starts with `{`.
     */
    private static function isJsonObjectShaped(string $rawBody, mixed $decoded): bool
    {
        return is_array($decoded) && str_starts_with(ltrim($rawBody), '{');
    }
}
