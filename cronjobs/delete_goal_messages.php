<?php
require __DIR__ . '/../vendor/autoload.php';

use Bot\Database;
use Config\AppConfig;

function sendTelegramRequest(string $method, array $params): array|null {
    $config = AppConfig::getConfig();
    $token  = $config['bot']['token'];

    $url = "https://api.telegram.org/bot{$token}/{$method}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($params),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        
        return null;
    }

    return [
        'http_code' => $httpCode,
        'body'      => json_decode($response, true),
        'raw'       => $response
    ];
}

function sendToTelegramChannel(string $message): void {
    $config = AppConfig::getConfig();
    sendTelegramRequest('sendMessage', [
        'chat_id'    => '@mybugsram',
        'text'       => $message,
        'parse_mode' => 'HTML',
    ]);
}

function deleteTelegramMessage(string $chatId, int $messageId, string $botToken): bool {
    $response = sendTelegramRequest('deleteMessage', [
        'chat_id'    => $chatId,
        'message_id' => $messageId
    ]);

    if (!$response) return false;

    $http = $response['http_code'];
    $body = $response['body'];

    return ($http === 200 && $body['ok']) ||
           ($http === 400 && isset($body['description']) && str_contains($body['description'], 'message to delete not found'));
}

function runDeletionLogic(): int {
    $processedCount = 0;

    try {
        $db = new Database();
        $tasks = $db->getDueDeletions();
        if (empty($tasks)) return 0;

        $botToken = AppConfig::getConfig()['bot']['token'];

        foreach ($tasks as $task) {
            $chatId    = $task['chat_id'];
            $messageId = $task['message_id'];

            if (deleteTelegramMessage($chatId, $messageId, $botToken)) {
                $db->removeDeletionLog($task['id']);
                $processedCount++;
            } else {
                error_log("❌ Failed to delete message {$messageId} in chat {$chatId}");
            }
        }
    } catch (Exception $e) {
        $errorMessage  = "<b>❌ خطای بحرانی در کرون جاب</b>\n\n";
        $errorMessage .= "متن خطا:\n<code>" . htmlspecialchars($e->getMessage()) . "</code>";
        sendToTelegramChannel($errorMessage);
        error_log("Exception in runDeletionLogic: " . $e->getMessage());
    }

    return $processedCount;
}
$startTime       = time();
$durationSeconds = 55;
$totalProcessed  = 0;

while ((time() - $startTime) < $durationSeconds) {
    $processed = runDeletionLogic();
    $totalProcessed += $processed;

    sleep(2);
}

if ($totalProcessed > 0) {
    $message  = "<b>✅ گزارش کرون جاب حذف پیام</b>\n\n";
    $message .= "تعداد پیام‌های حذف‌شده: <b>{$totalProcessed}</b>\n";
    $message .= "تاریخ و زمان: " . date('Y-m-d H:i:s');
    sendToTelegramChannel($message);
}
