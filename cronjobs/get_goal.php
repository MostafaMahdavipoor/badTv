<?php
require __DIR__ . '/../vendor/autoload.php';

use Bot\Database;
use Config\AppConfig;


$config = AppConfig::getConfig();


$videoApiToken = 'MjIzOTIxXzE3NTQyMDk0MTJfNjFkY2UzMmMzNzBjNDVhZGZkODM5YTEyYzFkYWQ2NjIzODM5YzhlNA==';
$telegramToken = $config['bot']['token'];
$chatId = 7184203071 ; 

// === API URL ===
$apiUrl = "https://www.scorebat.com/video-api/v3/feed/?token=" . $videoApiToken;

// === لیست 5 لیگ معتبر ===
$topLeagues = ['ENGLAND: Premier League', 'SPAIN: La Liga', 'ITALY: Serie A', 'GERMANY: Bundesliga', 'FRANCE: Ligue 1'];

// === دریافت اطلاعات ویدیو ===
$response = file_get_contents($apiUrl);
$data = json_decode($response, true);

if (!$data || empty($data['response'])) {
    die("خطا در دریافت اطلاعات از API ویدیو.");
}

// === فیلتر و ارسال فقط 5 لیگ معتبر ===
foreach ($data['response'] as $match) {
    $league = $match['competition']['name'];

    if (in_array($league, $topLeagues)) {
        $title = $match['title'];
        $matchUrl = $match['url'];
        $date = date("Y-m-d H:i", strtotime($match['date']));
        $message = "⚽️ $title\n📅 $date\n🏆 $league\n🎥 تماشای ویدیو گل:\n$matchUrl";

        sendToTelegram($telegramToken, $chatId, $message);
    }
}

// === تابع ارسال پیام به تلگرام ===
function sendToTelegram($token, $chat_id, $message) {
    $url = "https://api.telegram.org/bot$token/sendMessage";

    $post_fields = [
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => 'HTML'
    ];

    $ch = curl_init(); 
    curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type:multipart/form-data"));
    curl_setopt($ch, CURLOPT_URL, $url); 
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields); 
    $output = curl_exec($ch);
    curl_close($ch);

    echo "ارسال شد: $message\n";
}
?>