<?php

namespace Gaia\Clarity\Services;

use Gaia\Clarity\Services\Mediator;

/**
 * Handles HTTP response from the model through the controller. All data response shall
 * be formatted as JSON.
 *
 * @package Gaia\Clarity\Services
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */

abstract class Response
{
    /**
     * Return a JSON response.
     * 
     * @example Responses in JSON shall be in the following format:
     * {
     *      "status": 200,
     *      "message": "Success",
     *      "data": {
     *          [array of data]
     *      }
     * }
     * 
     * @param string $status_code
     * @param string $status_message
     * @param array $data
     * @return void
     */
    public static function json(string $status_code = '200', string $status_message = 'Success', ?array $data = []): void
    {
        http_response_code($status_code);
        header('Content-Type: application/json');
        echo self::jsonResponse($data, $status_code, $status_message);
        exit;
    }

    /**
     * Return a response telling the browser to handle file download.
     * 
     * @param string $file_path The path to the file to be downloaded.
     * @param string $file_name The name of the file to be downloaded.
     * @param bool $delete_after_download Whether to delete the file after it has been downloaded.
     * @return void
     */
    public static function download(string $file_path, string $file_name, bool $delete_after_download = false): void
    {
        if (is_file($file_path . $file_name)) {
            header('Content-Disposition: attachment; filename="' . addcslashes($file_name, '"\\') . '"');
            header('Content-Type: application/octet-stream');
            header('Content-Length: ' . filesize($file_path . $file_name));
            readfile($file_path . $file_name);

            if ($delete_after_download) {
                unlink($file_path . $file_name);
            }
        } else {
            Mediator::handleUserMessage('File not found');
        }
    }

    /**
     * Validate redirect URL to prevent open redirect vulnerabilities
     *
     * @param string $url The URL to validate.
     * @return bool True if valid, false otherwise.
     */
    private static function isValidRedirectUrl(string $url): bool
    {
        // Allow relative URLs (starting with /)
        if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
            return true;
        }

        // Allow absolute URLs only if they match the current host
        $parsed = parse_url($url);
        if ($parsed === false) {
            return false;
        }

        // If no scheme/host, treat as relative
        if (!isset($parsed['scheme']) && !isset($parsed['host'])) {
            return strpos($url, '/') === 0;
        }

        // For absolute URLs, check if host matches current host
        $current_host = $_SERVER['HTTP_HOST'] ?? '';
        if (isset($parsed['host']) && $parsed['host'] === $current_host) {
            return true;
        }

        return false;
    }

    /**
     * Redirect to a given URL.
     * 
     * @param string $url The URL to redirect to.
     * @return void
     */
    public static function redirect(string $url): void
    {
        // Validate URL to prevent open redirect vulnerabilities
        if (!self::isValidRedirectUrl($url)) {
            Mediator::handleUserMessage('Invalid redirect URL');
            return;
        }

        http_response_code(302);
        header('Location: ' . $url);
        exit;
    }

    /**
     * Construct a JSON response
     * 
     * @param array $data The data to be passed to the JSON response.
     * @param string $status_code The status code of the response.
     * @param string $status_message The status message of the response.
     * @return string
     */
    private static function jsonResponse(array $data, string $status_code = '200', string $status_message = 'Success'): string
    {
        // Sanitize $data before passing it into the JSON (handles empty arrays fine)
        $data = self::sanitizeData($data);

        $json_response = [
            'status' => $status_code,
            'message' => $status_message,
            'data' => $data
        ];

        return json_encode($json_response);
    }

    /**
     * Sanitize the data before passing it into the JSON response.
     * 
     * @param array $data The data to be sanitized.
     * @return array
     */
    private static function sanitizeData(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $sanitized[$key] = htmlspecialchars(stripslashes(trim($value)), ENT_QUOTES, 'UTF-8');
            } elseif (is_array($value)) {
                $sanitized[$key] = self::sanitizeData($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
