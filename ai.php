<?php

function aiReply(string $prompt): string {
    $apiKey = getenv("OPENAI_API_KEY");
    if (!$apiKey) {
        return "⚠️ الذكاء الاصطناعي غير مُفعّل حالياً (OPENAI_API_KEY غير موجود في Render).";
    }

    $model = getenv("AI_MODEL") ?: "gpt-4.1-mini";

    $system =
"أنت مساعد داخل بوت تيليجرام اسمه Nana.
اللغة العربية فقط.
كن عمليًا ومختصرًا.
إذا طلب المستخدم ألعاب/فعاليات للبوت أعطه أفكار جاهزة.
تجنب أي محتوى مخالف أو خطير.";

    $payload = [
        "model" => $model,
        "messages" => [
            ["role" => "system", "content" => $system],
            ["role" => "user", "content" => $prompt],
        ],
        "temperature" => 0.7,
        "max_tokens" => 350,
    ];

    $ch = curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer " . $apiKey,
            "Content-Type: application/json",
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 25,
    ]);

    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) return "⚠️ تعذر الاتصال بالذكاء الاصطناعي. حاول لاحقًا.";
    $data = json_decode($resp, true);

    if ($code >= 400 || !isset($data["choices"][0]["message"]["content"])) {
        error_log("AI error HTTP={$code} resp={$resp}");
        return "⚠️ صار خطأ في الذكاء الاصطناعي. حاول مرة ثانية.";
    }

    return trim($data["choices"][0]["message"]["content"]);
}
