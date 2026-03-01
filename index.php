<?php
require_once "config.php";
require_once "util.php";

foreach (glob("Plugins/*") as $plugin) {
    include_once $plugin;
}

$raw = file_get_contents("php://input");
if (empty($raw)) {
    exit("OK"); // لا تفتح هذا الملف من المتصفح
}

$update = json_decode($raw, true);
if (!is_array($update)) exit("OK");

// ===== استخراج الرسالة (message أو callback_query) =====
$message = $update["message"] ?? ($update["callback_query"]["message"] ?? null);
if (!$message) exit("OK");

$chatId   = $message["chat"]["id"] ?? null;
$textRaw  = $message["text"] ?? "";
$from     = $message["from"] ?? [];
$userId   = $from["id"] ?? 0;
$first    = $from["first_name"] ?? "";
$last     = $from["last_name"] ?? "";
$name     = trim($first . " " . $last);
$username = $from["username"] ?? "";

if ($chatId === null) exit("OK");

// ===== أدوات مساعدة =====
function isAdmin($userId, $sudoID) {
    return (string)$userId === (string)$sudoID;
}

function normalize($s) {
    $s = trim($s);
    // نخلي العربي مثل ما هو، وننزل الانجليزي فقط
    return trim(mb_strtolower($s, "UTF-8"));
}

// ===== تخزين بسيط (نقاط + جلسة اللعبة) =====
$dataDir = __DIR__ . "/data";
if (!is_dir($dataDir)) @mkdir($dataDir, 0777, true);

$pointsFile = $dataDir . "/points.json";
if (!file_exists($pointsFile)) file_put_contents($pointsFile, "{}");

function loadPoints($file) {
    $j = json_decode(file_get_contents($file), true);
    return is_array($j) ? $j : [];
}
function savePoints($file, $arr) {
    file_put_contents($file, json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function addPoints($pointsFile, $userId, $amount) {
    $points = loadPoints($pointsFile);
    if (!isset($points[$userId])) $points[$userId] = 0;
    $points[$userId] += (int)$amount;
    if ($points[$userId] < 0) $points[$userId] = 0;
    savePoints($pointsFile, $points);
    return $points[$userId];
}

function getPoints($pointsFile, $userId) {
    $points = loadPoints($pointsFile);
    return (int)($points[$userId] ?? 0);
}

$gameFile = $dataDir . "/game_" . $chatId . ".json";
function startGame($file) {
    $secret = rand(1, 5);
    file_put_contents($file, json_encode(["secret"=>$secret, "active"=>true], JSON_UNESCAPED_UNICODE));
}
function loadGame($file) {
    if (!file_exists($file)) return ["active"=>false];
    $j = json_decode(file_get_contents($file), true);
    return is_array($j) ? $j : ["active"=>false];
}
function endGame($file) {
    if (file_exists($file)) @unlink($file);
}

// ===== قراءة النص =====
$text = normalize($textRaw);

// ===== ردود احترافية عربية =====
$HELP =
"📌 *أوامر Nana*  
- ابدأ  
- مساعدة  
- معلوماتي  
- نقاطي  
- العاب  
- تحدي  
- احزر <رقم>

👮‍♂️ *أوامر المشرف* (للشخص المصرّح فقط)  
- زد_نقاط <id> <عدد>  
- احذف_نقاط <id> <عدد>

ملاحظة: في القروبات قد تحتاج تعطيل *Privacy Mode* من BotFather عشان يقرأ رسائل بدون (/).";

$WELCOME =
"👋 أهلًا بك في *Nana* 🤍  
أنا بوت عربي للأوامر والنقاط والألعاب.

اكتب: *مساعدة* لعرض الأوامر.";

// ===== Router =====
if ($text === "/start" || $text === "ابدأ" || $text === "start") {
    sendMessage($chatId, $WELCOME);
    exit("OK");
}

if ($text === "مساعدة" || $text === "اوامر" || $text === "الأوامر" || $text === "help") {
    sendMessage($chatId, $HELP);
    exit("OK");
}

if ($text === "معلوماتي" || $text === "معلومات") {
    $u = $username ? "@$username" : "بدون";
    sendMessage($chatId,
"👤 *معلوماتك*  
الاسم: $name  
المعرف: $u  
الآي دي: `$userId`");
    exit("OK");
}

if ($text === "نقاطي" || $text === "نقاط") {
    $p = getPoints($pointsFile, $userId);
    sendMessage($chatId, "🏆 نقاطك الحالية: *$p*");
    exit("OK");
}

if ($text === "العاب" || $text === "ألعاب") {
    sendMessage($chatId, "🎮 لعبة سريعة: اكتب *تحدي* لبدء لعبة التخمين (1 إلى 5).");
    exit("OK");
}

if ($text === "تحدي") {
    startGame($gameFile);
    sendMessage($chatId, "🎯 بدأت اللعبة! اكتب: *احزر 3* (رقم من 1 إلى 5)");
    exit("OK");
}

// احزر <رقم>
if (preg_match('/^احزر\s+(\d+)$/u', $text, $m)) {
    $guess = (int)$m[1];
    if ($guess < 1 || $guess > 5) {
        sendMessage($chatId, "⚠️ اختر رقم من 1 إلى 5 فقط.");
        exit("OK");
    }

    $game = loadGame($gameFile);
    if (empty($game["active"])) {
        sendMessage($chatId, "ℹ️ ما فيه لعبة شغالة. اكتب *تحدي* أول.");
        exit("OK");
    }

    $secret = (int)$game["secret"];
    if ($guess === $secret) {
        endGame($gameFile);
        $newP = addPoints($pointsFile, $userId, 5);
        sendMessage($chatId, "✅ صح! الرقم كان *$secret* 🎉\n+5 نقاط لك ✅\n🏆 نقاطك الآن: *$newP*");
    } else {
        sendMessage($chatId, "❌ غلط! جرّب مرة ثانية.");
    }
    exit("OK");
}

// ===== أوامر المشرف =====
if (isAdmin($userId, $sudoID)) {

    // زد_نقاط <id> <عدد>
    if (preg_match('/^زد_نقاط\s+(\d+)\s+(\d+)$/u', $text, $m)) {
        $target = $m[1];
        $amount = (int)$m[2];
        $newP = addPoints($pointsFile, $target, $amount);
        sendMessage($chatId, "✅ تم إضافة *$amount* نقطة للعضو `$target`.\n🏆 نقاطه الآن: *$newP*");
        exit("OK");
    }

    // احذف_نقاط <id> <عدد>
    if (preg_match('/^احذف_نقاط\s+(\d+)\s+(\d+)$/u', $text, $m)) {
        $target = $m[1];
        $amount = (int)$m[2];
        $newP = addPoints($pointsFile, $target, -$amount);
        sendMessage($chatId, "✅ تم خصم *$amount* نقطة من العضو `$target`.\n🏆 نقاطه الآن: *$newP*");
        exit("OK");
    }
}

// ===== افتراضي =====
/*
لو تبي يرد على أي كلام غير معروف فعّل هذا:
sendMessage($chatId, "ما فهمت عليك. اكتب (مساعدة).");
*/
exit("OK");
