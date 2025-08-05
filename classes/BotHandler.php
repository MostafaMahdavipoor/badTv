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
                $this->fileHandler->saveState($chatId, 'awaiting_goal_upload');
                $this->fileHandler->saveMessageId($chatId, $messageId);
                $promptText     = "لطفاً ویدیو یا گیف مورد نظر را ارسال یا فوروارد کنید.";
                $cancelKeyboard = [[['text' => '❌ لغو عملیات', 'callback_data' => 'admin_panel']]];

                $this->sendRequest("editMessageText", [
                    'chat_id'      => $chatId,
                    'message_id'   => $messageId,
                    'text'         => $promptText,
                    'reply_markup' => json_encode(['inline_keyboard' => $cancelKeyboard]),
                ]);
                break;
            case 'admin_list_goal':

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
                    $this->answerCallbackQuery($callbackQueryId, "کانال با موفقیت حذف شد.", true);
                    $this->showChannelsMenu($chatId, $messageId);
                } else {
                    $this->answerCallbackQuery($callbackQueryId, "خطا در حذف کانال.", true);
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
                    $this->answerCallbackQuery($callbackQueryId, "ادمین با موفقیت حذف شد.", true);
                    $this->showAdminsMenu($chatId, $messageId);
                } else {
                    $this->answerCallbackQuery($callbackQueryId, "خطا در حذف ادمین.", true);
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

                $this->answerCallbackQuery($callbackQueryId, $infoText, true);
                break;

            case 'confirm_caption':
                $stateData = $this->fileHandler->getUser($chatId);
                if (isset($stateData['state']) && $stateData['state'] === 'awaiting_caption_confirmation') {
                    $goalId = $this->db->saveGoal($chatId, $stateData['file_id'], $stateData['type'], $stateData['caption']);

                    if ($goalId) {
                        $this->showChannelSelectionMenu($chatId, $messageId, $goalId);
                    }

                    $this->fileHandler->saveState($chatId, '');
                }
                break;

            case 'change_caption':
                $stateData = $this->fileHandler->getUser($chatId);
                if (isset($stateData['state']) && $stateData['state'] === 'awaiting_caption_confirmation') {
                    $newState       = ['state' => 'awaiting_new_caption', 'file_id' => $stateData['file_id'], 'type' => $stateData['type']];
                    $cancelKeyboard = [[['text' => '❌ لغو', 'callback_data' => 'admin_panel']]];
                    $this->fileHandler->saveUser($chatId, $newState);
                    $this->sendRequest('editMessageText', [
                        'chat_id'      => $chatId, 'message_id' => $messageId,
                        'text'         => 'لطفاً کپشن جدید را ارسال کنید.',
                        'reply_markup' => json_encode(['inline_keyboard' => $cancelKeyboard]),

                    ]);
                }
                break;


            case (str_starts_with($callbackData, 'toggle_channel_')):

                if (preg_match('/^toggle_channel_(\d+)_(.+)$/', $callbackData, $matches)) {
                    $goalId      = (int) $matches[1]; // بخش عددی به عنوان goalId
                    $channelName = $matches[2];       // بقیه رشته به عنوان channelName

                    $stateData = $this->fileHandler->getUser($chatId)['state'] ?? null;

                    if ($stateData && $stateData['name'] === 'selecting_channels' && $stateData['goal_id'] == $goalId) {
                        $selectedChannels = $stateData['selected_channels'];

                        if (($key = array_search($channelName, $selectedChannels)) !== false) {
                            unset($selectedChannels[$key]);
                        } else {
                            $selectedChannels[] = $channelName;
                        }

                        $stateData['selected_channels'] = array_values($selectedChannels);
                        $this->fileHandler->saveUser($chatId, ['state' => $stateData]);

                        $this->updateChannelSelectionMenu($chatId, $messageId, $goalId, $selectedChannels);
                    }
                } else {
                    error_log("Could not parse callback_data: " . $callbackData);
                }
                break;

            case (str_starts_with($callbackData, 'send_goal_')):
                $goalId    = substr($callbackData, strlen('send_goal_'));
                $stateData = $this->fileHandler->getUser($chatId)['state'] ?? null;

                if ($stateData && $stateData['name'] === 'selecting_channels' && $stateData['goal_id'] == $goalId) {
                    $selectedChannels = $stateData['selected_channels'];

                    if (empty($selectedChannels)) {
                        $this->answerCallbackQuery($callbackQueryId, "هیچ کانالی انتخاب نشده است!", true);
                        break;
                    }

                    $goal = $this->db->getGoalById((int) $goalId);

                    if ($goal) {
                        $caption    = $goal['caption'];
                        $viewButton = [['text' => '👁 مشاهده گل', 'url' => "{$this->botLink}?start={$goal['token']}"]];
                        foreach ($selectedChannels as $channelName) {
                            $this->sendRequest($goal['type'] === 'video' ? 'sendVideo' : 'sendAnimation', [
                                'chat_id'      => $channelName,
                                'caption'      => $caption,
                                'file_id'      => $goal['file_id'],
                                'reply_markup' => json_encode(['inline_keyboard' => $viewButton]),
                            ]);
                        }

                        $this->sendRequest('editMessageText', [
                            'chat_id' => $chatId, 'message_id' => $messageId,
                            'text'    => '✅ پیام با موفقیت به ' . count($selectedChannels) . ' کانال ارسال شد.',
                        ]);

                        $this->fileHandler->saveUser($chatId, ['state' => null]);
                    }
                }
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
            $this->processNewCaption($this->message);
        }
    }
    private function processNewCaption(array $message): void
    {
        $chatId     = $message['chat']['id'];
        $newCaption = $message['text'] ?? '';

        $stateData = $this->fileHandler->getUser($chatId);

        if (isset($stateData['state']) && $stateData['state'] === 'awaiting_new_caption') {
            $goalId = $this->db->saveGoal($chatId, $stateData['file_id'], $stateData['type'], $newCaption);

            if ($goalId) {
                $this->sendRequest('sendMessage', ['chat_id' => $chatId, 'text' => '✅ گل با کپشن جدید ذخیره شد. حالا کانال‌های مقصد را انتخاب کنید:']);
                $this->showChannelSelectionMenu($chatId, null, $goalId);
            }

            $this->fileHandler->saveState($chatId, '');
        }
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
        $this->fileHandler->saveUser($chatId, ['state' => $stateData]);
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
            $fileType = 'gif';
        }

        if ($fileId === null) {
            $this->sendRequest('sendMessage', ['chat_id' => $chatId, 'text' => '❌ لطفاً فقط ویدیو یا گیف ارسال کنید.']);
            return;
        }

        $messageIdToEdit = $this->fileHandler->getMessageId($chatId);

        if ($existingCaption !== null) {

            $newState = [
                'state'   => 'awaiting_caption_confirmation',
                'file_id' => $fileId,
                'type'    => $fileType,
                'caption' => $existingCaption,
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
                'file_id' => $fileId, 'type' => $fileType];
            $this->fileHandler->saveUser($chatId, $newState);

            $promptText = "✅ فایل دریافت شد. اکنون کپشن مورد نظر خود را برای آن ارسال کنید.";
            $this->sendRequest('editMessageText', [
                'chat_id'    => $chatId,
                'message_id' => $messageIdToEdit,
                'text'       => $promptText,
            ]);
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
        $panelText = "👨‍💻 <b>پنل مدیریت ربات</b>\n\n";
        $panelText .= "ادمین عزیز، خوش آمدید. لطفاً یک گزینه را انتخاب کنید:";

        $inlineKeyboard = [

            [
                ['text' => '⚽ آپلود گل', 'callback_data' => 'admin_upload_goal'],
            ],
            [
                ['text' => '📋 لیست گل‌ها', 'callback_data' => 'admin_list_goal'],
            ],
            [
                ['text' => '⚙️ تنظیمات', 'callback_data' => 'admin_settings'],
            ],
        ];

        $data = [
            'chat_id'      => $this->chatId,
            'message_id'   => $this->messageId,
            'text'         => $panelText,
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => $inlineKeyboard,
            ]),
        ];
        if ($messageId == null) {
            $method = 'sendMessage';
        } else {
            $method = 'editMessageText';
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
}
