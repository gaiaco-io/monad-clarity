<?php

namespace Gaia\Clarity\Services;

use PDOException;
use Gaia\Clarity\Services\Mediator;
use Gaia\Clarity\Services\DB;

/**
 * Custom session management service to managing persistent session storage.
 *
 * @package Gaia\Clarity\Services
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */

final class Session
{
    public function start(mixed $user_id, array $payload = []): Session
    {
        $token = $this->setToken();
        $session_data = [
            'user_id' => $user_id,
            'digest' => hash('sha256', $token),
            'ip_address' => $_SERVER['REMOTE_ADDR'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT'],
            'payload' => json_encode($payload)
        ];

        DB::insert('sessions', $session_data, DB::ID_TYPE_UUID, 'session');

        // Set secure flag only if HTTPS is being used
        $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

        // Get cookie domain from environment (e.g., .gaiaco.io for subdomain SSO)
        $cookie_domain = getenv('COOKIE_DOMAIN') ?: null;

        $cookie_options = [
            'expires' => 0,
            'path' => '/',
            'httponly' => true,
            'secure' => $is_https,
            'samesite' => 'Lax'
        ];

        // Only set domain if configured (allows subdomain SSO)
        if ($cookie_domain !== null) {
            $cookie_options['domain'] = $cookie_domain;
        }

        setcookie('mid', $token, $cookie_options);
        return $this;
    }

    /**
     * Write a new value into the session payload.
     */
    public function write(string $key, mixed $value): void
    {
        $id = self::getSessionIdByToken();
        if ($id === null) {
            return;
        }
        $sql = 'SELECT payload FROM sessions WHERE id = :id;';
        $result = DB::run($sql, ['id' => $id], 'session')->fetch();
        if ($result === false) {
            return;
        }
        $payload = json_decode($result['payload'], true);
        $payload[$key] = trim($value);
        DB::update('sessions', ['payload' => json_encode($payload)], ['id' => $id], 'session');
    }

    public function readAll(): array
    {
        $id = self::getSessionIdByToken();
        if ($id === null) {
            return [];
        }
        $sql = 'SELECT * FROM sessions WHERE id = :id;';
        $result = DB::run($sql, ['id' => $id], 'session')->fetch();
        return $result !== false ? $result : [];
    }

    public function read(string $key): mixed
    {
        $id = self::getSessionIdByToken();
        if ($id === null) {
            return null;
        }
        $sql = 'SELECT payload FROM sessions WHERE id = :id;';
        $result = DB::run($sql, ['id' => $id], 'session')->fetch();
        if ($result === false) {
            return null;
        }
        $payload = json_decode($result['payload'], true);
        return $payload[$key] ?? null;
    }

    /**
     * Destroy the session.
     */
    public function destroy(): void
    {
        $id = self::getSessionIdByToken();
        if ($id !== null) {
            $sql = 'DELETE FROM sessions WHERE id = :id;';
            DB::run($sql, ['id' => $id], 'session');
        }
        session_unset();
        session_destroy();
        setcookie(session_name(), '', time() - 3600, '/');

        // Clear mid cookie with same domain settings as creation
        $cookie_domain = getenv('COOKIE_DOMAIN') ?: null;
        $cookie_options = [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true
        ];
        if ($cookie_domain !== null) {
            $cookie_options['domain'] = $cookie_domain;
        }
        setcookie('mid', '', $cookie_options);
    }

    /**
     * Check if a valid session exists.
     */
    public static function hasSession(): bool
    {
        $id = self::getSessionIdByToken();

        if ($id === null) {
            return false;
        }

        $sql = 'SELECT id FROM sessions WHERE id = :id;';
        $result = DB::run($sql, ['id' => $id], 'session')->fetch();
        return $result !== false;
    }

    /**
     * Create a MySQL DB table for persistent session storage. This method shall be
     * invoked through command line or as part of the monad's installation process.
     */
    public static function initSessionStorage(): void
    {
        $sql = 'SHOW TABLES LIKE "sessions";';
        $result = DB::run($sql, [], 'session')->fetch();

        try {
            if (empty($result)) {
                $sql = '
                    CREATE TABLE sessions (
                        id CHAR(36) PRIMARY KEY DEFAULT (UUID()),
                        created_at TIMESTAMP NOT NULL DEFAULT (UTC_TIMESTAMP()),
                        updated_at TIMESTAMP NOT NULL DEFAULT (UTC_TIMESTAMP()),
                        user_id CHAR(36) NOT NULL,
                        digest VARCHAR(255) NOT NULL,
                        ip_address VARCHAR(45) NOT NULL,
                        user_agent TEXT NOT NULL,
                        payload JSON NOT NULL
                    ) ENGINE=InnoDB;';
                DB::run($sql, [], 'session');
            }
        } catch (PDOException $e) {
            Mediator::handleException($e);
        }
    }

    private static function getSessionIdByToken(?string $token = null): mixed
    {
        if (empty($token)) {
            $token = $_COOKIE['mid'] ?? null;
        }

        if (empty($token)) {
            return null;
        }

        $sql = 'SELECT id FROM sessions WHERE digest = :digest;';
        $result = DB::run($sql, ['digest' => hash('sha256', $token)], 'session')->fetch();
        return $result !== false ? $result['id'] : null;
    }

    private static function setToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}
