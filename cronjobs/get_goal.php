<?php
// در ابتدای فایل اضافه کنید تا خروجی در مرورگر خواناتر باشد
header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/../vendor/autoload.php';

use Bot\Database;
use Config\AppConfig;

$config = AppConfig::getConfig();

$videoApiToken = 'MjIzOTIxXzE3NTQyMDk0MTJfNjFkY2UzMmMzNzBjNDVhZGZkODM5YTEyYzFkYWQ2NjIzODM5YzhlNA==';
$telegramToken = $config['bot']['token'];
$chatId = 7184203071;

$apiUrl = "https://www.scorebat.com/video-api/v3/feed/?token=" . $videoApiToken;

$topLeagues = [ 'ENGLAND: Premier League', 'SPAIN: La Liga', 'ITALY: Serie A', 'GERMANY: Bundesliga', 'FRANCE: Ligue 1' ];
$favoriteTeams = [ 'Real Madrid', 'Barcelona', 'Atletico Madrid', 'Bayer Leverkusen', 'Bayern Munich', 'Borussia Dortmund', 'Manchester City', 'Manchester United', 'Liverpool', 'Arsenal', 'Tottenham Hotspur', 'Chelsea', 'Paris Saint Germain', 'Inter', 'AC Milan', 'Juventus', 'Roma', 'Napoli' ];

date_default_timezone_set('Asia/Tehran');

echo "اسکریپت شروع به کار کرد.\n";
echo "================================\n";

// --- دیباگ بخش حافظه ---
$sent_log_file = __DIR__ . '/sent_matches.log';
echo "1. مسیر فایل لاگ: " . $sent_log_file . "\n";

if (!file_exists($sent_log_file)) {
    echo "2. فایل لاگ وجود نداشت، در حال ایجاد فایل خالی...\n";
    file_put_contents($sent_log_file, '');
} else {
    echo "2. فایل لاگ از قبل وجود دارد.\n";
}

$sent_ids = file($sent_log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($sent_ids === false) { die("خطا در خواندن فایل لاگ."); }

echo "3. تعداد شناسه‌های ارسال شده قبلی که از فایل خوانده شد: " . count($sent_ids) . "\n\n";

// --- دریافت اطلاعات ---
echo "4. در حال دریافت اطلاعات از API ویدیو...\n";
$response = file_get_contents($apiUrl);
$data = json_decode($response, true);
if (!$data || !isset($data['response']) || !is_array($data['response'])) { die("خطا! پاسخ دریافتی از API معتبر نیست."); }

echo "5. تعداد کل مسابقات دریافت شده: " . count($data['response']) . "\n";
echo "================================\n\n";

// حلقه اصلی
foreach ($data['response'] as $match) {
    if (!is_array($match) || !isset($match['title'], $match['matchviewUrl'])) { continue; }

    $title = $match['title'];
    echo "--- بررسی مسابقه: \"$title\"\n";

    // --- دیباگ شناسه ---
    $matchId = null;
    if (preg_match('/\/(\d+)\/\?token=/', $match['matchviewUrl'], $id_matches)) {
        $matchId = $id_matches[1];
    }
    
    if (!$matchId) {
        echo "❌ مسابقه رد شد (دلیل: شناسه منحصر به فرد پیدا نشد).\n---------------------------------\n";
        continue;
    }
    echo " - شناسه منحصر به فرد بازی: '$matchId'\n";

    // --- دیباگ بررسی تکراری بودن ---
    if (in_array($matchId, $sent_ids)) {
        echo "❌ مسابقه رد شد (دلیل: شناسه تکراری است و قبلاً ارسال شده).\n";
        echo "---------------------------------\n";
        continue;
    }

    // منطق فیلتر (بدون تغییر)
    $league = $match['competition'];
    $teams = explode(' - ', $title);
    $team1 = trim($teams[0] ?? '');
    $team2 = trim($teams[1] ?? '');
    $isTopLeagueMatch = in_array($league, $topLeagues);
    $isFavoriteTeamMatch = in_array($team1, $favoriteTeams) || in_array($team2, $favoriteTeams);

    echo " - بررسی لیگ معتبر: " . ($isTopLeagueMatch ? 'بله' : 'خیر') . "\n";
    echo " - بررسی تیم محبوب: " . ($isFavoriteTeamMatch ? 'بله' : 'خیر') . "\n";

    if ($isTopLeagueMatch || $isFavoriteTeamMatch) {
        echo "✅ مسابقه منتخب است! دلیل: " . ($isTopLeagueMatch ? "[لیگ معتبر] " : "") . ($isFavoriteTeamMatch ? "[تیم محبوب]" : "") . "\n";

        $matchPageUrl = $match['matchviewUrl'];
        $date = date("Y-m-d H:i", strtotime($match['date']));
        $message = "⚽️ $title\n📅 $date\n🏆 $league\n\n🎥 تماشای ویدیو گل:\n$matchPageUrl";
        
        echo "--> متن پیام نهایی برای ارسال:\n$message\n";
        sendToTelegram($telegramToken, $chatId, $message);
        
        // --- دیباگ ثبت شناسه ---
        echo "+++ شناسه '$matchId' به فایل لاگ اضافه شد.\n";
        file_put_contents($sent_log_file, $matchId . "\n", FILE_APPEND);
        $sent_ids[] = $matchId; // آپدیت آرایه در حال اجرا

    } else {
        echo "❌ مسابقه رد شد (هیچکدام از شرط‌های لیگ/تیم برقرار نبود).\n";
    }
    echo "---------------------------------\n";
}

echo "\nاسکریپت به پایان رسید.\n";

function sendToTelegram($token, $chat_id, $message) {
    echo ">>> تابع sendToTelegram فراخوانی شد...\n";
    $url = "https://api.telegram.org/bot$token/sendMessage";
    $post_fields = ['chat_id' => $chat_id, 'text' => $message, 'parse_mode' => 'HTML'];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type:multipart/form-data"));
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    $output = curl_exec($ch);
    curl_close($ch);
    echo "<<< پاسخ از API تلگرام: " . $output . "\n";
}
?>