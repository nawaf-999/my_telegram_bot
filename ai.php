<?php

function ai_chat($prompt, $ctx = []) {
    $apiKey = getenv("OPENAI_API_KEY");
    if (!$apiKey) return "⚠️ OPENAI_API_KEY غير موجود في Render Env.";

    $model = getenv("OPENAI_MODEL") ?: "gpt-4o-mini";

    $system = "أنت مساعد عربي ذكي داخل بوت تيليجرام اسمه Nana.
- ردودك عربية وواضحة ومختصرة.
- إذا السؤال عن نقاط/ألعاب قل: اكتب (مساعدة) للأوامر.
- لا تذكر مفاتيح أو أسرار.
- إذا ما فهمت اسأل سؤال واحد للتوضيح.";

    $payload = [
        "model" => $model,
        "input" => [
            ["role" => "system", "content" => $system],
            ["role" => "user", "content" => $prompt],
        ],
    ];

    $ch = curl_init("https://api.openai.com/v1/responses");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$apiKey}",
        "Content-Type: application/json",
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));

    $res = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) return "⚠️ صار خطأ في الاتصال بالذكاء الاصطناعي. ({$err})";
    $json = json_decode($res, true);

    if ($code >= 400) {
        // عشان تشوف السبب باللوقس في Render
        error_log("AI error HTTP-{$code}: " . $res);
        return "⚠️ صار خطأ في الذكاء الاصطناعي. تأكد من المفتاح في Render.";
    }

    // Responses API output extraction
    $text = $json["output"][0]["content"][0]["text"] ?? null;
    if (!$text) {
        return "⚠️ ما قدرت أطلع رد من الذكاء الاصطناعي.";
    }

    // مهم: نخليها plain text بدون تنسيق
    return trim($text);
}
