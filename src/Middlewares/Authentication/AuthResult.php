<?php

declare(strict_types=1);

namespace Monad\Clarity\Middlewares\Authentication;

/**
 * The outcome of Authentication::attempt()/login(). An immutable value object rather
 * than a bool: a failed attempt carries a reason (rate-limited, locked, invalid
 * credentials — Middlewares\Authentication's FAILURE_* constants), and a successful one
 * carries the new session plus whether the stored password hash should be upgraded.
 *
 * @package Monad\Clarity\Middlewares\Authentication
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class AuthResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?string $userId = null,
        public readonly ?string $sessionId = null,
        public readonly ?string $sessionToken = null,
        public readonly ?string $rememberToken = null,
        public readonly bool $needsRehash = false,
        public readonly ?string $failureReason = null,
    ) {
    }

    public static function success(
        string $userId,
        string $sessionId,
        string $sessionToken,
        bool $needsRehash = false,
    ): self {
        return new self(true, $userId, $sessionId, $sessionToken, needsRehash: $needsRehash);
    }

    public static function failure(string $reason): self
    {
        return new self(false, failureReason: $reason);
    }

    /**
     * A copy carrying a freshly issued/rotated remember-me token, for
     * Authentication::resumeFromRememberToken() to attach without a wider constructor.
     */
    public function withRememberToken(string $rememberToken): self
    {
        return new self(
            $this->success,
            $this->userId,
            $this->sessionId,
            $this->sessionToken,
            $rememberToken,
            $this->needsRehash,
            $this->failureReason,
        );
    }
}
