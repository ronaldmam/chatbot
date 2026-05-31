<?php
// get_history.php
header('Content-Type: application/json');
require_once 'config.php';

$sessionId = $_GET['sessionId'] ?? '';

if (empty($sessionId)) {
    echo json_encode(['error' => 'Missing session ID']);
    exit;
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$history = [];
$stmt = $conn->prepare("SELECT role, message FROM chat_history WHERE session_id = ? ORDER BY created_at ASC");
if ($stmt) {
    $stmt->bind_param("s", $sessionId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $history[] = [
            'role' => ($row['role'] === 'user') ? 'user' : 'bot',
            'message' => $row['message']
        ];
    }
    $stmt->close();
}

$conn->close();

echo json_encode(['history' => $history]);
