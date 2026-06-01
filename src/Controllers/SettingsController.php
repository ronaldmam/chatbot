<?php
// src/Controllers/SettingsController.php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

class SettingsController
{
    private string $settingsFilePath;

    public function __construct()
    {
        $this->settingsFilePath = dirname(dirname(__DIR__)) . '/bot_settings.json';
    }

    /**
     * Get active bot configuration settings.
     * Route: GET /api/settings/bot
     */
    public function getBotSettings(): void
    {
        try {
            $settings = $this->loadSettings();
            Response::json($settings);
        } catch (\Exception $e) {
            Response::json([
                'error' => 'Internal Server Error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update active bot configuration settings.
     * Route: POST /api/settings/bot
     */
    public function saveBotSettings(): void
    {
        try {
            $body = Request::getBody();
            
            $welcome = $body['welcome_message'] ?? '';
            $opt1 = $body['option_1_response'] ?? '';
            $opt2 = $body['option_2_response'] ?? '';
            $globalAiInstructions = $body['global_ai_instructions'] ?? '';

            if (empty($welcome) || empty($opt1) || empty($opt2)) {
                Response::json([
                    'error' => 'Bad Request',
                    'message' => 'All templates (Welcome message, Option 1, and Option 2) are required.'
                ], 400);
                return;
            }

            $settings = [
                'welcome_message' => $welcome,
                'option_1_response' => $opt1,
                'option_2_response' => $opt2,
                'global_ai_instructions' => $globalAiInstructions,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $result = file_put_contents(
                $this->settingsFilePath,
                json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            );

            if ($result === false) {
                Response::json([
                    'error' => 'Internal Server Error',
                    'message' => 'Failed to write settings to file system.'
                ], 500);
                return;
            }

            Response::json([
                'message' => 'Bot settings successfully updated.',
                'settings' => $settings
            ]);
        } catch (\Exception $e) {
            Response::json([
                'error' => 'Internal Server Error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Helper to load settings from JSON or compile default fallback
     */
    public function loadSettings(): array
    {
        if (file_exists($this->settingsFilePath)) {
            $content = file_get_contents($this->settingsFilePath);
            $data = json_decode($content, true);
            if (is_array($data)) {
                if (!isset($data['global_ai_instructions'])) {
                    $data['global_ai_instructions'] = '';
                }
                return $data;
            }
        }

        // Default templates fallback
        return [
            'welcome_message' => "¡Hola! Bienvenido a Naldike Store 🛍️. Elige una opción escribiendo el número:\n\n1️⃣ Consultar Catálogo / Stock\n2️⃣ Ver Estado de mi Pedido\n3️⃣ Hablar con el Asistente Inteligente (IA) / Preguntas Complejas",
            'option_1_response' => "Has seleccionado: Consultar Catálogo 🛍️. Escribe cualquier búsqueda de producto (ej. 'linterna') junto con el número '3' para activar el Asistente Inteligente (IA) que buscará en nuestro inventario en tiempo real.",
            'option_2_response' => "Has seleccionado: Ver Estado de Pedido 📦. Ingresa tu número de boleta/pedido y escribe '3' para activar el Asistente Inteligente (IA) para consultar con nuestro sistema.",
            'global_ai_instructions' => ''
        ];
    }
}
