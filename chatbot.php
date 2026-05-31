<?php
// chatbot.php
header('Content-Type: application/json');
require_once 'config.php';
require_once 'gemini_client.php';
require_once 'scraper.php';

// chatbot.php
header('Content-Type: application/json');
require_once 'config.php';
require_once 'gemini_client.php';
require_once 'scraper.php';

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
$userMessage = $input['message'] ?? '';
$sessionId = $input['sessionId'] ?? '';

if (empty($userMessage)) {
    echo json_encode(['error' => 'Mensaje vacío']);
    exit;
}

// Database Connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    // Fallback if DB fails, just work without history
    error_log("Connection failed: " . $conn->connect_error);
    $conn = null;
}

// 1. Scrape context
$productContext = scrapeProducts($userMessage);

// 2. Build System Instruction
$systemInstruction = "Eres un asistente de ventas experto y amable de la tienda 'Naldike Store'. 
Tu objetivo es cerrar ventas proporcionando información clara y concisa.
Usa la información de productos proporcionada para responder.
Si el usuario pregunta por stock, verifica el estado en la información proporcionada.
Si el usuario pide ver un producto, DEBES incluir la imagen usando el formato: [IMAGE: url_de_la_imagen].
NO inventes productos ni precios. Solo usa la información dada.
Si no encuentras la información, sugiere contactar al soporte o visitar la web.
Dirección: " . STORE_ADDRESS . ". Teléfono: " . STORE_PHONE . ".
Responde en español. Sé persuasivo pero honesto.
IMPORTANTE: Si muestras un producto, intenta incluir su imagen.";

// 3. Retrieve History
$history = [];
if ($conn) {
    $stmt = $conn->prepare("SELECT role, message FROM chat_history WHERE session_id = ? ORDER BY created_at ASC LIMIT 10");
    if ($stmt) {
        $stmt->bind_param("s", $sessionId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $history[] = ['role' => $row['role'], 'content' => $row['message']];
        }
        $stmt->close();
    }
}

// 4. Construct Messages
// Add context to the latest user message or as a system note
$messages = $history;
$messages[] = ['role' => 'user', 'content' => "Contexto de productos:\n" . $productContext . "\n\nConsulta del cliente: " . $userMessage];

// 5. Call Gemini
$reply = callGeminiAPI($messages, $systemInstruction);

// 6. Save to Database
if ($conn) {
    $stmt = $conn->prepare("INSERT INTO chat_history (session_id, role, message) VALUES (?, ?, ?)");

    // Save User Message
    $roleUser = 'user';
    $stmt->bind_param("sss", $sessionId, $roleUser, $userMessage);
    $stmt->execute();

    // Save Model Reply
    $roleModel = 'model';
    $stmt->bind_param("sss", $sessionId, $roleModel, $reply);
    $stmt->execute();

    $stmt->close();
    $conn->close();
}

// 7. Return Response
echo json_encode(['reply' => $reply]);
