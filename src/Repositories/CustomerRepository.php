<?php
// src/Repositories/CustomerRepository.php

namespace App\Repositories;

use App\Core\Database;
use App\Models\Customer;
use PDO;

class CustomerRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Retrieve a customer by their unique Platform Sender ID (PSID)
     */
    public function findByPsid(string $psid): ?Customer
    {
        $stmt = $this->db->prepare("SELECT * FROM customers WHERE psid = ? LIMIT 1");
        $stmt->execute([$psid]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return $this->mapRowToCustomer($row);
    }

    /**
     * Retrieve a customer by their unique ID
     */
    public function find(int $id): ?Customer
    {
        $stmt = $this->db->prepare("SELECT * FROM customers WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return $this->mapRowToCustomer($row);
    }

    /**
     * Save or update a customer profile in MySQL database
     */
    public function save(Customer $customer): bool
    {
        if (isset($customer->id) && $customer->id > 0) {
            $stmt = $this->db->prepare("UPDATE customers SET name = ?, email = ?, phone = ?, platform = ? WHERE id = ?");
            return $stmt->execute([
                $customer->name,
                $customer->email,
                $customer->phone,
                $customer->platform,
                $customer->id
            ]);
        } else {
            $stmt = $this->db->prepare("INSERT INTO customers (psid, name, email, phone, platform) VALUES (?, ?, ?, ?, ?)");
            $result = $stmt->execute([
                $customer->psid,
                $customer->name,
                $customer->email,
                $customer->phone,
                $customer->platform
            ]);
            if ($result) {
                $customer->id = (int) $this->db->lastInsertId();
            }
            return $result;
        }
    }

    /**
     * Retrieve matching customer, or create a default one if not found.
     * If found, updates the name if it has changed (e.g., better name from UI scraping).
     * If the psid is numeric and we find a name-slug customer with the same name, upgrade their psid.
     */
    public function findOrCreate(string $psid, string $name, string $platform): Customer
    {
        // 1. Try to find by exact psid first
        $customer = $this->findByPsid($psid);
        if ($customer) {
            // Update name if it has improved (e.g., we have a better display name now)
            if (!empty($name) && $name !== 'Cliente Anónimo' && $customer->name !== $name) {
                $stmt = $this->db->prepare("UPDATE customers SET name = ? WHERE id = ?");
                $stmt->execute([$name, $customer->id]);
                $customer->name = $name;
            }
            return $customer;
        }

        // 2. If psid is a real numeric Facebook ID, check if there's an existing customer
        //    with a name-slug psid that matches the same person (name similarity).
        //    This handles the upgrade from slug-psid to real numeric psid.
        if (is_numeric($psid) && !empty($name) && $name !== 'Cliente Anónimo') {
            $slugPsid = strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', $name));
            $existingBySlug = $this->findByPsid($slugPsid);
            if ($existingBySlug) {
                // Upgrade the psid to the real Facebook ID
                $stmt = $this->db->prepare("UPDATE customers SET psid = ?, name = ? WHERE id = ?");
                $stmt->execute([$psid, $name, $existingBySlug->id]);
                $existingBySlug->psid = $psid;
                $existingBySlug->name = $name;
                return $existingBySlug;
            }
        }

        // 3. Create a new customer record
        $customer = new Customer(null, $psid, $name, null, null, $platform);
        $this->save($customer);
        return $customer;
    }

    /**
     * Helper to map raw database row to Customer entity
     */
    private function mapRowToCustomer(array $row): Customer
    {
        return new Customer(
            (int) $row['id'],
            $row['psid'],
            $row['name'],
            $row['email'],
            $row['phone'],
            $row['platform'],
            $row['created_at'],
            $row['updated_at']
        );
    }
}
