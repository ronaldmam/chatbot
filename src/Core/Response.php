<?php
// src/Core/Response.php

namespace App\Core;

class Response
{
    public static function json(array $data, int $statusCode = 200): void
    {
        // Clean output buffers to prevent leakage of headers/warnings
        if (ob_get_level() > 0) {
            ob_clean();
        }
        
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    public static function text(string $content, int $statusCode = 200, string $contentType = 'text/plain'): void
    {
        if (ob_get_level() > 0) {
            ob_clean();
        }
        
        http_response_code($statusCode);
        header("Content-Type: {$contentType}; charset=utf-8");
        echo $content;
        exit;
    }
}
