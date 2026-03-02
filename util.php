<?php

function envOrFail(string $key): string {
    $v = getenv($key);
    if (!$v) {
        error_log("Missing env: $key");
        return "";
    }
    return $v;
}

function tgRequest(string $method, array $params = []): ?array {
    $token = envOrFail("BOT_TOKEN");
    if ($token === "") return null;

    $url = "https://api.telegram.org/bot{$token}/{$method}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_TIMEOUT => 25,
    ]);

    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        error_log("curl error: " . $err);
        return null;
    }

    $data = json_decode($resp, true);
    if (!is_array($data)) {
        error_log("bad json from tg: " . $resp);
        return null;
    }

    if ($code >= 400 || ($data["ok"] ?? false) !== true) {
        error_log("tg api error: HTTP={$code} resp={$resp}");
    }

    return $data;
}

function sendMessage(int|string $chatId, string $text, ?int $replyTo = null): void {
    $payload = [
        "chat_id" => $chatId,
        "text" => $text,
        "disable_web_page_preview" => true,
    ];
    if ($replyTo) $payload["reply_to_message_id"] = $replyTo;

    tgRequest("sendMessage", $payload);
}

function ensureDataDir(): string {
    $dir = __DIR__ . "/data";
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    return $dir;
}

function jsonLoad(string $path, array $default = []): array {
    if (!file_exists($path)) return $default;
    $raw = file_get_contents($path);
    $j = json_decode($raw, true);
    return is_array($j) ? $j : $default;
}

function jsonSave(string $path, array $data): void {
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function nowTs(): int { return time(); }

function norm(string $s): string {
    $s = trim($s);
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    return mb_strtolower($s, "UTF-8");
}
