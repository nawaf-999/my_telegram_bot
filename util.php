<?php

function botToken() {
    $t = getenv('BOT_TOKEN');
    if (!$t) { die("BOT_TOKEN missing"); }
    return $t;
}

function apiRequest($method, $params = []) {
    $url = "https://api.telegram.org/bot" . botToken() . "/" . $method;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);

    $res = curl_exec($ch);
    if ($res === false) {
        error_log("curl error: " . curl_error($ch));
        curl_close($ch);
        return null;
    }
    curl_close($ch);

    $json = json_decode($res, true);
    if (!is_array($json) || ($json["ok"] ?? false) !== true) {
        error_log("Telegram API error: " . $res);
    }
    return $json;
}

function sendMessage($chatId, $text, $replyToMessageId = null) {
    $payload = [
        "chat_id" => $chatId,
        "text" => $text,
        "parse_mode" => "HTML",
        "disable_web_page_preview" => true
    ];
    if ($replyToMessageId) $payload["reply_to_message_id"] = $replyToMessageId;

    return apiRequest("sendMessage", $payload);
}
