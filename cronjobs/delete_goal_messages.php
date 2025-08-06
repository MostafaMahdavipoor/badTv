<?php

date_default_timezone_set('Asia/Tehran');

require __DIR__ . '/../vendor/autoload.php';

use Config\AppConfig;
use Bot\Database;

// --- بخش تنظیمات کنترل سرعت ---
$requestsPerSecond = 15;    // حداکثر 15 درخواست در هر ثانیه
$workDuration      = 10;    // مدت زمان کار (به ثانیه)
$restDuration      = 5;     // مدت زمان استراحت (به ثانیه)
// ---------------------------------

$timeBetweenRequests = 1 / $requestsPerSecond;

function sendTelegramRequest(string $token, string $method, array $data): bool
{
    $url = "https://api.telegram.org/bot" . $token . "/" . $method;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decodedResponse = json_decode($response, true);
    return ($httpCode === 200 && $decodedResponse['ok'] === true) || ($httpCode === 400);
}


echo "Cron Job Started: Checking for messages to delete...\n";

$config = AppConfig::getConfig();
$botToken = $config['bot']['token'];
$db = new Database();

$messagesToDelete = $db->getDueDeletions();

if (empty($messagesToDelete)) {
    echo "No messages to delete at this time.\n";
    exit;
}

echo "Found " . count($messagesToDelete) . " messages to delete. Starting process...\n";

$workCycleStartTime = microtime(true);

foreach ($messagesToDelete as $message) {
    $elapsedWorkTime = microtime(true) - $workCycleStartTime;
    if ($elapsedWorkTime >= $workDuration) {
        echo "--> 10-second work cycle finished. Resting for 5 seconds...\n";
        sleep($restDuration);
        echo "--> Rest complete. Resuming...\n";
        $workCycleStartTime = microtime(true);
    }
    $requestStartTime = microtime(true);

    $logId = $message['id'];
    $chatId = $message['chat_id'];
    $messageId = $message['message_id'];

    echo "Processing message #{$messageId}...\n";

    sendTelegramRequest($botToken, 'deleteMessage', [
        'chat_id'    => $chatId,
        'message_id' => $messageId
    ]);

    $db->removeDeletionLog($logId);
    echo " -> Log entry #{$logId} removed.\n";

    $requestEndTime = microtime(true);
    $elapsedRequestTime = $requestEndTime - $requestStartTime;
    $sleepDuration = $timeBetweenRequests - $elapsedRequestTime;

    if ($sleepDuration > 0) {
        usleep($sleepDuration * 1000000);
    }
}

echo "Cron Job Finished.\n";