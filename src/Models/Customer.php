<?php
// src/Models/Customer.php

namespace App\Models;

class Customer
{
    public ?int $id;
    public string $psid;
    public string $name;
    public ?string $email;
    public ?string $phone;
    public string $platform;
    public ?string $createdAt;
    public ?string $updatedAt;

    public function __construct(
        ?int $id = null,
        string $psid = '',
        string $name = 'Cliente Anónimo',
        ?string $email = null,
        ?string $phone = null,
        string $platform = 'web',
        ?string $createdAt = null,
        ?string $updatedAt = null
    ) {
        $this->id = $id;
        $this->psid = $psid;
        $this->name = $name;
        $this->email = $email;
        $this->phone = $phone;
        $this->platform = $platform;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    /**
     * Map customer properties to serializable array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'psid' => $this->psid,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'platform' => $this->platform,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt
        ];
    }
}
