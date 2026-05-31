<?php
// src/Models/ChatMessage.php

namespace App\Models;

class ChatMessage
{
    public ?int $id;
    public int $conversationId;
    public string $sender; // customer, bot, agent
    public string $messageText;
    public ?string $createdAt;

    public function __construct(
        ?int $id = null,
        int $conversationId = 0,
        string $sender = 'bot',
        string $messageText = '',
        ?string $createdAt = null
    ) {
        $this->id = $id;
        $this->conversationId = $conversationId;
        $this->sender = $sender;
        $this->messageText = $messageText;
        $this->createdAt = $createdAt;
    }

    /**
     * Map message properties to array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversationId,
            'sender' => $this->sender,
            'message_text' => $this->messageText,
            'created_at' => $this->createdAt
        ];
    }
}
