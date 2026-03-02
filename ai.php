<?php

function askAI_OpenRouter($prompt) {
    $apiKey = getenv("OPENROUTER_API_KEY");
    $model  = getenv("AI_MODEL") ?: "openchat/openchat-3.5-0106:free";
    $maxChars = intval(getenv("AI_MAX_CHARS") ?: 1200);

    if (!$apiKey) {
        return ["ok" => false, "text" => "⚠️ ما تم ضبط OPENROUTER_API_KEY في Render."];
    }

    $url = "https://openrouter.ai/api/v1/chat/completions";

    $body = [
        "model" => $model,
        "messages" => [
            ["role" => "system", "content" => "أنت مساعد عربي ذكي، إجاباتك قصيرة وواضحة، وتستخدم لهجة عربية بسيطة عند الحاجة."],
            ["role" => "user", "content" => $prompt],
        ],
        "temperature" => 0.7,
    ];

    $headers = [
        "Authorization: Bearer {$apiKey}",
        "Content-Type: application/json",
        // مهم عند OpenRouter
        "HTTP-Referer: https://nana-bot.onrender.com",
        "X-Title: Nana Telegram Bot",
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_TIMEOUT => 25,
    ]);

    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) return ["ok" => false, "text" => "⚠️ خطأ اتصال بالذكاء الاصطناعي: {$err}"];

    $json = json_decode($res, true);
    $text = $json["choices"][0]["message"]["content"] ?? null;

    if (!$text) {
        $msg = $json["error"]["message"] ?? "رد غير مفهوم من المزود.";
        return ["ok" => false, "text" => "⚠️ صار خطأ في الذكاء الاصطناعي: {$msg}"];
    }

    $text = trim($text);
    if (mb_strlen($text, "UTF-8") > $maxChars) {
        $text = mb_substr($text, 0, $maxChars, "UTF-8") . "…";
    }

    return ["ok" => true, "text" => $text];
}
