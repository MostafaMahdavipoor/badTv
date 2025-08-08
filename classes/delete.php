<?php
namespace Bot;



use Config\AppConfig;

class delete
{

    private $messageId;
    private $chatId;

    private $botToken;

    public function __construct($chatId, $messageId)
    {
        $this->chatId = $chatId;
        $this->messageId = $messageId;
        $config                       = AppConfig::getConfig();
        $this->botToken               = $config['bot']['token'];
        sleep(seconds: 15);
        $this->deleteMessageWithDelay($messageId);

    }

    public function deleteMessageWithDelay($messageId = null): void
    {
        $this->sendRequest("deleteMessage", [
            "chat_id"    => $this->chatId,
            "message_id" => $messageId ?: $this->messageId,
        ]);
    }

    public function sendRequest($method, $data)
    {
        $url = "https://api.telegram.org/bot" . $this->botToken . "/$method";
        $ch  = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);
        $this->logTelegramRequest($method, $data, $response, $httpCode, $curlError);
        if ($curlError) {
            return false;
        }
        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response, true);
        } else {
            $errorResponse = json_decode($response, true);
            $errorMessage  = $errorResponse['description'] ?? 'Unknown error';
            return false;
        }
    }
    private function logTelegramRequest($method, $data, $response, $httpCode, $curlError = null): void
    {
        $logData = [
            'time'         => date("Y-m-d H:i:s"),
            'method'       => $method,
            'request_data' => $data,
            'response'     => $response,
            'http_code'    => $httpCode,
            'curl_error'   => $curlError,
        ];
        $logMessage = json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        error_log('logMessage:' . print_r($logMessage, true));
    }
}
