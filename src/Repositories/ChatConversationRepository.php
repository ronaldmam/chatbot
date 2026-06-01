<?php
// src/Repositories/ChatConversationRepository.php

namespace App\Repositories;

use App\Core\Database;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use PDO;

class ChatConversationRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Retrieve active or latest conversation of a specific customer, complete with messages
     */
    public function findActiveByCustomerId(int $customerId): ?ChatConversation
    {
        $stmt = $this->db->prepare("SELECT * FROM chat_conversations WHERE customer_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$customerId]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $conv = new ChatConversation(
            (int) $row['id'],
            (int) $row['customer_id'],
            $row['flow_state'],
            $row['wasapi_ticket_id'],
            (int) ($row['is_marketplace'] ?? 0),
            $row['marketplace_ref'] ?? null,
            $row['custom_instructions'] ?? null,
            $row['created_at'],
            $row['updated_at']
        );

        // Populate its messages history
        $conv->messages = $this->getMessagesByConversationId($conv->id);

        return $conv;
    }

    /**
     * Retrieve matching active conversation, or initialize a new one if missing
     */
    public function findOrCreateActive(int $customerId): ChatConversation
    {
        $conv = $this->findActiveByCustomerId($customerId);
        if (!$conv) {
            $conv = new ChatConversation(null, $customerId, 'bot');
            $this->save($conv);
        }
        return $conv;
    }

    /**
     * Insert or update a conversation meta record in the database
     */
    public function save(ChatConversation $conversation): bool
    {
        if (isset($conversation->id) && $conversation->id > 0) {
            $stmt = $this->db->prepare("UPDATE chat_conversations SET flow_state = ?, wasapi_ticket_id = ?, is_marketplace = ?, marketplace_ref = ?, custom_instructions = ? WHERE id = ?");
            return $stmt->execute([
                $conversation->flowState,
                $conversation->wasapiTicketId,
                $conversation->isMarketplace,
                $conversation->marketplaceRef,
                $conversation->customInstructions,
                $conversation->id
            ]);
        } else {
            $stmt = $this->db->prepare("INSERT INTO chat_conversations (customer_id, flow_state, wasapi_ticket_id, is_marketplace, marketplace_ref, custom_instructions) VALUES (?, ?, ?, ?, ?, ?)");
            $result = $stmt->execute([
                $conversation->customerId,
                $conversation->flowState,
                $conversation->wasapiTicketId,
                $conversation->isMarketplace,
                $conversation->marketplaceRef,
                $conversation->customInstructions
            ]);
            if ($result) {
                $conversation->id = (int) $this->db->lastInsertId();
            }
            return $result;
        }
    }

    /**
     * Append a message to a conversation thread and touch updated_at
     */
    public function addMessage(int $conversationId, string $sender, string $text): bool
    {
        $stmt = $this->db->prepare("INSERT INTO chat_messages (conversation_id, sender, message_text) VALUES (?, ?, ?)");
        $result = $stmt->execute([$conversationId, $sender, $text]);
        
        if ($result) {
            if ($sender === 'customer') {
                // Customer message: increment unread count and update updated_at (moves to top)
                $stmtUpdate = $this->db->prepare("UPDATE chat_conversations SET unread_count = unread_count + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmtUpdate->execute([$conversationId]);
            } else {
                // Bot or Agent message: just update updated_at to refresh ordering
                $stmtUpdate = $this->db->prepare("UPDATE chat_conversations SET updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmtUpdate->execute([$conversationId]);
            }
        }
        
        return $result;
    }

    /**
     * Reset unread message count for a conversation
     */
    public function resetUnreadCount(int $conversationId): bool
    {
        $stmt = $this->db->prepare("UPDATE chat_conversations SET unread_count = 0 WHERE id = ?");
        return $stmt->execute([$conversationId]);
    }

    /**
     * Fetch message thread list linked to a conversation
     */
    public function getMessagesByConversationId(int $conversationId, int $limit = 50): array
    {
        $stmt = $this->db->prepare("SELECT * FROM chat_messages WHERE conversation_id = :conversationId ORDER BY id ASC LIMIT :lim");
        $stmt->bindValue(':conversationId', $conversationId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $messages = [];
        while ($row = $stmt->fetch()) {
            $messages[] = new ChatMessage(
                (int) $row['id'],
                (int) $row['conversation_id'],
                $row['sender'],
                $row['message_text'],
                $row['created_at']
            );
        }
        return $messages;
    }

    /**
     * Fetch a conversation by its ID
     */
    public function findById(int $id): ?ChatConversation
    {
        $stmt = $this->db->prepare("SELECT * FROM chat_conversations WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $conv = new ChatConversation(
            (int) $row['id'],
            (int) $row['customer_id'],
            $row['flow_state'],
            $row['wasapi_ticket_id'],
            (int) ($row['is_marketplace'] ?? 0),
            $row['marketplace_ref'] ?? null,
            $row['custom_instructions'] ?? null,
            $row['created_at'],
            $row['updated_at']
        );

        $conv->messages = $this->getMessagesByConversationId($conv->id);
        return $conv;
    }

    /**
     * Fetch all conversations joined with customer details
     * 
     * @return array
     */
    public function findAllWithCustomerInfo(): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                cc.id,
                cc.customer_id,
                c.name AS customer_name,
                c.platform,
                c.psid,
                cc.flow_state,
                cc.unread_count,
                cc.is_marketplace,
                cc.marketplace_ref,
                cc.custom_instructions,
                cc.created_at
            FROM chat_conversations cc
            JOIN customers c ON cc.customer_id = c.id
            ORDER BY cc.updated_at DESC
        ");
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get statistics for conversations
     */
    public function getStats(): array
    {
        // Total active conversations
        $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM chat_conversations");
        $stmt->execute();
        $total = (int) $stmt->fetch()['total'];

        // Handovers (human state)
        $stmt = $this->db->prepare("SELECT COUNT(*) as human FROM chat_conversations WHERE flow_state = 'human'");
        $stmt->execute();
        $human = (int) $stmt->fetch()['human'];

        // AI containment (ia state)
        $stmt = $this->db->prepare("SELECT COUNT(*) as ia FROM chat_conversations WHERE flow_state = 'ia'");
        $stmt->execute();
        $ia = (int) $stmt->fetch()['ia'];

        // Bot containment (bot state)
        $stmt = $this->db->prepare("SELECT COUNT(*) as bot FROM chat_conversations WHERE flow_state = 'bot'");
        $stmt->execute();
        $bot = (int) $stmt->fetch()['bot'];

        // Calculate containment rate (AI + Bot / Total)
        $containmentRate = 0;
        if ($total > 0) {
            $containmentRate = round((($total - $human) / $total) * 100, 1);
        }

        return [
            'total_chats' => $total,
            'containment_rate' => $containmentRate,
            'human_handovers' => $human,
            'ai_chats' => $ia,
            'bot_chats' => $bot
        ];
    }

    /**
     * Get all undelivered agent messages for a specific customer PSID.
     */
    public function getPendingAgentMessages(string $customerPsid): array
    {
        $stmt = $this->db->prepare("
            SELECT cm.id, cm.message_text 
            FROM chat_messages cm
            JOIN chat_conversations cc ON cm.conversation_id = cc.id
            JOIN customers c ON cc.customer_id = c.id
            WHERE c.psid = ? AND cm.sender = 'agent' AND cm.is_delivered = 0
            ORDER BY cm.id ASC
        ");
        $stmt->execute([$customerPsid]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get all undelivered agent messages globally.
     */
    public function getAllPendingAgentMessages(): array
    {
        $stmt = $this->db->prepare("
            SELECT cm.id, cm.message_text, c.psid, c.name as customer_name
            FROM chat_messages cm
            JOIN chat_conversations cc ON cm.conversation_id = cc.id
            JOIN customers c ON cc.customer_id = c.id
            WHERE cm.sender = 'agent' AND cm.is_delivered = 0
            ORDER BY cm.id ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Mark specific message IDs as successfully delivered.
     */
    public function markMessagesAsDelivered(array $messageIds): bool
    {
        if (empty($messageIds)) {
            return true;
        }
        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $stmt = $this->db->prepare("UPDATE chat_messages SET is_delivered = 1 WHERE id IN ($placeholders)");
        return $stmt->execute($messageIds);
    }
}

