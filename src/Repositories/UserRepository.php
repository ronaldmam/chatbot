<?php
// src/Repositories/UserRepository.php

namespace App\Repositories;

use App\Core\Database;
use App\Models\User;
use PDO;

class UserRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Retrieve a user by username.
     * 
     * @param string $username Username
     * @return User|null User entity, or null
     */
    public function findByUsername(string $username): ?User
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return $this->mapRowToUser($row);
    }

    /**
     * Retrieve a user by ID.
     * 
     * @param int $id User ID
     * @return User|null User entity, or null
     */
    public function findById(int $id): ?User
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return $this->mapRowToUser($row);
    }

    /**
     * Helper to map a raw PDO associative row to a User Entity
     */
    private function mapRowToUser(array $row): User
    {
        return new User(
            (int) $row['id'],
            $row['username'],
            $row['email'],
            $row['password_hash'],
            $row['role'],
            $row['status'],
            $row['created_at'],
            $row['updated_at']
        );
    }
}
