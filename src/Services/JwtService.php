<?php
// src/Services/JwtService.php

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Exception;

class JwtService
{
    private string $secretKey;

    public function __construct()
    {
        // Use secret from config or a secure default
        $this->secretKey = defined('JWT_SECRET') ? JWT_SECRET : 'naldike_store_fallback_secret_key_2026!#$';
    }

    /**
     * Generate a new JWT token for a user.
     * 
     * @param array $userData Data to embed in the token (e.g. ['id' => 1, 'username' => 'admin', 'role' => 'admin'])
     * @param int $expirySeconds Token expiration in seconds (default 7 days)
     * @return string Generated JWT
     */
    public function generateToken(array $userData, int $expirySeconds = 604800): string
    {
        $issuedAt = time();
        $payload = [
            'iss' => 'naldike_platform',
            'aud' => 'naldike_angular_clients',
            'iat' => $issuedAt,
            'exp' => $issuedAt + $expirySeconds,
            'data' => $userData
        ];

        return JWT::encode($payload, $this->secretKey, 'HS256');
    }

    /**
     * Validate token and extract decoded payload data.
     * 
     * @param string $token JWT token
     * @return array|null Decoded payload data array, or null if invalid
     */
    public function validateToken(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secretKey, 'HS256'));
            
            // Return embedded user data
            return (array) $decoded->data;
        } catch (Exception $e) {
            error_log('JWT Validation Error: ' . $e->getMessage());
            return null;
        }
    }
}
