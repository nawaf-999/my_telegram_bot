<?php
require_once "config.php";
require_once "util.php";

foreach(glob("Plugins/*") as $plugin){
    include_once $plugin;
}

$update = file_get_contents("php://input");
//if this file accessed via browser or terminal(empty input), exit
if(empty($update)){
    exit("Error, don't open this file directly from your browser or terminal");
}

$updateData = json_decode($update,true);
$messageData = isset($updateData["callback_query"]) ? $updateData["callback_query"]["message"] : $updateData["message"];
$messageTime = $messageData["date"];
$chatId = $messageData["chat"]["id"];
$messageId = $messageData["message_id"];
$messageText = $messageData["text"];

$data = $updateData["callback_query"]["data"];
$from_id = $messageData["from"]["id"];
$from_name = $messageData["from"]["first_name"] . $messageData["from"]["last_name"];
$from_username = $messageData["from"]["username"];

// media
$sticker = $messageData["sticker"];
$sticker_id = $messageData["sticker"]["file_id"];
$voice = $messageData["voice"];
$voice_id = $messageData["voice"]["file_id"];
$file = $messageData["document"];
$file_id = $messageData["document"]["file_id"];
$audio = $messageData["audio"];
$audio_id = $messageData["audio"]["file_id"];
$video = $messageData["video"];
$video_id = $messageData["video"]["file_id"];
$contact = $messageData["contact"];
$contact_id = $messageData["contact"]["file_id"];
$photo = $messageData["photo"];
$photo_id = $messageData["message"]["photo"][0]["file_id"];
sendMessage($chatId, "البوت يعمل الآن 🔥");
$text = trim(mb_strtolower($messageText));

switch($text){

    case "/start":
    case "ابدأ":
        sendMessage($chatId, "👋 أهلاً بك في بوت Nana 🤍

اكتب (مساعدة) لرؤية الأوامر.");
    break;

    case "مساعدة":
        sendMessage($chatId, "📋 الأوامر:

ابدأ
معلوماتي
نقاطي
العاب
عن البوت");
    break;

    case "معلوماتي":
        sendMessage($chatId, "👤 معلوماتك:

الاسم: $from_name
المعرف: @$from_username
الآي دي: $from_id");
    break;

    case "نقاطي":
        sendMessage($chatId, "🏆 نقاطك الحالية: 0");
    break;

    case "العاب":
        sendMessage($chatId, "🎮 اكتب (تحدي) للعب لعبة سريعة!");
    break;

    case "تحدي":
        $number = rand(1,5);
        sendMessage($chatId, "🎯 خمن رقم من 1 إلى 5!");
    break;

    case "عن البوت":
        sendMessage($chatId, "🤖 Nana Bot يعمل على Render 🚀");
    break;
}
