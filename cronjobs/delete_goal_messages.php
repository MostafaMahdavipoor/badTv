<?php

// فایل را در ریشه پروژه خود قرار دهید
require __DIR__ . '/vendor/autoload.php';

use Config\AppConfig;
use Bot\Database;

// --- تنظیمات کنترل سرعت ---
$requestsPerSecond = 15;    // حداکثر 15 درخواست در هر ثانیه
$workDuration      = 10;    // مدت زمان کار (به ثانیه)
$restDuration      = 5;     // مدت زمان استراحت (به ثانیه)
$idleSleep         = 5;     // مدت زمان استراحت وقتی کاری برای انجام نیست (به ثانیه)
// ---------------------------------

$timeBetweenRequests = 1 / $requestsPerSecond;
$config = AppConfig::getConfig();
$botToken = $config['bot']['token'];

function sendTelegramRequest(string $token, string $method, array $data): bool
{
    // این تابع بدون تغییر باقی می‌ماند
    $url = "https://api.telegram.org/bot" . $token . "/" . $method;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        echo "cURL Error: " . $curlError . "\n";
        return false;
    }

    $decodedResponse = json_decode($response, true);
    // اگر پیام قبلا حذف شده باشد (کد 400)، آن را به عنوان موفقیت در نظر می‌گیریم
    return ($httpCode === 200 && ($decodedResponse['ok'] ?? false) === true) || ($httpCode === 400);
}

echo "✅ Worker process started. Waiting for tasks...\n";

// حلقه بی‌نهایت برای اجرای دائمی اسکریپت
while (true) {
    try {
        $db = new Database();
        $messagesToDelete = $db->getDueDeletions();

        if (empty($messagesToDelete)) {
            // اگر پیامی برای حذف نبود، ۵ ثانیه صبر کن و دوباره چک کن
            // echo "No messages to delete. Sleeping for {$idleSleep} seconds...\n";
            sleep($idleSleep);
            continue; // برو به ابتدای حلقه
        }

        echo "Found " . count($messagesToDelete) . " messages to delete. Starting process...\n";

        $workCycleStartTime = microtime(true);

        foreach ($messagesToDelete as $message) {
            $elapsedWorkTime = microtime(true) - $workCycleStartTime;
            if ($elapsedWorkTime >= $workDuration) {
                echo "--> Work cycle finished. Resting for {$restDuration} seconds...\n";
                sleep($restDuration);
                echo "--> Rest complete. Resuming...\n";
                $workCycleStartTime = microtime(true);
            }
            
            $requestStartTime = microtime(true);

            $logId = $message['id'];
            $chatId = $message['chat_id'];
            $messageId = $message['message_id'];

            echo "Processing message #{$messageId} for chat #{$chatId}...\n";

            // ارسال درخواست حذف به تلگرام
            if (sendTelegramRequest($botToken, 'deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId])) {
                // فقط در صورت موفقیت‌آمیز بودن حذف، لاگ را از دیتابیس پاک کن
                $db->removeDeletionLog($logId);
                echo " -> Success. Log entry #{$logId} removed.\n";
            } else {
                echo " -> Failed to delete message #{$messageId}. Log will be kept for retry.\n";
            }

            // کنترل سرعت برای جلوگیری از محدود شدن توسط تلگرام
            $requestEndTime = microtime(true);
            $elapsedRequestTime = $requestEndTime - $requestStartTime;
            $sleepDuration = $timeBetweenRequests - $elapsedRequestTime;

            if ($sleepDuration > 0) {
                usleep($sleepDuration * 1000000);
            }
        }

        echo "Batch finished. Looking for new tasks...\n";

    } catch (Throwable $e) {
        // در صورت بروز هرگونه خطا (مثلا قطعی دیتابیس)، خطا را نمایش بده و ۱۰ ثانیه صبر کن
        echo "❌ An error occurred: " . $e->getMessage() . "\n";
        echo "Waiting for 10 seconds before retrying...\n";
        sleep(10);
    }
}