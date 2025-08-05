<?php
namespace Bot;

use Config\AppConfig;
use Payment\ZarinpalPaymentHandler;

require_once __DIR__ . "/jdf.php";

class BotHandler
{
    private $chatId;
    private $text;
    private $messageId;
    private $message;
    public $db;
    private $fileHandler;
    private $zarinpalPaymentHandler;
    private $botToken;
    private $botLink;

    public function __construct($chatId, $text, $messageId, $message)
    {
        $this->chatId                 = $chatId;
        $this->text                   = $text;
        $this->messageId              = $messageId;
        $this->message                = $message;
        $this->db                     = new Database();
        $this->fileHandler            = new FileHandler();
        $config                       = AppConfig::getConfig();
        $this->botToken               = $config['bot']['token'];
        $this->botLink                = $config['bot']['bot_link'];
        $this->zarinpalPaymentHandler = new ZarinpalPaymentHandler();
    }
    public function deleteMessageWithDelay(): void
    {
        $this->sendRequest("deleteMessage", [
            "chat_id"    => $this->chatId,
            "message_id" => $this->messageId,
        ]);
    }
    public function handleSuccessfulPayment($update): void
    {
        $userLanguage = $this->db->getUserLanguage($this->chatId);
        if (isset($update['message']['successful_payment'])) {
            $chatId            = $update['message']['chat']['id'];
            $payload           = $update['message']['successful_payment']['invoice_payload'];
            $successfulPayment = $update['message']['successful_payment'];
        }
    }
    public function handlePreCheckoutQuery($update): void
    {
        if (isset($update['pre_checkout_query'])) {
            $query_id = $update['pre_checkout_query']['id'];
            file_put_contents('log.txt', date('Y-m-d H:i:s') . " - Received pre_checkout_query: " . print_r($update, true) . "\n", FILE_APPEND);
            $url         = "https://api.telegram.org/bot" . $this->botToken . "/answerPreCheckoutQuery";
            $post_fields = [
                'pre_checkout_query_id' => $query_id,
                'ok'                    => true,
                'error_message'         => "",
            ];
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_fields));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            $response = curl_exec($ch);
            curl_close($ch);
            file_put_contents('log.txt', date('Y-m-d H:i:s') . " - answerPreCheckoutQuery Response: " . print_r(json_decode($response, true), true) . "\n", FILE_APPEND);
        }
    }
    public function handleCallbackQuery($callbackQuery): void
    {
        $callbackData    = $callbackQuery["data"] ?? null;
        $chatId          = $callbackQuery["message"]["chat"]["id"] ?? null;
        $callbackQueryId = $callbackQuery["id"] ?? null;
        $messageId       = $callbackQuery["message"]["message_id"] ?? null;
        $currentKeyboard = $callbackQuery["message"]["reply_markup"]["inline_keyboard"] ?? [];
        $userLanguage    = $this->db->getUserLanguage($this->chatId);
        $user            = $this->message['from'] ?? $this->callbackQuery['from'] ?? null;
        if ($user !== null) {
            $this->db->saveUser($user);
        } else {
            error_log("❌ Cannot save user: 'from' is missing in both message and callbackQuery.");
        }
        if (! $callbackData || ! $chatId || ! $callbackQueryId || ! $messageId) {
            error_log("Callback query missing required data.");
            return;
        }

        switch ($callbackData) {
            case 'admin_upload_goal':

                break;

            case 'admin_list_goal':

                break;

            case 'admin_settings':
                $settingsText = "⚙️ <b>بخش تنظیمات</b>\n\n";
                $settingsText .= "لطفاً یکی از گزینه‌های زیر را انتخاب کنید:";

                $settingsKeyboard = [
                    [
                        ['text' => '➕ افزودن ادمین', 'callback_data' => 'settings_add_admin'],
                        ['text' => '👥 لیست ادمین‌ها', 'callback_data' => 'settings_list_admins'],
                    ],
                    [
                        ['text' => '📢 مدیریت کانال‌ها', 'callback_data' => 'settings_manage_channels'],
                    ],
                    [
                        ['text' => '⬅️ بازگشت به پنل ادمین', 'callback_data' => 'admin_panel'],
                    ],
                ];

                $this->sendRequest("editMessageText", [
                    'chat_id'      => $chatId,
                    'message_id'   => $messageId,
                    'text'         => $settingsText,
                    'parse_mode'   => 'HTML',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => $settingsKeyboard,
                    ]),
                ]);

                break;

            case 'settings_manage_channels':

                $this->fileHandler->saveState($chatId, 'awaiting_channel_link');

                $promptText = "لطفا لینک یا یوزرنیم کانال مورد نظر را ارسال کنید.\n\n";
                $promptText .= "مثال:\n";
                $promptText .= "https://t.me/my_channel\n";
                $promptText .= "یا\n";
                $promptText .= "@my_channel\n\n";
                $promptText .= "<i>⚠️ ربات باید حتما در کانال مورد نظر ادمین باشد.</i>";

                $cancelKeyboard = [
                    [['text' => '❌ لغو عملیات', 'callback_data' => 'cancel_action']],
                ];

                $this->sendRequest("editMessageText", [
                    'chat_id'      => $chatId,
                    'message_id'   => $messageId,
                    'text'         => $promptText,
                    'parse_mode'   => 'HTML',
                    'reply_markup' => json_encode(['inline_keyboard' => $cancelKeyboard]),
                ]);
                break;

            case 'cancel_action':
                $this->fileHandler->saveState($chatId, '');
                $this->AdminMenu($chatId);
                break;
        }

    }
    public function handleInlineQuery(): void
    {

    }
    public function handleRequest(): void
    {

        if (isset($this->message["from"])) {
            $this->db->saveUser($this->message["from"]);
        } else {
            error_log("BotHandler::handleRequest: 'from' field missing for non-start message. Update type might not be a user message.");
        }

      
        if (str_starts_with($this->text, "/start")) {
            $isAdmin = $this->db->isAdmin($this->chatId);
            $this->fileHandler->saveState($this->chatId, '');

            if ($isAdmin) {
                $this->AdminMenu($this->chatId);
            } else {
                $this->sendRequest("sendMessage", [
                    "chat_id"    => $this->chatId,
                    "text"       => "hi :)",
                    "parse_mode" => "HTML",

                ]);
            }
            return;
        } 
        $state = $this->fileHandler->getState($this->chatId);

        if ($state === 'awaiting_channel_link') {
            $this->processChannelLink($this->chatId, $this->text);
        }
    }

    private function processChannelLink(int $chatId, string $channelLink): void
    {
        $channelUsername   = str_replace(['https://t.me/', 't.me/', '@'], '', $channelLink);
        $channelIdentifier = '@' . $channelUsername;
        $isAdmin           = $this->checkBotAdminStatus($channelIdentifier);

        if ($isAdmin) {
            $this->db->addChannel($channelIdentifier);

            $this->sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text'    => "✅ کانال {$channelIdentifier} با موفقیت اضافه شد.",
            ]);
            $this->fileHandler->saveState($chatId, 'start');
        } else {
            $this->sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text'    => "❌ خطا: ربات در کانال {$channelIdentifier} ادمین نیست یا کانال وجود ندارد. لطفاً ربات را ادمین کرده و دوباره تلاش کنید.",
            ]);
        }
    }

    private function checkBotAdminStatus(string $channelIdentifier): bool
    {

        $botInfo = $this->sendRequest('getMe', []);
        if (! $botInfo || ! $botInfo['ok']) {
            error_log("Could not get bot info (getMe)");
            return false;
        }
        $botId    = $botInfo['result']['id'];
        $response = $this->sendRequest('getChatMember', [
            'chat_id' => $channelIdentifier,
            'user_id' => $botId,
        ]);
        return $response && $response['ok'] && $response['result']['status'] === 'administrator';
    }
    public function AdminMenu(int $chatId): void
    {
        $panelText = "👨‍💻 <b>پنل مدیریت ربات</b>\n\n";
        $panelText .= "ادمین عزیز، خوش آمدید. لطفاً یک گزینه را انتخاب کنید:";

        $inlineKeyboard = [

            [
                ['text' => '⚽ آپلود گل', 'callback_data' => 'admin_upload_goal'],
                ['text' => '📋 لیست گل‌ها', 'callback_data' => 'admin_list_goal'],
            ],
            [
                ['text' => '⚙️ تنظیمات', 'callback_data' => 'admin_settings'],
            ],
        ];

        $data = [
            'chat_id'      => $chatId,
            'message_id'   => $this->messageId,
            'text'         => $panelText,
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $inlineKeyboard,
            ]),
        ];
        $this->sendRequest("editMessageText", $data);
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
    }
}
