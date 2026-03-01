<?php

function apiRequest($method, $params = []) {
    $token = getenv("BOT_TOKEN");
    if (!$token) return false;

    $url = "https://api.telegram.org/bot{$token}/{$method}";

    $options = [
        "http" => [
            "header"  => "Content-Type: application/x-www-form-urlencoded\r\n",
            "method"  => "POST",
            "content" => http_build_query($params),
            "timeout" => 30
        ]
    ];

    $context  = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);
    return $result ? json_decode($result, true) : false;
}

function sendMessage($chatId, $text) {
    // لا parse_mode نهائياً (هذا يمنع خطأ Unsupported start tag)
    return apiRequest("sendMessage", [
        "chat_id" => $chatId,
        "text"    => $text,
        "disable_web_page_preview" => true
    ]);
}
