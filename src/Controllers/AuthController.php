<?php
// src/Controllers/AuthController.php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\UserRepository;
use App\Services\JwtService;

class AuthController
{
    private UserRepository $userRepository;
    private JwtService $jwtService;

    public function __construct()
    {
        $this->userRepository = new UserRepository();
        $this->jwtService = new JwtService();
    }

    /**
     * Handle platform user login and JWT token issuance.
     * 
     * Route: POST /api/auth/login
     */
    public function login(): void
    {
        $body = Request::getBody();
        $username = $body['username'] ?? '';
        $password = $body['password'] ?? '';

        if (empty($username) || empty($password)) {
            Response::json([
                'error' => 'Bad Request',
                'message' => 'Both username and password are required.'
            ], 400);
            return;
        }

        // Retrieve user
        $user = $this->userRepository->findByUsername($username);

        // Validate user credentials safely using constant time comparison
        if (!$user || !password_verify($password, $user->passwordHash)) {
            Response::json([
                'error' => 'Unauthorized',
                'message' => 'Invalid username or password.'
            ], 401);
            return;
        }

        // Validate account status
        if ($user->status !== 'active') {
            Response::json([
                'error' => 'Forbidden',
                'message' => 'This user account is currently deactivated.'
            ], 403);
            return;
        }

        // Embed dynamic details in the JWT
        $tokenPayload = [
            'id' => $user->id,
            'username' => $user->username,
            'role' => $user->role
        ];

        // Generate 12-hour valid JWT
        $token = $this->jwtService->generateToken($tokenPayload);

        Response::json([
            'message' => 'Authenticated successfully',
            'token' => $token,
            'user' => $user->toArray()
        ]);
    }
}
