<?php
namespace Bot;

use Config\AppConfig;
use PDO;
use PDOException;

class Database
{
    private $pdo; // به جای mysqli از pdo استفاده می‌کنیم
    private $botLink;

    public function __construct()
    {
        $config        = AppConfig::getConfig();
        $this->botLink = $config['bot']['bot_link'];
        $dbConfig      = $config['database'];

        $dsn     = "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']};charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // پرتاب استثنا در زمان خطا
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // دریافت نتایج به صورت آرایه انجمنی
            PDO::ATTR_EMULATE_PREPARES   => false,                  // استفاده از prepare واقعی دیتابیس
        ];

        try {
            $this->pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], $options);
        } catch (PDOException $e) {
            error_log("❌ Database Connection Failed: " . $e->getMessage());
            exit();
        }
    }

    public function saveUser($user, $entryToken = null)
    {
        $excludedUsers = [193551966];
        if (in_array($user['id'], $excludedUsers)) {
            return;
        }

        $stmt = $this->pdo->prepare("SELECT 1 FROM users WHERE chat_id = ?");
        $stmt->execute([$user['id']]);

        if ($stmt->fetchColumn() === false) { // اگر کاربر وجود نداشت
            $username  = $user['username'] ?? '';
            $firstName = $user['first_name'] ?? '';
            $lastName  = $user['last_name'] ?? '';
            $language  = $user['language_code'] ?? 'en';

            $stmt = $this->pdo->prepare("
                INSERT INTO users (chat_id, username, first_name, last_name, language, last_activity, entry_token)
                VALUES (?, ?, ?, ?, ?, NOW(), ?)
            ");
            $stmt->execute([
                $user['id'],
                $username,
                $firstName,
                $lastName,
                $language,
                $entryToken,
            ]);
        } else { // اگر کاربر وجود داشت
            $username  = $user['username'] ?? '';
            $firstName = $user['first_name'] ?? '';
            $lastName  = $user['last_name'] ?? '';
            $language  = $user['language_code'] ?? 'en';

            $stmt = $this->pdo->prepare("
                UPDATE users
                SET username = ?, first_name = ?, last_name = ?, language = ?, last_activity = NOW()
                WHERE chat_id = ?
            ");
            $stmt->execute([
                $username,
                $firstName,
                $lastName,
                $language,
                $user['id'],
            ]);
        }
    }

    public function getAllUsers(): array
    {
        try {
            $stmt = $this->pdo->query("SELECT * FROM users");
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("❌ Failed to get all users: " . $e->getMessage());
            return [];
        }
    }

    public function getAdmins(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM users WHERE is_admin = 1");
        return $stmt->fetchAll();
    }

    public function isAdmin($chatId): bool
    {
        $stmt = $this->pdo->prepare("SELECT is_admin FROM users WHERE chat_id = ?");
        $stmt->execute([$chatId]);
        $user = $stmt->fetch();
        return $user && $user['is_admin'] == 1;
    }

    public function addChannel(string $channelUsername): bool
    {
        $settingsKey = 'registered_channels';
        $channels    = $this->getSetting($settingsKey, []);
        if (in_array($channelUsername, $channels)) {
            return true;
        }
        $channels[] = $channelUsername;
        return $this->saveSetting($settingsKey, $channels, 'لیست کانال‌های ثبت شده در ربات');
    }

    public function deleteChannelByUsername(string $channelUsername): bool
    {
        $settingsKey = 'registered_channels';
        $channels    = $this->getSetting($settingsKey, []);
        if (! in_array($channelUsername, $channels)) {
            return true;
        }
        $updatedChannels = array_diff($channels, [$channelUsername]);
        return $this->saveSetting($settingsKey, array_values($updatedChannels), 'لیست کانال‌های ثبت شده در ربات');
    }
    public function addAdmin(int $chatId): bool
    {
        $stmt = $this->pdo->prepare("UPDATE users SET is_admin = 1 WHERE chat_id = ?");
        return $stmt->execute([$chatId]);
    }

    public function removeAdmin(int $chatId): bool
    {
        $stmt = $this->pdo->prepare("UPDATE users SET is_admin = 0 WHERE chat_id = ?");
        return $stmt->execute([$chatId]);
    }
    public function getAllChannels(): array
    {
        return $this->getSetting('registered_channels', []);
    }
    public function deleteChannel(string $channelUsername): bool
    {
        $settingsKey = 'registered_channels';
        $channels    = $this->getSetting($settingsKey, []);
        if (! in_array($channelUsername, $channels)) {
            return true;
        }
        $updatedChannels = array_diff($channels, [$channelUsername]);
        $updatedChannels = array_values($updatedChannels);
        return $this->saveSetting($settingsKey, $updatedChannels, 'لیست کانال‌های ثبت شده در ربات');
    }

    public function saveSetting(string $key, $value, ?string $description = null): bool
    {
        if (is_bool($value)) {
            $value = $value ? 1 : 0;
        } elseif (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        $sql = "
            INSERT INTO settings (`key`, `value`, `description`)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                `value` = VALUES(`value`),
                `description` = VALUES(`description`),
                `updated_at` = NOW()
        ";

        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([$key, $value, $description]);
        } catch (PDOException $e) {
            error_log("❌ Failed to save setting '{$key}': " . $e->getMessage());
            return false;
        }
    }
    public function getSetting(string $key, $default = null)
    {
        $stmt = $this->pdo->prepare("SELECT `value` FROM settings WHERE `key` = ?");
        $stmt->execute([$key]);
        $setting = $stmt->fetch();

        if ($setting === false) {
            return $default;
        }

        $value        = $setting['value'];
        $decodedValue = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decodedValue;
        }
        return $value;
    }

    public function getUsernameByChatId($chatId)
    {
        $stmt = $this->pdo->prepare("SELECT username FROM users WHERE chat_id = ?");
        $stmt->execute([$chatId]);
        $result = $stmt->fetch();
        return $result['username'] ?? 'Unknown';
    }

    public function setUserLanguage($chatId, $language): bool
    {
        $stmt = $this->pdo->prepare("UPDATE users SET language = ? WHERE chat_id = ?");
        return $stmt->execute([$language, $chatId]);
    }

    public function getUserByUsername($username)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        return $stmt->fetch();
    }

    public function getUserLanguage($chatId)
    {
        $stmt = $this->pdo->prepare("SELECT language FROM users WHERE chat_id = ? LIMIT 1");
        $stmt->execute([$chatId]);
        $result = $stmt->fetch();
        return $result['language'] ?? 'fa';
    }

    public function getUserInfo($chatId)
    {
        try {
            $stmt = $this->pdo->prepare("SELECT username, first_name, last_name FROM users WHERE chat_id = ?");
            $stmt->execute([$chatId]);
            $user = $stmt->fetch();
            if (! $user) {
                error_log("User not found for chat_id: {$chatId}");
                return null;
            }
            return $user;
        } catch (PDOException $e) {
            error_log("Failed to get user info: " . $e->getMessage());
            return null;
        }
    }

    public function getUserByChatIdOrUsername($identifier)
    {
        if (is_numeric($identifier)) {
            $stmt = $this->pdo->prepare("SELECT * FROM users WHERE chat_id = ?");
        } else {
            $identifier = ltrim($identifier, '@');
            $stmt       = $this->pdo->prepare("SELECT * FROM users WHERE username = ?");
        }
        $stmt->execute([$identifier]);
        return $stmt->fetch();
    }

    public function getUserFullName($chatId): string
    {
        $stmt = $this->pdo->prepare("SELECT first_name, last_name FROM users WHERE chat_id = ?");
        $stmt->execute([$chatId]);
        $result = $stmt->fetch();
        return trim(($result['first_name'] ?? '') . ' ' . ($result['last_name'] ?? ''));
    }

    public function getUsersBatch($limit = 20, $offset = 0): array
    {
        try {
            $query = "SELECT * FROM users ORDER BY id ASC LIMIT :limit OFFSET :offset";
            $stmt  = $this->pdo->prepare($query);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("❌ Failed to get users batch: " . $e->getMessage());
            return [];
        }
    }

    public function updateUserStatus($chatId, $status): bool
    {
        try {
            $query = "UPDATE users SET status = ? WHERE chat_id = ?";
            $stmt  = $this->pdo->prepare($query);
            $stmt->execute([$status, $chatId]);
            return $stmt->rowCount() > 0; // rowCount تعداد ردیف‌های تحت تاثیر را برمی‌گرداند
        } catch (PDOException $e) {
            error_log("Error updating status for User ID: $chatId - " . $e->getMessage());
            return false;
        }
    }

    public function getUserByUserId($userId)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE chat_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        return $stmt->fetch();
    }
}
