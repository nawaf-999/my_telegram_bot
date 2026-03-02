<?php
require_once __DIR__ . "/util.php";
require_once __DIR__ . "/ai.php";

$update = file_get_contents("php://input");
if (empty($update)) exit("OK");

$u = json_decode($update, true);
if (!is_array($u)) exit("OK");

$msg = $u["message"] ?? ($u["callback_query"]["message"] ?? null);
if (!$msg) exit("OK");

$chatId = $msg["chat"]["id"] ?? null;
$textRaw = $msg["text"] ?? "";
$from = $msg["from"] ?? [];
$uid = $from["id"] ?? 0;
$first = $from["first_name"] ?? "";
$last  = $from["last_name"] ?? "";
$name = trim($first . " " . $last);

if (!$chatId || !$uid) exit("OK");

$db = loadData();
if (!rateLimitOk($db, $chatId, 1)) { saveData($db); exit("OK"); }

$user = &getUser($db, (string)$uid);

$text = normalizeText($textRaw);

// ====== أدوات مساعدة ======
function addPoints(&$user, $amount) { $user["points"] = max(0, intval($user["points"]) + intval($amount)); }

function helpText() {
    return
"📋 أوامر Nana:
• ابدأ
• مساعدة
• نقاطي
• يومي (مكافأة يومية)
• تحويل 50 @username
• العاب
• تحدي رقم
• حجر ورقة مقص
• صح
• لغز
• توب

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

// ====== نظام الألعاب (state) ======
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
    ];
    $r = $rs[array_rand($rs)];
    $user["state"] = "riddle";
    $user["state_data"] = ["q" => $r[0], "a" => $r[1]];
}

// ====== تعامل مع الردود داخل الألعاب ======
if ($user["state"] === "guess") {
    $n = intval($user["state_data"]["n"] ?? 0);
    $g = intval($text);
    if ($g >= 1 && $g <= 5) {
        if ($g === $n) {
            addPoints($user, 10);
            sendMessage($chatId, "🎯 صح عليك! الرقم كان {$n}\n✅ +10 نقاط (نقاطك: {$user["points"]})");
        } else {
            sendMessage($chatId, "❌ لا.. الرقم كان {$n}\nجرّب لعبة ثانية: اكتب (العاب)");
        }
        $user["state"] = null; $user["state_data"] = [];
        saveData($db); exit("OK");
    } else {
        sendMessage($chatId, "اكتب رقم من 1 إلى 5 🙂");
        saveData($db); exit("OK");
    }
}

if ($user["state"] === "rps") {
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

    if ($result === "فوز") { addPoints($user, 7); }
    if ($result === "خساره") { addPoints($user, -2); }

    sendMessage($chatId, "🧠 أنا اخترت: {$bot}\nأنت اخترت: {$pick}\nالنتيجة: {$result}\nنقاطك: {$user["points"]}");
    $user["state"] = null; $user["state_data"] = [];
    saveData($db); exit("OK");
}

if ($user["state"] === "tf") {
    $a = $user["state_data"]["a"] ?? "";
    $t = str_replace(["؟","!"], "", $text);
    $t = str_replace(" ", "", $t);
    $t = str_replace("أ", "ا", $t);

    $isTrue = in_array($t, ["صح","صحيح","true"]);
    $isFalse = in_array($t, ["خطا","خطأ","false"]);

    if (!$isTrue && !$isFalse) {
        sendMessage($chatId, "جاوب بـ: صح أو خطأ");
        saveData($db); exit("OK");
    }

    $ok = ($isTrue && $a === "صح") || ($isFalse && $a === "خطا");
    if ($ok) { addPoints($user, 6); sendMessage($chatId, "✅ إجابة صحيحة! +6 نقاط\nنقاطك: {$user["points"]}"); }
    else { addPoints($user, -1); sendMessage($chatId, "❌ غلط. الإجابة: {$a}\n-1 نقطة\nنقاطك: {$user["points"]}"); }

    $user["state"] = null; $user["state_data"] = [];
    saveData($db); exit("OK");
}

if ($user["state"] === "riddle") {
    $ans = normalizeText($user["state_data"]["a"] ?? "");
    $given = normalizeText($textRaw);
    if ($given === "استسلام" || $given === "الحل") {
        sendMessage($chatId, "الحل: {$ans}\nاكتب (لغز) للغز جديد");
        $user["state"] = null; $user["state_data"] = [];
        saveData($db); exit("OK");
    }
    if (normalizeText($given) === $ans) {
        addPoints($user, 12);
        sendMessage($chatId, "🔥 صح! +12 نقاط\nنقاطك: {$user["points"]}");
        $user["state"] = null; $user["state_data"] = [];
        saveData($db); exit("OK");
    } else {
        sendMessage($chatId, "مو صحيح.. جرّب مرة ثانية أو اكتب (الحل)");
        saveData($db); exit("OK");
    }
}

// ====== أوامر أساسية ======
if ($text === "/start" || $text === "ابدأ" || $text === "start") {
    sendMessage($chatId,
"👋 أهلًا {$name} في *Nana* 🤍
أنا بوت عربي للألعاب والنقاط والفعاليات.

" . helpText()
    );
    saveData($db); exit("OK");
}

if ($text === "مساعدة" || $text === "help" || $text === "/help") {
    sendMessage($chatId, helpText());
    saveData($db); exit("OK");
}

if ($text === "نقاطي" || $text === "نقاط") {
    sendMessage($chatId, "🏆 نقاطك الحالية: {$user["points"]}");
    saveData($db); exit("OK");
}

if ($text === "يومي" || $text === "مكافأة" || $text === "اليومي") {
    $now = time();
    if (($now - intval($user["last_daily"])) < 24*3600) {
        $left = (24*3600) - ($now - intval($user["last_daily"]));
        $h = floor($left/3600);
        $m = floor(($left%3600)/60);
        sendMessage($chatId, "⏳ أخذتها اليوم! ارجع بعد {$h}س {$m}د");
    } else {
        $bonus = rand(10, 25);
        addPoints($user, $bonus);
        $user["last_daily"] = $now;
        sendMessage($chatId, "🎁 مكافأة يومية: +{$bonus} نقطة\nنقاطك: {$user["points"]}");
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
    sendMessage($chatId, "✅ " . $user["state_data"]["q"]);
    saveData($db); exit("OK");
}

if ($text === "لغز") {
    startRiddle($user);
    sendMessage($chatId, "🧩 " . $user["state_data"]["q"] . "\n(اكتب: الحل أو استسلام إذا تبغى الإجابة)");
    saveData($db); exit("OK");
}

if ($text === "توب" || $text === "الصداره" || $text === "top") {
    $all = $db["users"];
    uasort($all, function($a,$b){ return intval($b["points"]??0) <=> intval($a["points"]??0); });
    $top = array_slice($all, 0, 10, true);
    $out = "🏅 أفضل 10:\n";
    $i=1;
    foreach ($top as $id => $urow) {
        $out .= "{$i}) {$id} — " . intval($urow["points"]??0) . " نقطة\n";
        $i++;
    }
    sendMessage($chatId, $out);
    saveData($db); exit("OK");
}

// تحويل نقاط: "تحويل 50 @user"
if (preg_match("/^تحويل\s+(\d+)\s+@?([a-z0-9_]{3,})$/iu", $textRaw, $m)) {
    $amount = intval($m[1]);
    $toUser = strtolower($m[2]);

    if ($amount <= 0) { sendMessage($chatId, "اكتب رقم صحيح للتحويل."); saveData($db); exit("OK"); }
    if ($user["points"] < $amount) { sendMessage($chatId, "نقاطك ما تكفي للتحويل."); saveData($db); exit("OK"); }

    // بدون قاعدة بيانات ما نقدر نعرف UID من username داخل تيليجرام بسهولة.
    // فبنخلي التحويل “بالآيدي” كحل عملي: تحويل 50 123456789
    sendMessage($chatId, "⚠️ التحويل باليوزر يحتاج قاعدة بيانات أو جلب UID.\nاستخدم التحويل بالآيدي:\nتحويل 50 123456789");
    saveData($db); exit("OK");
}

// تحويل بالنمـر/ID: "تحويل 50 123456789"
if (preg_match("/^تحويل\s+(\d+)\s+(\d+)$/iu", $textRaw, $m)) {
    $amount = intval($m[1]);
    $toId = (string)$m[2];
    if ($amount <= 0) { sendMessage($chatId, "اكتب رقم صحيح للتحويل."); saveData($db); exit("OK"); }
    if ($user["points"] < $amount) { sendMessage($chatId, "نقاطك ما تكفي للتحويل."); saveData($db); exit("OK"); }

    $toUser = &getUser($db, $toId);
    addPoints($user, -$amount);
    addPoints($toUser, $amount);

    sendMessage($chatId, "✅ تم تحويل {$amount} نقطة إلى {$toId}\nنقاطك الآن: {$user["points"]}");
    saveData($db); exit("OK");
}

// ====== الذكاء الاصطناعي: فقط إذا الرسالة تبدأ بـ # ======
$rawTrim = trim($textRaw);
if (mb_substr($rawTrim, 0, 1, "UTF-8") === "#") {
    $prompt = trim(mb_substr($rawTrim, 1, null, "UTF-8"));
    if ($prompt === "") {
        sendMessage($chatId, "اكتب بعد # سؤالك 🙂 مثال:\n# كم تاريخ اليوم؟");
        saveData($db); exit("OK");
    }

    $ai = askAI_OpenRouter($prompt);
    sendMessage($chatId, $ai["ok"] ? $ai["text"] : ("⚠️ " . $ai["text"]));
    saveData($db); exit("OK");
}

// لو مو أمر ولا لعبة ولا # — نعطي تلميح
sendMessage($chatId, "اكتب (مساعدة) للأوامر، أو استخدم # للذكاء الاصطناعي مثل:\n# اشرح لي موضوع بسيط");
saveData($db);
exit("OK");
