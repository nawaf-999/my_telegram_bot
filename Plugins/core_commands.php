<?php

function data_path() {
    return __DIR__ . "/../data.json";
}

function load_data() {
    $path = data_path();
    if (!file_exists($path)) return ["users" => []];
    $raw = file_get_contents($path);
    $json = json_decode($raw, true);
    return $json ?: ["users" => []];
}

function save_data($data) {
    file_put_contents(data_path(), json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
}

function get_user(&$data, $userId) {
    if (!isset($data["users"][$userId])) {
        $data["users"][$userId] = [
            "points" => 0,
            "daily_last" => null,
        ];
    }
    return $data["users"][$userId];
}

function add_points($userId, $amount) {
    $data = load_data();
    $u = get_user($data, $userId);
    $u["points"] += $amount;
    $data["users"][$userId] = $u;
    save_data($data);
    return $u["points"];
}

function transfer_points($fromId, $toId, $amount) {
    $amount = (int)$amount;
    if ($amount <= 0) return "المبلغ لازم يكون رقم أكبر من صفر.";

    $data = load_data();
    $from = get_user($data, $fromId);
    $to = get_user($data, $toId);

    if ($from["points"] < $amount) return "نقاطك ما تكفي. نقاطك الحالية: {$from["points"]}";

    $from["points"] -= $amount;
    $to["points"] += $amount;

    $data["users"][$fromId] = $from;
    $data["users"][$toId] = $to;
    save_data($data);

    return "تم تحويل {$amount} نقطة ✅\nرصيدك الآن: {$from["points"]}";
}

function handle_core_commands($chatId, $messageId, $text, $ctx) {
    $userId = $ctx["user_id"];
    $name = $ctx["name"];
    $username = $ctx["username"];

    $t = trim($text);

    // unified help text
    $help = "أوامر Nana 🤍
- ابدأ
- مساعدة
- معلوماتي
- نقاطي
- يومي (مكافأة يومية)
- تحويل 50 إلى 123456789
- العاب
- فعالية

ذكاء اصطناعي:
أي رسالة تبدأ بـ # تروح للذكاء الاصطناعي
مثال: # اكتب لي خطة مذاكرة";

    // Normalize for Arabic commands: compare without case changes
    if ($t === "/start" || $t === "ابدأ" || $t === "start") {
        sendMessage($chatId, "👋 أهلًا {$name}!\nأنا Nana بوت عربي للألعاب والنقاط والفعاليات.\nاكتب: مساعدة", $messageId);
        return;
    }

    if ($t === "مساعدة" || $t === "help") {
        sendMessage($chatId, $help, $messageId);
        return;
    }

    if ($t === "معلوماتي") {
        $u = $username ? "@{$username}" : "بدون";
        sendMessage($chatId, "👤 معلوماتك:\nالاسم: {$name}\nالمعرف: {$u}\nID: {$userId}", $messageId);
        return;
    }

    if ($t === "نقاطي") {
        $data = load_data();
        $u = get_user($data, $userId);
        sendMessage($chatId, "🏆 نقاطك الحالية: {$u["points"]}", $messageId);
        return;
    }

    if ($t === "يومي") {
        $data = load_data();
        $u = get_user($data, $userId);

        $today = date("Y-m-d");
        if ($u["daily_last"] === $today) {
            sendMessage($chatId, "أخذت مكافأتك اليوم خلاص ✅ تعال بكرة.", $messageId);
            return;
        }

        $reward = 20;
        $u["daily_last"] = $today;
        $u["points"] += $reward;
        $data["users"][$userId] = $u;
        save_data($data);

        sendMessage($chatId, "🎁 مكافأة يومية: +{$reward} نقطة\nرصيدك الآن: {$u["points"]}", $messageId);
        return;
    }

    if (mb_substr($t, 0, 5, "UTF-8") === "تحويل") {
        // تحويل 50 إلى 123456789
        $parts = preg_split("/\s+/", $t);
        // expected: [تحويل, amount, إلى, userId]
        if (count($parts) >= 4) {
            $amount = (int)$parts[1];
            $toId = (int)$parts[3];
            $res = transfer_points($userId, $toId, $amount);
            sendMessage($chatId, $res, $messageId);
        } else {
            sendMessage($chatId, "الصيغة:\nتحويل 50 إلى 123456789", $messageId);
        }
        return;
    }

    if ($t === "العاب") {
        sendMessage($chatId, "🎮 ألعاب Nana:
1) اكتب: تحدي
2) اكتب: حجر ورقة مقص
3) اكتب: سؤال", $messageId);
        return;
    }

    if ($t === "تحدي") {
        $n = rand(1, 5);
        // نخليها بسيطة الآن: يعطي رقم ويكافئك إذا قلت "جواب X"
        sendMessage($chatId, "🎯 خمن رقم من 1 إلى 5\nاكتب: جواب رقم\nمثال: جواب 3", $messageId);
        return;
    }

    if (mb_substr($t, 0, 4, "UTF-8") === "جواب") {
        // نسخة بسيطة: مكافأة مشاركة
        $new = add_points($userId, 5);
        sendMessage($chatId, "✅ تم تسجيل مشاركتك! +5 نقاط\nرصيدك: {$new}", $messageId);
        return;
    }

    if ($t === "حجر ورقة مقص") {
        $choices = ["حجر", "ورقة", "مقص"];
        $bot = $choices[array_rand($choices)];
        sendMessage($chatId, "🪨📄✂️ اختر أنت:\nاكتب: حجر\nأو: ورقة\nأو: مقص\n(البوت اختار بسر!)", $messageId);
        return;
    }

    if ($t === "حجر" || $t === "ورقة" || $t === "مقص") {
        $choices = ["حجر", "ورقة", "مقص"];
        $bot = $choices[array_rand($choices)];
        $user = $t;

        $result = "تعادل";
        if (($user === "حجر" && $bot === "مقص") || ($user === "ورقة" && $bot === "حجر") || ($user === "مقص" && $bot === "ورقة")) {
            $result = "فزت";
        } elseif ($user !== $bot) {
            $result = "خسرت";
        }

        if ($result === "فزت") add_points($userId, 10);

        sendMessage($chatId, "أنا اخترت: {$bot}\nالنتيجة: {$result}\n(إذا فزت: +10 نقاط)", $messageId);
        return;
    }

    if ($t === "فعالية") {
        sendMessage($chatId, "✨ فعالية اليوم:\nارسل كلمة (نشاط) وخذ +10 نقاط.", $messageId);
        return;
    }

    if ($t === "نشاط") {
        $new = add_points($userId, 10);
        sendMessage($chatId, "🔥 ممتاز! +10 نقاط\nرصيدك: {$new}", $messageId);
        return;
    }

    // default: do nothing (or give hint)
    // sendMessage($chatId, "اكتب: مساعدة", $messageId);
}
