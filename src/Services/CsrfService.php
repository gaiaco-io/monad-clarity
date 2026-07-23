<?php

namespace Gaia\Clarity\Services;

/**
 * CSRF protection service. Auto-selects storage strategy:
 * - Session payload when mid session is active
 * - Double-submit cookie when no session exists
 *
 * @package Gaia\Clarity\Services
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */

final class CsrfService
{
    private const PAYLOAD_KEY = 'csrf_token';
    private const FIELD_NAME = '_csrf';
    private const HEADER_NAME = 'X-CSRF-Token';
    private const COOKIE_NAME = 'csrf';
    private const TOKEN_BYTES = 32;

    /** @var string[] */
    private static array $exempt_uris = [];

    /**
     * Generate a new CSRF token and persist it.
     */
    public function generate(): string
    {
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));

        if (Session::hasSession()) {
            (new Session())->write(self::PAYLOAD_KEY, $token);
        } else {
            $this->setCookieToken($token);
        }

        return $token;
    }

    /**
     * Return the existing token or generate a new one.
     */
    public function getToken(): string
    {
        $stored = $this->getStoredToken();

        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        return $this->generate();
    }

    /**
     * Validate the submitted CSRF token.
     */
    public function validate(?string $submitted = null): bool
    {
        if (!$this->shouldValidate()) {
            return true;
        }

        $uri = $this->getRequestUri();
        if ($this->isExempt($uri)) {
            return true;
        }

        if (!$this->isSameOrigin()) {
            return false;
        }

        $submitted = $this->resolveSubmittedToken($submitted);
        $stored = $this->getStoredToken();

        if (!is_string($submitted) || $submitted === '' || !is_string($stored) || $stored === '') {
            return false;
        }

        return hash_equals($stored, $submitted);
    }

    /**
     * Enforce CSRF validation; fail closed on invalid token.
     */
    public function requireValid(bool $is_json = false): void
    {
        if (!$this->shouldValidate()) {
            return;
        }

        if ($this->isExempt($this->getRequestUri())) {
            return;
        }

        if (!$this->validate()) {
            if ($is_json) {
                Response::json('403', 'Forbidden');
            }

            Mediator::handleUserMessage('Invalid or expired form submission.');
        }
    }

    /**
     * Regenerate the CSRF token after a successful mutation.
     */
    public function rotate(): string
    {
        return $this->generate();
    }

    /**
     * Expose the form field name for views and JS.
     */
    public function getFieldName(): string
    {
        return self::FIELD_NAME;
    }

    /**
     * True for unsafe HTTP methods that require CSRF validation.
     */
    public function shouldValidate(): bool
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        return in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    /**
     * Check if a URI is exempt from CSRF validation.
     */
    public function isExempt(string $uri): bool
    {
        $path = parse_url($uri, PHP_URL_PATH) ?? $uri;
        $exempt = self::$exempt_uris;

        $env_exempt = getenv('CSRF_EXEMPT_URIS') ?: '';
        if ($env_exempt !== '') {
            $exempt = array_merge($exempt, array_map('trim', explode(',', $env_exempt)));
        }

        foreach ($exempt as $pattern) {
            if ($pattern === '') {
                continue;
            }

            if ($path === $pattern || str_starts_with($path, rtrim($pattern, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Data array for View::prepare() / View::set().
     *
     * @return array{csrf_token: string, csrf_field: string}
     */
    public function toViewData(): array
    {
        return [
            'csrf_token' => $this->getToken(),
            'csrf_field' => self::FIELD_NAME,
        ];
    }

    /**
     * HTML hidden input for forms.
     */
    public function field(): string
    {
        $token = htmlspecialchars($this->getToken(), ENT_QUOTES, 'UTF-8');

        return '<input type="hidden" name="' . self::FIELD_NAME . '" value="' . $token . '">';
    }

    /**
     * Meta tag attributes for JS AJAX setups.
     *
     * @return array{name: string, content: string}
     */
    public function meta(): array
    {
        return [
            'name' => 'csrf-token',
            'content' => $this->getToken(),
        ];
    }

    /**
     * Register URIs exempt from CSRF validation (e.g. webhooks, API routes).
     *
     * @param string[] $uris
     */
    public static function setExemptUris(array $uris): void
    {
        self::$exempt_uris = $uris;
    }

    /**
     * Read the stored token from session payload or cookie.
     */
    private function getStoredToken(): ?string
    {
        if (Session::hasSession()) {
            $token = (new Session())->read(self::PAYLOAD_KEY);

            return is_string($token) ? $token : null;
        }

        $token = $_COOKIE[self::COOKIE_NAME] ?? null;

        return is_string($token) ? $token : null;
    }

    /**
     * Resolve submitted token from argument, POST body, or header.
     */
    private function resolveSubmittedToken(?string $submitted): ?string
    {
        if ($submitted !== null) {
            return $submitted;
        }

        $request = new Request();
        $from_post = $request->getPostData(self::FIELD_NAME);

        if (is_string($from_post) && $from_post !== '') {
            return $from_post;
        }

        $from_header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

        return is_string($from_header) ? trim($from_header) : null;
    }

    /**
     * Set the CSRF cookie for guest (no-session) requests.
     */
    private function setCookieToken(string $token): void
    {
        $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

        $cookie_domain = getenv('COOKIE_DOMAIN') ?: null;
        $expires = time() + (APP['session_timeout'] ?? 1800);

        $cookie_options = [
            'expires' => $expires,
            'path' => '/',
            'httponly' => true,
            'secure' => $is_https,
            'samesite' => 'Lax',
        ];

        if ($cookie_domain !== null) {
            $cookie_options['domain'] = $cookie_domain;
        }

        setcookie(self::COOKIE_NAME, $token, $cookie_options);
    }

    /**
     * Defense-in-depth: reject cross-origin POST when Origin/Referer is present.
     */
    private function isSameOrigin(): bool
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';

        if ($host === '') {
            return true;
        }

        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        if (is_string($origin) && $origin !== '') {
            $origin_host = parse_url($origin, PHP_URL_HOST);

            return is_string($origin_host) && strcasecmp($origin_host, $host) === 0;
        }

        $referer = $_SERVER['HTTP_REFERER'] ?? null;
        if (is_string($referer) && $referer !== '') {
            $referer_host = parse_url($referer, PHP_URL_HOST);

            return is_string($referer_host) && strcasecmp($referer_host, $host) === 0;
        }

        return true;
    }

    /**
     * Normalise the request URI for exempt matching.
     */
    private function getRequestUri(): string
    {
        return $_SERVER['REQUEST_URI'] ?? '/';
    }
}
