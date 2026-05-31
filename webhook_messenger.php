<?php
// webhook_messenger.php

require_once 'config.php';
require_once 'fb_handler.php';
require_once 'gemini_client.php';
require_once 'scraper.php';

// 1. Verificación manual del Webhook (GET request desde Meta)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $verify_token = FB_VERIFY_TOKEN;

    if (isset($_GET['hub_mode']) && $_GET['hub_mode'] === 'subscribe' && isset($_GET['hub_verify_token'])) {
        if ($_GET['hub_verify_token'] === $verify_token) {
            echo $_GET['hub_challenge'];
            http_response_code(200);
            exit;
        } else {
            http_response_code(403);
            exit;
        }
    }
}

// 2. Recepción de mensajes de usuarios (POST)
$inputJSON = file_get_contents('php://input');

if ($inputJSON) {
    // Guarda el registro crudo para propósitos de depuración en caso de errores con Webhooks
    file_put_contents('fb_log.txt', date("Y-m-d H:i:s") . " " . $inputJSON . "\n", FILE_APPEND);
    
    $input = json_decode($inputJSON, true);

    if (isset($input['object']) && $input['object'] === 'page') {

        foreach ($input['entry'] as $entry) {
            
            // Check if messaging event exists
            if (isset($entry['messaging'][0])) {
                $webhook_event = $entry['messaging'][0];
                $sender_psid = $webhook_event['sender']['id'];

                if (isset($webhook_event['message']) && isset($webhook_event['message']['text'])) {
                    $userMessage = $webhook_event['message']['text'];

                    // Conexión a la base de datos
                    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
                    if ($conn->connect_error) {
                        $conn = null;
                        error_log("Error de BD en Webhook: " . $conn->connect_error);
                    }

                    // Scrape información de productos dependiendo de búsqueda actual
                    $productContext = scrapeProducts($userMessage);

                    // Instrucción base
                    $systemInstruction = "Eres un asistente de ventas experto y amable de la tienda '" . STORE_NAME . "'. 
                    Tu objetivo es cerrar ventas proporcionando información clara y concisa.
                    Usa la información de productos proporcionada para responder.
                    NO inventes productos ni precios. Solo usa la información dada.
                    Dirección: " . STORE_ADDRESS . ". Teléfono: " . STORE_PHONE . ".
                    Responde en español. Sé persuasivo pero honesto.
                    IMPORTANTE: Este es un chat en Messenger, no incluyas el texto [IMAGE: url] ni [VIDEO: url], si necesitas enviar un link a la imagen simplemente escribe el enlace directo de la imagen.";

                    // Recuperar el historial de chat (el session_id es el id de usuario de facebook `sender_psid`)
                    $history = [];
                    if ($conn) {
                        $stmt = $conn->prepare("SELECT role, message FROM chat_history WHERE session_id = ? ORDER BY created_at ASC LIMIT 10");
                        if ($stmt) {
                            $stmt->bind_param("s", $sender_psid);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            while ($row = $result->fetch_assoc()) {
                                $history[] = ['role' => $row['role'], 'content' => $row['message']];
                            }
                            $stmt->close();
                        }
                    }

                    // Construir los mensajes para Gemini incluyendo el nuevo mensaje del usuario
                    $messages = $history;
                    $messages[] = ['role' => 'user', 'content' => "Contexto de productos:\n" . $productContext . "\n\nConsulta del cliente: " . $userMessage];

                    // Llamar a Gemini y obtener la respuesta
                    $reply = callGeminiAPI($messages, $systemInstruction);

                    // Reemplazar la marca de imagen si Gemini todavía generó alguna, por el puro enlace.
                    $reply = preg_replace('/\[(IMAGE|VIDEO):\s*(.*?)\]/', '$2', $reply);

                    // Guardar Historial en Base de Datos
                    if ($conn) {
                        $stmt = $conn->prepare("INSERT INTO chat_history (session_id, role, message) VALUES (?, ?, ?)");
                        $roleUser = 'user';
                        $stmt->bind_param("sss", $sender_psid, $roleUser, $userMessage);
                        $stmt->execute();
                        
                        $roleModel = 'model';
                        $stmt->bind_param("sss", $sender_psid, $roleModel, $reply);
                        $stmt->execute();
                        
                        $stmt->close();
                        $conn->close();
                    }

                    // Responder vía Facebook Messenger
                    callSendAPI($sender_psid, $reply);
                }
            }
        }

        // Devolver código HTTP 200 a Meta para confirmar recepción y evitar bloqueos
        http_response_code(200);
        exit;
    }
}

// Si la solicitud no proviene de un objeto de página o no es válida
http_response_code(404);
