<?php

function command_ping(){
    sendMessage("✅ شغال يا بطل");
}

function command_start(){
    $msg =
"هلا 👋 أنا *Nana* 🤖

أنا بوت عربي للأوامر والفعاليات داخل الخاص والقروبات.

📌 أهم الأوامر:
• *اوامر*  — عرض الأوامر
• *مساعدة* — شرح سريع
• *نقاطي*  — نقاطك الحالية
• *لعبة*   — لعبة سريعة

ابدأ اكتب: *اوامر*";
    sendMessage($msg, true); // true = تفعيل الماركداون
}

function command_help(){
    $msg =
"🧠 *مساعدة Nana*

اكتب كلمة من التالي:
• *اوامر* لعرض كل شيء
• *لعبة* للترفيه
• *نقاطي* لمعرفة نقاطك

إذا كنت في قروب: تأكد تعطيني صلاحية قراءة الرسائل (Privacy off) من BotFather إذا تحتاج.";
    sendMessage($msg, true);
}

function command_commands(){
    $msg =
"📋 *قائمة أوامر Nana*

⚙️ عام:
• /start
• /help
• /ping

🗣️ عربي:
• اوامر
• مساعدة
• لعبة
• نقاطي";
    sendMessage($msg, true);
}
