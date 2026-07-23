<?php

declare(strict_types=1);

namespace Gaia\Clarity\Services;

use Gaia\Clarity\Services\LLM\LLMException;
use Gaia\Clarity\Services\LLM\LLMRequest;
use Gaia\Clarity\Services\LLM\LLMResponse;
use JsonException;
use Psr\Http\Message\ResponseInterface;

/**
 * Shared contract every provider adapter (`Services\LLMAdapters\*`) implements: one
 * complete() method translating a provider-agnostic LLMRequest to that provider's own
 * wire format and back into a provider-agnostic LLMResponse (ReleaseNotes §11.3 — the
 * ten-field contract, split across LLMRequest's seven request-side fields and
 * LLMResponse's six response-side fields, with `provider`/`model` echoed on the
 * response for provenance).
 *
 * No global registry or provider-name dispatch: an adapter is constructed directly with
 * its own credentials and an HttpClient — `new LLMAdapters\Anthropic(apiKey: ...,
 * httpClient: ...)` — the same instance-based shape as every other provider-pluggable
 * component built this phase (Files' S3 client, Cache's Redis handle). LLM has no natural
 * one-per-application-lifecycle instance the way DB/Session/Route do, so a static facade
 * with a global credential registry would be scaffolding this doesn't need.
 *
 * Deliberately excludes (§11.4): agents, tool orchestration, vector databases, memory,
 * prompt pipelines, automatic retries across providers.
 *
 * @package Gaia\Clarity\Services
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
abstract class LLM
{
    public function __construct(
        protected readonly string $apiKey,
        protected readonly HttpClient $httpClient,
    ) {
    }

    abstract public function complete(LLMRequest $request): LLMResponse;

    /**
     * The provider identifier echoed onto every LLMResponse this adapter returns (e.g.
     * 'anthropic') and used in this class's own error messages.
     */
    abstract protected function providerName(): string;

    /**
     * @throws LLMException if $response's status code isn't 2xx.
     */
    protected function assertSuccessful(ResponseInterface $response): void
    {
        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            throw new LLMException(sprintf(
                '%s request failed with HTTP %d: %s',
                $this->providerName(),
                $status,
                (string) $response->getBody()
            ));
        }
    }

    /**
     * @return array<string, mixed>
     * @throws LLMException if the body isn't valid JSON, or isn't a JSON object.
     */
    protected function decodeJsonBody(ResponseInterface $response): array
    {
        try {
            $decoded = json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new LLMException(
                sprintf('%s returned a response that was not valid JSON: %s', $this->providerName(), $e->getMessage()),
                previous: $e
            );
        }

        if (!is_array($decoded)) {
            throw new LLMException(sprintf('%s returned a JSON response whose top level was not an object.', $this->providerName()));
        }

        return $decoded;
    }
}
