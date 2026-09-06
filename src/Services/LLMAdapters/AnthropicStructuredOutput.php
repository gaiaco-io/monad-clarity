<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\LLMAdapters;

/**
 * Which wire mechanism `LLMAdapters\Anthropic` uses to satisfy `LLMRequest::$responseSchema`
 * (ReleaseNotes §11.3.8). Anthropic has two, neither of which reaches every model, so the
 * choice belongs to whoever knows which model the adapter is pointed at — see
 * `ReleaseNotes_1.8.0.md` §2.1.
 *
 * This is Anthropic's problem alone. `LLMAdapters\OpenAI`, `Gemini` and `DeepSeek` each have
 * exactly one mechanism, which is why this enum lives beside the adapter it configures rather
 * than in `Services\LLM\` among the value objects every adapter shares.
 *
 * @package Monad\Clarity\Services\LLMAdapters
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
enum AnthropicStructuredOutput
{
    /**
     * A synthetic tool whose `input_schema` is the caller's schema, with `tool_choice` forced
     * to it; the answer is read out of the resulting `tool_use` block. Anthropic's documented
     * pattern, it accepts any JSON Schema the caller can write, and it is refused
     * by the newest models, which reject `tool_choice` of type "tool" or "any".
     *
     * The default, because it is what every caller has been getting since 1.0.0.
     */
    case ForcedTool;

    /**
     * `output_config.format`, Anthropic's native schema-constrained decoding; the answer
     * arrives as ordinary JSON text. Required by the models that refuse the forced tool, absent
     * from several older ones that the forced tool still serves, and documented as accepting a
     * narrower range of JSON Schema — see `ReleaseNotes_1.8.0.md` §2.4.
     */
    case NativeSchema;
}
