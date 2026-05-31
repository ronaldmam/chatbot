<?php
// src/Models/KnowledgeBase.php

namespace App\Models;

class KnowledgeBase
{
    public ?int $id;
    public string $type; // pdf, url, woocommerce
    public string $title;
    public string $content;
    public ?string $sourceUrl;
    public ?array $metaInfo;
    public ?string $createdAt;
    public ?string $updatedAt;

    public function __construct(
        ?int $id = null,
        string $type = 'url',
        string $title = '',
        string $content = '',
        ?string $sourceUrl = null,
        ?array $metaInfo = null,
        ?string $createdAt = null,
        ?string $updatedAt = null
    ) {
        $this->id = $id;
        $this->type = $type;
        $this->title = $title;
        $this->content = $content;
        $this->sourceUrl = $sourceUrl;
        $this->metaInfo = $metaInfo;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    /**
     * Map knowledge record properties to serializable array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'content' => $this->content,
            'source_url' => $this->sourceUrl,
            'meta_info' => $this->metaInfo,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt
        ];
    }
}
