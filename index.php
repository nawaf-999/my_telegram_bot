<?php
// Nana Bot - index.php (FULL)
// يعتمد على util.php + ai.php
require_once __DIR__ . "/util.php";
require_once __DIR__ . "/ai.php";

// =====================
// قراءة التحديث من تيليجرام
// =====================
$update = file_get_contents("php://input");
if (empty($update)) exit("OK");

$u = json_decode($update, true);
if (!is_array($u)) exit("OK");

// رسالة عادية أو callback_query
$msg = $u["message"] ?? ($u["callback_query"]["message"] ?? null);
if (!$msg) exit("OK");

// بيانات عامة
$chatId  = $msg["chat"]["id"] ?? null;
$textRaw = $msg["text"] ?? "";
$from    = $msg["from"] ?? [];
$uid     = $from["id"] ?? 0;

$first   = $from["first_name"] ?? "";
$last    = $from["last_name"] ?? "";
$name    = trim($first . " " . $last);

if (!$chatId || !$uid) exit("OK");

// =====================
// تحميل قاعدة البيانات البسيطة + Rate Limit
// =====================
$db = loadData();
if (!rateLimitOk($db, $chatId, 1)) { saveData($db); exit("OK"); }

$user = &getUser($db, (string)$uid);

// النص بعد التطبيع
$text = normalizeText($textRaw);

// =====================
// أدوات مساعدة (نقاط/نصوص)
// =====================
function addPoints(&$user, $amount) {
    $user["points"] = max(0, intval($user["points"]) + intval($amount));
}

function helpText() {
    return
"📋 أوامر Nana:
- ابدأ
- مساعدة
- نقاطي
- يومي (مكافأة يومية)
- العاب
- تحدي رقم
- حجر ورقة مقص
- صح
- لغز
- كت
- توب

🛡️ أوامر القروب (للأدمن فقط):
- اوامر القروب
- حظر (بالرد) أو: حظر 123456
- فك حظر (بالرد) أو: فك حظر 123456
- طرد (بالرد) أو: طرد 123456
- تقييد 10m (بالرد) أو: تقييد 10m 123456
- فك تقييد (بالرد) أو: فك تقييد 123456
صيغة الوقت: 10m / 2h / 1d

🤖 الذكاء الاصطناعي:
اكتب رسالتك وتبدأ بـ # مثل:
# كم تاريخ اليوم؟
";
}

function gamesMenu() {
    return
"🎮 الألعاب:
1) تحدي رقم  (تكتب: تحدي رقم)
2) حجر ورقة مقص (تكتب: حجر ورقة مقص)
3) صح (سؤال صح/خطأ)
4) لغز (لغز سريع)
";
}

// =====================
// Telegram API Helper (لإدارة القروب)
// =====================
function tg($method, $data){
    // لو util.php فيه sendCommand استخدمه
    if (function_exists("sendCommand")) {
        return sendCommand($method, $data);
    }

    // fallback
    $token = defined("TOKEN") ? TOKEN : getenv("BOT_TOKEN");
    $url = "https://api.telegram.org/bot{$token}/{$method}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_TIMEOUT => 20,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($res === false) return ["ok"=>false, "description"=>$err ?: "curl_error"];
    $j = json_decode($res, true);
    return is_array($j) ? $j : ["ok"=>false, "description"=>"bad_json"];
}

function isGroupChat($msg){
    $type = $msg["chat"]["type"] ?? "";
    return in_array($type, ["group","supergroup"], true);
}

function getTargetUserId($msg, $textRaw){
    // الأفضل: Reply على رسالة الشخص
    if (isset($msg["reply_to_message"]["from"]["id"])) {
        return intval($msg["reply_to_message"]["from"]["id"]);
    }
    // أو: حظر 123456789
    if (preg_match("/\b(\d{5,})\b/u", $textRaw, $m)) {
        return intval($m[1]);
    }
    return 0;
}

function isAdminOrSudo($chatId, $uid){
    // sudoID إذا موجود في config.php
    global $sudoID;
    if (!empty($sudoID) && strval($uid) === strval($sudoID)) return true;

    $r = tg("getChatMember", ["chat_id"=>$chatId, "user_id"=>$uid]);
    if (!($r["ok"] ?? false)) return false;
    $st = $r["result"]["status"] ?? "";
    return in_array($st, ["administrator","creator"], true);
}

function banUser($chatId, $targetId){
    return tg("banChatMember", ["chat_id"=>$chatId, "user_id"=>$targetId]);
}

function unbanUser($chatId, $targetId){
    return tg("unbanChatMember", ["chat_id"=>$chatId, "user_id"=>$targetId]);
}

function kickUser($chatId, $targetId){
    $b = banUser($chatId, $targetId);
    if (!($b["ok"] ?? false)) return $b;
    return unbanUser($chatId, $targetId);
}

function muteUser($chatId, $targetId, $seconds){
    $until = time() + max(60, intval($seconds));
    return tg("restrictChatMember", [
        "chat_id"=>$chatId,
        "user_id"=>$targetId,
        "until_date"=>$until,
        "permissions"=> json_encode([
            "can_send_messages"=>false,
            "can_send_audios"=>false,
            "can_send_documents"=>false,
            "can_send_photos"=>false,
            "can_send_videos"=>false,
            "can_send_video_notes"=>false,
            "can_send_voice_notes"=>false,
            "can_send_polls"=>false,
            "can_send_other_messages"=>false,
            "can_add_web_page_previews"=>false,
            "can_change_info"=>false,
            "can_invite_users"=>false,
            "can_pin_messages"=>false
        ], JSON_UNESCAPED_UNICODE)
    ]);
}

function unmuteUser($chatId, $targetId){
    return tg("restrictChatMember", [
        "chat_id"=>$chatId,
        "user_id"=>$targetId,
        "permissions"=> json_encode([
            "can_send_messages"=>true,
            "can_send_audios"=>true,
            "can_send_documents"=>true,
            "can_send_photos"=>true,
            "can_send_videos"=>true,
            "can_send_video_notes"=>true,
            "can_send_voice_notes"=>true,
            "can_send_polls"=>true,
            "can_send_other_messages"=>true,
            "can_add_web_page_previews"=>true,
            "can_change_info"=>false,
            "can_invite_users"=>true,
            "can_pin_messages"=>false
        ], JSON_UNESCAPED_UNICODE)
    ]);
}

function parseDurationSeconds($textRaw){
    // تقييد 10m / 2h / 1d / أو رقم ثواني
    if (preg_match("/\b(\d+)\s*(s|m|h|d)\b/iu", $textRaw, $m)) {
        $n = intval($m[1]); $u = strtolower($m[2]);
        if ($u==="s") return $n;
        if ($u==="m") return $n*60;
        if ($u==="h") return $n*3600;
        if ($u==="d") return $n*86400;
    }
    if (preg_match("/\b(\d{2,})\b/u", $textRaw, $m)) return intval($m[1]);
    return 600; // 10 دقائق
}

// =====================
// نظام الألعاب (State)
// =====================
function startGuessGame(&$user) {
    $user["state"] = "guess";
    $user["state_data"] = ["n" => rand(1, 5)];
}

function startRPS(&$user) {
    $user["state"] = "rps";
    $user["state_data"] = ["pending" => true];
}

function startTrueFalse(&$user) {
    $qs = [
        ["السؤال: الشمس نجم؟ (صح/خطأ)", "صح"],
        ["السؤال: جدة هي عاصمة السعودية؟ (صح/خطأ)", "خطا"],
        ["السؤال: الماء يغلي عند 100 درجة مئوية؟ (صح/خطأ)", "صح"],
        ["السؤال: القطط تقدر تشوف بالظلام الكامل 100%؟ (صح/خطأ)", "خطا"],
    ];
    $q = $qs[array_rand($qs)];
    $user["state"] = "tf";
    $user["state_data"] = ["q" => $q[0], "a" => $q[1]];
}

function startRiddle(&$user) {
    $rs = [
        ["شيء كل ما أخذت منه كبر.. وش هو؟", "الحفره"],
        ["يمشي بلا رجلين ويبكي بلا عينين.. وش هو؟", "السحاب"],
        ["له أسنان وما يعض.. وش هو؟", "المشط"],
        ["شيء إذا لمسته صاح.. وش هو؟", "الجرس"],
    ];
    $r = $rs[array_rand($rs)];
    $user["state"] = "riddle";
    $user["state_data"] = ["q" => $r[0], "a" => $r[1]];
}

// =====================
// التعامل مع الردود داخل الألعاب
// =====================
if (($user["state"] ?? null) === "guess") {
    $n = intval($user["state_data"]["n"] ?? 0);
    $g = intval($text);
    if ($g >= 1 && $g <= 5) {
        if ($g === $n) {
            addPoints($user, 10);
            sendMessage($chatId, "🎯 صح عليك! الرقم كان $n\n+10 نقاط\nنقاطك: ".$user["points"]);
        } else {
            sendMessage($chatId, "❌ لا.. الرقم كان $n\nجرّب لعبة ثانية: اكتب (العاب)");
        }
        $user["state"] = null; $user["state_data"] = [];
        saveData($db); exit("OK");
    } else {
        sendMessage($chatId, "اكتب رقم من 1 إلى 5 🙂");
        saveData($db); exit("OK");
    }
}

if (($user["state"] ?? null) === "rps") {
    $choices = ["حجر","ورقه","مقص"];
    $map = [
        "حجر" => "حجر", "ورقه" => "ورقه", "ورقة" => "ورقه", "مقص" => "مقص",
        "rock"=>"حجر","paper"=>"ورقه","scissors"=>"مقص"
    ];

    $pick = $map[$textRaw] ?? $map[$text] ?? null;
    if (!$pick) {
        sendMessage($chatId, "اكتب اختيارك: حجر / ورقة / مقص");
        saveData($db); exit("OK");
    }

    $bot = $choices[array_rand($choices)];
    $result = "تعادل";
    if ($pick === "حجر" && $bot === "مقص") $result = "فوز";
    if ($pick === "مقص" && $bot === "ورقه") $result = "فوز";
    if ($pick === "ورقه" && $bot === "حجر") $result = "فوز";
    if ($pick !== $bot && $result !== "فوز") $result = "خساره";

    if ($result === "فوز") addPoints($user, 7);
    if ($result === "خساره") addPoints($user, -2);

    sendMessage($chatId, "🧠 أنا اخترت: $bot\nأنت اخترت: $pick\nالنتيجة: $result\nنقاطك: ".$user["points"]);
    $user["state"] = null; $user["state_data"] = [];
    saveData($db); exit("OK");
}

if (($user["state"] ?? null) === "tf") {
    $a = $user["state_data"]["a"] ?? "";
    $t = str_replace(["؟","!","."], "", $text);
    $t = str_replace(" ", "", $t);
    $t = str_replace("أ", "ا", $t);

    $isTrue = in_array($t, ["صح","صحيح","true"], true);
    $isFalse = in_array($t, ["خطا","خطأ","false"], true);

    if (!$isTrue && !$isFalse) {
        sendMessage($chatId, "جاوب بـ: صح أو خطأ");
        saveData($db); exit("OK");
    }

    $ok = ($isTrue && $a === "صح") || ($isFalse && $a === "خطا");
    if ($ok) {
        addPoints($user, 6);
        sendMessage($chatId, "✅ إجابة صحيحة! +6 نقاط\nنقاطك: ".$user["points"]);
    } else {
        addPoints($user, -1);
        sendMessage($chatId, "❌ غلط. الإجابة: $a\n-1 نقطة\nنقاطك: ".$user["points"]);
    }

    $user["state"] = null; $user["state_data"] = [];
    saveData($db); exit("OK");
}

if (($user["state"] ?? null) === "riddle") {
    $ans = normalizeText($user["state_data"]["a"] ?? "");
    $given = normalizeText($textRaw);

    if ($given === "استسلام" || $given === "الحل") {
        sendMessage($chatId, "الحل: $ans\nاكتب (لغز) للغز جديد");
        $user["state"] = null; $user["state_data"] = [];
        saveData($db); exit("OK");
    }

    if ($given === $ans) {
        addPoints($user, 12);
        sendMessage($chatId, "🔥 صح! +12 نقاط\nنقاطك: ".$user["points"]);
        $user["state"] = null; $user["state_data"] = [];
        saveData($db); exit("OK");
    } else {
        sendMessage($chatId, "مو صحيح.. جرّب مرة ثانية أو اكتب (الحل)");
        saveData($db); exit("OK");
    }
}

// =====================
// أوامر إدارة القروب (للأدمن)
// =====================
if (isGroupChat($msg)) {

    if ($text === "اوامر القروب" || $text === "ادارة" || $text === "إدارة") {
        sendMessage($chatId,
"🛡️ أوامر الإدارة (لازم Admin + صلاحيات):
- حظر (بالرد) أو: حظر 123456
- فك حظر (بالرد) أو: فك حظر 123456
- طرد (بالرد) أو: طرد 123456
- تقييد 10m (بالرد) أو: تقييد 10m 123456
- فك تقييد (بالرد) أو: فك تقييد 123456
صيغة الوقت: 10m / 2h / 1d");
        saveData($db); exit("OK");
    }

    $isAdmin = isAdminOrSudo($chatId, $uid);

    // حظر
    if (preg_match("/^(حظر|بان|ban)\b/iu", $textRaw)) {
        if (!$isAdmin){ sendMessage($chatId, "🚫 هذا الأمر للأدمن فقط."); saveData($db); exit("OK"); }
        $target = getTargetUserId($msg, $textRaw);
        if (!$target){ sendMessage($chatId, "رد على رسالة الشخص أو اكتب آيديه."); saveData($db); exit("OK"); }
        $r = banUser($chatId, $target);
        sendMessage($chatId, ($r["ok"]??false) ? "✅ تم حظر العضو." : ("⚠️ فشل الحظر: ".($r["description"]??"")));
        saveData($db); exit("OK");
    }

    // فك حظر
    if (preg_match("/^(فك\s*حظر|unban)\b/iu", $textRaw)) {
        if (!$isAdmin){ sendMessage($chatId, "🚫 هذا الأمر للأدمن فقط."); saveData($db); exit("OK"); }
        $target = getTargetUserId($msg, $textRaw);
        if (!$target){ sendMessage($chatId, "رد على رسالة الشخص أو اكتب آيديه."); saveData($db); exit("OK"); }
        $r = unbanUser($chatId, $target);
        sendMessage($chatId, ($r["ok"]??false) ? "✅ تم فك الحظر." : ("⚠️ فشل فك الحظر: ".($r["description"]??"")));
        saveData($db); exit("OK");
    }

    // طرد
    if (preg_match("/^(طرد|kick)\b/iu", $textRaw)) {
        if (!$isAdmin){ sendMessage($chatId, "🚫 هذا الأمر للأدمن فقط."); saveData($db); exit("OK"); }
        $target = getTargetUserId($msg, $textRaw);
        if (!$target){ sendMessage($chatId, "رد على رسالة الشخص أو اكتب آيديه."); saveData($db); exit("OK"); }
        $r = kickUser($chatId, $target);
        sendMessage($chatId, ($r["ok"]??false) ? "✅ تم طرد العضو." : ("⚠️ فشل الطرد: ".($r["description"]??"")));
        saveData($db); exit("OK");
    }

    // تقييد
    if (preg_match("/^(تقييد|mute)\b/iu", $textRaw)) {
        if (!$isAdmin){ sendMessage($chatId, "🚫 هذا الأمر للأدمن فقط."); saveData($db); exit("OK"); }
        $target = getTargetUserId($msg, $textRaw);
        if (!$target){ sendMessage($chatId, "رد على رسالة الشخص أو اكتب آيديه."); saveData($db); exit("OK"); }
        $sec = parseDurationSeconds($textRaw);
        $r = muteUser($chatId, $target, $sec);
        $mins = max(1, round($sec/60));
        sendMessage($chatId, ($r["ok"]??false) ? "✅ تم تقييد العضو لمدة $mins دقيقة." : ("⚠️ فشل التقييد: ".($r["description"]??"")));
        saveData($db); exit("OK");
    }

    // فك تقييد
    if (preg_match("/^(فك\s*تقييد|unmute)\b/iu", $textRaw)) {
        if (!$isAdmin){ sendMessage($chatId, "🚫 هذا الأمر للأدمن فقط."); saveData($db); exit("OK"); }
        $target = getTargetUserId($msg, $textRaw);
        if (!$target){ sendMessage($chatId, "رد على رسالة الشخص أو اكتب آيديه."); saveData($db); exit("OK"); }
        $r = unmuteUser($chatId, $target);
        sendMessage($chatId, ($r["ok"]??false) ? "✅ تم فك التقييد." : ("⚠️ فشل فك التقييد: ".($r["description"]??"")));
        saveData($db); exit("OK");
    }
}

// =====================
// أوامر أساسية
// =====================
if ($text === "/start" || $text === "ابدأ" || $text === "start") {
    if (empty($user["points"])) $user["points"] = 0;
    if (empty($user["last_daily"])) $user["last_daily"] = 0;
    if (!isset($user["state"])) $user["state"] = null;
    if (!isset($user["state_data"])) $user["state_data"] = [];

    sendMessage($chatId,
"👋 أهلًا $name في Nana 🤍
أنا بوت عربي للألعاب والنقاط والفعاليات.

".helpText()
    );
    saveData($db); exit("OK");
}

if ($text === "مساعدة" || $text === "help" || $text === "/help") {
    sendMessage($chatId, helpText());
    saveData($db); exit("OK");
}

if ($text === "نقاطي" || $text === "نقاط") {
    sendMessage($chatId, "🏆 نقاطك الحالية: ".$user["points"]);
    saveData($db); exit("OK");
}

if ($text === "يومي" || $text === "مكافأة" || $text === "اليومي") {
    $now = time();
    $last = intval($user["last_daily"] ?? 0);

    if (($now - $last) < 24*3600) {
        $left = (24*3600) - ($now - $last);
        $h = floor($left/3600);
        $m = floor(($left%3600)/60);
        sendMessage($chatId, "⏳ أخذتها اليوم! ارجع بعد ".$h."س ".$m."د");
    } else {
        $bonus = rand(10, 25);
        addPoints($user, $bonus);
        $user["last_daily"] = $now;
        sendMessage($chatId, "🎁 مكافأة يومية: +$bonus نقطة\nنقاطك: ".$user["points"]);
    }
    saveData($db); exit("OK");
}

if ($text === "العاب" || $text === "ألعاب" || $text === "games") {
    sendMessage($chatId, gamesMenu());
    saveData($db); exit("OK");
}

if ($text === "تحدي رقم" || $text === "تحدي") {
    startGuessGame($user);
    sendMessage($chatId, "🎯 خمن رقم من 1 إلى 5");
    saveData($db); exit("OK");
}

if ($text === "حجر ورقة مقص" || $text === "حجر ورقه مقص") {
    startRPS($user);
    sendMessage($chatId, "✊✋✌️ اكتب اختيارك: حجر / ورقة / مقص");
    saveData($db); exit("OK");
}

if ($text === "صح" || $text === "صح ولا غلط" || $text === "سؤال") {
    startTrueFalse($user);
    sendMessage($chatId, "✅ ".$user["state_data"]["q"]);
    saveData($db); exit("OK");
}

if ($text === "لغز") {
    startRiddle($user);
    sendMessage($chatId, "🧩 ".$user["state_data"]["q"]."\n(اكتب: الحل أو استسلام إذا تبغى الإجابة)");
    saveData($db); exit("OK");
}

if ($text === "كت" || $text === "كت تويت" || $text === "cat") {
    $cats = [
        "😺 سؤال: لو قطتك تتكلم، وش أول شيء بتقوله لك؟",
        "🐾 تحدّي سريع: اكتب أفضل اسم لقط في 3 حروف بس!",
        "😼 تويت: القطة ما تتجاهلك… هي بس تعطيك فرصة تشتاق لها.",
        "🧠 سؤال: مين أذكى برأيك: القط ولا الكلب؟ وليه؟",
        "😂 كت-ميم: إذا شفت قط يطالع في الفراغ… غالبًا هو شايف شيء إحنا ما نشوفه.",
        "🐱 سؤال: لو تقدر تختار قوة خارقة لقطتك، وش بتكون؟",
        "🎯 فعالية: اكتب (مواء) وخلك صادق… وش شعورك اليوم؟",
        "😹 سؤال سريع: لو صرت قط ليوم واحد، وش أول مكان بتروح له؟",
        "🧩 لغز قططي: شيء تحبه القطط وتكرهه الفيران… وش هو؟ (فكر شوي!)",
    ];
    sendMessage($chatId, $cats[array_rand($cats)]);
    saveData($db); exit("OK");
}

if ($text === "توب" || $text === "الصداره" || $text === "top") {
    $all = $db["users"] ?? [];
    uasort($all, function($a,$b){
        return intval($b["points"] ?? 0) <=> intval($a["points"] ?? 0);
    });

    $top = array_slice($all, 0, 10, true);
    $out = "🏅 أفضل 10:\n";
    $i = 1;
    foreach ($top as $id => $urow) {
        $out .= $i.") ".$id." — ".intval($urow["points"] ?? 0)." نقطة\n";
        $i++;
    }
    sendMessage($chatId, $out);
    saveData($db); exit("OK");
}

// =====================
// الذكاء الاصطناعي (فقط لو الرسالة تبدأ بـ #)
// =====================
$rawTrim = trim($textRaw);
if (mb_substr($rawTrim, 0, 1, "UTF-8") === "#") {
    $prompt = trim(mb_substr($rawTrim, 1, null, "UTF-8"));

    if ($prompt === "") {
        sendMessage($chatId, "اكتب بعد # سؤالك 🙂 مثال:\n# كم تاريخ اليوم؟");
        saveData($db); exit("OK");
    }

    // لازم تكون ai.php فيها الدالة askAI_OpenRouter
    // وترجع: ["ok"=>true/false, "text"=>"..."]
    $ai = askAI_OpenRouter($prompt);

    if (($ai["ok"] ?? false) && !empty($ai["text"])) {
        sendMessage($chatId, $ai["text"]);
    } else {
        $err = $ai["text"] ?? "صار خطأ في الذكاء الاصطناعي. حاول مرة ثانية.";
        sendMessage($chatId, "⚠️ ".$err);
    }

    saveData($db); exit("OK");
}

// =====================
// افتراضي: تلميح
// =====================
sendMessage($chatId, "اكتب (مساعدة) للأوامر، أو استخدم # للذكاء الاصطناعي مثل:\n# اشرح لي موضوع بسيط");
saveData($db);
exit("OK");
