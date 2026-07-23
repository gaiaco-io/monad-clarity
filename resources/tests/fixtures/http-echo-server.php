<?php

declare(strict_types=1);

/**
 * Minimal router for the PHP built-in server, used as a local fixture so HttpClientTest
 * never depends on the real internet. Started/stopped by HttpClientTest itself.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/echo') {
    $headers = [];

    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_')) {
            $headers[str_replace('_', '-', substr($key, 5))] = $value;
        }
    }

    header('Content-Type: application/json');
    echo json_encode([
        'method' => $_SERVER['REQUEST_METHOD'],
        'headers' => $headers,
        'body' => file_get_contents('php://input'),
    ]);

    return;
}

if (preg_match('#^/status/(\d+)$#', (string) $path, $matches)) {
    http_response_code((int) $matches[1]);
    echo 'status-' . $matches[1];

    return;
}

if ($path === '/redirect') {
    header('Location: /redirected', true, 302);

    return;
}

if ($path === '/redirected') {
    echo 'redirected';

    return;
}

if ($path === '/slow') {
    sleep(2);
    echo 'slow-response';

    return;
}

http_response_code(404);
echo 'not-found';
