<?php
// fb_handler.php
require_once 'config.php';

function callSendAPI($sender_psid, $response_text) {
    if (empty(FB_PAGE_ACCESS_TOKEN) || FB_PAGE_ACCESS_TOKEN === 'AQUI_TU_PAGE_ACCESS_TOKEN') {
        error_log("Error: FB_PAGE_ACCESS_TOKEN no configurado.");
        return false;
    }

    $url = "https://graph.facebook.com/v19.0/me/messages?access_token=" . FB_PAGE_ACCESS_TOKEN;

    $data = [
        'recipient' => ['id' => $sender_psid],
        'message' => ['text' => $response_text],
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
        error_log('Error cURL enviando mensaje a Messenger: ' . curl_error($ch));
    }
    
    curl_close($ch);
    return $result;
}
