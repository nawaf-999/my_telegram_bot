<?php
require_once "config.php";
require_once "util.php";

// Load plugins (optional)
foreach (glob("Plugins/*.php") as $plugin) {
    include_once $plugin;
}

$raw = file_get_contents("php://input");
if (!$raw) {
    http_response_code(200);
    exit("OK");
}

$update = json_decode($raw, true);
if (!is_array($update)) {
    http_response_code(200);
    exit("OK");
}

// Support message + callback_query
$message = $update["message"] ?? ($update["callback_query"]["message"] ?? null);
if (!$message) {
    http_response_code(200);
    exit("OK");
}

$chatId = $message["chat"]["id"] ?? null;
$messageId = $message["message_id"] ?? null;
$text = $message["text"] ?? $message["caption"] ?? "";

$from = $message["from"] ?? [];
$fromId = $from["id"] ?? 0;
$firstName = $from["first_name"] ?? "";
$lastName  = $from["last_name"] ?? "";
$fullName = trim($firstName . " " . $lastName);
$username = $from["username"] ?? "";

if (!$chatId) {
    http_response_code(200);
    exit("OK");
}

// normalize arabic text
$cmd = trim($text);
$lower = mb_strtolower($cmd, "UTF-8");

// ✅ لا ترد على كل رسالة برسالة ثابتة (هذا سبب سبام ومشاكل)
// الرد يكون حسب الأوامر فقط

switch ($lower) {

    case "/start":
    case "ابدأ":
    case "ابدا":
        sendMessage($chatId,
"👋 أهلاً $fullName في <b>Nana</b> 🤍

اكتب: <b>مساعدة</b> لعرض الأوامر.");
        break;

    case "مساعدة":
    case "help":
        sendMessage($chatId,
"📋 <b>أوامر Nana</b>

• ابدأ
• مساعدة
• معلوماتي
• نقاطي
• العاب
• عن البوت

جرّب الآن: اكتب <b>معلوماتي</b>");
        break;

    case "معلوماتي":
        sendMessage($chatId,
"👤 <b>معلوماتك</b>
• الاسم: $fullName
• اليوزر: @" . ($username ?: "بدون") . "
• الآيدي: <code>$fromId</code>");
        break;

    case "نقاطي":
        // لاحقًا نخزنها بملف/DB — الآن ثابت
        sendMessage($chatId, "🏆 نقاطك الحالية: <b>0</b>");
        break;

    case "العاب":
        sendMessage($chatId,
"🎮 <b>الألعاب</b>
اكتب: <b>تحدي</b> (تخمين رقم)");
        break;

    case "تحدي":
        // لعبة بسيطة
        $n = rand(1,5);
        // نخزن الرقم في جلسة بسيطة (ملف) لو تبي — الآن نرسل تحدي فقط
        sendMessage($chatId, "🎯 خمن رقم من 1 إلى 5 (اكتب رقم فقط)");
        break;

    case "عن البوت":
        sendMessage($chatId, "🤖 Nana Bot شغال على Render ✅");
        break;

    default:
        // لو كتب رقم للتحدي أو كلام عام
        if (preg_match('/^[1-5]$/', $lower)) {
            sendMessage($chatId, "✅ استلمت رقمك: <b>$lower</b> (نطوّر اللعبة بعدين)");
        } else {
            sendMessage($chatId, "ما فهمت 🙃 اكتب <b>مساعدة</b>.");
        }
        break;
}

http_response_code(200);
echo "OK";
