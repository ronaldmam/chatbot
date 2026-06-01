<?php
// src/Repositories/KnowledgeBaseRepository.php

namespace App\Repositories;

use App\Core\Database;
use App\Models\KnowledgeBase;
use PDO;

class KnowledgeBaseRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Save or update a KnowledgeBase entry in the database
     */
    public function save(KnowledgeBase $kb): bool
    {
        $metaJSON = $kb->metaInfo ? json_encode($kb->metaInfo, JSON_UNESCAPED_UNICODE) : null;

        if (isset($kb->id) && $kb->id > 0) {
            $stmt = $this->db->prepare("UPDATE knowledge_base SET type = ?, title = ?, content = ?, source_url = ?, meta_info = ? WHERE id = ?");
            return $stmt->execute([
                $kb->type,
                $kb->title,
                $kb->content,
                $kb->sourceUrl,
                $metaJSON,
                $kb->id
            ]);
        } else {
            $stmt = $this->db->prepare("INSERT INTO knowledge_base (type, title, content, source_url, meta_info) VALUES (?, ?, ?, ?, ?)");
            $result = $stmt->execute([
                $kb->type,
                $kb->title,
                $kb->content,
                $kb->sourceUrl,
                $metaJSON
            ]);
            if ($result) {
                $kb->id = (int) $this->db->lastInsertId();
                $kb->createdAt = date('Y-m-d H:i:s');
                $kb->updatedAt = date('Y-m-d H:i:s');
            }
            return $result;
        }
    }

    /**
     * Delete a knowledge item by ID
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM knowledge_base WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Search knowledge base using MySQL FULLTEXT score, fallback to LIKE queries
     * 
     * @param string $query Natural language client message search query
     * @param int $limit Max results to inject as context
     * @return KnowledgeBase[] Ingested matches
     */
    public function search(string $query, int $limit = 3): array
    {
        $results = [];

        try {
            // 1. Try MySQL Full-Text Search (highly performant on natural text)
            $stmt = $this->db->prepare("SELECT *, MATCH(content) AGAINST(:q1) as score FROM knowledge_base WHERE MATCH(content) AGAINST(:q2) ORDER BY score DESC LIMIT :lim");
            $stmt->bindValue(':q1', $query, PDO::PARAM_STR);
            $stmt->bindValue(':q2', $query, PDO::PARAM_STR);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            while ($row = $stmt->fetch()) {
                $results[] = $this->mapRowToKb($row);
            }
        } catch (\PDOException $e) {
            error_log("Full-Text Search failed, falling back to LIKE: " . $e->getMessage());
        }

        // 2. Fallback to standard LIKE if no fulltext matches found (essential for short words or non-indexed content)
        if (empty($results)) {
            try {
                $stmt = $this->db->prepare("SELECT * FROM knowledge_base WHERE content LIKE :q1 OR title LIKE :q2 LIMIT :lim");
                $likeQuery = "%" . $query . "%";
                $stmt->bindValue(':q1', $likeQuery, PDO::PARAM_STR);
                $stmt->bindValue(':q2', $likeQuery, PDO::PARAM_STR);
                $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
                $stmt->execute();
                
                while ($row = $stmt->fetch()) {
                    $results[] = $this->mapRowToKb($row);
                }
            } catch (\PDOException $e) {
                error_log("Fallback LIKE Search failed: " . $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Retrieve WooCommerce Sync credential configuration item
     */
    public function findWooCommerceConfig(): ?KnowledgeBase
    {
        $stmt = $this->db->prepare("SELECT * FROM knowledge_base WHERE type = 'woocommerce' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return $this->mapRowToKb($row);
    }

    /**
     * Retrieve all knowledge base items
     * 
     * @return KnowledgeBase[]
     */
    public function findAll(): array
    {
        $stmt = $this->db->prepare("SELECT * FROM knowledge_base ORDER BY id DESC");
        $stmt->execute();
        $items = [];
        while ($row = $stmt->fetch()) {
            $items[] = $this->mapRowToKb($row);
        }
        return $items;
    }

    /**
     * Find knowledge base item by ID
     */
    public function find(int $id): ?KnowledgeBase
    {
        $stmt = $this->db->prepare("SELECT * FROM knowledge_base WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return $this->mapRowToKb($row);
    }

    /**
     * Helper to map raw database row to KnowledgeBase entity
     */
    private function mapRowToKb(array $row): KnowledgeBase
    {
        $meta = isset($row['meta_info']) ? json_decode($row['meta_info'], true) : null;
        return new KnowledgeBase(
            (int) $row['id'],
            $row['type'],
            $row['title'],
            $row['content'],
            $row['source_url'],
            $meta,
            $row['created_at'],
            $row['updated_at']
        );
    }
}
