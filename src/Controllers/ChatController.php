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
     *
     * NEW FLOW (v1.8):
     * - If conversation is BRAND NEW (flowState='bot', no prior bot messages):
     *   → Return status='new_conversation' so the JS script sends the 3 warm
     *     greeting messages directly in Messenger and starts the 3-min follow-up timer.
     *   → Switch flowState to 'ia' immediately so subsequent messages go to Gemini.
     * - If conversation is ongoing (flowState='ia'): reply via Gemini AI.
     * - If flowState='human': stay silent.
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

            // 3. Save incoming customer message to thread history
            $this->chatRepository->addMessage($conv->id, 'customer', $text);

            // 4. Flow state machine
            if ($conv->flowState === 'human') {
                // Human agent in control — bot stays completely silent
                Response::json(['status' => 'human_in_control', 'reply' => null]);
                return;
            }

            if ($conv->flowState === 'bot') {
                // ── NEW CONVERSATION GREETING PATH ──────────────────────────────────
                // Count how many bot/agent messages exist in this conv.
                // If there are none, this is the very first interaction → send the warm
                // greeting sequence (3 msgs) from the JS script, then switch to AI mode.
                $priorBotMessages = array_filter($conv->messages ?? [], function($m) {
                    return in_array($m->sender ?? '', ['bot', 'agent']);
                });

                if (empty($priorBotMessages)) {
                    // Switch to AI mode right away so the next customer message hits Gemini
                    $conv->flowState = 'ia';
                    $this->chatRepository->save($conv);

                    // Record a placeholder in history so we don't greet twice on retry
                    $greetingLog = "[SALUDO_INICIAL] Bienvenida enviada por el script al cliente {$customerName}";
                    $this->chatRepository->addMessage($conv->id, 'bot', $greetingLog);

                    // Tell the JS to send the 3 warm greeting messages
                    Response::json([
                        'status'        => 'new_conversation',
                        'customer_name' => $customerName,
                        'marketplace_ref' => $conv->marketplaceRef ?? null
                    ]);
                    return;
                }

                // If we're still in 'bot' state but already have prior bot messages,
                // fall through to the legacy options-menu logic below.
                $cleanText = trim(strtolower($text));
                $settingsController = new SettingsController();
                $settings = $settingsController->loadSettings();

                if ($cleanText === '3') {
                    $conv->flowState = 'ia';
                    $this->chatRepository->save($conv);
                    $reply = $this->getAiReply($conv, $text);
                } elseif ($cleanText === '1') {
                    $reply = $settings['option_1_response'] ?? '';
                } elseif ($cleanText === '2') {
                    $reply = $settings['option_2_response'] ?? '';
                } else {
                    $reply = $settings['welcome_message'] ?? '';
                }

                $this->chatRepository->addMessage($conv->id, 'bot', $reply);
                Response::json(['status' => 'automated_reply', 'reply' => $reply]);
                return;
            }

            // flowState === 'ia': normal AI reply via Gemini
            $reply = $this->getAiReply($conv, $text);
            $this->chatRepository->addMessage($conv->id, 'bot', $reply);

            Response::json([
                'status' => 'automated_reply',
                'reply'  => $reply
            ]);
        } catch (\Exception $e) {
            Response::json([
                'error'   => 'Internal Server Error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate and return a follow-up message after the 3-minute no-reply timer fires.
     * The JS script calls this endpoint when the customer hasn't responded in 3 minutes
     * after the initial greeting. Gemini generates a message with the product link,
     * store address, and opening hours.
     *
     * Route: POST /api/automation/followup
     */
    public function handleAutomationFollowup(): void
    {
        try {
            $body = Request::getBody();
            $psid            = $body['psid'] ?? '';
            $customerName    = $body['customer_name'] ?? 'Cliente Anónimo';
            $marketplaceRef  = $body['marketplace_ref'] ?? null;

            if (empty($psid)) {
                Response::json(['error' => 'Bad Request', 'message' => 'psid is required.'], 400);
                return;
            }

            // Locate the conversation
            $customerRepo = new \App\Repositories\CustomerRepository();
            $customer = $customerRepo->findOrCreate($psid, $customerName, 'messenger');
            $conv = $this->chatRepository->findOrCreateActive($customer->id);

            // Build a specific prompt that asks Gemini to compose the follow-up
            $storeUrl     = defined('STORE_URL')     ? STORE_URL     : 'https://naldike.com';
            $storeAddress = defined('STORE_ADDRESS') ? STORE_ADDRESS : 'Lima, Perú';
            $storePhone   = defined('STORE_PHONE')   ? STORE_PHONE   : '939021800';
            $storeSchedule = defined('STORE_SCHEDULE') ? STORE_SCHEDULE : 'Lunes a Sábado 9am–7pm, Domingos 10am–2pm';

            $refPart = !empty($marketplaceRef)
                ? "El cliente consultó por el siguiente artículo de Marketplace: '{$marketplaceRef}'. Incluye en tu mensaje el link directo a nuestra tienda online {$storeUrl} para que pueda verlo y comprarlo."
                : "Incluye el link general de nuestra tienda online: {$storeUrl}";

            $followUpPrompt = "El cliente lleva más de 3 minutos sin responder después de que le enviamos nuestra presentación inicial. "
                . "Genera un mensaje amigable y conciso (máximo 3 oraciones) que incluya: "
                . "(1) un recordatorio cálido de que estamos disponibles, "
                . "(2) la dirección de nuestra tienda física: {$storeAddress}, "
                . "(3) nuestro horario de atención: {$storeSchedule}, "
                . "(4) {$refPart}. "
                . "El mensaje debe motivar al cliente a visitarnos o comprar. Redáctalo en español, tono amigable y no invasivo.";

            $reply = $this->getAiReply($conv, $followUpPrompt);

            // Save the follow-up in conversation history
            $this->chatRepository->addMessage($conv->id, 'bot', $reply);

            Response::json([
                'status' => 'followup_reply',
                'reply'  => $reply
            ]);
        } catch (\Exception $e) {
            Response::json([
                'error'   => 'Internal Server Error',
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
