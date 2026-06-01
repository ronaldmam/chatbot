<?php
// index.php

// Set up error reporting for local development (should be adjusted for production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Enable standard CORS headers (crucial for Angular decoupled communication)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Load Composer Autoloader and Global Configuration
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

use App\Core\Router;
use App\Core\Request;
use App\Core\Response;

// 1. Initialize the Custom Router
$router = new Router();

// 2. Register Routes (To be mapped as we implement Controllers)

// Basic check route (Ping/Health)
$router->get('/api/ping', function() {
    Response::json(['status' => 'online', 'message' => 'Naldike Store multi-agent platform is running!']);
});

// Authentication Routes
$router->post('/api/auth/login', [App\Controllers\AuthController::class, 'login']);

// Webhook / Messaging Channel Routes
$router->get('/api/webhook/messenger', [App\Controllers\WebhookController::class, 'verifyMessenger']);
$router->post('/api/webhook/messenger', [App\Controllers\WebhookController::class, 'handleMessenger']);
$router->get('/api/webhook/whatsapp', [App\Controllers\WebhookController::class, 'verifyWhatsApp']);
$router->post('/api/webhook/whatsapp', [App\Controllers\WebhookController::class, 'handleWhatsApp']);

// Chat Auditing & Override Routes (Protected)
$router->get('/api/chats', [App\Controllers\ChatController::class, 'getAll'], [App\Core\Middleware\JwtAuthMiddleware::class]);
$router->get('/api/chats/stats', [App\Controllers\ChatController::class, 'stats'], [App\Core\Middleware\JwtAuthMiddleware::class]);
$router->get('/api/chats/{id}', [App\Controllers\ChatController::class, 'getMessages'], [App\Core\Middleware\JwtAuthMiddleware::class]);
$router->put('/api/chats/{id}/state', [App\Controllers\ChatController::class, 'updateState'], [App\Core\Middleware\JwtAuthMiddleware::class]);
$router->post('/api/chats/{id}/message', [App\Controllers\ChatController::class, 'sendMessage'], [App\Core\Middleware\JwtAuthMiddleware::class]);

// Browser automation scraper route (Public locally)
$router->post('/api/automation/message', [App\Controllers\ChatController::class, 'handleAutomationMessage']);
$router->post('/api/automation/followup', [App\Controllers\ChatController::class, 'handleAutomationFollowup']);
$router->get('/api/automation/pending', [App\Controllers\ChatController::class, 'getPendingAutomationMessages']);
$router->post('/api/automation/delivered', [App\Controllers\ChatController::class, 'markAutomationMessagesDelivered']);

// Knowledge Ingestion & RAG Index Routes (Protected)
$router->get('/api/ingest', [App\Controllers\IngestionController::class, 'getAll'], [App\Core\Middleware\JwtAuthMiddleware::class]);
$router->post('/api/ingest/url', [App\Controllers\IngestionController::class, 'ingestUrl'], [App\Core\Middleware\JwtAuthMiddleware::class]);
$router->post('/api/ingest/pdf', [App\Controllers\IngestionController::class, 'ingestPdf'], [App\Core\Middleware\JwtAuthMiddleware::class]);
$router->post('/api/ingest/woocommerce', [App\Controllers\IngestionController::class, 'syncWooCommerce'], [App\Core\Middleware\JwtAuthMiddleware::class]);
$router->delete('/api/ingest/{id}', [App\Controllers\IngestionController::class, 'deleteItem'], [App\Core\Middleware\JwtAuthMiddleware::class]);

// System settings configuration routes (Protected)
$router->get('/api/settings/bot', [App\Controllers\SettingsController::class, 'getBotSettings'], [App\Core\Middleware\JwtAuthMiddleware::class]);
$router->post('/api/settings/bot', [App\Controllers\SettingsController::class, 'saveBotSettings'], [App\Core\Middleware\JwtAuthMiddleware::class]);

// 3. Dispatch the incoming request
try {
    $router->dispatch();
} catch (\Exception $e) {
    Response::json([
        'error' => 'Internal Server Error',
        'details' => $e->getMessage()
    ], 500);
}
