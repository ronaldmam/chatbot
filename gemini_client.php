<?php
// gemini_client.php
require_once 'config.php';

function callGeminiAPI($messages, $systemInstruction = '')
{
    $apiKey = GEMINI_API_KEY;
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=" . $apiKey;

    // Convert OpenAI-style messages to Gemini format
    // Gemini uses 'parts' => [['text' => '...']] and 'role' => 'user'/'model'
    $contents = [];
    foreach ($messages as $msg) {
        $role = ($msg['role'] === 'user') ? 'user' : 'model';
        $contents[] = [
            'role' => $role,
            'parts' => [['text' => $msg['content']]]
        ];
    }

    // Add system instruction if provided (Gemini Pro supports system instructions via separate field or prepended text)
    // For simplicity and compatibility, we'll prepend it to the first user message or use the system_instruction field if supported by the specific endpoint version.
    // Current v1beta supports system_instruction.

    $data = [
        'contents' => $contents,
        'generationConfig' => [
            'temperature' => 0.7,
            'maxOutputTokens' => 800,
        ]
    ];

    if (!empty($systemInstruction)) {
        $data['systemInstruction'] = [
            'parts' => [['text' => $systemInstruction]]
        ];
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);

    // Disable SSL verification for local dev if needed (not recommended for prod)
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        return "Error connecting to AI: " . curl_error($ch);
    }

    curl_close($ch);

    $jsonResponse = json_decode($response, true);

    if (isset($jsonResponse['candidates'][0]['content']['parts'][0]['text'])) {
        return $jsonResponse['candidates'][0]['content']['parts'][0]['text'];
    } else {
        // Log error for debugging
        file_put_contents('gemini_error.log', $response);
        return "Lo siento, tuve un problema al procesar tu solicitud.";
    }
}
