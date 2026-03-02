<?php
require_once __DIR__ . "/config.php";

function askAI($prompt) {

  $apiKey = getenv("OPENROUTER_API_KEY");

  if (!$apiKey) {
    return ["ok" => false, "text" => "⚠️ ما تم ضبط مفتاح OpenRouter في Render."];
  }

  $url = "https://openrouter.ai/api/v1/chat/completions";

  $body = [
    "model" => "openchat/openchat-3.5-0106:free", // موديل مجاني
    "messages" => [
      ["role" => "system", "content" => "أنت مساعد عربي ذكي ومختصر."],
      ["role" => "user", "content" => $prompt]
    ]
  ];

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      "Authorization: Bearer " . $apiKey,
      "HTTP-Referer: https://your-render-url.onrender.com",
      "Content-Type: application/json"
    ],
    CURLOPT_POSTFIELDS => json_encode($body),
    CURLOPT_TIMEOUT => 20,
  ]);

  $res = curl_exec($ch);
  curl_close($ch);

  $json = json_decode($res, true);

  if (!isset($json["choices"][0]["message"]["content"])) {
    return ["ok" => false, "text" => "⚠️ صار خطأ من OpenRouter."];
  }

  return ["ok" => true, "text" => $json["choices"][0]["message"]["content"]];
}
