<?php
// src/Core/Middleware/MiddlewareInterface.php

namespace App\Core\Middleware;

interface MiddlewareInterface
{
    /**
     * Handle an incoming request.
     * 
     * @return bool True to continue processing, False to abort.
     */
    public function handle(): bool;
}
