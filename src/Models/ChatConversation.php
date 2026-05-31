<?php
// src/Models/ChatConversation.php

namespace App\Models;

class ChatConversation
{
    public ?int $id;
    public int $customerId;
    public string $flowState; // bot, ia, human
    public ?string $wasapiTicketId;
    public ?string $createdAt;
    public ?string $updatedAt;
    
    public int $isMarketplace; // 0 or 1
    public ?string $marketplaceRef;
    
    /**
     * @var ChatMessage[] Array of ChatMessage objects
     */
    public array $messages = [];

    public function __construct(
        ?int $id = null,
        int $customerId = 0,
        string $flowState = 'bot',
        ?string $wasapiTicketId = null,
        int $isMarketplace = 0,
        ?string $marketplaceRef = null,
        ?string $createdAt = null,
        ?string $updatedAt = null
    ) {
        $this->id = $id;
        $this->customerId = $customerId;
        $this->flowState = $flowState;
        $this->wasapiTicketId = $wasapiTicketId;
        $this->isMarketplace = $isMarketplace;
        $this->marketplaceRef = $marketplaceRef;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    /**
     * Map conversation properties to full serializable array with its thread history
     */
    public function toArray(): array
    {
        $serializedMessages = [];
        foreach ($this->messages as $msg) {
            $serializedMessages[] = $msg->toArray();
        }

        return [
            'id' => $this->id,
            'customer_id' => $this->customerId,
            'flow_state' => $this->flowState,
            'wasapi_ticket_id' => $this->wasapiTicketId,
            'is_marketplace' => $this->isMarketplace,
            'marketplace_ref' => $this->marketplaceRef,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'messages' => $serializedMessages
        ];
    }
}
