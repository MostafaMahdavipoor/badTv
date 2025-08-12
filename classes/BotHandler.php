<?php

namespace Bot;

use Bot\delete;
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
    private $callbackQueryId;
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
    public function deleteMessageWithDelay($messageId = null): void
    {
        $this->sendRequest("deleteMessage", [
            "chat_id"    => $this->chatId,
            "message_id" => $messageId ?: $this->messageId,
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
        $callbackData          = $callbackQuery["data"] ?? null;
        $chatId                = $callbackQuery["message"]["chat"]["id"] ?? null;
        $this->callbackQueryId = $callbackQuery["id"] ?? null;
        $messageId             = $callbackQuery["message"]["message_id"] ?? null;
        $currentKeyboard       = $callbackQuery["message"]["reply_markup"]["inline_keyboard"] ?? [];
        $userLanguage          = $this->db->getUserLanguage($this->chatId);
        $user                  = $this->message['from'] ?? $this->callbackQuery['from'] ?? null;
        if ($user !== null) {
            $this->db->saveUser($user);
        } else {
            error_log("❌ Cannot save user: 'from' is missing in both message and callbackQuery.");
        }
        if (! $callbackData || ! $chatId || ! $this->callbackQueryId || ! $messageId) {
            error_log("Callback query missing required data.");
            return;
        }

        switch ($callbackData) {
            case 'admin_upload_goal':
                $this->fileHandler->saveState($chatId, 'awaiting_goal_upload');
                $this->fileHandler->saveMessageId($chatId, $messageId);
                $promptText     = "لطفاً ویدیو یا گیف مورد نظر را ارسال کنید.";
                $cancelKeyboard = [[['text' => '❌ لغو عملیات', 'callback_data' => 'admin_panel']]];

                $this->sendRequest("editMessageText", [
                    'chat_id'      => $chatId,
                    'message_id'   => $messageId,
                    'text'         => $promptText,
                    'reply_markup' => json_encode(['inline_keyboard' => $cancelKeyboard]),
                ]);
                break;

            case 'admin_settings':
                $settingsText = "⚙️ <b>بخش تنظیمات</b>\n\n";
                $settingsText .= "لطفاً یکی از گزینه‌های زیر را انتخاب کنید:";

                $settingsKeyboard = [
                    [
                        ['text' => '👥 مدیریت ادمین‌ها', 'callback_data' => 'settings_list_admins'],
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
                $this->showChannelsMenu($chatId, $messageId);
                break;
            case 'prompt_add_channel':

                $this->fileHandler->saveState($chatId, 'awaiting_channel_link');
                $this->fileHandler->saveMessageId($chatId, $messageId);

                $promptText = "لطفا لینک یا یوزرنیم کانال مورد نظر را ارسال کنید.\n\n";
                $promptText .= "<i>⚠️ ربات باید حتما در کانال مورد نظر ادمین باشد.</i>";
                $cancelKeyboard = [[['text' => '❌ لغو و بازگشت', 'callback_data' => 'settings_manage_channels']]];

                $res = $this->sendRequest("editMessageText", [
                    'chat_id'      => $chatId,
                    'message_id'   => $messageId,
                    'text'         => $promptText,
                    'parse_mode'   => 'HTML',
                    'reply_markup' => json_encode(['inline_keyboard' => $cancelKeyboard]),
                ]);
                $this->fileHandler->saveMessageId($chatId, $res['result']['message_id']);
                break;
            case 'cancel_action':
                $this->fileHandler->saveState($chatId, '');
                $this->AdminMenu($messageId);
                break;
            case 'admin_panel':
                $this->fileHandler->saveState($chatId, '');
                $this->AdminMenu($messageId);
                break;

            case (str_starts_with($callbackData, 'delete_channel_')):
                $channelUsernameToDelete = urldecode(substr($callbackData, strlen('delete_channel_')));
                $deleted                 = $this->db->deleteChannelByUsername($channelUsernameToDelete);
                if ($deleted) {
                    $this->answerCallbackQuery($this->callbackQueryId, "کانال با موفقیت حذف شد.", true);
                    $this->showChannelsMenu($chatId, $messageId);
                } else {
                    $this->answerCallbackQuery($this->callbackQueryId, "خطا در حذف کانال.", true);
                }
                break;

            case 'settings_list_admins':
                $this->showAdminsMenu($chatId, $messageId);
                break;

            case 'prompt_add_admin':

                $this->fileHandler->saveState($chatId, 'awaiting_admin_id');
                $this->fileHandler->saveMessageId($chatId, $messageId);

                $promptText = "لطفاً اطلاعات کاربر مورد نظر را به یکی از سه روش زیر ارسال کنید:\n\n";
                $promptText .= "1️⃣ ارسال آیدی عددی (مثال: 12345678)\n";
                $promptText .= "2️⃣ ارسال یوزرنیم (مثال: @username)\n";
                $promptText .= "3️⃣ فوروارد کردن پیامی از کاربر\n\n";
                $promptText .= "<i>توجه: برای افزودن ادمین کاربر باید قبلاً ربات را استارت زده باشد.</i>";

                $cancelKeyboard = [[['text' => '❌ لغو و بازگشت', 'callback_data' => 'settings_list_admins']]];

                $res = $this->sendRequest("editMessageText", [
                    'chat_id'      => $chatId,
                    'message_id'   => $messageId,
                    'text'         => $promptText,
                    'parse_mode'   => 'HTML',
                    'reply_markup' => json_encode(['inline_keyboard' => $cancelKeyboard]),
                ]);
                $this->fileHandler->saveMessageId($chatId, $res['result']['message_id']);
                break;

            case (str_starts_with($callbackData, 'remove_admin_')):
                $adminIdToRemove = substr($callbackData, strlen('remove_admin_'));

                $removed = $this->db->removeAdmin((int) $adminIdToRemove);

                if ($removed) {
                    $this->answerCallbackQuery($this->callbackQueryId, "ادمین با موفقیت حذف شد.", true);
                    $this->showAdminsMenu($chatId, $messageId);
                } else {
                    $this->answerCallbackQuery($this->callbackQueryId, "خطا در حذف ادمین.", true);
                }
                break;

            case (str_starts_with($callbackData, 'show_admin_info_')):
                $adminIdToShow = substr($callbackData, strlen('show_admin_info_'));
                $adminInfo     = $this->db->getUserInfo((int) $adminIdToShow);

                if ($adminInfo && ! empty($adminInfo['username'])) {
                    $infoText = "برای تماس با این ادمین، از یوزرنیم زیر استفاده کنید:\n@" . $adminInfo['username'];
                } else {
                    $infoText = "این کاربر یوزرنیم عمومی ندارد و امکان تماس مستقیم وجود ندارد.";
                }

                $this->answerCallbackQuery($this->callbackQueryId, $infoText, true);
                break;

            case 'confirm_caption':
                $stateData = $this->fileHandler->getUser($chatId);
                if (isset($stateData['state']) && $stateData['state'] === 'awaiting_caption_confirmation') {
                    $goalId = $this->db->saveGoal($chatId, $stateData['file_id'], $stateData['type'], $stateData['caption'], $stateData['public_url']);

                    if ($goalId) {
                        $this->showChannelSelectionMenu($chatId, $messageId, $goalId);
                    }
                }
                break;

            case 'change_caption':
                $stateData = $this->fileHandler->getUser($chatId);
                if (isset($stateData['state']) && $stateData['state'] === 'awaiting_caption_confirmation') {
                    $newState = [
                        'state' => 'awaiting_new_caption',
                        'file_id' => $stateData['file_id'],
                        'type' => $stateData['type'],
                        'public_url' => $stateData['public_url']
                    ];
                    $cancelKeyboard = [[['text' => '❌ لغو', 'callback_data' => 'admin_panel']]];
                    $this->fileHandler->saveUser($chatId, $newState);
                    $res = $this->sendRequest('editMessageText', [
                        'chat_id'      => $chatId,
                        'message_id' => $messageId,
                        'text'         => 'لطفاً کپشن جدید را ارسال کنید.',
                        'reply_markup' => json_encode(['inline_keyboard' => $cancelKeyboard]),

                    ]);

                    $this->fileHandler->saveMessageId($chatId, $res['result']['message_id']);
                }
                break;

            case (str_starts_with($callbackData, 'toggle_channel_')):

                if (preg_match('/^toggle_channel_(\d+)_(.+)$/', $callbackData, $matches)) {
                    $goalId      = (int) $matches[1];
                    $channelName = $matches[2];

                    $stateData = $this->fileHandler->getUser($chatId) ?? null;

                    if ($stateData && $stateData['state'] === 'selecting_channels' && $stateData['goal_id'] == $goalId) {
                        $selectedChannels = $stateData['selected_channels'];

                        if (($key = array_search($channelName, $selectedChannels)) !== false) {
                            unset($selectedChannels[$key]);
                        } else {
                            $selectedChannels[] = $channelName;
                        }

                        $stateData['selected_channels'] = array_values($selectedChannels);
                        $this->fileHandler->saveUser($chatId, $stateData);

                        $this->updateChannelSelectionMenu($chatId, $messageId, $goalId, $selectedChannels);
                    } else {
                        error_log("Could not parse callback_data: " . print_r($stateData, true));
                    }
                } else {
                    error_log("Could not parse callback_data: " . $callbackData);
                }
                break;



            // case (str_starts_with($callbackData, 'send_goal_')):
            //     $goalId    = (int) substr($callbackData, strlen('send_goal_'));
            //     $stateData = $this->fileHandler->getUser($chatId) ?? null;

            //     if ($stateData && $stateData['state'] === 'selecting_channels' && $stateData['goal_id'] == $goalId) {
            //         $selectedChannels = $stateData['selected_channels'];

            //         if (empty($selectedChannels)) {
            //             $this->answerCallbackQuery($this->callbackQueryId, "هیچ کانالی انتخاب نشده است!", true);
            //             break;
            //         }

            //         $goal = $this->db->getGoalById($goalId);

            //         if ($goal) {
            //             $channelMessageIds = [];
            //             $config = AppConfig::getConfig();
            //             $miniAppUrl = $config['bot']['mini_app_url'];
            //             $miniAppGoalUrl = $miniAppUrl . 'index.html?goal_token=' . $goal['token'];
            //             $viewButton = [[['text' => '👁 مشاهده گل', 'web_app' => ['url' => $miniAppGoalUrl]]]];
                       
            //             $method = '';
            //             switch ($goal['type']) {
            //                 case 'video':
            //                     $method = 'sendVideo';
            //                     break;
            //                 case 'animation':
            //                     $method = 'sendAnimation';
            //                     break;
            //                 case 'photo':
            //                     $method = 'sendPhoto';
            //                     break;
            //                 case 'document':
            //                     $method = 'sendDocument';
            //                     break;
            //                 default:
            //                     error_log("Invalid goal type for sending: " . $goal['type']);
            //                     $this->answerCallbackQuery($this->callbackQueryId, "خطا: نوع فایل نامعتبر است!", true);
            //                     break 2;
            //             }

            //             foreach ($selectedChannels as $channelName) {
            //                 $params = [
            //                     'chat_id'      => $channelName,
            //                     'caption'      => $goal['caption'],
            //                     'parse_mode'   => 'HTML',
            //                     'reply_markup' => json_encode(['inline_keyboard' => $viewButton]),
            //                 ];
            //                 $params[$goal['type']] = $goal['file_id'];
            //                 $response = $this->sendRequest($method, $params);

            //                 if ($response && $response['ok']) {
            //                     $channelMessageIds[$channelName] = $response['result']['message_id'];
            //                 } else {
            //                     error_log("Failed to send goal {$goalId} to channel {$channelName}");
            //                 }
            //             }

            //             if (!empty($channelMessageIds)) {
            //                 $this->db->saveChannelMessageIds($goalId, $channelMessageIds);
            //             }

            //             $this->sendRequest('editMessageText', [
            //                 'chat_id'      => $chatId,
            //                 'message_id'   => $messageId,
            //                 'text'         => '✅ پیام با موفقیت به ' . count($selectedChannels) . ' کانال ارسال شد.',
            //                 'reply_markup' => json_encode([
            //                     'inline_keyboard' => [
            //                         [
            //                             ['text' => '⬅️ بازگشت به پنل ادمین', 'callback_data' => 'admin_panel'],
            //                         ],
            //                     ],
            //                 ]),
            //             ]);
            //             $this->fileHandler->clearUser($chatId);
            //         }
            //     }
            //     break;
            
            case (str_starts_with($callbackData, 'send_goal_')):
                $goalId    = (int) substr($callbackData, strlen('send_goal_'));
                $stateData = $this->fileHandler->getUser($chatId) ?? null;

                if ($stateData && $stateData['state'] === 'selecting_channels' && $stateData['goal_id'] == $goalId) {
                    $selectedChannels = $stateData['selected_channels'];

                    if (empty($selectedChannels)) {
                        $this->answerCallbackQuery($this->callbackQueryId, "هیچ کانالی انتخاب نشده است!", true);
                        break;
                    }

                    $goal = $this->db->getGoalById($goalId);

                    if ($goal) {
                        
                        $channelMessageIds = [];
                        $config = AppConfig::getConfig();
                        $miniAppUrl = $config['bot']['mini_app_url'];
                        $miniAppGoalUrl = $miniAppUrl . 'index.html?goal_token=' . $goal['token'];
                        
                        // [!] اصلاح ساختار دکمه برای جلوگیری از خطای BUTTON_TYPE_INVALID
                       // $viewButton = [[['text' => '👁 مشاهده گل', 'web_app' => ['url' => $miniAppGoalUrl]]]];

                        // [!] حذف کامل switch و متدهای ارسال فایل
                        
                        foreach ($selectedChannels as $channelName) {
                            // [!] استفاده از sendMessage برای ارسال فقط متن (کپشن)
                            $params = [
                                'chat_id'      => $channelName,
                                'text'         => $goal['caption']."\n".$miniAppGoalUrl, // استفاده از 'text' به جای 'caption'
                                'parse_mode'   => 'HTML',
                            //    'reply_markup' => json_encode(['inline_keyboard' => $viewButton]),
                            ];
                            
                            // [!] دیگر فایل مدیا ارسال نمی‌شود
                            $response = $this->sendRequest('sendMessage', $params);

                            if ($response && $response['ok']) {
                                $channelMessageIds[$channelName] = $response['result']['message_id'];
                            } else {
                                $errorDescription = $response['description'] ?? 'Unknown Error';
                                error_log("Failed to send goal caption {$goalId} to channel {$channelName}. Reason: {$errorDescription}");
                            }
                        }

                        if (!empty($channelMessageIds)) {
                            $this->db->saveChannelMessageIds($goalId, $channelMessageIds);
                        }

                        $this->sendRequest('editMessageText', [
                            'chat_id'      => $chatId,
                            'message_id'   => $messageId,
                            'text'         => '✅ پیام با موفقیت به ' . count($selectedChannels) . ' کانال ارسال شد.',
                            'reply_markup' => json_encode([
                                'inline_keyboard' => [
                                    [
                                        ['text' => '⬅️ بازگشت به پنل ادمین', 'callback_data' => 'admin_panel'],
                                    ],
                                ],
                            ]),
                        ]);
                        $this->fileHandler->clearUser($chatId);
                    }
                }
                break;

            case (str_starts_with($callbackData, 'check_join_')):

                $this->answerCallbackQuery($this->callbackQueryId);
                $token = substr($callbackData, strlen('check_join_'));
                $this->handleGoalStart($token);
                break;

            case 'admin_list_goal':
                $this->showGoalsList(1, $messageId);
                break;

            case 'bot_stats':
                $this->showBotStats($messageId);
                break;
            case (str_starts_with($callbackData, 'list_goals_page_')):
                $page = (int) substr($callbackData, strlen('list_goals_page_'));
                $this->showGoalsList($page, $messageId);
                break;
            case (str_starts_with($callbackData, 'back_list_goals_page_')):
                $this->deleteMessageWithDelay();
                $page = (int) substr($callbackData, strlen('list_goals_page_'));
                $this->showGoalsList(1);
                break;
        }

        if (preg_match('/^show_goal_details_(\d+)_(\d+)$/', $callbackData, $matches)) {
            $goalId = (int) $matches[1];
            $page   = (int) $matches[2];
            $this->showGoalDetails($goalId, $messageId, $page);
        } elseif (preg_match('/^delete_goal_(\d+)_(\d+)$/', $callbackData, $matches)) {
            $goalId = (int) $matches[1];
            $page   = (int) $matches[2];

            $goal = $this->db->getGoalById($goalId);

            if (!$goal) {
                $this->answerCallbackQuery($this->callbackQueryId, "❌ گل یافت نشد یا قبلاً حذف شده است.", true);
                return;
            }

            if (!empty($goal['channel_message_ids'])) {
                $messageIdsByChannel = json_decode($goal['channel_message_ids'], true);

                if (is_array($messageIdsByChannel)) {
                    foreach ($messageIdsByChannel as $channelId => $messageIdToDelete) {
                        $this->sendRequest("deleteMessage", [
                            "chat_id"    => $channelId,
                            "message_id" => $messageIdToDelete,
                        ]);
                    }
                }
            }
            $deleted = $this->db->deleteGoalById($goalId);

            if ($deleted) {
                $this->answerCallbackQuery($this->callbackQueryId, "✅ گل با موفقیت از دیتابیس و کانال‌ها حذف شد.", false);
                $this->deleteMessageWithDelay($messageId);
                $this->showGoalsList($page, null);
            } else {
                $this->answerCallbackQuery($this->callbackQueryId, "❌ خطا در حذف گل از دیتابیس!", true);
            }
        }
    }
    public function handleInlineQuery(): void {}
    public function handleRequest(): void
    {

        if (isset($this->message["from"])) {
            $this->db->saveUser($this->message["from"]);
        } else {
            error_log("BotHandler::handleRequest: 'from' field missing for non-start message. Update type might not be a user message.");
        }

        if (str_starts_with($this->text, "/start")) {

            $parts = explode(' ', $this->text, 2);

            if (isset($parts[1]) && str_starts_with($parts[1], 'goal_')) {
                $token = substr($parts[1], strlen('goal_'));
                $this->handleGoalStart($token);
                return;
            }

            $isAdmin = $this->db->isAdmin($this->chatId);
            $this->fileHandler->clearUser($this->chatId);

            if ($isAdmin) {
                $this->AdminMenu();
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
            $this->deleteMessageWithDelay();
            $this->processChannelLink($this->chatId, $this->text);
        } elseif ($state === 'awaiting_admin_id') {
            $this->deleteMessageWithDelay();
            $this->processAdminAddition($this->message);
        } elseif ($state === 'awaiting_goal_upload') {
            $this->deleteMessageWithDelay();
            $this->processGoalUpload($this->message);
        } elseif ($state === 'awaiting_new_caption') {
            $this->deleteMessageWithDelay();
            $this->processNewCaption($this->message);
        }
    }

    private function processNewCaption(array $message): void
    {
        $chatId     = $message['chat']['id'];
        $newCaption = $message['text'] ?? '';

        $stateData = $this->fileHandler->getUser($chatId);

        if (isset($stateData['state']) && $stateData['state'] === 'awaiting_new_caption') {
            $goalId = $this->db->saveGoal($chatId, $stateData['file_id'], $stateData['type'], $newCaption, $stateData['public_url']);
            if ($goalId) {
                $messageIdToEdit = $this->fileHandler->getMessageId($chatId);
                $this->showChannelSelectionMenu($chatId, $messageIdToEdit, $goalId);
            }
        }
    }

    private function handleGoalStart(string $token): void
    {
        $goal = $this->db->getGoalByToken($token);
        if (! $goal) {
            $this->sendRequest("sendMessage", ["chat_id" => $this->chatId, "text" => "❌ محتوای مورد نظر یافت نشد یا منقضی شده است."]);
            return;
        }

        $requiredChannels  = $this->db->getAllChannels();
        $notJoinedChannels = [];
        foreach ($requiredChannels as $channel) {
            $response = $this->sendRequest('getChatMember', [
                'chat_id' => $channel,
                'user_id' => $this->chatId,
            ]);
            if (! $response['ok'] || ! in_array($response['result']['status'], ['member', 'administrator', 'creator'])) {
                $notJoinedChannels[] = $channel;
            }
        }

        if (empty($notJoinedChannels)) {
            $this->sendGoalToUser($goal);
        } else {
            $this->promptUserToJoin($notJoinedChannels, $token);
        }
    }

    private function sendGoalToUser(array $goal): void
    {
        $this->sendRequest("sendMessage", [
            "chat_id" => $this->chatId,
            "text" => "✅ گل برای شما ارسال شد. این یک پیام موقتی است و در 15 ثانیه حذف می‌شود.\n\nبرای ذخیره دائمی، لطفاً آن را به saveMessage فوروارد کنید.",
        ]);
        $method  = '';
        $chatId  = $this->chatId;
        $fileId  = $goal['file_id'];
        $caption = $goal['caption'];

        switch ($goal['type']) {
            case 'video':
                $method = 'sendVideo';
                break;
            case 'animation':
                $method = 'sendAnimation';
                break;
            case 'photo':
                $method = 'sendPhoto';
                break;
            case 'document':
                $method = 'sendDocument';
                break;
            default:
                error_log("Invalid goal type for sending: " . $goal['type']);
                return;
        }

        $params = [
            'chat_id' => $chatId,
            'caption' => $caption,
        ];

        $fileParamName          = $goal['type'];
        $params[$fileParamName] = $fileId;

        $response = $this->sendRequest($method, $params);

        if ($response && $response['ok']) {
            $messageId = $response['result']['message_id'];
            $deleteAt  = date('Y-m-d H:i:s', time() + (15));
            $this->db->logScheduledDelete($goal['id'], $this->chatId, $messageId, $deleteAt);

            new delete($this->chatId, $messageId);
        }
    }

    private function promptUserToJoin(array $channels, string $token): void
    {
        $text     = "❗️ برای مشاهده این محتوا، ابتدا باید در کانال‌های زیر عضو شوید. پس از عضویت، روی دکمه «عضو شدم» کلیک کنید.";
        $keyboard = [];

        foreach ($channels as $channel) {
            $channelUsername = ltrim($channel, '@');
            $keyboard[]      = [['text' => "👈 عضویت در کانال {$channelUsername}", 'url' => "https://t.me/{$channelUsername}"]];
        }

        $keyboard[] = [['text' => '✅ عضو شدم', 'callback_data' => "check_join_{$token}"]];

        $this->sendRequest("sendMessage", [
            'chat_id'      => $this->chatId,
            'text'         => $text,
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);
    }
    private function updateChannelSelectionMenu(int $chatId, int $messageId, int $goalId, array $selectedChannels): void
    {
        $allChannels    = $this->db->getAllChannels();
        $inlineKeyboard = [];

        foreach ($allChannels as $channel) {
            $isChecked        = in_array($channel, $selectedChannels);
            $icon             = $isChecked ? '✅' : '🔲';
            $inlineKeyboard[] = [['text' => "{$icon} " . $channel, 'callback_data' => "toggle_channel_{$goalId}_{$channel}"]];
        }

        $inlineKeyboard[] = [['text' => '✅ ارسال به کانال‌های انتخاب شده', 'callback_data' => "send_goal_{$goalId}"]];
        $inlineKeyboard[] = [['text' => '❌ لغو', 'callback_data' => 'admin_panel']];

        $this->sendRequest('editMessageReplyMarkup', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'reply_markup' => json_encode(['inline_keyboard' => $inlineKeyboard]),
        ]);
    }

    private function showChannelSelectionMenu(int $chatId, ?int $messageId, int $goalId): void
    {

        $stateData = [
            'state'             => 'selecting_channels',
            'goal_id'           => $goalId,
            'selected_channels' => [],
        ];
        $this->fileHandler->saveUser($chatId, $stateData);
        $allChannels = $this->db->getAllChannels();
        $text        = "لطفاً کانال‌های مورد نظر برای ارسال را انتخاب کنید:";

        $inlineKeyboard = [];
        foreach ($allChannels as $channel) {
            $inlineKeyboard[] = [['text' => "🔲 " . $channel, 'callback_data' => "toggle_channel_{$goalId}_{$channel}"]];
        }

        $inlineKeyboard[] = [['text' => '✅ ارسال به کانال‌های انتخاب شده', 'callback_data' => "send_goal_{$goalId}"]];
        $inlineKeyboard[] = [['text' => '❌ لغو', 'callback_data' => 'admin_panel']];

        $data = [
            'chat_id'      => $chatId,
            'text'         => $text,
            'reply_markup' => json_encode(['inline_keyboard' => $inlineKeyboard]),
        ];

        if ($messageId) {
            $data['message_id'] = $messageId;
            $this->sendRequest('editMessageText', $data);
        } else {
            $this->sendRequest('sendMessage', $data);
        }
    }

    private function processGoalUpload(array $message): void
    {
        $chatId          = $message['chat']['id'];
        $fileId          = null;
        $fileType        = null;
        $existingCaption = $message['caption'] ?? null;

        if (isset($message['video'])) {
            $fileId   = $message['video']['file_id'];
            $fileType = 'video';
        } elseif (isset($message['animation'])) {
            $fileId   = $message['animation']['file_id'];
            $fileType = 'animation';
        } elseif (isset($message['photo'])) {
            $photoArray = $message['photo'];
            $fileId     = end($photoArray)['file_id'];
            $fileType   = 'photo';
        } elseif (isset($message['document'])) {
            $fileId   = $message['document']['file_id'];
            $fileType = 'document';
        }
        if ($fileId === null) {
            $this->sendRequest('sendMessage', ['chat_id' => $chatId, 'text' => '❌ لطفاً فقط ویدیو، عکس یا گیف ارسال کنید.']);
            return;
        }

        $publicFileUrl = $this->downloadFileFromTelegram($fileId, $fileType);
        if ($publicFileUrl === null) {
            $this->sendRequest('sendMessage', ['chat_id' => $chatId, 'text' => '❌ خطایی در پردازش و ذخیره فایل رخ داد. لطفاً دوباره تلاش کنید.']);
            return;
        }

        $messageIdToEdit = $this->fileHandler->getMessageId($chatId);

        if ($existingCaption !== null) {
            $newState = [
                'state'   => 'awaiting_caption_confirmation',
                'file_id' => $fileId,
                'type'    => $fileType,
                'caption' => $existingCaption,
                'public_url' => $publicFileUrl,
            ];
            $this->fileHandler->saveUser($chatId, $newState);

            $promptText      = "یک کپشن برای این فایل شناسایی شد:\n\n<code>" . htmlspecialchars($existingCaption) . "</code>\n\nآیا از همین کپشن استفاده شود؟";
            $confirmKeyboard = [
                [['text' => '✅ بله، همین خوبه', 'callback_data' => 'confirm_caption']],
                [['text' => '✏️ نه، تغییرش میدم', 'callback_data' => 'change_caption']],
            ];

            $this->sendRequest('editMessageText', [
                'chat_id'      => $chatId,
                'message_id'   => $messageIdToEdit,
                'text'         => $promptText,
                'parse_mode'   => 'HTML',
                'reply_markup' => json_encode(['inline_keyboard' => $confirmKeyboard]),
            ]);
        } else {
            $newState = [
                'state'   => 'awaiting_new_caption',
                'file_id' => $fileId,
                'type' => $fileType,
                'public_url' => $publicFileUrl,
            ];
            $this->fileHandler->saveUser($chatId, $newState);

            $promptText = "✅ فایل دریافت و با موفقیت ذخیره شد. اکنون کپشن مورد نظر خود را برای آن ارسال کنید.";
            $res        = $this->sendRequest('editMessageText', [
                'chat_id'    => $chatId,
                'message_id' => $messageIdToEdit,
                'text'       => $promptText,
            ]);

            $this->fileHandler->saveMessageId($chatId, $res['result']['message_id']);
        }
    }

    private function processAdminAddition(array $message): void
    {

        $chatId           = $message['chat']['id'];
        $newAdminId       = null;
        $newAdminUsername = null;
        if (isset($message['forward_from'])) {
            $newAdminId       = $message['forward_from']['id'];
            $newAdminUsername = $message['forward_from']['username'] ?? $message['forward_from']['first_name'];
        } elseif (isset($message['text'])) {
            $text = $message['text'];
            if (is_numeric($text)) {
                $newAdminId       = (int) $text;
                $newAdminUsername = "کاربر با آیدی " . $newAdminId;
            } else {
                $usernameToFind = ltrim($text, '@');
                $user           = $this->db->getUserByUsername($usernameToFind);

                if ($user) {
                    $newAdminId       = $user['chat_id'];
                    $newAdminUsername = '@' . $user['username'];
                }
            }
        }

        $messageIdToEdit = $this->fileHandler->getMessageId($chatId);

        if ($newAdminId && $messageIdToEdit) {
            $this->db->addAdmin($newAdminId);
            $this->answerCallbackQuery("", "کاربر {$newAdminUsername} با موفقیت به لیست ادمین‌ها اضافه شد.");
            $this->showAdminsMenu($chatId, $messageIdToEdit);
        } else {
            $this->sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text'    => "❌ ورودی نامعتبر است.\n\n" .
                    "لطفاً آیدی عددی کاربر، یوزرنیم او (که قبلا ربات را استارت زده) را ارسال کرده یا پیام وی را فوروارد کنید.",
            ]);
        }
        $this->fileHandler->saveState($chatId, '');
    }

    private function showAdminsMenu(int $chatId, int $messageId): void
    {
        $admins = $this->db->getAdmins();

        $text = "👥 <b>مدیریت ادمین‌ها</b>\n\n";
        if (empty($admins)) {
            $text .= "در حال حاضر به جز شما، ادمین دیگری ثبت نشده است.";
        } else {
            $text .= "برای چت با هر ادمین روی نام او کلیک کنید:";
        }

        $inlineKeyboard = [];
        foreach ($admins as $admin) {
            if ($admin['chat_id'] == $chatId) {
                continue;
            }

            $adminChatId = $admin['chat_id'];
            $adminName   = $admin['first_name'] ?? ('@' . $admin['username']) ?? $admin['chat_id'];

            $inlineKeyboard[] = [
                ['text' => "👤 " . $adminName, 'callback_data' => 'show_admin_info_' . $adminChatId],
                ['text' => '❌ حذف', 'callback_data' => 'remove_admin_' . $adminChatId],
            ];
        }

        $inlineKeyboard[] = [['text' => '➕ افزودن ادمین جدید', 'callback_data' => 'prompt_add_admin']];
        $inlineKeyboard[] = [['text' => '⬅️ بازگشت به تنظیمات', 'callback_data' => 'admin_settings']];

        $this->sendRequest('editMessageText', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => $text,
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode(['inline_keyboard' => $inlineKeyboard]),
        ]);
    }
    private function showChannelsMenu(int $chatId, int $messageId): void
    {
        $channels = $this->db->getAllChannels();

        $text = "📢 <b>مدیریت کانال‌ها</b>\n\n";
        if (empty($channels)) {
            $text .= "در حال حاضر هیچ کانالی ثبت نشده است.";
        } else {
            $text .= "برای حذف هر کانال، روی دکمه ❌ کنار آن کلیک کنید:";
        }

        $inlineKeyboard = [];
        foreach ($channels as $channelUsername) {

            $inlineKeyboard[] = [
                ['text' => $channelUsername, 'url' => 'https://t.me/' . ltrim($channelUsername, '@')],
                ['text' => '❌', 'callback_data' => 'delete_channel_' . $channelUsername],
            ];
        }

        $inlineKeyboard[] = [['text' => '➕ افزودن کانال جدید', 'callback_data' => 'prompt_add_channel']];
        $inlineKeyboard[] = [['text' => '⬅️ بازگشت به تنظیمات', 'callback_data' => 'admin_settings']];

        $this->sendRequest('editMessageText', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'text'         => $text,
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode(['inline_keyboard' => $inlineKeyboard]),
        ]);
    }
    private function processChannelLink(int $chatId, string $channelLink): void
    {
        $channelUsername   = str_replace(['https://t.me/', 't.me/', '@'], '', $channelLink);
        $channelIdentifier = '@' . $channelUsername;
        $isAdmin           = $this->checkBotAdminStatus($channelIdentifier);
        $messageId         = $this->fileHandler->getMessageId($chatId);

        $inlineKeyboard[] = [['text' => '⬅️ بازگشت  ', 'callback_data' => 'settings_manage_channels']];
        if ($isAdmin) {
            $this->db->addChannel($channelIdentifier);

            $this->sendRequest('editMessageText', [
                'chat_id'      => $chatId,
                'message_id'   => $messageId,
                'text'         => "✅ کانال {$channelIdentifier} با موفقیت اضافه شد.",
                'reply_markup' => json_encode(['inline_keyboard' => $inlineKeyboard]),

            ]);
            $this->fileHandler->saveState($chatId, 'start');
        } else {
            $this->sendRequest('editMessageText', [
                'chat_id'      => $chatId,
                'message_id'   => $messageId,
                'text'         => "❌ ربات در کانال {$channelIdentifier} ادمین نیست یا کانال وجود ندارد. لطفاً ربات را ادمین کرده و دوباره تلاش کنید.",
                'reply_markup' => json_encode(['inline_keyboard' => $inlineKeyboard]),
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
    public function AdminMenu($messageId = null): void
    {
        $persianDate = jdf::jdate("l, j F Y");

        $panelText = "👨‍💻 پنل مدیریت ربات خوش آمدید \n\n";
        $panelText .= "امروز: $persianDate \n\n";
        $panelText .= "برای مدیریت بخش‌های مختلف، یکی از گزینه‌های زیر را انتخاب کنید:";

        $inlineKeyboard = [
            [
                ['text' => '➕ ارسال یک گل جدید', 'callback_data' => 'admin_upload_goal'],
            ],
            [
                ['text' => '🗂 مدیریت گل‌های ارسالی', 'callback_data' => 'admin_list_goal'],
            ],
            [
                ['text' => '📈 مشاهده آمار دقیق', 'callback_data' => 'bot_stats'],
            ],
            [
                ['text' => '🛠 تنظیمات اصلی ربات', 'callback_data' => 'admin_settings'],
            ],
        ];

        $data = [
            'chat_id'      => $this->chatId,
            'text'         => $panelText,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $inlineKeyboard]),
        ];

        if ($messageId === null) {
            $method = 'sendMessage';
        } else {
            $method             = 'editMessageText';
            $data['message_id'] = $messageId;
        }
        $this->sendRequest($method, $data);
    }
    private function answerCallbackQuery(string $callbackQueryId, string $text = '', bool $showAlert = false): void
    {
        $data = [
            'callback_query_id' => $callbackQueryId,
        ];
        if (! empty($text)) {
            $data['text'] = $text;
        }
        if ($showAlert) {
            $data['show_alert'] = true;
        }
        $this->sendRequest('answerCallbackQuery', $data);
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
    private function showGoalsList(int $page = 1, ?int $messageId = null): void
    {
        // این متد برای ویرایش پیام موجود یا ارسال پیام جدید استفاده می‌شود
        $isEdit = $messageId !== null;

        $perPage    = 16;
        $goals      = $this->db->getGoalsPaginated($page, $perPage);
        $totalGoals = $this->db->getGoalsCount();
        $totalPages = ceil($totalGoals / $perPage);

        $text = "📋 <b>لیست گل‌ها (صفحه {$page} از {$totalPages})</b>\n\n";
        $text .= "برای مشاهده جزئیات و حذف، روی هر گل کلیک کنید:";

        if (empty($goals)) {
            $text = "هیچ گلی برای نمایش وجود ندارد.";
        }

        $inlineKeyboard = [];
        $row            = [];
        foreach ($goals as $goal) {
            $buttonText = "گل #" . $goal['id'];
            if (! empty($goal['caption'])) {
                $buttonText = mb_substr($goal['caption'], 0, 20) . '...';
            }

            // *** تغییر کلیدی اول: اضافه کردن شماره صفحه به callback_data ***
            $row[] = ['text' => $buttonText, 'callback_data' => 'show_goal_details_' . $goal['id'] . '_' . $page];

            if (count($row) == 2) {
                $inlineKeyboard[] = $row;
                $row              = [];
            }
        }
        if (! empty($row)) {
            $inlineKeyboard[] = $row;
        }

        $paginationButtons = [];
        if ($page > 1) {
            $paginationButtons[] = ['text' => '◀️ قبلی', 'callback_data' => 'list_goals_page_' . ($page - 1)];
        }
        if ($page < $totalPages) {
            $paginationButtons[] = ['text' => 'بعدی ▶️', 'callback_data' => 'list_goals_page_' . ($page + 1)];
        }
        if (! empty($paginationButtons)) {
            $inlineKeyboard[] = $paginationButtons;
        }

        $inlineKeyboard[] = [['text' => '⬅️ بازگشت به پنل ادمین', 'callback_data' => 'admin_panel']];

        $data = [
            'chat_id'      => $this->chatId,
            'text'         => $text,
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode(['inline_keyboard' => $inlineKeyboard]),
        ];

        if ($isEdit) {
            $data['message_id'] = $messageId;
            $this->sendRequest('editMessageText', $data);
        } else {
            $this->sendRequest('sendMessage', $data);
        }
    }
    private function showGoalDetails(int $goalId, int $originalMessageId, int $page): void
    {
        $this->deleteMessageWithDelay($originalMessageId);

        $goal = $this->db->getGoalById($goalId);
        if (! $goal) {
            $this->answerCallbackQuery($this->callbackQueryId, "❌ گل مورد نظر یافت نشد!", true);
            $this->showGoalsList($page, null);
            return;
        }

        $this->answerCallbackQuery($this->callbackQueryId);

        $backToListButton = ['text' => '⬅️ بازگشت به لیست', 'callback_data' => 'back_list_goals_page_' . $page];

        $method = 'send' . ucfirst($goal['type']);
        $params = [
            'chat_id'      => $this->chatId,
            'caption'      => $goal['caption'] . "\n\n" . "آیا مایل به حذف این گل هستید؟",
            $goal['type']  => $goal['file_id'],
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => '❌ حذف گل', 'callback_data' => 'delete_goal_' . $goalId . '_' . $page],
                    ],
                    [
                        $backToListButton,
                    ],
                ],
            ]),
        ];

        $this->sendRequest($method, $params);
    }
    private function showBotStats(int $messageId): void
    {
        // 1. Fetch statistics from your database
        $userStats   = $this->db->getUserStats();
        $goalStats   = $this->db->getGoalsStats(); // File data comes from here
        $allAdmins   = $this->db->getAdmins();
        $allChannels = $this->db->getAllChannels();

        // 2. Prepare user statistics variables
        $totalUsers      = number_format($userStats['total_users']);
        $blockedUsers    = number_format($userStats['blocked_users']);
        $joinedToday     = number_format($this->db->getNewUsersCount('today'));
        $joinedYesterday = number_format($this->db->getNewUsersCount('yesterday'));
        $joinedWeek      = number_format($this->db->getNewUsersCount('week'));
        $joinedMonth     = number_format($this->db->getNewUsersCount('month'));

        // User activity statistics
        $online    = number_format($this->db->getActiveUsersCount('online'));
        $lastHour  = number_format($this->db->getActiveUsersCount('hour'));
        $yesterday = number_format($this->db->getActiveUsersCount('yesterday'));
        $week      = number_format($this->db->getActiveUsersCount('week'));
        $month     = number_format($this->db->getActiveUsersCount('month'));

        // 3. Build the message string in English with <blockquote>
        $message = "<b>📊 وضعیت ربات</b> \n";

        // --- Users Section ---
        $message .= "<blockquote>";
        $message .= "👥 <b>کاربران:</b> | همه: <code>" . $totalUsers . "</code> | 🚫 بلاک: <code>" . $blockedUsers . "</code>\n";
        $message .= "</blockquote>";

        // --- User Join Stats Section ---
        $message .= "<blockquote>";
        $message .= "💹 <b>یوزر ها:</b>\n";
        $message .= "▫️ <i>امروز:</i> <code>" . $joinedToday . "</code> | <i>دیروز:</i> <code>" . $joinedYesterday . "</code>\n";
        $message .= "▫️ <i>این هفته:</i> <code>" . $joinedWeek . "</code> | <i>این ماه :</i> <code>" . $joinedMonth . "</code>\n";
        $message .= "</blockquote>";

        // --- User Activity Section ---
        $message .= "<blockquote>";
        $message .= "🟢 <b> تعداد کاربران فعال: </b>\n";
        $message .= "▫️ <i>آنلاین:</i> <code>" . $online . "</code> | <i>ساعت گذشته:</i> <code>" . $lastHour . "</code>\n";
        $message .= "▫️ <i>دیروز:</i> <code>" . $yesterday . "</code>\n";
        $message .= "▫️ <i>این هفته :</i> <code>" . $week . "</code> | <i>این ماه:</i> <code>" . $month . "</code>\n";
        $message .= "</blockquote>";

        // --- Content Stats Section ---
        $message .= "<blockquote>";
        $message .= "🗂 <b>تعداد فایل ها :</b>\n";

        $totalFiles = number_format($goalStats['total'] ?? 0);
        $videos     = number_format($goalStats['video'] ?? 0);
        $photos     = number_format($goalStats['photo'] ?? 0);
        $animations = number_format($goalStats['animation'] ?? 0);
        $documents  = number_format($goalStats['document'] ?? 0);

        $message .= "▫️ 🎥 <b>ویدو:</b> <code>" . $videos . "</code> | 🖼️ <b>عکس:</b> <code>" . $photos . "</code>\n";
        $message .= "▫️ 🎞️ <b>گیف:</b> <code>" . $animations . "</code> | 📄 <b>فایل:</b> <code>" . $documents . "</code>\n\n";
        $message .= "▫️ <b>همه فایل ها:</b> <code>" . $totalFiles . "</code>\n";
        $message .= "</blockquote>";

        // --- Management Section ---
        $adminCount   = number_format(count($allAdmins));
        $channelCount = number_format(count($allChannels));
        $message .= "<blockquote>";
        $message .= "🛡 <b>مدیریت:</b> | 👑 <i>ادمین ها:</i> <code>" . $adminCount . "</code> | 📢 <i>کانال های فعال:</i> <code>" . $channelCount . "</code>\n";
        $message .= "</blockquote>";

        $inlineKeyboard = [
            [['text' => '⬅️ بازگشت به پنل', 'callback_data' => 'admin_panel']],
            // [['text' => '👤 ۱۰ کاربر آخر', 'callback_data' => 'last_10_users']],
            // [['text' => '🚫 ۱۰ کاربر مسدود شده آخر', 'callback_data' => 'last_10_blocked']],
        ];
        // 4. Send the final request to Telegram
        $this->sendRequest('editMessageText', [
            'chat_id'      => $this->chatId,
            'message_id'   => $messageId,
            'text'         => $message,
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode(['inline_keyboard' => $inlineKeyboard]),
        ]);
    }
    // Bot/BotHandler.php -> داخل کلاس BotHandler

    private function downloadFileFromTelegram(string $fileId, string $fileType): ?string
    {
        $fileInfo = $this->sendRequest('getFile', ['file_id' => $fileId]);
        if (!$fileInfo || !$fileInfo['ok']) {
            error_log("Failed to get file info for file_id: " . $fileId);
            return null;
        }
        $filePath = $fileInfo['result']['file_path'];

        $fileUrl = "https://api.telegram.org/file/bot" . $this->botToken . "/" . $filePath;

        $uploadsDir = __DIR__ . '/../uploads/' . $fileType . '/';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0775, true); // ساخت پوشه در صورت عدم وجود
        }
        $fileExtension = pathinfo($filePath, PATHINFO_EXTENSION);
        $uniqueName = uniqid('goal_', true) . '.' . $fileExtension;
        $localFilePath = $uploadsDir . $uniqueName;

        $config = AppConfig::getConfig();
        $baseUrl = $config['app']['base_url'];
        $publicUrl = $baseUrl . 'uploads/' . $fileType . '/' . $uniqueName;

        $fileContents = file_get_contents($fileUrl);
        if ($fileContents === false) {
            error_log("Failed to download file from: " . $fileUrl);
            return null;
        }
        if (file_put_contents($localFilePath, $fileContents) === false) {
            error_log("Failed to save file to: " . $localFilePath);
            return null;
        }

        return $publicUrl;
    }
}
