<?php
// src/Controllers/WebhookController.php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\CustomerRepository;
use App\Repositories\ChatConversationRepository;
use App\Services\FacebookService;
use App\Services\GeminiService;

class WebhookController
{
    private CustomerRepository $customerRepository;
    private ChatConversationRepository $conversationRepository;
    private FacebookService $facebookService;

    public function __construct()
    {
        $this->customerRepository = new CustomerRepository();
        $this->conversationRepository = new ChatConversationRepository();
        $this->facebookService = new FacebookService();
    }

    /**
     * Handle Meta Webhook Verification (GET request)
     * 
     * Route: GET /api/webhook/messenger
     */
    public function verifyMessenger(): void
    {
        $verifyToken = defined('FB_VERIFY_TOKEN') ? FB_VERIFY_TOKEN : '';
        $queryParams = Request::getQueryParams();

        $mode = $queryParams['hub_mode'] ?? '';
        $token = $queryParams['hub_verify_token'] ?? '';
        $challenge = $queryParams['hub_challenge'] ?? '';

        if ($mode === 'subscribe' && $token === $verifyToken) {
            error_log("Webhook Messenger verificado correctamente.");
            Response::text($challenge, 200);
        } else {
            error_log("Fallo en verificación de Webhook Messenger.");
            Response::text('Forbidden', 403);
        }
    }

    /**
     * Handle Meta Incoming Webhook Events (POST request)
     * 
     * Route: POST /api/webhook/messenger
     */
    public function handleMessenger(): void
    {
        $input = Request::getBody();

        // Audit incoming webhooks raw data to log file for debugging
        file_put_contents(
            'fb_webhook_log.txt', 
            date("Y-m-d H:i:s") . " " . json_encode($input) . "\n", 
            FILE_APPEND
        );

        if (isset($input['object']) && $input['object'] === 'page') {
            foreach ($input['entry'] as $entry) {
                if (isset($entry['messaging'][0])) {
                    $messaging = $entry['messaging'][0];
                    $senderPsid = $messaging['sender']['id'];
                    
                    // 1. Identify Marketplace referrals in Messenger webhook payload
                    $isMarketplace = 0;
                    $marketplaceRef = null;
                    
                    if (isset($messaging['referral'])) {
                        $referral = $messaging['referral'];
                    } elseif (isset($messaging['message']['referral'])) {
                        $referral = $messaging['message']['referral'];
                    } else {
                        $referral = null;
                    }
                    
                    if ($referral && isset($referral['source']) && strtoupper($referral['source']) === 'MARKETPLACE') {
                        $isMarketplace = 1;
                        $marketplaceRef = $referral['ref'] ?? ($referral['ads_context_data']['ad_title'] ?? 'Marketplace Listing');
                    }
                    
                    if (isset($messaging['message']['text'])) {
                        $text = $messaging['message']['text'];
                        $this->processMessage('messenger', $senderPsid, $text, 'Cliente Anónimo', $isMarketplace, $marketplaceRef);
                    }
                }
            }
            Response::text('EVENT_RECEIVED', 200);
        } else {
            Response::text('NOT_FOUND', 404);
        }
    }

    /**
     * Unified message processor to direct options flow vs AI Conversational flow
     */
    private function processMessage(
        string $platform, 
        string $psid, 
        string $text, 
        string $customerName = 'Cliente Anónimo',
        int $isMarketplace = 0,
        ?string $marketplaceRef = null
    ): void {
        // 1. Fetch or create Customer Entity
        $customer = $this->customerRepository->findOrCreate($psid, $customerName, $platform);

        // 2. Fetch or create Customer active Conversation
        $conv = $this->conversationRepository->findOrCreateActive($customer->id);

        // Update Marketplace status if detected in this session
        if ($isMarketplace) {
            $conv->isMarketplace = 1;
            if (!empty($marketplaceRef)) {
                $conv->marketplaceRef = $marketplaceRef;
            }
            $this->conversationRepository->save($conv);
        }

        // 3. Save incoming message to thread audit history
        $this->conversationRepository->addMessage($conv->id, 'customer', $text);

        $reply = '';

        // 4. Flow state machine
        if ($conv->flowState === 'bot') {
            $cleanText = trim(strtolower($text));
            
            $settingsController = new SettingsController();
            $settings = $settingsController->loadSettings();

            if ($cleanText === '3') {
                // Switch flow state to AI
                $conv->flowState = 'ia';
                $this->conversationRepository->save($conv);
                
                // Get AI response immediately
                $reply = $this->getAiReply($conv, $text);
            } else if ($cleanText === '1') {
                $reply = $settings['option_1_response'] ?? '';
            } else if ($cleanText === '2') {
                $reply = $settings['option_2_response'] ?? '';
            } else {
                $reply = $settings['welcome_message'] ?? '';
            }
        } elseif ($conv->flowState === 'ia') {
            
            // Check for customer frustration/support demand to trigger local Sales Manager Handover
            if ($this->shouldHandoverToHuman($text)) {
                $conv->flowState = 'human';
                $this->conversationRepository->save($conv);
                
                $reply = "Entiendo que requieres una atención más personalizada. He derivado esta conversación a uno de nuestros gestores de ventas. En breve te responderemos de manera directa y personalizada aquí mismo.";
            } else {
                $reply = $this->getAiReply($conv, $text);
                
                // Graceful degradation: if Gemini API key quota is exhausted or errors out
                if (strpos($reply, 'Lo siento, mi motor') === 0 || strpos($reply, 'Lo siento, experimenté') === 0) {
                    $conv->flowState = 'human';
                    $this->conversationRepository->save($conv);
                    $reply = "En este momento tenemos una alta demanda en nuestro canal inteligente. Para darte una atención inmediata y sin esperas, acabo de transferir esta conversación a uno de nuestros gestores de ventas. ¡Te responderemos de inmediato por aquí!";
                }
            }
        } else {
            // Human state: system is completely silent because the Sales Manager handles the chat manually from the platform dashboard
            return;
        }

        // 5. Save generated reply in thread history
        $this->conversationRepository->addMessage($conv->id, 'bot', $reply);

        // 6. Send reply back to customer
        if ($platform === 'messenger') {
            $this->facebookService->sendTextMessage($psid, $reply);
        } elseif ($platform === 'whatsapp') {
            $waService = new \App\Services\WhatsAppService();
            $waService->sendTextMessage($psid, $reply);
        }
    }

    /**
     * Get AI response using Gemini Service and dynamic scraped product context
     */
    private function getAiReply($conv, string $text): string
    {
        // Check if GeminiService exists (we'll implement it next)
        if (class_exists('App\Services\GeminiService')) {
            try {
                $gemini = new GeminiService();
                return $gemini->generateResponse($conv, $text);
            } catch (\Exception $e) {
                error_log("Error in Gemini service processing: " . $e->getMessage());
            }
        }
        return "Lo siento, mi motor de inteligencia artificial está experimentando una actualización. ¿Puedo ayudarte con otra cosa?";
    }

    /**
     * Detect customer frustration or explicit support demands
     */
    private function shouldHandoverToHuman(string $text): bool
    {
        $text = mb_strtolower($text, 'UTF-8');
        $triggers = [
            'asesor', 'humano', 'agente', 'persona', 'queja', 'estafa', 
            'soporte', 'hablar con alguien', 'operador', 'malo', 'pesimo',
            'no sirve', 'devolucion', 'reembolso', 'garantia'
        ];

        foreach ($triggers as $trigger) {
            if (strpos($text, $trigger) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Handle Meta WhatsApp Cloud API Webhook Verification (GET request)
     * 
     * Route: GET /api/webhook/whatsapp
     */
    public function verifyWhatsApp(): void
    {
        $verifyToken = defined('WA_VERIFY_TOKEN') ? WA_VERIFY_TOKEN : '';
        $queryParams = Request::getQueryParams();

        $mode = $queryParams['hub_mode'] ?? '';
        $token = $queryParams['hub_verify_token'] ?? '';
        $challenge = $queryParams['hub_challenge'] ?? '';

        if ($mode === 'subscribe' && $token === $verifyToken) {
            error_log("Webhook WhatsApp verificado correctamente.");
            Response::text($challenge, 200);
        } else {
            error_log("Fallo en verificación de Webhook WhatsApp.");
            Response::text('Forbidden', 403);
        }
    }

    /**
     * Handle Meta WhatsApp Cloud API Webhook Events (POST request)
     * 
     * Route: POST /api/webhook/whatsapp
     */
    public function handleWhatsApp(): void
    {
        $input = Request::getBody();

        // Audit incoming WhatsApp webhooks raw data to log file for debugging
        file_put_contents(
            'wa_webhook_log.txt', 
            date("Y-m-d H:i:s") . " " . json_encode($input) . "\n", 
            FILE_APPEND
        );

        if (isset($input['entry'][0]['changes'][0]['value']['messages'][0])) {
            $changeValue = $input['entry'][0]['changes'][0]['value'];
            $message = $changeValue['messages'][0];
            $senderPhone = $message['from']; // PSID is the phone number of the customer
            
            // Get contact name if present
            $contactName = $changeValue['contacts'][0]['profile']['name'] ?? 'Cliente WhatsApp';

            if (isset($message['text']['body'])) {
                $text = $message['text']['body'];
                $this->processMessage('whatsapp', $senderPhone, $text, $contactName);
            }
        }
        
        Response::text('EVENT_RECEIVED', 200);
    }
}
