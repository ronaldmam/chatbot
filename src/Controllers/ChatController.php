<?php
// src/Controllers/ChatController.php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\ChatConversationRepository;

class ChatController
{
    private ChatConversationRepository $chatRepository;

    public function __construct()
    {
        $this->chatRepository = new ChatConversationRepository();
    }

    /**
     * Get all active conversations with customer details.
     * Route: GET /api/chats
     */
    public function getAll(): void
    {
        try {
            $chats = $this->chatRepository->findAllWithCustomerInfo();
            Response::json($chats);
        } catch (\Exception $e) {
            Response::json([
                'error' => 'Internal Server Error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all messages for a specific conversation.
     * Route: GET /api/chats/{id}
     */
    public function getMessages($id): void
    {
        try {
            $conversationId = (int)$id;
            $conversation = $this->chatRepository->findById($conversationId);

            if (!$conversation) {
                Response::json([
                    'error' => 'Not Found',
                    'message' => "Conversation with ID $conversationId not found."
                ], 404);
                return;
            }

            // Reset unread count for this conversation as the agent is now reading/viewing it
            $this->chatRepository->resetUnreadCount($conversationId);

            // Return array of serialized messages
            $serializedMessages = [];
            foreach ($conversation->messages as $msg) {
                $serializedMessages[] = $msg->toArray();
            }

            Response::json($serializedMessages);
        } catch (\Exception $e) {
            Response::json([
                'error' => 'Internal Server Error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update flow state of a specific conversation (manual override).
     * Route: PUT /api/chats/{id}/state
     */
    public function updateState($id): void
    {
        try {
            $conversationId = (int)$id;
            $body = Request::getBody();
            $flowState = $body['flow_state'] ?? '';

            if (!in_array($flowState, ['bot', 'ia', 'human'])) {
                Response::json([
                    'error' => 'Bad Request',
                    'message' => "Invalid flow state. Must be 'bot', 'ia', or 'human'."
                ], 400);
                return;
            }

            $conversation = $this->chatRepository->findById($conversationId);

            if (!$conversation) {
                Response::json([
                    'error' => 'Not Found',
                    'message' => "Conversation with ID $conversationId not found."
                ], 404);
                return;
            }

            $conversation->flowState = $flowState;
            
            // If returning to bot/ia, we can also clear the wasapi ticket to reset handover state
            if ($flowState !== 'human') {
                $conversation->wasapiTicketId = null;
            }

            $this->chatRepository->save($conversation);

            Response::json([
                'message' => "Flow state updated successfully to '$flowState'.",
                'conversation' => $conversation->toArray()
            ]);
        } catch (\Exception $e) {
            Response::json([
                'error' => 'Internal Server Error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send a manual response from the sales manager.
     * Route: POST /api/chats/{id}/message
     */
    public function sendMessage($id): void
    {
        try {
            $conversationId = (int)$id;
            $body = Request::getBody();
            $messageText = $body['message_text'] ?? '';

            if (empty($messageText)) {
                Response::json([
                    'error' => 'Bad Request',
                    'message' => 'Message text is required.'
                ], 400);
                return;
            }

            $conversation = $this->chatRepository->findById($conversationId);

            if (!$conversation) {
                Response::json([
                    'error' => 'Not Found',
                    'message' => "Conversation with ID $conversationId not found."
                ], 404);
                return;
            }

            // 1. Save message as 'agent' sender
            $this->chatRepository->addMessage($conversationId, 'agent', $messageText);

            // 2. Fetch the newly inserted message ID
            $db = \App\Core\Database::getConnection();
            $newId = (int)$db->lastInsertId();
            
            // 3. Retrieve customer details to deliver message in real channels if required
            $customerRepo = new \App\Repositories\CustomerRepository();
            $customer = $customerRepo->find($conversation->customerId);

            if ($customer) {
                if ($customer->platform === 'messenger') {
                    // Deliver to Facebook Messenger real client!
                    $fbService = new \App\Services\FacebookService();
                    $fbService->sendTextMessage($customer->psid, $messageText);
                } elseif ($customer->platform === 'whatsapp') {
                    // Deliver to WhatsApp Cloud API real client!
                    $waService = new \App\Services\WhatsAppService();
                    $waService->sendTextMessage($customer->psid, $messageText);
                }
            }

            $newMessage = [
                'id' => $newId,
                'conversation_id' => $conversationId,
                'sender' => 'agent',
                'message_text' => $messageText,
                'created_at' => date('Y-m-d H:i:s')
            ];

            Response::json($newMessage);
        } catch (\Exception $e) {
            Response::json([
                'error' => 'Internal Server Error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle automated scraping requests from the Tampermonkey browser script.
     * Route: POST /api/automation/message
     */
    public function handleAutomationMessage(): void
    {
        try {
            $body = Request::getBody();
            $psid = $body['psid'] ?? '';
            $customerName = $body['customer_name'] ?? 'Cliente Anónimo';
            $text = $body['message_text'] ?? '';
            $isMarketplace = (int)($body['is_marketplace'] ?? 0);
            $marketplaceRef = $body['marketplace_ref'] ?? null;

            if (empty($psid) || empty($text)) {
                Response::json([
                    'error' => 'Bad Request',
                    'message' => 'psid and message_text are required.'
                ], 400);
                return;
            }

            // 1. Fetch or create Customer Entity
            $customerRepo = new \App\Repositories\CustomerRepository();
            $customer = $customerRepo->findOrCreate($psid, $customerName, 'messenger');

            // 2. Fetch or create Customer active Conversation
            $conv = $this->chatRepository->findOrCreateActive($customer->id);

            // Update Marketplace status if detected in this session
            if ($isMarketplace) {
                $conv->isMarketplace = 1;
                if (!empty($marketplaceRef)) {
                    $conv->marketplaceRef = $marketplaceRef;
                }
                $this->chatRepository->save($conv);
            }

            // 3. Save incoming message to thread history
            $this->chatRepository->addMessage($conv->id, 'customer', $text);

            // 4. Flow state machine
            $reply = '';
            if ($conv->flowState === 'bot') {
                $cleanText = trim(strtolower($text));
                
                $settingsController = new SettingsController();
                $settings = $settingsController->loadSettings();

                if ($cleanText === '3') {
                    $conv->flowState = 'ia';
                    $this->chatRepository->save($conv);
                    $reply = $this->getAiReply($conv, $text);
                } else if ($cleanText === '1') {
                    $reply = $settings['option_1_response'] ?? '';
                } else if ($cleanText === '2') {
                    $reply = $settings['option_2_response'] ?? '';
                } else {
                    $reply = $settings['welcome_message'] ?? '';
                }
            } elseif ($conv->flowState === 'ia') {
                $reply = $this->getAiReply($conv, $text);
            } else {
                // Human state: do not automate replies!
                Response::json([
                    'status' => 'human_in_control',
                    'reply' => null
                ]);
                return;
            }

            // 5. Save generated reply in thread history
            $this->chatRepository->addMessage($conv->id, 'bot', $reply);

            Response::json([
                'status' => 'automated_reply',
                'reply' => $reply
            ]);
        } catch (\Exception $e) {
            Response::json([
                'error' => 'Internal Server Error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function getAiReply($conv, string $text): string
    {
        if (class_exists('App\Services\GeminiService')) {
            try {
                $gemini = new \App\Services\GeminiService();
                return $gemini->generateResponse($conv, $text);
            } catch (\Exception $e) {
                error_log("Error in Gemini service processing: " . $e->getMessage());
            }
        }
        return "Lo siento, mi motor de inteligencia artificial está experimentando una actualización. ¿Puedo ayudarte con otra cosa?";
    }

    /**
     * Get pending undelivered agent messages for automation client to type out.
     * Route: GET /api/automation/pending
     */
    public function getPendingAutomationMessages(): void
    {
        try {
            $psid = $_GET['psid'] ?? '';
            if (empty($psid)) {
                $messages = $this->chatRepository->getAllPendingAgentMessages();
            } else {
                $messages = $this->chatRepository->getPendingAgentMessages($psid);
            }

            Response::json([
                'status' => 'success',
                'messages' => $messages
            ]);
        } catch (\Exception $e) {
            Response::json([
                'error' => 'Internal Server Error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark pending messages as successfully delivered in the browser.
     * Route: POST /api/automation/delivered
     */
    public function markAutomationMessagesDelivered(): void
    {
        try {
            $body = Request::getBody();
            $messageIds = $body['message_ids'] ?? [];

            if (empty($messageIds) || !is_array($messageIds)) {
                Response::json([
                    'error' => 'Bad Request',
                    'message' => 'message_ids array is required.'
                ], 400);
                return;
            }

            $this->chatRepository->markMessagesAsDelivered($messageIds);
            Response::json(['status' => 'success']);
        } catch (\Exception $e) {
            Response::json([
                'error' => 'Internal Server Error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get platform statistics.
     * Route: GET /api/chats/stats
     */
    public function stats(): void
    {
        try {
            $stats = $this->chatRepository->getStats();
            Response::json($stats);
        } catch (\Exception $e) {
            Response::json([
                'error' => 'Internal Server Error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
