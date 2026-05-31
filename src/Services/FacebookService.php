<?php
// src/Services/FacebookService.php

namespace App\Services;

class FacebookService
{
    private string $accessToken;

    public function __construct()
    {
        $this->accessToken = defined('FB_PAGE_ACCESS_TOKEN') ? FB_PAGE_ACCESS_TOKEN : '';
    }

    /**
     * Send a standard text message reply to a customer via FB Messenger Send API
     * 
     * @param string $recipientId Customer's PSID
     * @param string $text Message response content
     * @return bool True on success, False on failure
     */
    public function sendTextMessage(string $recipientId, string $text): bool
    {
        if (empty($this->accessToken) || $this->accessToken === 'AQUI_TU_PAGE_ACCESS_TOKEN') {
            error_log("Facebook Page Access Token is not configured.");
            return false;
        }

        $url = "https://graph.facebook.com/v19.0/me/messages?access_token=" . $this->accessToken;

        $data = [
            'recipient' => ['id' => $recipientId],
            'message' => ['text' => $text],
            'messaging_type' => 'RESPONSE'
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        // Disable SSL verification for development environments if needed
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $result = curl_exec($ch);
        
        if (curl_errno($ch)) {
            error_log('Facebook API curl error: ' . curl_error($ch));
            curl_close($ch);
            return false;
        }

        $response = json_decode($result, true);
        curl_close($ch);

        return isset($response['message_id']) || isset($response['recipient_id']);
    }
}
