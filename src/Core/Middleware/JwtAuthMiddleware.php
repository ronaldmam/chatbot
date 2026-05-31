<?php
// src/Core/Middleware/JwtAuthMiddleware.php

namespace App\Core\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Services\JwtService;

class JwtAuthMiddleware implements MiddlewareInterface
{
    public static ?array $currentUser = null;

    /**
     * Handle JWT auth verification. Intercepts request if unauthorized.
     * 
     * @return bool True if authorized, False to abort request.
     */
    public function handle(): bool
    {
        $token = Request::getBearerToken();
        
        if (empty($token)) {
            Response::json([
                'error' => 'Unauthorized',
                'message' => 'Missing Authorization Bearer token.'
            ], 401);
            return false;
        }

        $jwtService = new JwtService();
        $userData = $jwtService->validateToken($token);

        if ($userData === null) {
            Response::json([
                'error' => 'Unauthorized',
                'message' => 'Token has expired or is invalid.'
            ], 401);
            return false;
        }

        // Store user payload statically for controllers to fetch context (e.g. user id, role)
        self::$currentUser = $userData;
        return true;
    }

    /**
     * Helper to retrieve currently authenticated user details.
     * 
     * @return array|null Authenticated user data, or null
     */
    public static function getCurrentUser(): ?array
    {
        return self::$currentUser;
    }
}
