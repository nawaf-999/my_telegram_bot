<?php

function tgRequest($method, $payload = []) {
    $token = getenv("BOT_TOKEN");
    if (!$token) return ["ok" => false, "description" => "BOT_TOKEN not set"];

    $url = "https://api.telegram.org/bot{$token}/{$method}";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) return ["ok" => false, "description" => $err];
    $json = json_decode($res, true);
    return $json ?: ["ok" => false, "description" => "Bad JSON response", "raw" => $res];
}

function sendMessage($chatId, $text, $replyTo = null) {
    // IMPORTANT: no parse_mode to avoid "can't parse entities"
    $payload = [
        "chat_id" => $chatId,
        "text" => $text,
        "disable_web_page_preview" => true,
    ];
    if ($replyTo) $payload["reply_to_message_id"] = $replyTo;

    return tgRequest("sendMessage", $payload);
}
