<?php
// src/Services/GeminiService.php

namespace App\Services;

use App\Repositories\KnowledgeBaseRepository;
use App\Models\ChatConversation;
use Exception;

class GeminiService
{
    private string $apiKey;
    private KnowledgeBaseRepository $kbRepository;
    private WooCommerceService $wcService;

    public function __construct()
    {
        $this->apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
        $this->kbRepository = new KnowledgeBaseRepository();
        $this->wcService = new WooCommerceService();
    }

    /**
     * Generate structured, RAG-enriched AI response using Google Gemini 2.0 Flash
     * 
     * @param ChatConversation $conv ChatConversation entity with message history
     * @param string $userMessage Incoming user prompt
     * @return string Generated AI reply
     */
    public function generateResponse(ChatConversation $conv, string $userMessage): string
    {
        if (empty($this->apiKey)) {
            error_log("Gemini API Key is not configured.");
            return "Lo siento, mi motor de inteligencia artificial no se encuentra configurado en estos momentos.";
        }

        // 1. Extract keywords from current user message
        $keywords = $this->extractKeywords($userMessage);

        $ragContext = '';
        $productContext = '';

        // 2. Query KnowledgeBase RAG for matches (PDF rules, URLs) based on user query
        if (!empty($keywords)) {
            $kbMatches = $this->kbRepository->search($keywords, 2);
            foreach ($kbMatches as $match) {
                $ragContext .= "Base de Conocimiento ({$match->title}):\n{$match->content}\n\n";
            }
        }

        // 3. Search WooCommerce stock / scraper
        // A) If it is a Facebook Marketplace conversation, prioritize searching the related product reference
        if ($conv->isMarketplace === 1 && !empty($conv->marketplaceRef)) {
            $mktRef = $conv->marketplaceRef;
            // Clean up marketplace reference text (remove price and codes)
            $mktKeyword = preg_replace('/S\/\.?\s*\d+[\.,]/i', '', $mktRef);
            $mktKeyword = preg_replace('/\b[A-Z]{2,4}\d{2,}\b/i', '', $mktKeyword); // strip item codes
            $mktKeyword = trim(preg_replace('/\s+/', ' ', $mktKeyword));

            if (!empty($mktKeyword)) {
                $mktProducts = $this->wcService->searchProducts($mktKeyword);
                if (!empty($mktProducts) && stripos($mktProducts, 'No se encontraron') === false && stripos($mktProducts, 'No se pudo') === false) {
                    $productContext .= "=== PRODUCTO ASOCIADO A ESTE ANUNCIO DE MARKETPLACE ===\n" . $mktProducts . "\n";
                }
            }
        }

        // B) Search WooCommerce using current client message keywords
        if (!empty($keywords)) {
            $userProducts = $this->wcService->searchProducts($keywords);
            if (!empty($userProducts) && stripos($userProducts, 'No se encontraron') === false && stripos($userProducts, 'No se pudo') === false) {
                $productContext .= "=== PRODUCTOS ENCONTRADOS POR BÚSQUEDA DEL CLIENTE ===\n" . $userProducts . "\n";
            }
        }

        // 4. Build consolidated search context
        $compiledContext = "=== CONTEXTO DE NALDIKE STORE ===\n";
        if (!empty($ragContext)) {
            $compiledContext .= $ragContext;
        } else {
            $compiledContext .= "No hay información específica en el manual de políticas de la empresa.\n";
        }

        $compiledContext .= "\n=== INVENTARIO Y STOCK REAL ===\n";
        if (!empty($productContext)) {
            $compiledContext .= $productContext . "\n";
        } else {
            $compiledContext .= "No se encontraron productos coincidentes en el inventario actual.\n";
        }

        // 5. Structure System Instructions to enforce corporate rules and behavior
        $systemInstruction = "Eres un asistente de ventas experto y muy amable de la tienda 'Naldike Store'. 
        Tu objetivo es cerrar ventas proporcionando información clara, concisa y veraz.
        Usa la información de la empresa y los productos proporcionados en el contexto para responder la consulta del cliente.
        NO inventes productos, precios, políticas de envío o devoluciones que no estén descritos explícitamente en el contexto.
        
        🔴 CRÍTICO — CONTROL DE ALUCINACIONES Y TRANSFERENCIA HUMANA:
        Si el cliente te consulta sobre un producto que NO figura en el contexto de inventario provisto arriba, o si te realiza una pregunta técnica, un reclamo, o solicita información que NO tienes o no está explícitamente descrita en el contexto, NO INVENTES NADA.
        En su lugar, DEBES responder iniciando tu mensaje EXACTAMENTE con el prefijo: 'HUMAN_TRANSFER: ' seguido de una disculpa muy amable y explicándole al cliente que lo transferirás de inmediato con un asesor de ventas humano (Gestor de Compras) para que le proporcione la información exacta y lo ayude personalmente.

        Dirección comercial: " . (defined('STORE_ADDRESS') ? STORE_ADDRESS : 'Tacna, Perú') . ".
        Teléfono de contacto: " . (defined('STORE_PHONE') ? STORE_PHONE : '939021800') . ".
        Responde siempre en español. Sé conversacional, persuasivo y servicial.
        IMPORTANTE: Como estás en un chat móvil (WhatsApp/Messenger), mantén tus respuestas muy cortas (máximo 2-3 párrafos breves o 4 oraciones en total) y haz preguntas para continuar la venta.";

        // Append global AI instructions (configured in the Dashboard globally)
        $settingsController = new \App\Controllers\SettingsController();
        $settings = $settingsController->loadSettings();
        $globalAiInstructions = $settings['global_ai_instructions'] ?? '';
        if (!empty($globalAiInstructions)) {
            $systemInstruction .= "\n\n⚠️ INSTRUCCIONES DE COMUNICACIÓN Y TONO GENERALES (APLICADAS GLOBALMENTE):\n" . $globalAiInstructions;
        }

        // Append custom instructions for this specific conversation if provided
        if (!empty($conv->customInstructions)) {
            $systemInstruction .= "\n\n⚠️ INSTRUCCIONES ESPECÍFICAS PARA ESTA CONVERSACIÓN INDIVIDUAL:\n" . $conv->customInstructions;
        }

        // 6. Convert thread history to standard Gemini model API message format
        $contents = [];
        $historyLimit = 8;
        $historyCount = count($conv->messages);
        $startIndex = max(0, $historyCount - $historyLimit);

        for ($i = $startIndex; $i < $historyCount; $i++) {
            $msg = $conv->messages[$i];
            
            // Skip latest message inside history because we will bundle it with compiled RAG context manually below
            if ($i === $historyCount - 1 && $msg->sender === 'customer') {
                continue;
            }

            $role = ($msg->sender === 'customer') ? 'user' : 'model';
            $contents[] = [
                'role' => $role,
                'parts' => [['text' => $msg->messageText]]
            ];
        }

        // Inject the RAG contexts bundled with the latest prompt
        $latestPayload = "Contexto para tu respuesta:\n" . $compiledContext . "\n\nConsulta actual del cliente: " . $userMessage;
        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $latestPayload]]
        ];

        // 7. Request Payload for Gemini Pro/Flash GenerateContent
        $data = [
            'contents' => $contents,
            'systemInstruction' => [
                'parts' => [['text' => $systemInstruction]]
            ],
            'generationConfig' => [
                'temperature' => 0.35, // Highly deterministic responses, preventing sales hallucinations
                'maxOutputTokens' => 600
            ]
        ];

        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=" . $this->apiKey;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception("Gemini service connection error: " . $error);
        }
        
        curl_close($ch);

        $jsonResponse = json_decode($response, true);
        
        if (isset($jsonResponse['candidates'][0]['content']['parts'][0]['text'])) {
            return $jsonResponse['candidates'][0]['content']['parts'][0]['text'];
        } else {
            // Log api failures for tracking
            file_put_contents(
                'gemini_api_error.log', 
                date("Y-m-d H:i:s") . " Response: " . $response . "\n", 
                FILE_APPEND
            );
            return "Lo siento, experimenté una pequeña interrupción en mi sistema de respuesta. ¿Me podrías indicar de nuevo qué producto buscabas?";
        }
    }

    /**
     * Clear and isolate business-relevant keyword phrases for database full-text matching
     */
    private function extractKeywords(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text); // Remove punctuation
        
        $stopwords = ['hola', 'que', 'tal', 'tienes', 'tienen', 'busco', 'necesito', 'quiero', 'precio', 'de', 'la', 'el', 'los', 'las', 'un', 'una', 'unos', 'unas', 'para', 'con', 'en', 'por', 'favor', 'como', 'cual', 'cuantos', 'cuanto', 'cuesta', 'hay', 'alguna', 'algun', 'algún'];
        
        $words = explode(' ', $text);
        $keywords = array_filter($words, function($word) use ($stopwords) {
            $word = trim($word);
            return !empty($word) && !in_array($word, $stopwords);
        });
        
        return implode(' ', $keywords);
    }
}
