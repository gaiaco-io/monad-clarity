<?php

namespace Gaia\Clarity\Services;

/**
 * Handles incoming HTTP requests by parsing submitted form data and URL query parameters.
 *
 * @package Gaia\Clarity\Services
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */

final class Request
{
    private array $input = [];
    private array $request = [];

    /**
     * Construct a request object as an array containing the request data:
     *  - request line
     *  - request headers
     *  - request body
     */
    public function __construct()
    {
        $request_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $request_uri = $_SERVER['REQUEST_URI'] ?? '/';
        $server_protocol = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';

        $this->request = [
            'request_line' =>
            $request_method . ' ' .
                $request_uri .
                ' ' . $server_protocol,
            'headers' => [
                'Host' => $_SERVER['HTTP_HOST'] ?? '',
                'User-Agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'Accept' => $_SERVER['HTTP_ACCEPT'] ?? '',
                'Content-Type' => $_SERVER['CONTENT_TYPE'] ?? ''
            ],
            'body' =>
            array_map(function ($value) {
                return is_string($value) ? trim($value) : $value;
            }, $_POST),
            'query' =>
            array_map(function ($value) {
                return is_string($value) ? trim($value) : $value;
            }, $_GET)
        ];
    }

    /**
     * Sanitise the input data and insert it into the request object.
     */
    public function assign(array $input): void
    {
        foreach ($input as $key => $value) {
            if (is_string($value)) {
                $this->input[$key] = trim($value);
            } else {
                $this->input[$key] = $value;
            }
        }
    }

    /**
     * Retrieve a specific sanitised input field value if a field name is provided.
     * Else, return all sanitised input field values.
     */
    public function getAssignedData(?string $field_name = null): string|array|null
    {
        return $field_name ? $this->input[$field_name] ?? null : $this->input;
    }

    /**
     * Retrieve the HTTP request object
     */
    public function getRequest(): array
    {
        return $this->request;
    }

    /**
     * Retrieve part of the HTTP request object
     */
    public function getRequestPart(string $part): string|null
    {
        return $this->request[$part] ?? null;
    }

    /**
     * Retrieve the POST data field from the request object's body part
     */
    public function getPostData(string $field_name): string|null
    {
        return $this->request['body'][$field_name] ?? null;
    }

    /**
     * Retrieve the query string data value from the request object's query part
     */
    public function getQueryData(?string $field_name = null): string|null
    {
        return $field_name ? ($this->request['query'][$field_name] ?? null) : null;
    }
}
