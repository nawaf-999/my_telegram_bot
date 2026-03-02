<?php
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/util.php";

// اقرأ تحديث تيليجرام
$raw = file_get_contents("php://input");
if (!$raw) {
    http_response_code(200);
    exit("OK");
}

$update = json_decode($raw, true);
if (!$update) {
    http_response_code(200);
    exit("OK");
}

// استخرج الرسالة
$message = $update["message"] ?? null;
if (!$message) {
    // تجاهل أي نوع آخر (callback_query وغيره) حالياً
    http_response_code(200);
    exit("OK");
}

$chatId = $message["chat"]["id"] ?? null;
$text   = $message["text"] ?? "";

if (!$chatId) {
    http_response_code(200);
    exit("OK");
}

// تطبيع النص (عربي)
$text = trim($text);
$textLower = mb_strtolower($text, "UTF-8");

// أوامر البوت (عربي 100%)
switch ($textLower) {

    case "/start":
    case "ابدأ":
    case "ابدا":
        sendMessage($chatId,
"  اهلين ، انا Nana كيف تحب اساعدك ؟ .

اكتب: مساعدة
لعرض قائمة الأوامر.");
        break;

    case "مساعدة":
    case "help":
        sendMessage($chatId,
"📋 الأوامر المتاحة:

ابدأ
مساعدة
معلوماتي
نقاطي
لعبة
عن البوت");
        break;

    case "معلوماتي":
        $first = $message["from"]["first_name"] ?? "";
        $last  = $message["from"]["last_name"] ?? "";
        $name  = trim($first . " " . $last);
        $user  = $message["from"]["username"] ?? "";
        $uid   = $message["from"]["id"] ?? "";

        sendMessage($chatId,
"👤 معلوماتك:
الاسم: {$name}
المعرف: @" . ($user ?: "بدون")
 . "\nالآي دي: {$uid}");
        break;

    case "نقاطي":
        // حالياً ثابتة (نطورها لاحقاً)
        sendMessage($chatId, "🏆 نقاطك الحالية: 0");
        break;

    case "لعبة":
        $n = rand(1, 5);
        // نرسل التحدي ونخزن الرقم في الذاكرة لاحقاً (حالياً بس مثال)
        sendMessage($chatId, "🎮 لعبة سريعة: خمن رقم من 1 إلى 5 (اكتب الرقم فقط).");
        break;

    case "عن البوت":
        sendMessage($chatId, "اي استفسار بخصوصي تقدر تواصل مع المنشئ @NTTIX .");
        break;

    default:
        // لو المستخدم كتب رقم
        if (preg_match('/^[1-5]$/', $textLower)) {
            sendMessage($chatId, "👍 استلمت اختيارك: {$textLower}\n(بنضيف نظام الفوز والخسارة قريباً)");
        } else {
            sendMessage($chatId, "ما فهمت عليك 🤍\nاكتب: مساعدة");
        }
        break;
}

http_response_code(200);
echo "OK";
