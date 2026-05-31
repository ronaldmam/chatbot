<?php
// src/Core/Database.php

namespace App\Core;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;

    private function __construct() {} // Prevent direct instantiation
    private function __clone() {}       // Prevent cloning

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            try {
                $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
                
                $options = [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ];

                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                // Return a clear, secure JSON error or throw
                Response::json([
                    'error' => 'Database connection failed',
                    'details' => $e->getMessage() // In prod, we would hide specific DB errors
                ], 500);
            }
        }

        return self::$instance;
    }
}
