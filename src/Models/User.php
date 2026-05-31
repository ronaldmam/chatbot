<?php
// src/Models/User.php

namespace App\Models;

class User
{
    public ?int $id;
    public string $username;
    public string $email;
    public string $passwordHash;
    public string $role;
    public string $status;
    public ?string $createdAt;
    public ?string $updatedAt;

    public function __construct(
        ?int $id = null,
        string $username = '',
        string $email = '',
        string $passwordHash = '',
        string $role = 'agent',
        string $status = 'active',
        ?string $createdAt = null,
        ?string $updatedAt = null
    ) {
        $this->id = $id;
        $this->username = $username;
        $this->email = $email;
        $this->passwordHash = $passwordHash;
        $this->role = $role;
        $this->status = $status;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    /**
     * Map object variables to serializable array for API responses (excludes password hash).
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'email' => $this->email,
            'role' => $this->role,
            'status' => $this->status,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt
        ];
    }
}
