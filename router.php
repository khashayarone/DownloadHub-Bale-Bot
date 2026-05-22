<?php
/**
 * router.php - مسیریاب اصلی ربات
 * 
 * مسئولیت‌ها:
 * 1. دریافت و اعتبارسنجی درخواست‌های Webhook از بله
 * 2. تشخیص نوع آپدیت (پیام، کالبک، edited_message)
 * 3. مسیریابی به هندلر مناسب
 * 4. بررسی حالت تعمیرات (Maintenance Mode)
 * 5. جلوگیری از پردازش تکراری آپدیت‌ها (با update_id)
 * 6. مدیریت خطاهای سطح بالا
 * 7. لاگ کردن تمام درخواست‌های دریافتی
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bale/Client.php';
require_once __DIR__ . '/bale/Handlers.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Logger.php';

class Router {
    
    private $bale;
    private $logger;
    private $db;
    private $handlers;
    
    // وضعیت تعمیرات
    private $maintenanceMode = false;
    private $maintenanceFile;
    
    // پیام حالت تعمیرات
    private $maintenanceMessage = "🔧 *ربات در حال بروزرسانی است*\n\nلطفاً چند دقیقه دیگر مجدداً تلاش کنید.\nاز شکیبایی شما سپاسگزاریم.";
    
    public function __construct() {
        $this->bale = bale();
        $this->logger = logger();
        $this->db = Database::getInstance();
        $this->handlers = handlers();
        $this->maintenanceFile = sys_get_temp_dir() . '/downloadhub_maintenance';
        
        // بررسی وضعیت تعمیرات
        $this->checkMaintenanceMode();
    }
    
    /**
     * بررسی وضعیت حالت تعمیرات
     */
    private function checkMaintenanceMode() {
        $this->maintenanceMode = file_exists($this->maintenanceFile);
        if ($this->maintenanceMode) {
            $this->logger->info("Bot is in maintenance mode");
        }
    }
    
    /**
     * فعال/غیرفعال کردن حالت تعمیرات (برای ادمین)
     * @param bool $enable
     */
    public function setMaintenanceMode($enable) {
        if ($enable) {
            file_put_contents($this->maintenanceFile, time());
            $this->maintenanceMode = true;
            $this->logger->info("Maintenance mode enabled");
        } else {
            if (file_exists($this->maintenanceFile)) {
                unlink($this->maintenanceFile);
            }
            $this->maintenanceMode = false;
            $this->logger->info("Maintenance mode disabled");
        }
    }
    
    /**
     * تنظیم پیام حالت تعمیرات
     * @param string $message
     */
    public function setMaintenanceMessage($message) {
        $this->maintenanceMessage = $message;
    }
    
    /**
     * دریافت ورودی خام از php://input و اعتبارسنجی
     * @return array|null آپدیت معتبر یا null
     */
    private function getValidatedUpdate() {
        // دریافت محتوای خام
        $input = file_get_contents('php://input');
        
        if (empty($input)) {
            $this->logger->debug("Empty input received");
            return null;
        }
        
        // دیکد JSON
        $update = json_decode($input, true);
        
        if ($update === null) {
            $this->logger->error("Invalid JSON received", ['input' => substr($input, 0, 500)]);
            return null;
        }
        
        // اعتبارسنجی ساختار پایه
        if (!isset($update['update_id'])) {
            $this->logger->warning("Update missing update_id", ['update' => $update]);
            return null;
        }
        
        return $update;
    }
    
    /**
     * جلوگیری از پردازش تکراری آپدیت
     * @param int $updateId
     * @return bool true اگر قبلاً پردازش شده باشد
     */
    private function isDuplicateUpdate($updateId) {
        $sql = "SELECT COUNT(*) as count FROM processed_updates WHERE update_id = ?";
        $result = $this->db->fetchOne($sql, [$updateId]);
        
        if ($result && $result['count'] > 0) {
            $this->logger->debug("Duplicate update detected", ['update_id' => $updateId]);
            return true;
        }
        
        // ذخیره update_id برای جلوگیری از تکرار در آینده
        $this->db->insert('processed_updates', ['update_id' => $updateId]);
        
        // پاک کردن آپدیت‌های قدیمی (بیشتر از 24 ساعت)
        $this->cleanOldUpdates();
        
        return false;
    }
    
    /**
     * پاک کردن آپدیت‌های قدیمی از دیتابیس
     */
    private function cleanOldUpdates() {
        $sql = "DELETE FROM processed_updates WHERE processed_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        $this->db->execute($sql);
    }
    
    /**
     * پردازش درخواست Webhook اصلی
     * @return void
     */
    public function process() {
        $startTime = microtime(true);
        
        // 1. دریافت آپدیت
        $update = $this->getValidatedUpdate();
        
        if (!$update) {
            $this->sendHttpResponse(200, 'OK');
            return;
        }
        
        $updateId = $update['update_id'];
        $this->logger->debug("Processing update", ['update_id' => $updateId]);
        
        // 2. جلوگیری از پردازش تکراری
        if ($this->isDuplicateUpdate($updateId)) {
            $this->sendHttpResponse(200, 'OK');
            return;
        }
        
        // 3. بررسی حالت تعمیرات
        if ($this->maintenanceMode) {
            $this->handleMaintenanceMode($update);
            $this->sendHttpResponse(200, 'OK');
            return;
        }
        
        // 4. مسیریابی به هندلر مناسب
        try {
            $this->routeUpdate($update);
        } catch (Exception $e) {
            $this->logger->exception($e, $this->getUserIdFromUpdate($update));
            $this->sendErrorToUser($update, "خطای داخلی ربات. لطفاً دوباره تلاش کنید.");
        }
        
        // 5. بروزرسانی آخرین فعالیت کاربر
        $userId = $this->getUserIdFromUpdate($update);
        if ($userId) {
            $this->updateUserLastActivity($userId);
        }
        
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        $this->logger->debug("Update processed", [
            'update_id' => $updateId,
            'duration_ms' => $duration
        ]);
        
        // 6. پاسخ به بله (همیشه 200 OK)
        $this->sendHttpResponse(200, 'OK');
    }
    
    /**
     * مسیریابی آپدیت به هندلر مناسب
     * @param array $update
     */
    private function routeUpdate($update) {
        // پیام متنی
        if (isset($update['message'])) {
            $message = $update['message'];
            
            // بررسی وجود متن
            if (!isset($message['text']) && !isset($message['caption'])) {
                // پیام غیرمتنی (عکس، ویدیو، صدا، سند، استیکر)
                $this->handleNonTextMessage($update, $message);
                return;
            }
            
            $text = $message['text'] ?? $message['caption'] ?? '';
            
            // دستور /start
            if (strpos($text, '/start') === 0) {
                $this->handlers->handleMessage($update, $message);
                return;
            }
            
            // دستور /help (اختیاری)
            if (strpos($text, '/help') === 0) {
                $this->handleHelpCommand($update, $message);
                return;
            }
            
            // دستور /status (اختیاری)
            if (strpos($text, '/status') === 0) {
                $this->handleStatusCommand($update, $message);
                return;
            }
            
            // پیام عادی
            $this->handlers->handleMessage($update, $message);
            return;
        }
        
        // کالبک دکمه شیشه‌ای
        if (isset($update['callback_query'])) {
            $this->handlers->handleCallbackQuery($update, $update['callback_query']);
            return;
        }
        
        // پیام ویرایش شده
        if (isset($update['edited_message'])) {
            $this->logger->debug("Edited message received", [
                'message_id' => $update['edited_message']['message_id']
            ]);
            // می‌توانید هندلر جداگانه اضافه کنید، فعلاً نادیده گرفته می‌شود
            return;
        }
        
        // آپدیت‌های دیگر (ناشناخته)
        $this->logger->warning("Unknown update type", ['update' => $update]);
    }
    
    /**
     * پردازش پیام‌های غیرمتنی (عکس، ویدیو، صدا، سند، استیکر)
     * @param array $update
     * @param array $message
     */
    private function handleNonTextMessage($update, $message) {
        $userId = $message['from']['id'];
        $chatId = $message['chat']['id'];
        
        // دریافت state کاربر
        $stateManager = stateManager($userId);
        $currentState = $stateManager->getState();
        
        // اگر کاربر در حالت انتظار لینک است، پیام غیرمتنی را به عنوان خطا نمایش بده
        if ($currentState === StateManager::STATE_WAITING_URL) {
            $text = "❌ *فرمت ورودی نامعتبر*\n\n";
            $text .= "لطفاً یک لینک معتبر بفرستید.\n";
            $text .= "لینک باید از پلتفرم‌های YouTube، SoundCloud، Instagram یا TikTok باشد.";
            
            $lastMessageId = $stateManager->getLastMessageId();
            if ($lastMessageId) {
                $this->bale->editMessageText($chatId, $lastMessageId, $text, 'Markdown');
            } else {
                $result = $this->bale->sendMessage($chatId, $text, 'Markdown');
                if ($result && isset($result['message_id'])) {
                    $stateManager->updateLastMessageId($result['message_id']);
                }
            }
            return;
        }
        
        // در غیر این صورت، پیام را نادیده بگیر (لاگ کن اما پاسخی نده)
        $this->logger->debug("Non-text message ignored", [
            'user_id' => $userId,
            'state' => $currentState,
            'message_type' => $this->getMessageType($message)
        ]);
    }
    
    /**
     * دریافت نوع پیام
     * @param array $message
     * @return string
     */
    private function getMessageType($message) {
        if (isset($message['photo'])) return 'photo';
        if (isset($message['video'])) return 'video';
        if (isset($message['audio'])) return 'audio';
        if (isset($message['document'])) return 'document';
        if (isset($message['sticker'])) return 'sticker';
        if (isset($message['voice'])) return 'voice';
        if (isset($message['animation'])) return 'animation';
        return 'unknown';
    }
    
    /**
     * هندلر دستور /help
     * @param array $update
     * @param array $message
     */
    private function handleHelpCommand($update, $message) {
        $userId = $message['from']['id'];
        $chatId = $message['chat']['id'];
        
        $text = "📚 *راهنمای ربات DownloadHub*\n\n";
        $text .= "🎯 *راهنمای استفاده:*\n";
        $text .= "1️⃣ از منوی اصلی پلتفرم مورد نظر را انتخاب کنید\n";
        $text .= "2️⃣ لینک محتوا را بفرستید\n";
        $text .= "3️⃣ کیفیت مورد نظر را انتخاب کنید\n";
        $text .= "4️⃣ درخواست خود را تأیید کنید\n\n";
        
        $text .= "🎬 *پلتفرم‌های پشتیبانی شده:*\n";
        $text .= "• YouTube - دانلود ویدیو و صدا\n";
        $text .= "• SoundCloud - دانلود موزیک\n";
        $text .= "• Instagram - دانلود ویدیو و عکس\n";
        $text .= "• TikTok - دانلود ویدیو بدون واترمارک\n\n";
        
        $text .= "⚡ *نکات مهم:*\n";
        $text .= "• کاربران رایگان تا 50MB و کیفیت 720p\n";
        $text .= "• فایل‌های دانلود شده در کانال آرشیو شما ذخیره می‌شوند\n";
        $text .= "• ربات باید در کانال آرشیو ادمین باشد\n\n";
        
        $text .= "📞 *پشتیبانی:*\n";
        $text .= "برای ارتباط با پشتیبانی، از منوی اصلی گزینه 'پشتیبانی' را انتخاب کنید.";
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🏠 منوی اصلی', 'callback_data' => 'nav:home']]
            ]
        ];
        
        $this->bale->sendMessage($chatId, $text, 'Markdown', null, $keyboard);
    }
    
    /**
     * هندلر دستور /status
     * @param array $update
     * @param array $message
     */
    private function handleStatusCommand($update, $message) {
        $userId = $message['from']['id'];
        $chatId = $message['chat']['id'];
        
        $stateManager = stateManager($userId);
        $stateManager->setState(StateManager::STATE_SHOW_STATUS);
        
        $this->handlers->handleMessage($update, $message);
    }
    
    /**
     * مدیریت حالت تعمیرات
     * @param array $update
     */
    private function handleMaintenanceMode($update) {
        $userId = $this->getUserIdFromUpdate($update);
        
        // اگر کاربر ادمین است، اجازه ادامه بده (برای مدیریت)
        if ($userId == ADMIN_USER_ID) {
            $this->logger->info("Admin bypassed maintenance mode", ['user_id' => $userId]);
            $this->routeUpdate($update);
            return;
        }
        
        // ارسال پیام حالت تعمیرات به کاربر
        $chatId = $this->getChatIdFromUpdate($update);
        if ($chatId) {
            $this->bale->sendMessage($chatId, $this->maintenanceMessage, 'Markdown');
        }
        
        $this->logger->debug("Request rejected due to maintenance mode", [
            'user_id' => $userId,
            'update_id' => $update['update_id'] ?? null
        ]);
    }
    
    /**
     * ارسال خطا به کاربر
     * @param array $update
     * @param string $errorMessage
     */
    private function sendErrorToUser($update, $errorMessage) {
        $chatId = $this->getChatIdFromUpdate($update);
        if ($chatId) {
            $this->bale->sendMessage($chatId, "❌ " . $errorMessage, 'Markdown');
        }
    }
    
    /**
     * استخراج user_id از آپدیت
     * @param array $update
     * @return int|null
     */
    private function getUserIdFromUpdate($update) {
        if (isset($update['message']['from']['id'])) {
            return $update['message']['from']['id'];
        }
        if (isset($update['callback_query']['from']['id'])) {
            return $update['callback_query']['from']['id'];
        }
        if (isset($update['edited_message']['from']['id'])) {
            return $update['edited_message']['from']['id'];
        }
        return null;
    }
    
    /**
     * استخراج chat_id از آپدیت
     * @param array $update
     * @return int|null
     */
    private function getChatIdFromUpdate($update) {
        if (isset($update['message']['chat']['id'])) {
            return $update['message']['chat']['id'];
        }
        if (isset($update['callback_query']['message']['chat']['id'])) {
            return $update['callback_query']['message']['chat']['id'];
        }
        if (isset($update['edited_message']['chat']['id'])) {
            return $update['edited_message']['chat']['id'];
        }
        return null;
    }
    
    /**
     * بروزرسانی آخرین فعالیت کاربر
     * @param int $userId
     */
    private function updateUserLastActivity($userId) {
        $sql = "UPDATE users SET last_active_at = NOW() WHERE id = ?";
        $this->db->execute($sql, [$userId]);
    }
    
    /**
     * ارسال پاسخ HTTP به بله
     * @param int $statusCode
     * @param string $message
     */
    private function sendHttpResponse($statusCode, $message) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode(['status' => $message]);
    }
    
    /**
     * تابع استاتیک برای اجرای روتین اصلی
     */
    public static function run() {
        $router = new self();
        $router->process();
    }
}

// ==================== نقطه ورود اصلی ====================
// این فایل به عنوان webhook استفاده می‌شود

// تنظیمات زمان اجرا برای جلوگیری از timeout
ignore_user_abort(true);
set_time_limit(60);

// اجرای روتین اصلی
Router::run();
