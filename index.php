<?php
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/util.php";
require_once __DIR__ . "/ai.php";

/**
 * Nana — نظام عربي احترافي:
 * - أوامر + نقاط + تحويل + متصدرين + هدية يومية
 * - ألعاب: تخمين رقم، حجر/ورقة/مقص، صح/خطأ (أسئلة)، حساب سريع
 * - الذكاء الاصطناعي: فقط إذا الرسالة تبدأ بـ #
 */

$raw = file_get_contents("php://input");
if (!$raw) { http_response_code(200); exit("OK"); }

$update = json_decode($raw, true);
if (!is_array($update)) { http_response_code(200); exit("OK"); }

$message = $update["message"] ?? ($update["callback_query"]["message"] ?? null);
if (!$message) { http_response_code(200); exit("OK"); }

$chatId = $message["chat"]["id"] ?? null;
if ($chatId === null) { http_response_code(200); exit("OK"); }

$chatType = $message["chat"]["type"] ?? "private";
$textRaw  = $message["text"] ?? ($message["caption"] ?? "");
$textTrim = trim((string)$textRaw);

$from = $message["from"] ?? [];
$userId = (int)($from["id"] ?? 0);
$first  = (string)($from["first_name"] ?? "");
$last   = (string)($from["last_name"] ?? "");
$userName = trim($first . " " . $last);
$userU = (string)($from["username"] ?? "");
$messageId = (int)($message["message_id"] ?? 0);

$replyToUserId = null;
if (isset($message["reply_to_message"]["from"]["id"])) {
    $replyToUserId = (int)$message["reply_to_message"]["from"]["id"];
}

$dataDir = ensureDataDir();
$pointsPath = $dataDir . "/points.json";
$statePath  = $dataDir . "/state.json";

$points = jsonLoad($pointsPath, []);
$state  = jsonLoad($statePath, []);

function getP(array $points, int $uid): int { return (int)($points[(string)$uid] ?? 0); }
function setP(array &$points, int $uid, int $val): void { $points[(string)$uid] = max(0, (int)$val); }
function addP(array &$points, int $uid, int $delta): int { $n = getP($points, $uid) + (int)$delta; setP($points, $uid, $n); return getP($points, $uid); }

function keyUserChat(int|string $chatId, int $uid): string { return (string)$chatId . ":" . (string)$uid; }

function isSudo(int $uid): bool {
    $sudo = (string)($GLOBALS["sudoID"] ?? "");
    return $sudo !== "" && (string)$uid === $sudo;
}

// ===== سياسة القروبات (عشان ما يصير سبام) =====
// في القروبات: نرد فقط على:
// 1) أوامر تبدأ بـ /
// 2) كلمات الأوامر العربية (مساعدة/ابدأ/نقاطي/العاب...)
// 3) رسائل تبدأ بـ # (AI)
$isGroup = ($chatType !== "private");
$maybeCommand = false;
if ($textTrim !== "") {
    $n = norm($textTrim);
    if (mb_substr($textTrim, 0, 1, "UTF-8") === "#" || mb_substr($textTrim, 0, 1, "UTF-8") === "/") {
        $maybeCommand = true;
    } else {
        $cmdWords = ["ابدأ","ابدا","مساعدة","اوامر","الأوامر","نقاطي","المتصدرين","تحويل","حول","العاب","ألعاب","لعبة","هدية","فعالية","الغِ","الغاء","الغِ_اللعبة","الغاء اللعبة"];
        foreach ($cmdWords as $w) {
            if ($n === norm($w) || str_starts_with($n, norm($w) . " ")) { $maybeCommand = true; break; }
        }
    }
}
if ($isGroup && !$maybeCommand) { http_response_code(200); exit("OK"); }

// ===== الذكاء الاصطناعي بـ # فقط =====
if ($textTrim !== "" && mb_substr($textTrim, 0, 1, "UTF-8") === "#") {
    $prompt = trim(mb_substr($textTrim, 1, null, "UTF-8"));
    if ($prompt === "") {
        sendMessage($chatId, "اكتب سؤالك بعد علامة # 🙂 مثال: #وش أفضل طريقة أذاكر؟", $messageId ?: null);
        http_response_code(200); exit("OK");
    }
    $reply = aiReply($prompt);
    sendMessage($chatId, $reply, $messageId ?: null);
    http_response_code(200); exit("OK");
}

// ===== أوامر Nana العربية =====
$cmd = norm($textTrim);

// دعم /start@bot في القروبات
if (str_starts_with($cmd, "/start")) $cmd = "/start";
if (str_starts_with($cmd, "/help"))  $cmd = "/help";

// --- Helper رسائل ---
$HELP =
"📌 أوامر Nana:
• ابدأ / /start
• مساعدة
• نقاطي
• المتصدرين
• هدية (مرة كل 24 ساعة)
• العاب (قائمة الألعاب)

🎮 ألعاب:
• تحدي (تخمين 1-5)
• حجر (حجر/ورقة/مقص)
• سؤال (صح/خطأ)
• حساب (سؤال حساب سريع)
• الغِ اللعبة

💸 تحويل نقاط:
• حول 10 (بالرد على الشخص)
• حول 10 123456789 (بالآيدي)

🤖 ذكاء اصطناعي:
• اكتب # ثم سؤالك
مثال: #اكتب لي خطة مذاكرة";

$WELCOME =
"👋 أهلًا بك في Nana 🤍
أنا بوت عربي للألعاب والنقاط والفعاليات.
اكتب: مساعدة";

function topLeaders(array $points, int $limit = 10): array {
    arsort($points);
    $out = [];
    foreach ($points as $uid => $p) {
        $out[] = [$uid, (int)$p];
        if (count($out) >= $limit) break;
    }
    return $out;
}

// ===== إدارة حالات الألعاب =====
$k = keyUserChat($chatId, $userId);
if (!isset($state["game"])) $state["game"] = [];
if (!isset($state["cooldowns"])) $state["cooldowns"] = [];
if (!isset($state["cooldowns"]["gift"])) $state["cooldowns"]["gift"] = [];

$game = $state["game"][$k] ?? ["type" => null, "data" => []];

function clearGame(array &$state, string $k): void {
    unset($state["game"][$k]);
}

// ===== تنفيذ الأوامر =====

// start/help
if ($cmd === "/start" || $cmd === "ابدأ" || $cmd === "ابدا") {
    sendMessage($chatId, $WELCOME, $messageId ?: null);
    jsonSave($GLOBALS["statePath"], $GLOBALS["state"]);
    jsonSave($GLOBALS["pointsPath"], $GLOBALS["points"]);
    http_response_code(200); exit("OK");
}
if ($cmd === "/help" || $cmd === "مساعدة" || $cmd === "اوامر" || $cmd === "الأوامر") {
    sendMessage($chatId, $HELP, $messageId ?: null);
    http_response_code(200); exit("OK");
}

// نقاطي
if ($cmd === "نقاطي") {
    $p = getP($points, $userId);
    sendMessage($chatId, "🏆 نقاطك الحالية: {$p}", $messageId ?: null);
    http_response_code(200); exit("OK");
}

// المتصدرين
if ($cmd === "المتصدرين") {
    $leaders = topLeaders($points, 10);
    if (!$leaders) {
        sendMessage($chatId, "ما فيه نقاط حتى الآن. اكتب: هدية", $messageId ?: null);
        http_response_code(200); exit("OK");
    }
    $msg = "🏅 المتصدرين:\n";
    $i = 1;
    foreach ($leaders as [$uid, $p]) {
        $msg .= "{$i}) {$uid} — {$p}\n";
        $i++;
    }
    $msg .= "\n(عرضنا الآيدي لأنه ما نقدر نجيب أسماء الكل بدون قاعدة بيانات)";
    sendMessage($chatId, $msg, $messageId ?: null);
    http_response_code(200); exit("OK");
}

// هدية يومية (24 ساعة)
if ($cmd === "هدية") {
    $last = (int)($state["cooldowns"]["gift"][(string)$userId] ?? 0);
    $now = nowTs();
    $cool = 24 * 3600;
    if ($last && ($now - $last) < $cool) {
        $left = $cool - ($now - $last);
        $h = intdiv($left, 3600);
        $m = intdiv($left % 3600, 60);
        sendMessage($chatId, "⏳ استلمت هديتك قبل كذا. تعال بعد {$h}س {$m}د.", $messageId ?: null);
        http_response_code(200); exit("OK");
    }
    $gain = rand(3, 12);
    $newP = addP($points, $userId, $gain);
    $state["cooldowns"]["gift"][(string)$userId] = $now;
    jsonSave($pointsPath, $points);
    jsonSave($statePath, $state);
    sendMessage($chatId, "🎁 هدية لك: +{$gain} نقطة ✅\n🏆 نقاطك الآن: {$newP}", $messageId ?: null);
    http_response_code(200); exit("OK");
}

// فعاليات (عشوائي)
if ($cmd === "فعالية") {
    $events = [
        "فعالية اليوم: أول شخص يكتب (نانا) يحصل 5 نقاط — (نفّذها يدويًا كمشرف)",
        "فعالية سريعة: اكتب (حساب) وخلك أسرع واحد!",
        "فعالية: اكتب (سؤال) وخنشوف مين يفوز!",
        "فعالية: أرسل #سؤال للذكاء الاصطناعي وخذ أفضل نصيحة."
    ];
    sendMessage($chatId, "🎉 " . $events[array_rand($events)], $messageId ?: null);
    http_response_code(200); exit("OK");
}

// ألعاب: قائمة
if ($cmd === "العاب" || $cmd === "ألعاب") {
    sendMessage($chatId,
"🎮 الألعاب المتاحة:
• تحدي  (تخمين رقم 1-5)
• حجر   (حجر/ورقة/مقص)
• سؤال  (صح/خطأ)
• حساب  (سؤال حساب سريع)
• الغِ اللعبة", $messageId ?: null);
    http_response_code(200); exit("OK");
}

// الغِ اللعبة
if ($cmd === "الغِ اللعبة" || $cmd === "الغاء اللعبة" || $cmd === "الغِ_اللعبة") {
    clearGame($state, $k);
    jsonSave($statePath, $state);
    sendMessage($chatId, "تم إلغاء اللعبة الحالية ✅", $messageId ?: null);
    http_response_code(200); exit("OK");
}

// ===== بدء الألعاب =====

// تحدي (تخمين 1-5)
if ($cmd === "تحدي") {
    $secret = rand(1,5);
    $state["game"][$k] = ["type" => "guess", "data" => ["secret" => $secret, "tries" => 0]];
    jsonSave($statePath, $state);
    sendMessage($chatId, "🎯 بدأنا! خمن رقم من 1 إلى 5 (اكتب رقم فقط).", $messageId ?: null);
    http_response_code(200); exit("OK");
}

// حجر/ورقة/مقص
if ($cmd === "حجر") {
    $state["game"][$k] = ["type" => "rps", "data" => []];
    jsonSave($statePath, $state);
    sendMessage($chatId, "🪨✋✂️ اختر: حجر / ورقة / مقص", $messageId ?: null);
    http_response_code(200); exit("OK");
}

// سؤال (صح/خطأ)
if ($cmd === "سؤال") {
    $qs = [
        ["السؤال: الشمس نجم وليست كوكب. (صح/خطأ)", "صح"],
        ["السؤال: الماء يغلي عند 50 درجة مئوية. (صح/خطأ)", "خطأ"],
        ["السؤال: الرياض عاصمة السعودية. (صح/خطأ)", "صح"],
        ["السؤال: القطط من الزواحف. (صح/خطأ)", "خطأ"],
    ];
    $q = $qs[array_rand($qs)];
    $state["game"][$k] = ["type" => "tf", "data" => ["q" => $q[0], "a" => $q[1]]];
    jsonSave($statePath, $state);
    sendMessage($chatId, "❓ {$q[0]}", $messageId ?: null);
    http_response_code(200); exit("OK");
}

// حساب سريع
if ($cmd === "حساب") {
    $a = rand(2, 20);
    $b = rand(2, 20);
    $op = ["+", "-", "×"][array_rand([0,1,2])];
    $ans = 0;
    if ($op === "+") $ans = $a + $b;
    if ($op === "-") { if ($b > $a) [$a,$b]=[$b,$a]; $ans = $a - $b; }
    if ($op === "×") $ans = $a * $b;

    $state["game"][$k] = ["type" => "math", "data" => ["q" => "احسب: {$a} {$op} {$b}", "a" => (string)$ans]];
    jsonSave($statePath, $state);
    sendMessage($chatId, "🧮 " . $state["game"][$k]["data"]["q"], $messageId ?: null);
    http_response_code(200); exit("OK");
}

// ===== تحويل نقاط =====
// صيغة 1: حول 10 (لازم تكون راد على شخص)
// صيغة 2: حول 10 123456789
if (str_starts_with($cmd, "حول ") || str_starts_with($cmd, "تحويل ")) {
    $parts = explode(" ", $cmd);
    // مثال: حول 10 123
    $amount = isset($parts[1]) ? (int)$parts[1] : 0;
    $target = isset($parts[2]) ? (int)$parts[2] : 0;

    if ($amount <= 0) {
        sendMessage($chatId, "اكتب: حول 10 (بالرد على الشخص) أو حول 10 123456789", $messageId ?: null);
        http_response_code(200); exit("OK");
    }

    if ($target === 0) {
        if ($replyToUserId) $target = $replyToUserId;
    }

    if ($target === 0) {
        sendMessage($chatId, "لازم ترد على الشخص ثم تكتب: حول 10\nأو تكتب: حول 10 123456789", $messageId ?: null);
        http_response_code(200); exit("OK");
    }

    if ($target === $userId) {
        sendMessage($chatId, "😂 ما تقدر تحول لنفسك.", $messageId ?: null);
        http_response_code(200); exit("OK");
    }

    $have = getP($points, $userId);
    if ($have < $amount) {
        sendMessage($chatId, "نقاطك ما تكفي. نقاطك الحالية: {$have}", $messageId ?: null);
        http_response_code(200); exit("OK");
    }

    addP($points, $userId, -$amount);
    $newT = addP($points, $target, $amount);

    jsonSave($pointsPath, $points);
    sendMessage($chatId, "✅ تم تحويل {$amount} نقطة.\n🏆 نقاط المستلم الآن: {$newT}", $messageId ?: null);
    http_response_code(200); exit("OK");
}

// ===== أوامر السوبر (اختياري) =====
if (isSudo($userId) && str_starts_with($cmd, "زد_نقاط ")) {
    // زد_نقاط 123456 50
    $p = explode(" ", $cmd);
    $target = isset($p[1]) ? (int)$p[1] : 0;
    $amount = isset($p[2]) ? (int)$p[2] : 0;
    if ($target && $amount) {
        $new = addP($points, $target, $amount);
        jsonSave($pointsPath, $points);
        sendMessage($chatId, "✅ زدنا {$amount} نقطة لـ {$target}. نقاطه الآن: {$new}", $messageId ?: null);
    } else {
        sendMessage($chatId, "الصيغة: زد_نقاط 123456 50", $messageId ?: null);
    }
    http_response_code(200); exit("OK");
}
if (isSudo($userId) && str_starts_with($cmd, "خصم_نقاط ")) {
    // خصم_نقاط 123456 50
    $p = explode(" ", $cmd);
    $target = isset($p[1]) ? (int)$p[1] : 0;
    $amount = isset($p[2]) ? (int)$p[2] : 0;
    if ($target && $amount) {
        $new = addP($points, $target, -$amount);
        jsonSave($pointsPath, $points);
        sendMessage($chatId, "✅ خصمنا {$amount} نقطة من {$target}. نقاطه الآن: {$new}", $messageId ?: null);
    } else {
        sendMessage($chatId, "الصيغة: خصم_نقاط 123456 50", $messageId ?: null);
    }
    http_response_code(200); exit("OK");
}

// ===== معالجة إجابات الألعاب (إذا فيه لعبة شغالة) =====
if (($game["type"] ?? null) === "guess") {
    if (preg_match('/^[1-5]$/u', $cmd)) {
        $guess = (int)$cmd;
        $secret = (int)($game["data"]["secret"] ?? 0);
        $tries  = (int)($game["data"]["tries"] ?? 0) + 1;

        if ($guess === $secret) {
            clearGame($state, $k);
            $gain = max(2, 6 - $tries); // أقل محاولات = نقاط أكثر
            $newP = addP($points, $userId, $gain);
            jsonSave($pointsPath, $points);
            jsonSave($statePath, $state);
            sendMessage($chatId, "✅ صح! الرقم كان {$secret} 🎉\n+{$gain} نقاط\n🏆 نقاطك الآن: {$newP}", $messageId ?: null);
        } else {
            $state["game"][$k]["data"]["tries"] = $tries;
            jsonSave($statePath, $state);
            sendMessage($chatId, "❌ غلط… جرّب مرة ثانية.", $messageId ?: null);
        }
        http_response_code(200); exit("OK");
    }
}

if (($game["type"] ?? null) === "rps") {
    // حجر/ورقة/مقص
    $choices = ["حجر","ورقة","مقص"];
    $c = null;
    foreach ($choices as $x) if ($cmd === norm($x)) $c = $x;
    if ($c) {
        $bot = $choices[array_rand($choices)];
        $win = [
            "حجر" => "مقص",
            "ورقة" => "حجر",
            "مقص" => "ورقة",
        ];
        $result = "تعادل 🤝";
        $delta = 0;
        if ($c !== $bot) {
            if ($win[$c] === $bot) { $result = "فزت 🎉"; $delta = 3; }
            else { $result = "خسرت 😅"; $delta = 0; }
        }
        clearGame($state, $k);
        if ($delta) {
            $newP = addP($points, $userId, $delta);
            jsonSave($pointsPath, $points);
            sendMessage($chatId, "أنت: {$c}\nNana: {$bot}\n{$result}\n+{$delta} نقاط ✅\n🏆 نقاطك: {$newP}", $messageId ?: null);
        } else {
            sendMessage($chatId, "أنت: {$c}\nNana: {$bot}\n{$result}", $messageId ?: null);
        }
        jsonSave($statePath, $state);
        http_response_code(200); exit("OK");
    }
}

if (($game["type"] ?? null) === "tf") {
    if ($cmd === "صح" || $cmd === "خطأ" || $cmd === "خطا") {
        $ans = ($cmd === "خطا") ? "خطأ" : $cmd;
        $correct = (string)($game["data"]["a"] ?? "");
        clearGame($state, $k);
        if ($ans === $correct) {
            $newP = addP($points, $userId, 4);
            jsonSave($pointsPath, $points);
            sendMessage($chatId, "✅ إجابة صحيحة! +4 نقاط\n🏆 نقاطك: {$newP}", $messageId ?: null);
        } else {
            sendMessage($chatId, "❌ خطأ… الإجابة الصحيحة: {$correct}", $messageId ?: null);
        }
        jsonSave($statePath, $state);
        http_response_code(200); exit("OK");
    }
}

if (($game["type"] ?? null) === "math") {
    // أي رقم = جواب محتمل
    if (preg_match('/^\d+$/u', $cmd)) {
        $correct = (string)($game["data"]["a"] ?? "");
        clearGame($state, $k);
        if ($cmd === $correct) {
            $newP = addP($points, $userId, 5);
            jsonSave($pointsPath, $points);
            sendMessage($chatId, "✅ صح! +5 نقاط\n🏆 نقاطك: {$newP}", $messageId ?: null);
        } else {
            sendMessage($chatId, "❌ غلط… الجواب الصحيح: {$correct}", $messageId ?: null);
        }
        jsonSave($statePath, $state);
        http_response_code(200); exit("OK");
    }
}

// افتراضي: لا نرد (لتجنب الإزعاج)
http_response_code(200);
echo "OK";
