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

        // 1. Extract keywords to perform efficient RAG index searches
        $keywords = $this->extractKeywords($userMessage);

        $ragContext = '';
        $productContext = '';

        if (!empty($keywords)) {
            // 2. Query KnowledgeBase RAG for matches (PDF rules, URLs)
            $kbMatches = $this->kbRepository->search($keywords, 2);
            foreach ($kbMatches as $match) {
                $ragContext .= "Base de Conocimiento ({$match->title}):\n{$match->content}\n\n";
            }

            // 3. Search WooCommerce stock real-time status for product keywords
            $productContext = $this->wcService->searchProducts($keywords);
        }

        // 4. Build consolidated search context
        $compiledContext = "=== CONTEXTO DE NALDIKE STORE ===\n";
        if (!empty($ragContext)) {
            $compiledContext .= $ragContext;
        } else {
            $compiledContext .= "No hay información específica en el manual de políticas de la empresa.\n";
        }

        $compiledContext .= "\n=== INVENTARIO Y STOCK REAL ===\n";
        $compiledContext .= $productContext . "\n";

        // 5. Structure System Instructions to enforce corporate rules and behavior
        $systemInstruction = "Eres un asistente de ventas experto y muy amable de la tienda 'Naldike Store'. 
        Tu objetivo es cerrar ventas proporcionando información clara, concisa y veraz.
        Usa la información de la empresa y los productos proporcionados en el contexto para responder la consulta del cliente.
        NO inventes productos, precios, políticas de envío o devoluciones que no estén descritos explícitamente en el contexto.
        Si no posees la información solicitada en el contexto, sé honesto y dile que esperarás a transferirlo con un asesor humano.
        Dirección comercial: " . (defined('STORE_ADDRESS') ? STORE_ADDRESS : 'Av. Principal 123, Lima, Perú') . ".
        Teléfono de contacto: " . (defined('STORE_PHONE') ? STORE_PHONE : '939021800') . ".
        Responde siempre en español. Sé conversacional, persuasivo y servicial.
        IMPORTANTE: Como estás en un chat móvil (WhatsApp/Messenger), mantén tus respuestas muy cortas (máximo 2-3 párrafos breves o 4 oraciones en total) y haz preguntas para continuar la venta.";

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
