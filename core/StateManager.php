<?php
/**
 * core/StateManager.php - مدیریت حالت‌های کاربران (State Machine)
 * 
 * مسئولیت‌ها:
 * 1. ذخیره و بازیابی state کاربر (مرحله فعلی در مکالمه)
 * 2. مدیریت last_message_id برای ادیت پیام واحد
 * 3. ذخیره داده‌های موقت (temp_data) در حین مکالمه
 * 4. پشتیبانی از تمام stateهای تعریف شده
 * 5. تاریخچه stateها برای دیباگ (اختیاری)
 * 6. ریست کردن state کاربر (برای خروج اضطراری)
 * 7. قفلگذاری روی state برای جلوگیری از تداخل همزمان
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Logger.php';

class StateManager {
    
    // تعریف تمام stateهای ممکن در ربات
    const STATE_SPONSOR_CHECK = 'sponsor_check';
    const STATE_ARCHIVE_CHECK = 'archive_check';
    const STATE_MAIN_MENU = 'main_menu';
    const STATE_WAITING_PLATFORM = 'waiting_platform';
    const STATE_WAITING_URL = 'waiting_url';
    const STATE_WAITING_QUALITY = 'waiting_quality';
    const STATE_CONFIRM_DOWNLOAD = 'confirm_download';
    const STATE_SHOW_STATUS = 'show_status';
    const STATE_REQUEST_DETAIL = 'request_detail';
    const STATE_SETTINGS = 'settings';
    const STATE_SETTINGS_DARKMODE = 'settings_darkmode';
    const STATE_SETTINGS_CHANGE_CHANNEL = 'settings_change_channel';
    const STATE_SUPPORT_NEW_TICKET = 'support_new_ticket';
    const STATE_SUPPORT_TICKET_DETAIL = 'support_ticket_detail';
    const STATE_ADMIN_PANEL = 'admin_panel';
    const STATE_ADMIN_BROADCAST = 'admin_broadcast';
    const STATE_ADMIN_BROADCAST_TEXT = 'admin_broadcast_text';
    
    // لیست stateهای قابل بازگشت (برای دکمه بازگشت)
    private $backableStates = [
        self::STATE_WAITING_URL,
        self::STATE_WAITING_QUALITY,
        self::STATE_CONFIRM_DOWNLOAD,
        self::STATE_REQUEST_DETAIL,
        self::STATE_SETTINGS,
        self::STATE_SUPPORT_NEW_TICKET,
        self::STATE_ADMIN_PANEL,
        self::STATE_ADMIN_BROADCAST
    ];
    
    // نگاشت state به عنوان نمایشی (برای لاگ)
    private $stateTitles = [
        self::STATE_SPONSOR_CHECK => 'بررسی عضویت در کانال اسپانسر',
        self::STATE_ARCHIVE_CHECK => 'بررسی کانال آرشیو',
        self::STATE_MAIN_MENU => 'منوی اصلی',
        self::STATE_WAITING_PLATFORM => 'انتظار برای انتخاب پلتفرم',
        self::STATE_WAITING_URL => 'انتظار برای دریافت لینک',
        self::STATE_WAITING_QUALITY => 'انتظار برای انتخاب کیفیت',
        self::STATE_CONFIRM_DOWNLOAD => 'تأیید نهایی دانلود',
        self::STATE_SHOW_STATUS => 'نمایش وضعیت درخواست‌ها',
        self::STATE_REQUEST_DETAIL => 'جزئیات درخواست',
        self::STATE_SETTINGS => 'تنظیمات',
        self::STATE_SETTINGS_DARKMODE => 'تنظیمات حالت شب',
        self::STATE_SETTINGS_CHANGE_CHANNEL => 'تغییر کانال آرشیو',
        self::STATE_SUPPORT_NEW_TICKET => 'تیکت جدید پشتیبانی',
        self::STATE_SUPPORT_TICKET_DETAIL => 'جزئیات تیکت',
        self::STATE_ADMIN_PANEL => 'پنل ادمین',
        self::STATE_ADMIN_BROADCAST => 'ارسال همگانی - انتخاب مخاطب',
        self::STATE_ADMIN_BROADCAST_TEXT => 'ارسال همگانی - دریافت متن'
    ];
    
    private $db;
    private $logger;
    private $userId;
    
    // قفل ساده برای جلوگیری از پردازش همزمان (با فایل)
    private $lockFile;
    private $lockTimeout = 5; // ثانیه
    
    public function __construct($userId = null) {
        $this->db = Database::getInstance();
        $this->logger = logger();
        $this->userId = $userId;
        $this->lockFile = sys_get_temp_dir() . "/state_lock_{$userId}.lock";
    }
    
    /**
     * گرفتن قفل state (برای جلوگیری از تداخل همزمان)
     * @return bool
     */
    private function acquireLock() {
        if ($this->userId === null) {
            return true;
        }
        
        $fp = fopen($this->lockFile, 'w');
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            // قفل قبلاً گرفته شده
            fclose($fp);
            $this->logger->debug("State lock already held", ['user_id' => $this->userId]);
            return false;
        }
        
        fwrite($fp, time());
        fflush($fp);
        // قفل را نگه می‌داریم - تا زمانی که فایل باز است
        return $fp;
    }
    
    /**
     * آزاد کردن قفل state
     * @param resource $fp
     */
    private function releaseLock($fp) {
        if ($fp !== true && is_resource($fp)) {
            flock($fp, LOCK_UN);
            fclose($fp);
            @unlink($this->lockFile);
        }
    }
    
    /**
     * دریافت state فعلی کاربر
     * @param int|null $userId (اگر null باشد از $this->userId استفاده می‌شود)
     * @return string state فعلی (پیش‌فرض: STATE_SPONSOR_CHECK)
     */
    public function getState($userId = null) {
        $userId = $userId ?? $this->userId;
        if ($userId === null) {
            return self::STATE_SPONSOR_CHECK;
        }
        
        $sql = "SELECT state FROM user_states WHERE user_id = ?";
        $result = $this->db->fetchOne($sql, [$userId]);
        
        if ($result && isset($result['state'])) {
            return $result['state'];
        }
        
        // اگر کاربر وجود ندارد، state پیش‌فرض را برگردان
        return self::STATE_SPONSOR_CHECK;
    }
    
    /**
     * تنظیم state جدید برای کاربر
     * @param string $state
     * @param int|null $userId
     * @param int|null $messageId شناسه آخرین پیام (برای ادیت)
     * @return bool موفقیت
     */
    public function setState($state, $userId = null, $messageId = null) {
        $userId = $userId ?? $this->userId;
        if ($userId === null) {
            return false;
        }
        
        $lock = $this->acquireLock();
        if (!$lock) {
            $this->logger->warning("Could not acquire state lock", ['user_id' => $userId]);
            return false;
        }
        
        try {
            $oldState = $this->getState($userId);
            
            // ثبت تغییر state در لاگ (برای دیباگ)
            $this->logger->debug("State transition", [
                'user_id' => $userId,
                'from' => $oldState,
                'to' => $state,
                'title' => $this->stateTitles[$state] ?? $state
            ]);
            
            // ذخیره تاریخچه state (اختیاری - در temp_data)
            $history = $this->getTempData($userId, 'state_history') ?: [];
            $history[] = [
                'from' => $oldState,
                'to' => $state,
                'time' => date('Y-m-d H:i:s')
            ];
            // فقط 50 تا آخر را نگه دار
            $history = array_slice($history, -50);
            $this->setTempData($userId, 'state_history', $history);
            
            // بروزرسانی دیتابیس
            $sql = "INSERT INTO user_states (user_id, state, last_message_id, updated_at) 
                    VALUES (?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE 
                    state = VALUES(state),
                    last_message_id = IFNULL(VALUES(last_message_id), last_message_id),
                    updated_at = NOW()";
            
            $result = $this->db->execute($sql, [$userId, $state, $messageId]);
            
            return $result !== false;
            
        } finally {
            $this->releaseLock($lock);
        }
    }
    
    /**
     * دریافت آخرین message_id ذخیره شده برای کاربر
     * @param int|null $userId
     * @return int|null
     */
    public function getLastMessageId($userId = null) {
        $userId = $userId ?? $this->userId;
        if ($userId === null) {
            return null;
        }
        
        $sql = "SELECT last_message_id FROM user_states WHERE user_id = ?";
        $result = $this->db->fetchOne($sql, [$userId]);
        
        if ($result && isset($result['last_message_id'])) {
            return (int) $result['last_message_id'];
        }
        
        return null;
    }
    
    /**
     * بروزرسانی last_message_id (بعد از ارسال یا ادیت پیام)
     * @param int $messageId
     * @param int|null $userId
     * @return bool
     */
    public function updateLastMessageId($messageId, $userId = null) {
        $userId = $userId ?? $this->userId;
        if ($userId === null) {
            return false;
        }
        
        $sql = "INSERT INTO user_states (user_id, last_message_id, updated_at) 
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                last_message_id = VALUES(last_message_id),
                updated_at = NOW()";
        
        $result = $this->db->execute($sql, [$userId, $messageId]);
        
        return $result !== false;
    }
    
    /**
     * ذخیره داده موقت برای کاربر (در temp_data به صورت JSON)
     * @param int|null $userId
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public function setTempData($userId = null, $key, $value) {
        $userId = $userId ?? $this->userId;
        if ($userId === null) {
            return false;
        }
        
        // دریافت temp_data موجود
        $sql = "SELECT temp_data FROM user_states WHERE user_id = ?";
        $result = $this->db->fetchOne($sql, [$userId]);
        
        $tempData = [];
        if ($result && $result['temp_data']) {
            $tempData = json_decode($result['temp_data'], true) ?: [];
        }
        
        // تنظیم مقدار جدید
        $tempData[$key] = $value;
        
        // ذخیره در دیتابیس
        $jsonData = json_encode($tempData);
        $sql = "INSERT INTO user_states (user_id, temp_data, updated_at) 
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                temp_data = VALUES(temp_data),
                updated_at = NOW()";
        
        $result = $this->db->execute($sql, [$userId, $jsonData]);
        
        return $result !== false;
    }
    
    /**
     * دریافت داده موقت کاربر
     * @param int|null $userId
     * @param string|null $key (اگر null باشد کل داده برگردانده می‌شود)
     * @return mixed|null
     */
    public function getTempData($userId = null, $key = null) {
        $userId = $userId ?? $this->userId;
        if ($userId === null) {
            return null;
        }
        
        $sql = "SELECT temp_data FROM user_states WHERE user_id = ?";
        $result = $this->db->fetchOne($sql, [$userId]);
        
        $tempData = [];
        if ($result && $result['temp_data']) {
            $tempData = json_decode($result['temp_data'], true) ?: [];
        }
        
        if ($key === null) {
            return $tempData;
        }
        
        return $tempData[$key] ?? null;
    }
    
    /**
     * حذف یک کلید از داده‌های موقت
     * @param int|null $userId
     * @param string $key
     * @return bool
     */
    public function unsetTempData($userId = null, $key) {
        $userId = $userId ?? $this->userId;
        if ($userId === null) {
            return false;
        }
        
        $tempData = $this->getTempData($userId);
        if (isset($tempData[$key])) {
            unset($tempData[$key]);
            return $this->setTempData($userId, null, $tempData);
        }
        
        return true;
    }
    
    /**
     * پاک کردن تمام داده‌های موقت کاربر
     * @param int|null $userId
     * @return bool
     */
    public function clearTempData($userId = null) {
        $userId = $userId ?? $this->userId;
        if ($userId === null) {
            return false;
        }
        
        $sql = "UPDATE user_states SET temp_data = NULL, updated_at = NOW() WHERE user_id = ?";
        $result = $this->db->execute($sql, [$userId]);
        
        return $result !== false;
    }
    
    /**
     * ریست کردن کامل state کاربر (برای شروع مجدد)
     * @param int|null $userId
     * @param bool $clearHistory
     * @return bool
     */
    public function resetState($userId = null, $clearHistory = false) {
        $userId = $userId ?? $this->userId;
        if ($userId === null) {
            return false;
        }
        
        $lock = $this->acquireLock();
        if (!$lock) {
            return false;
        }
        
        try {
            if ($clearHistory) {
                // حذف کامل رکورد و ایجاد مجدد
                $this->db->delete('user_states', 'user_id = ?', [$userId]);
                $result = $this->setState(self::STATE_SPONSOR_CHECK, $userId);
            } else {
                // فقط reset state و پاک کردن temp_data
                $sql = "UPDATE user_states 
                        SET state = ?, temp_data = NULL, updated_at = NOW() 
                        WHERE user_id = ?";
                $result = $this->db->execute($sql, [self::STATE_SPONSOR_CHECK, $userId]);
            }
            
            $this->logger->info("State reset", ['user_id' => $userId, 'clear_history' => $clearHistory]);
            
            return $result !== false;
            
        } finally {
            $this->releaseLock($lock);
        }
    }
    
    /**
     * بررسی آیا state فعلی قابل بازگشت است
     * @param string|null $state
     * @return bool
     */
    public function isBackable($state = null) {
        $state = $state ?? $this->getState();
        return in_array($state, $this->backableStates);
    }
    
    /**
     * رفتن به state قبلی (برای دکمه بازگشت)
     * @param int|null $userId
     * @return string|null state قبلی یا null
     */
    public function goBack($userId = null) {
        $userId = $userId ?? $this->userId;
        if ($userId === null) {
            return null;
        }
        
        $history = $this->getTempData($userId, 'state_history') ?: [];
        
        if (empty($history)) {
            // اگر تاریخچه نداریم، به منوی اصلی برو
            $this->setState(self::STATE_MAIN_MENU, $userId);
            return self::STATE_MAIN_MENU;
        }
        
        // آخرین state قبلی را پیدا کن
        $lastTransition = null;
        $currentState = $this->getState($userId);
        
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if ($history[$i]['to'] === $currentState && isset($history[$i - 1])) {
                $lastTransition = $history[$i - 1]['from'];
                break;
            }
        }
        
        if ($lastTransition && $lastTransition !== $currentState) {
            $this->setState($lastTransition, $userId);
            return $lastTransition;
        }
        
        // fallback به منوی اصلی
        $this->setState(self::STATE_MAIN_MENU, $userId);
        return self::STATE_MAIN_MENU;
    }
    
    /**
     * بررسی آیا کاربر در یک state خاص است
     * @param string|array $states
     * @param int|null $userId
     * @return bool
     */
    public function isState($states, $userId = null) {
        $currentState = $this->getState($userId);
        
        if (is_array($states)) {
            return in_array($currentState, $states);
        }
        
        return $currentState === $states;
    }
    
    /**
     * دریافت عنوان state (برای نمایش در لاگ)
     * @param string|null $state
     * @return string
     */
    public function getStateTitle($state = null) {
        $state = $state ?? $this->getState();
        return $this->stateTitles[$state] ?? $state;
    }
    
    /**
     * دریافت تاریخچه stateهای کاربر (برای دیباگ)
     * @param int|null $userId
     * @param int $limit
     * @return array
     */
    public function getStateHistory($userId = null, $limit = 20) {
        $userId = $userId ?? $this->userId;
        $history = $this->getTempData($userId, 'state_history') ?: [];
        
        return array_slice($history, -$limit);
    }
    
    /**
     * بررسی آیا کاربر در جریان یک عملیات است (state غیر از منوی اصلی و بررسی‌ها)
     * @param int|null $userId
     * @return bool
     */
    public function isInOperation($userId = null) {
        $state = $this->getState($userId);
        $operationStates = [
            self::STATE_WAITING_URL,
            self::STATE_WAITING_QUALITY,
            self::STATE_CONFIRM_DOWNLOAD,
            self::STATE_SUPPORT_NEW_TICKET,
            self::STATE_ADMIN_BROADCAST_TEXT
        ];
        
        return in_array($state, $operationStates);
    }
    
    /**
     * دریافت آمار stateهای کاربران (برای پنل ادمین)
     * @return array
     */
    public function getStateStats() {
        $sql = "SELECT state, COUNT(*) as count FROM user_states GROUP BY state";
        $results = $this->db->fetchAll($sql);
        
        $stats = [];
        foreach ($results as $row) {
            $stats[$row['state']] = [
                'count' => (int) $row['count'],
                'title' => $this->stateTitles[$row['state']] ?? $row['state']
            ];
        }
        
        return $stats;
    }
    
    /**
     * پاک کردن stateهای قدیمی (کاربرانی که مدت طولانی فعال نبوده‌اند)
     * @param int $inactiveDays
     * @return int تعداد رکوردهای پاک شده
     */
    public function cleanupInactiveStates($inactiveDays = 30) {
        $sql = "DELETE us FROM user_states us
                LEFT JOIN users u ON us.user_id = u.id
                WHERE u.last_active_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                OR u.id IS NULL";
        
        $result = $this->db->execute($sql, [$inactiveDays]);
        
        $count = $result !== false ? $result : 0;
        
        if ($count > 0) {
            $this->logger->info("Cleaned up inactive states", [
                'inactive_days' => $inactiveDays,
                'count' => $count
            ]);
        }
        
        return $count;
    }
    
    /**
     * خروج از حالت فعلی و بازگشت به منوی اصلی (با حفظ داده‌ها)
     * @param int|null $userId
     * @return bool
     */
    public function exitToMainMenu($userId = null) {
        $userId = $userId ?? $this->userId;
        
        // داده‌های موقت را پاک نکن، فقط state را عوض کن
        $sql = "UPDATE user_states SET state = ?, updated_at = NOW() WHERE user_id = ?";
        $result = $this->db->execute($sql, [self::STATE_MAIN_MENU, $userId]);
        
        if ($result !== false) {
            $this->logger->debug("Exited to main menu", ['user_id' => $userId]);
            return true;
        }
        
        return false;
    }
}

/**
 * تابع کمکی برای دسترسی سریع به StateManager
 * @param int|null $userId
 * @return StateManager
 */
function stateManager($userId = null) {
    return new StateManager($userId);
}
