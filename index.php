<?php
require_once "config.php";
require_once "util.php";

foreach (glob("Plugins/*.php") as $plugin) {
    include_once $plugin;
}

$update = file_get_contents("php://input");
if (empty($update)) {
    exit("OK");
}

$updateData = json_decode($update, true);
if (!$updateData) exit("OK");

$messageData = isset($updateData["callback_query"])
    ? $updateData["callback_query"]["message"]
    : ($updateData["message"] ?? null);

if (!$messageData) exit("OK");

$chatId = $messageData["chat"]["id"];
$messageId = $messageData["message_id"] ?? null;

$messageText = $messageData["text"] ?? "";
$messageText = trim($messageText);

$from = $messageData["from"] ?? [];
$from_id = $from["id"] ?? 0;
$from_first = $from["first_name"] ?? "";
$from_last = $from["last_name"] ?? "";
$from_name = trim($from_first . " " . $from_last);
$from_username = $from["username"] ?? "";

// Normalize text (Arabic-friendly)
$text = trim($messageText);

// 1) AI mode only if starts with #
if (mb_substr($text, 0, 1, "UTF-8") === "#") {
    $prompt = trim(mb_substr($text, 1, null, "UTF-8"));
    if ($prompt === "") {
        sendMessage($chatId, "اكتب سؤالك بعد علامة # مثال:\n# كم تاريخ اليوم؟", $messageId);
        exit("OK");
    }

    $aiReply = ai_chat($prompt, [
        "chat_id" => $chatId,
        "user_id" => $from_id,
        "name" => $from_name,
        "username" => $from_username,
    ]);

    sendMessage($chatId, $aiReply, $messageId);
    exit("OK");
}

// 2) Commands (Arabic)
handle_core_commands($chatId, $messageId, $text, [
    "user_id" => $from_id,
    "name" => $from_name,
    "username" => $from_username,
]);

exit("OK");
