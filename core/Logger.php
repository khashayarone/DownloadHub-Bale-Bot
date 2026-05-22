<?php
/**
 * core/Logger.php - سیستم لاگینگ پیشرفته
 * 
 * مسئولیت‌ها:
 * 1. ذخیره لاگ در فایل (با چرخش خودکار روزانه)
 * 2. ذخیره لاگ در دیتابیس (برای خطاهای حیاتی)
 * 3. سطوح مختلف لاگ (DEBUG, INFO, WARNING, ERROR, CRITICAL)
 * 4. ماسک کردن اطلاعات حساس (توکن‌ها، رمزها)
 * 5. نمایش لاگ در خروجی (در حالت development)
 * 6. آمارگیری از خطاها برای پنل ادمین
 */

require_once dirname(__DIR__) . '/config.php';

class Logger {
    
    // سطوح لاگ با اولویت عددی (بالاتر = مهم‌تر)
    const LEVEL_DEBUG = 0;
    const LEVEL_INFO = 1;
    const LEVEL_WARNING = 2;
    const LEVEL_ERROR = 3;
    const LEVEL_CRITICAL = 4;
    
    // نام سطوح برای نمایش
    private static $levelNames = [
        self::LEVEL_DEBUG => 'DEBUG',
        self::LEVEL_INFO => 'INFO',
        self::LEVEL_WARNING => 'WARNING',
        self::LEVEL_ERROR => 'ERROR',
        self::LEVEL_CRITICAL => 'CRITICAL'
    ];
    
    // حداقل سطح برای ذخیره‌سازی (هر چی پایین‌تر از این نادیده گرفته شود)
    private $minLevel;
    
    // مسیر فایل لاگ جاری
    private $logFile;
    
    // آیا لاگ در دیتابیس هم ذخیره شود (فقط برای ERROR و CRITICAL)
    private $dbLoggingEnabled = true;
    
    // نمونه دیتابیس (برای ذخیره لاگ‌های حیاتی)
    private $db;
    
    // آیا در حالت development هستیم (نمایش لاگ در خروجی)
    private $isDevelopment;
    
    // اطلاعات حساس برای ماسک کردن
    private $sensitivePatterns = [
        '/BALE_BOT_TOKEN[\s]*[:=][\s]*[\'"]?([a-zA-Z0-9_:]+)[\'"]?/i',
        '/GITHUB_TOKEN[\s]*[:=][\s]*[\'"]?([a-zA-Z0-9_]+)[\'"]?/i',
        '/password[\s]*[:=][\s]*[\'"]?([^\'"]+)[\'"]?/i',
        '/bearer[\s]+([a-zA-Z0-9_\-\.]+)/i',
    ];
    
    // نمونه singleton
    private static $instance = null;
    
    /**
     * سازنده خصوصی (Singleton Pattern)
     * @param int $minLevel حداقل سطح لاگ (پیش‌فرض INFO)
     */
    private function __construct($minLevel = self::LEVEL_INFO) {
        $this->minLevel = $minLevel;
        $this->isDevelopment = (ENVIRONMENT === 'development');
        
        // تنظیم مسیر فایل لاگ بر اساس تاریخ امروز
        $logDir = LOGS_PATH;
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $today = date('Y-m-d');
        $this->logFile = $logDir . "/app-{$today}.log";
        
        // چرخش لاگ: اگر فایل امروز وجود ندارد اما فایل دیروز وجود دارد، نگه می‌داریم
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $yesterdayFile = $logDir . "/app-{$yesterday}.log";
        
        // حذف خودکار لاگ‌های قدیمی‌تر از 30 روز
        $this->cleanOldLogs();
        
        // اتصال به دیتابیس برای لاگ‌های حیاتی (اختیاری)
        if ($this->dbLoggingEnabled && class_exists('Database')) {
            try {
                $this->db = Database::getInstance();
                if (!$this->db->isConnected()) {
                    $this->dbLoggingEnabled = false;
                }
            } catch (Exception $e) {
                $this->dbLoggingEnabled = false;
                $this->writeToFile("CRITICAL", "Database logging disabled: " . $e->getMessage());
            }
        }
    }
    
    /**
     * دریافت نمونه واحد (Singleton)
     * @param int $minLevel
     * @return Logger
     */
    public static function getInstance($minLevel = self::LEVEL_INFO) {
        if (self::$instance === null) {
            self::$instance = new self($minLevel);
        }
        return self::$instance;
    }
    
    /**
     * حذف خودکار فایل‌های لاگ قدیمی (بیشتر از 30 روز)
     */
    private function cleanOldLogs() {
        $logDir = LOGS_PATH;
        $files = glob($logDir . "/app-*.log");
        $now = time();
        $maxAge = 30 * 24 * 3600; // 30 روز
        
        foreach ($files as $file) {
            if (is_file($file) && ($now - filemtime($file)) > $maxAge) {
                unlink($file);
            }
        }
    }
    
    /**
     * ماسک کردن اطلاعات حساس در متن لاگ
     * @param string $message
     * @return string
     */
    private function maskSensitiveData($message) {
        $masked = $message;
        foreach ($this->sensitivePatterns as $pattern) {
            $masked = preg_replace_callback($pattern, function($matches) {
                if (isset($matches[1])) {
                    $length = strlen($matches[1]);
                    $maskedValue = str_repeat('*', min($length, 8)) . substr($matches[1], -4);
                    return str_replace($matches[1], $maskedValue, $matches[0]);
                }
                return $matches[0];
            }, $masked);
        }
        return $masked;
    }
    
    /**
     * نوشتن لاگ در فایل
     * @param string $levelName
     * @param string $message
     * @param array $context
     */
    private function writeToFile($levelName, $message, $context = []) {
        $timestamp = date('Y-m-d H:i:s');
        $pid = getmypid();
        $contextStr = !empty($context) ? ' | ' . json_encode($context) : '';
        $logLine = "[{$timestamp}] [{$levelName}] [PID:{$pid}] {$message}{$contextStr}" . PHP_EOL;
        
        file_put_contents($this->logFile, $logLine, FILE_APPEND | LOCK_EX);
        
        // در حالت development، لاگ را در خروجی هم نمایش بده
        if ($this->isDevelopment) {
            echo $logLine;
        }
    }
    
    /**
     * نوشتن لاگ در دیتابیس (برای سطوح بالا)
     * @param int $level
     * @param string $levelName
     * @param string $message
     * @param array $context
     * @param int|null $userId
     */
    private function writeToDatabase($level, $levelName, $message, $context = [], $userId = null) {
        if (!$this->dbLoggingEnabled || !$this->db) {
            return;
        }
        
        // فقط سطوح ERROR و CRITICAL در دیتابیس ذخیره شوند (برای کاهش حجم)
        if ($level < self::LEVEL_ERROR) {
            return;
        }
        
        try {
            $this->db->insert('system_logs', [
                'level' => $levelName,
                'message' => substr($this->maskSensitiveData($message), 0, 1000),
                'context' => !empty($context) ? json_encode($context) : null,
                'user_id' => $userId
            ]);
        } catch (Exception $e) {
            // اگر ذخیره در دیتابیس خطا داد، فقط در فایل لاگ بنویس (جلوگیری از حلقه بی‌نهایت)
            $this->writeToFile($levelName, "Failed to write log to database: " . $e->getMessage());
        }
    }
    
    /**
     * لاگ با سطح DEBUG
     * @param string $message
     * @param array $context
     * @param int|null $userId
     */
    public function debug($message, $context = [], $userId = null) {
        if ($this->minLevel <= self::LEVEL_DEBUG) {
            $maskedMessage = $this->maskSensitiveData($message);
            $this->writeToFile('DEBUG', $maskedMessage, $context);
        }
    }
    
    /**
     * لاگ با سطح INFO
     * @param string $message
     * @param array $context
     * @param int|null $userId
     */
    public function info($message, $context = [], $userId = null) {
        if ($this->minLevel <= self::LEVEL_INFO) {
            $maskedMessage = $this->maskSensitiveData($message);
            $this->writeToFile('INFO', $maskedMessage, $context);
        }
    }
    
    /**
     * لاگ با سطح WARNING
     * @param string $message
     * @param array $context
     * @param int|null $userId
     */
    public function warning($message, $context = [], $userId = null) {
        if ($this->minLevel <= self::LEVEL_WARNING) {
            $maskedMessage = $this->maskSensitiveData($message);
            $this->writeToFile('WARNING', $maskedMessage, $context);
        }
    }
    
    /**
     * لاگ با سطح ERROR
     * @param string $message
     * @param array $context
     * @param int|null $userId
     */
    public function error($message, $context = [], $userId = null) {
        if ($this->minLevel <= self::LEVEL_ERROR) {
            $maskedMessage = $this->maskSensitiveData($message);
            $this->writeToFile('ERROR', $maskedMessage, $context);
            $this->writeToDatabase(self::LEVEL_ERROR, 'ERROR', $message, $context, $userId);
        }
    }
    
    /**
     * لاگ با سطح CRITICAL
     * @param string $message
     * @param array $context
     * @param int|null $userId
     */
    public function critical($message, $context = [], $userId = null) {
        if ($this->minLevel <= self::LEVEL_CRITICAL) {
            $maskedMessage = $this->maskSensitiveData($message);
            $this->writeToFile('CRITICAL', $maskedMessage, $context);
            $this->writeToDatabase(self::LEVEL_CRITICAL, 'CRITICAL', $message, $context, $userId);
            
            // در حالت CRITICAL، می‌توانیم به ادمین هم هشدار بدهیم
            $this->notifyAdmin($message, $context);
        }
    }
    
    /**
     * ارسال هشدار به ادمین (برای خطاهای حیاتی)
     * @param string $message
     * @param array $context
     */
    private function notifyAdmin($message, $context = []) {
        // فقط در محیط production و اگر خطای حیاتی رخ داده باشد
        if (ENVIRONMENT !== 'production') {
            return;
        }
        
        // جلوگیری از ارسال هشدار تکراری برای یک خطا (هر 5 دقیقه یکبار)
        $lastNotifyFile = LOGS_PATH . '/last_critical_notify';
        if (file_exists($lastNotifyFile)) {
            $lastTime = (int) file_get_contents($lastNotifyFile);
            if (time() - $lastTime < 300) { // 5 دقیقه
                return;
            }
        }
        
        // ذخیره زمان آخرین هشدار
        file_put_contents($lastNotifyFile, time());
        
        // در آینده: ارسال پیام به ادمین در بله
        // فعلاً فقط در فایل لاگ ثبت می‌شود
        $this->writeToFile('CRITICAL', "ADMIN NOTIFICATION TRIGGERED: " . $message, $context);
    }
    
    /**
     * لاگ یک استثنا (Exception)
     * @param Throwable $e
     * @param int|null $userId
     */
    public function exception($e, $userId = null) {
        $message = sprintf(
            "Exception: %s in %s:%d\nStack trace:\n%s",
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );
        
        $this->error($message, ['exception_class' => get_class($e)], $userId);
    }
    
    /**
     * لاگ یک درخواست API (با زمان اجرا)
     * @param string $apiName
     * @param float $durationMs
     * @param bool $success
     * @param int|null $userId
     */
    public function apiCall($apiName, $durationMs, $success = true, $userId = null) {
        $status = $success ? 'SUCCESS' : 'FAILED';
        $this->info("API Call: {$apiName} | {$status} | {$durationMs}ms", [
            'api' => $apiName,
            'duration_ms' => $durationMs,
            'success' => $success
        ], $userId);
        
        // اگر API بیش از 5 ثانیه طول کشید، هشدار بده
        if ($durationMs > 5000) {
            $this->warning("Slow API response: {$apiName} took {$durationMs}ms", [
                'api' => $apiName,
                'duration_ms' => $durationMs
            ], $userId);
        }
    }
    
    /**
     * دریافت آمار خطاها از دیتابیس (برای پنل ادمین)
     * @param int $lastHours تعداد ساعت‌های اخیر
     * @return array
     */
    public function getErrorStats($lastHours = 24) {
        if (!$this->dbLoggingEnabled || !$this->db) {
            return [
                'error' => 'Database logging is disabled',
                'total_errors' => 0,
                'by_level' => [],
                'by_hour' => []
            ];
        }
        
        try {
            $sql = "
                SELECT 
                    level,
                    COUNT(*) as count,
                    DATE_FORMAT(created_at, '%Y-%m-%d %H:00') as hour
                FROM system_logs
                WHERE created_at > DATE_SUB(NOW(), INTERVAL {$lastHours} HOUR)
                GROUP BY level, hour
                ORDER BY created_at DESC
            ";
            
            $results = $this->db->fetchAll($sql);
            
            $byLevel = [];
            $byHour = [];
            
            foreach ($results as $row) {
                $level = $row['level'];
                $hour = $row['hour'];
                $count = (int) $row['count'];
                
                if (!isset($byLevel[$level])) {
                    $byLevel[$level] = 0;
                }
                $byLevel[$level] += $count;
                
                if (!isset($byHour[$hour])) {
                    $byHour[$hour] = [];
                }
                if (!isset($byHour[$hour][$level])) {
                    $byHour[$hour][$level] = 0;
                }
                $byHour[$hour][$level] += $count;
            }
            
            // دریافت آخرین خطاها
            $lastErrors = $this->db->fetchAll(
                "SELECT * FROM system_logs ORDER BY created_at DESC LIMIT 20"
            );
            
            return [
                'success' => true,
                'total_errors' => array_sum($byLevel),
                'by_level' => $byLevel,
                'by_hour' => $byHour,
                'last_errors' => $lastErrors,
                'last_hours' => $lastHours
            ];
        } catch (Exception $e) {
            return [
                'error' => $e->getMessage(),
                'total_errors' => 0,
                'by_level' => [],
                'by_hour' => [],
                'last_errors' => []
            ];
        }
    }
    
    /**
     * دریافت محتوای فایل لاگ امروز (برای ادمین)
     * @param int $lines تعداد خطوط آخر (0 = کل فایل)
     * @return string
     */
    public function getTodayLogFile($lines = 100) {
        if (!file_exists($this->logFile)) {
            return "No log file for today.";
        }
        
        if ($lines > 0) {
            // خواندن N خط آخر
            $file = new SplFileObject($this->logFile);
            $file->seek(PHP_INT_MAX);
            $totalLines = $file->key();
            $startLine = max(0, $totalLines - $lines);
            
            $result = '';
            $file->seek($startLine);
            while (!$file->eof()) {
                $result .= $file->current();
                $file->next();
            }
            return $result;
        } else {
            // خواندن کل فایل
            return file_get_contents($this->logFile);
        }
    }
    
    /**
     * پاک کردن تمام لاگ‌های دیتابیس (فقط برای ادمین)
     * @param int $olderThanDays حذف لاگ‌های قدیمی‌تر از این تعداد روز
     * @return int تعداد رکوردهای حذف شده
     */
    public function cleanDatabaseLogs($olderThanDays = 30) {
        if (!$this->dbLoggingEnabled || !$this->db) {
            return 0;
        }
        
        try {
            $sql = "DELETE FROM system_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL {$olderThanDays} DAY)";
            return $this->db->execute($sql);
        } catch (Exception $e) {
            $this->error("Failed to clean database logs: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * تنظیم حداقل سطح لاگ (داینامیک)
     * @param int $level
     */
    public function setMinLevel($level) {
        $this->minLevel = $level;
        $this->info("Minimum log level changed to " . self::$levelNames[$level]);
    }
    
    /**
     * فعال/غیرفعال کردن ذخیره لاگ در دیتابیس
     * @param bool $enabled
     */
    public function setDbLoggingEnabled($enabled) {
        $this->dbLoggingEnabled = $enabled;
    }
}

/**
 * تابع کمکی برای دسترسی سریع به لاگر
 * @param int $minLevel
 * @return Logger
 */
function logger($minLevel = Logger::LEVEL_INFO) {
    return Logger::getInstance($minLevel);
}

/**
 * تابع کمکی برای لاگ سریع (بدون نیاز به نمونه)
 * @param string $message
 * @param string $level
 * @param array $context
 * @param int|null $userId
 */
function quick_log($message, $level = 'INFO', $context = [], $userId = null) {
    $logger = logger();
    
    switch (strtoupper($level)) {
        case 'DEBUG':
            $logger->debug($message, $context, $userId);
            break;
        case 'INFO':
            $logger->info($message, $context, $userId);
            break;
        case 'WARNING':
            $logger->warning($message, $context, $userId);
            break;
        case 'ERROR':
            $logger->error($message, $context, $userId);
            break;
        case 'CRITICAL':
            $logger->critical($message, $context, $userId);
            break;
        default:
            $logger->info($message, $context, $userId);
    }
}
