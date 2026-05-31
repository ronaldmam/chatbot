<?php
// src/Core/Request.php

namespace App\Core;

class Request
{
    public static function getMethod(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD']);
    }

    public static function getBody(): array
    {
        $body = [];
        
        if (self::getMethod() === 'POST' || self::getMethod() === 'PUT' || self::getMethod() === 'DELETE') {
            // Check for JSON request body
            $contentType = $_SERVER["CONTENT_TYPE"] ?? '';
            if (strpos($contentType, 'application/json') !== false) {
                $input = file_get_contents('php://input');
                $body = json_decode($input, true) ?? [];
            } else {
                // Standard form inputs
                $body = $_POST;
            }
        }
        
        return $body;
    }

    public static function getQueryParams(): array
    {
        return $_GET;
    }

    public static function getHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$headerName] = $value;
            }
        }
        return $headers;
    }

    public static function getHeader(string $name): ?string
    {
        $headers = self::getHeaders();
        return $headers[$name] ?? $headers[strtolower($name)] ?? null;
    }

    public static function getBearerToken(): ?string
    {
        $authorization = self::getHeader('Authorization');
        if (!empty($authorization) && preg_match('/Bearer\s(\S+)/', $authorization, $matches)) {
            return $matches[1];
        }
        return null;
    }
}
