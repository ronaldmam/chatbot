<?php
require_once __DIR__ . '/config.php';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "--- CUSTOMERS ---\n";
    $stmt = $pdo->query("SELECT id, psid, name FROM customers");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "ID: {$row['id']} | PSID: {$row['psid']} | Name: {$row['name']}\n";
    }
    
    echo "\n--- CONVERSATIONS ---\n";
    $stmt = $pdo->query("SELECT cc.id, cc.customer_id, c.name, cc.flow_state, cc.is_marketplace, cc.marketplace_ref FROM chat_conversations cc JOIN customers c ON cc.customer_id = c.id");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "ConvID: {$row['id']} | CustID: {$row['customer_id']} | CustName: {$row['name']} | Flow: {$row['flow_state']} | Ref: {$row['marketplace_ref']}\n";
    }

    echo "\n--- RECENT MESSAGES ---\n";
    $stmt = $pdo->query("SELECT cm.id, cm.conversation_id, cm.sender, cm.message_text, cm.created_at FROM chat_messages cm ORDER BY cm.id DESC LIMIT 10");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "MsgID: {$row['id']} | ConvID: {$row['conversation_id']} | Sender: {$row['sender']} | Text: {$row['message_text']} | Time: {$row['created_at']}\n";
    }
} catch (Exception $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
}
