<?php
// src/Services/WhatsAppService.php

namespace App\Services;

class WhatsAppService
{
    private string $accessToken;
    private string $phoneNumberId;

    public function __construct()
    {
        $this->accessToken = defined('WA_ACCESS_TOKEN') ? WA_ACCESS_TOKEN : '';
        $this->phoneNumberId = defined('WA_PHONE_NUMBER_ID') ? WA_PHONE_NUMBER_ID : '';
    }

    /**
     * Send a standard text message reply to a customer via WhatsApp Cloud API
     * 
     * @param string $recipientPhone Customer's phone number with country code (e.g. 51939021800)
     * @param string $text Message response content
     * @return bool True on success, False on failure
     */
    public function sendTextMessage(string $recipientPhone, string $text): bool
    {
        if (empty($this->accessToken) || empty($this->phoneNumberId) || $this->accessToken === 'AQUI_TU_WHATSAPP_ACCESS_TOKEN') {
            error_log("WhatsApp Cloud API credentials are not fully configured.");
            return false;
        }

        $url = "https://graph.facebook.com/v19.0/" . $this->phoneNumberId . "/messages";

        $data = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $recipientPhone,
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $text
            ]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->accessToken
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        // Disable SSL verification for development environments if needed
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $result = curl_exec($ch);
        
        if (curl_errno($ch)) {
            error_log('WhatsApp Cloud API curl error: ' . curl_error($ch));
            curl_close($ch);
            return false;
        }

        $response = json_decode($result, true);
        curl_close($ch);

        return isset($response['messages'][0]['id']);
    }
}
