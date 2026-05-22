<?php
/**
 * config.php - تنظیمات مرکزی ربات DownloadHub
 * 
 * این فایل تمام ثابت‌ها، تنظیمات دیتابیس، توکن‌ها و محدودیت‌ها را تعریف می‌کند.
 * پس از نصب اولیه، توکن‌های واقعی را جایگزین کنید.
 */

// ==================== حالت اجرا ====================
define('ENVIRONMENT', 'production'); // development, production
define('DEBUG_MODE', false); // نمایش خطاهای دقیق (فقط در development فعال شود)

// تنظیمات خطا (بر اساس محیط)
if (ENVIRONMENT === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// ==================== مسیرهای اصلی ====================
define('BASE_PATH', dirname(__DIR__));
define('BASE_URL', 'https://khashayar.one/downloadhub');
define('LOGS_PATH', BASE_PATH . '/logs');
define('CACHE_PATH', BASE_PATH . '/cache');

// ==================== دیتابیس ====================
define('DB_HOST', 'localhost');
define('DB_NAME', 'khashayar_downloadhub'); // نام دیتابیس خود را جایگزین کنید
define('DB_USER', 'khashayar_user');       // نام کاربری دیتابیس
define('DB_PASS', 'your_strong_password'); // رمز عبور دیتابیس
define('DB_CHARSET', 'utf8mb4');

// ==================== API بله ====================
define('BALE_BOT_TOKEN', '123456789:abcdIuZmK5qNEm2A1BhUaAg7MPJv1O9KCcBQB2ro'); // توکن واقعی را جایگزین کنید
define('BALE_API_BASE', 'https://tapi.bale.ai/bot' . BALE_BOT_TOKEN);
define('BALE_FILE_BASE', 'https://tapi.bale.ai/file/bot' . BALE_BOT_TOKEN);
define('BALE_WEBHOOK_PATH', '/downloadhub/webhook.php');

// ==================== API گیت‌هاب ====================
define('GITHUB_TOKEN', 'github_pat_xxxx'); // Personal Access Token با دسترسی workflow
define('GITHUB_REPO_OWNER', 'khashayardev');
define('GITHUB_REPO_NAME', 'DownloadHub');
define('GITHUB_API_BASE', 'https://api.github.com');
define('GITHUB_REPO_URL', "https://github.com/{GITHUB_REPO_OWNER}/{GITHUB_REPO_NAME}");

// ==================== محدودیت‌ها و تنظیمات ====================

// محدودیت‌های رایگان
define('FREE_MAX_FILE_SIZE_MB', 50);        // حداکثر حجم فایل برای کاربران رایگان
define('FREE_ALLOWED_QUALITIES', serialize(['720p', 'best_audio'])); // کیفیت‌های مجاز
define('FREE_MAX_CONCURRENT_REQUESTS', 3);   // حداکثر درخواست همزمان per user

// محدودیت‌های پریمیوم (برای توسعه آینده)
define('PREMIUM_MAX_FILE_SIZE_MB', 200);
define('PREMIUM_ALLOWED_QUALITIES', serialize(['720p', '1080p', '4k', 'best_audio']));
define('PREMIUM_MAX_CONCURRENT_REQUESTS', 10);
define('PREMIUM_PRIORITY', 100); // اولویت بالاتر در صف

// محدودیت‌های سیستمی
define('MAX_CONCURRENT_ACTIONS', 5);        // حداکثر اکشن همزمان در گیت‌هاب
define('MAX_QUEUE_PER_USER', 10);           // حداکثر درخواست در صف per user
define('QUEUE_PROCESS_INTERVAL_SECONDS', 60); // هر چند ثانیه cron اجرا شود
define('LOCK_EXPIRE_SECONDS', 1800);         // قفل روی URL بعد از ۳۰ دقیقه منقضی شود (1800 ثانیه)
define('BALE_RATE_LIMIT_PER_SECOND', 30);    // حداکثر درخواست در ثانیه به API بله
define('GITHUB_RATE_LIMIT_BUFFER', 100);     // هشدار وقتی کمتر از این مقدار باقی ماند

// تنظیمات وب‌هوک پیشرفت (از اکشن به ربات)
define('PROGRESS_WEBHOOK_SECRET', 'your_random_secret_key_32chars'); // برای اعتبارسنجی درخواست‌ها

// تنظیمات ادمین
define('ADMIN_USER_ID', 123456789); // شناسه بله ادمین (خودتان)
define('ADMIN_PANEL_PASSWORD', password_hash('your_secure_password', PASSWORD_DEFAULT)); // رمز پنل ادمین

// ==================== توابع کمکی ====================

/**
 * دریافت تنظیمات کیفیت مجاز برای یک کاربر
 * @param int $user_id
 * @return array
 */
function getAllowedQualitiesForUser($user_id) {
    // این تابع بعداً در core/Database.php کامل می‌شود
    // فعلاً پیش‌فرض رایگان را برمی‌گرداند
    return unserialize(FREE_ALLOWED_QUALITIES);
}

/**
 * دریافت حداکثر حجم فایل مجاز برای یک کاربر
 * @param int $user_id
 * @return int
 */
function getMaxFileSizeForUser($user_id) {
    return FREE_MAX_FILE_SIZE_MB;
}

/**
 * دریافت اولویت کاربر در صف
 * @param int $user_id
 * @return int
 */
function getUserPriority($user_id) {
    // در آینده: بررسی اشتراک فعال و برگرداندن PREMIUM_PRIORITY
    return 0;
}

/**
 * ذخیره لاگ سریع (قبل از بارگذاری Logger)
 * @param string $message
 * @param string $level
 */
function quickLog($message, $level = 'INFO') {
    $logDir = LOGS_PATH;
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/bootstrap.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] [$level] $message" . PHP_EOL, FILE_APPEND);
}

// ایجاد پوشه لاگ در صورت عدم وجود
if (!is_dir(LOGS_PATH)) {
    mkdir(LOGS_PATH, 0755, true);
}

quickLog('config.php loaded successfully', 'INFO');
