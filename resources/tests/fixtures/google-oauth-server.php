<?php

declare(strict_types=1);

/**
 * Fake Google OAuth2 endpoints for AuthenticationTest's Google SSO coverage, so it never
 * depends on real Google infrastructure or credentials — same pattern as
 * HttpClientTest's own local-server fixture. The `code` sent to /o/oauth2/token
 * deterministically selects a scripted id_token (itself just base64url(json(claims)),
 * not a real signed JWT — this fixture stands in for Google's own signature
 * verification, which AuthenticationTest does not re-implement or need to).
 */

function base64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

const CLIENT_ID = 'test-client-id';

$scenarios = [
    'valid-code' => [
        'sub' => 'google-user-1',
        'email' => 'user@example.com',
        'email_verified' => 'true',
        'aud' => CLIENT_ID,
        'iss' => 'https://accounts.google.com',
        'exp' => time() + 3600,
        'name' => 'Test User',
    ],
    'unverified-email-code' => [
        'sub' => 'google-user-2',
        'email' => 'unverified@example.com',
        'email_verified' => 'false',
        'aud' => CLIENT_ID,
        'iss' => 'https://accounts.google.com',
        'exp' => time() + 3600,
        'name' => 'Unverified User',
    ],
    'wrong-audience-code' => [
        'sub' => 'google-user-3',
        'email' => 'user@example.com',
        'email_verified' => 'true',
        'aud' => 'someone-elses-client-id',
        'iss' => 'https://accounts.google.com',
        'exp' => time() + 3600,
    ],
    'bad-issuer-code' => [
        'sub' => 'google-user-4',
        'email' => 'user@example.com',
        'email_verified' => 'true',
        'aud' => CLIENT_ID,
        'iss' => 'https://evil.example',
        'exp' => time() + 3600,
    ],
    'expired-code' => [
        'sub' => 'google-user-5',
        'email' => 'user@example.com',
        'email_verified' => 'true',
        'aud' => CLIENT_ID,
        'iss' => 'https://accounts.google.com',
        'exp' => time() - 100,
    ],
];

if ($path === '/o/oauth2/token') {
    parse_str((string) file_get_contents('php://input'), $body);
    $code = $body['code'] ?? '';

    if ($code === 'invalid-code') {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_grant']);

        return;
    }

    if ($code === 'no-id-token-code') {
        header('Content-Type: application/json');
        echo json_encode(['access_token' => 'fake-access-token', 'expires_in' => 3600]);

        return;
    }

    if (!isset($scenarios[$code])) {
        http_response_code(400);
        echo json_encode(['error' => 'unknown_scenario']);

        return;
    }

    header('Content-Type: application/json');
    echo json_encode([
        'id_token' => base64UrlEncode(json_encode($scenarios[$code], JSON_THROW_ON_ERROR)),
        'access_token' => 'fake-access-token',
        'expires_in' => 3600,
    ]);

    return;
}

if ($path === '/oauth2/v3/tokeninfo') {
    $idToken = $_GET['id_token'] ?? '';
    $decoded = base64_decode(strtr($idToken, '-_', '+/'), true);
    $claims = $decoded !== false ? json_decode($decoded, true) : null;

    if (!is_array($claims)) {
        http_response_code(400);
        echo json_encode(['error_description' => 'Invalid Value']);

        return;
    }

    header('Content-Type: application/json');
    echo json_encode($claims);

    return;
}

http_response_code(404);
echo 'not-found';
