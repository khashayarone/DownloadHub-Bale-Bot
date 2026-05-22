<?php
/**
 * install.php - نصب‌کننده یکباره ربات DownloadHub
 * 
 * مسئولیت‌ها:
 * 1. ایجاد تمام جداول دیتابیس
 * 2. درج داده‌های اولیه (پلن‌ها، تنظیمات پیش‌فرض)
 * 3. ایجاد کاربر ادمین در صورت عدم وجود
 * 4. تست اتصال به API بله و گیت‌هاب
 * 5. تنظیم Webhook در بله
 * 6. بررسی مجوزهای پوشه‌ها
 * 7. ایجاد فایل‌های پیکربندی در صورت نیاز
 * 8. نمایش وضعیت نصب به صورت مرحله به مرحله
 * 
 * نحوه استفاده:
 * 1. فایل را در مرورگر باز کنید: https://khashayar.one/downloadhub/install.php
 * 2. مراحل نصب را طی کنید
 * 3. پس از نصب موفق، فایل را حذف کنید (یا دسترسی به آن را محدود کنید)
 * 
 * امنیت: این فایل فقط یکبار اجرا می‌شود. پس از نصب، به طور خودکار غیرفعال می‌شود.
 */

// تنظیمات زمان اجرا
set_time_limit(120);
error_reporting(E_ALL);
ini_set('display_errors', 1);

// جلوگیری از اجرای مجدد پس از نصب موفق
$installedFlag = __DIR__ . '/.installed';
if (file_exists($installedFlag)) {
    die("✅ ربات قبلاً نصب شده است. برای نصب مجدد، فایل <code>.installed</code> را حذف کنید.");
}

// بارگذاری تنظیمات
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/bale/Client.php';
require_once __DIR__ . '/github/Client.php';

class Installer {
    
    private $db;
    private $bale;
    private $github;
    private $steps = [];
    private $errors = [];
    private $success = [];
    private $warnings = [];
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->bale = bale();
        $this->github = github();
    }
    
    /**
     * اجرای نصب
     * @return bool
     */
    public function run() {
        $this->log("🚀 شروع فرآیند نصب ربات DownloadHub", "info");
        
        // مرحله 1: بررسی PHP نسخه
        $this->stepCheckPhpVersion();
        
        // مرحله 2: بررسی مجوزهای پوشه‌ها
        $this->stepCheckPermissions();
        
        // مرحله 3: اتصال به دیتابیس
        $this->stepDatabaseConnection();
        
        // مرحله 4: ایجاد جداول دیتابیس
        $this->stepCreateTables();
        
        // مرحله 5: درج داده‌های اولیه
        $this->stepInsertInitialData();
        
        // مرحله 6: تست اتصال به API بله
        $this->stepTestBaleApi();
        
        // مرحله 7: تست اتصال به API گیت‌هاب
        $this->stepTestGithubApi();
        
        // مرحله 8: تنظیم Webhook در بله
        $this->stepSetWebhook();
        
        // مرحله 9: ایجاد کاربر ادمین
        $this->stepCreateAdminUser();
        
        // مرحله 10: ایجاد فایل نصب شده
        $this->stepCreateInstalledFlag();
        
        // نمایش نتیجه نهایی
        $this->showResults();
        
        return empty($this->errors);
    }
    
    /**
     * مرحله 1: بررسی نسخه PHP
     */
    private function stepCheckPhpVersion() {
        $this->log("📌 مرحله 1: بررسی نسخه PHP", "step");
        
        $version = phpversion();
        $required = '8.1';
        
        if (version_compare($version, $required, '>=')) {
            $this->success[] = "PHP نسخه {$version} (≥ {$required}) - ✅";
            $this->log("PHP version {$version} is OK", "success");
        } else {
            $this->errors[] = "PHP نسخه {$version} است، اما نسخه {$required} یا بالاتر نیاز است - ❌";
            $this->log("PHP version {$version} is below required {$required}", "error");
        }
    }
    
    /**
     * مرحله 2: بررسی مجوزهای پوشه‌ها
     */
    private function stepCheckPermissions() {
        $this->log("📌 مرحله 2: بررسی مجوزهای پوشه‌ها", "step");
        
        $folders = [
            LOGS_PATH => 'پوشه لاگ',
            __DIR__ . '/cache' => 'پوشه کش (اختیاری)'
        ];
        
        foreach ($folders as $path => $name) {
            if (!is_dir($path)) {
                if (mkdir($path, 0755, true)) {
                    $this->success[] = "{$name} ایجاد شد - ✅";
                    $this->log("Created folder: {$path}", "success");
                } else {
                    $this->errors[] = "امکان ایجاد {$name} وجود ندارد - ❌";
                    $this->log("Cannot create folder: {$path}", "error");
                }
            } elseif (is_writable($path)) {
                $this->success[] = "{$name} قابل نوشتن است - ✅";
            } else {
                $this->errors[] = "{$name} قابل نوشتن نیست - ❌";
                $this->log("Folder not writable: {$path}", "error");
            }
        }
    }
    
    /**
     * مرحله 3: اتصال به دیتابیس
     */
    private function stepDatabaseConnection() {
        $this->log("📌 مرحله 3: اتصال به دیتابیس", "step");
        
        if ($this->db->isConnected()) {
            $this->success[] = "اتصال به دیتابیس با موفقیت برقرار شد - ✅";
            $this->log("Database connected", "success");
        } else {
            $this->errors[] = "اتصال به دیتابیس ناموفق: " . $this->db->getLastError() . " - ❌";
            $this->log("Database connection failed: " . $this->db->getLastError(), "error");
        }
    }
    
    /**
     * مرحله 4: ایجاد جداول دیتابیس
     */
    private function stepCreateTables() {
        $this->log("📌 مرحله 4: ایجاد جداول دیتابیس", "step");
        
        $tableErrors = $this->db->installTables();
        
        if (empty($tableErrors)) {
            $this->success[] = "تمام جداول دیتابیس با موفقیت ایجاد شدند - ✅";
            $this->log("All tables created successfully", "success");
        } else {
            foreach ($tableErrors as $error) {
                $this->errors[] = $error . " - ❌";
                $this->log($error, "error");
            }
        }
    }
    
    /**
     * مرحله 5: درج داده‌های اولیه
     */
    private function stepInsertInitialData() {
        $this->log("📌 مرحله 5: درج داده‌های اولیه", "step");
        
        try {
            // بررسی وجود پلن‌ها
            $plansCount = $this->db->count('plans');
            if ($plansCount > 0) {
                $this->success[] = "داده‌های اولیه (پلن‌ها) با موفقیت درج شدند - ✅";
            } else {
                $this->warnings[] = "داده‌های اولیه درج نشدند، ممکن است نیاز به اجرای دستی داشته باشید - ⚠️";
            }
            
            $this->log("Initial data check completed", "success");
        } catch (Exception $e) {
            $this->errors[] = "خطا در درج داده‌های اولیه: " . $e->getMessage() . " - ❌";
            $this->log("Initial data insertion failed: " . $e->getMessage(), "error");
        }
    }
    
    /**
     * مرحله 6: تست اتصال به API بله
     */
    private function stepTestBaleApi() {
        $this->log("📌 مرحله 6: تست اتصال به API بله", "step");
        
        try {
            $result = $this->bale->getMe();
            
            if ($result && isset($result['id'])) {
                $this->success[] = "اتصال به API بله برقرار است - ✅";
                $this->success[] = "نام ربات: @{$result['username']} (ID: {$result['id']}) - 🤖";
                $this->log("Bale API connected: @{$result['username']}", "success");
            } else {
                $this->errors[] = "اتصال به API بله ناموفق - ❌";
                $this->errors[] = "لطفاً توکن BALE_BOT_TOKEN را در config.php بررسی کنید - 🔧";
                $this->log("Bale API connection failed", "error");
            }
        } catch (Exception $e) {
            $this->errors[] = "خطا در اتصال به API بله: " . $e->getMessage() . " - ❌";
            $this->log("Bale API error: " . $e->getMessage(), "error");
        }
    }
    
    /**
     * مرحله 7: تست اتصال به API گیت‌هاب
     */
    private function stepTestGithubApi() {
        $this->log("📌 مرحله 7: تست اتصال به API گیت‌هاب", "step");
        
        try {
            $rateLimit = $this->github->getRateLimit();
            
            if (isset($rateLimit['remaining'])) {
                $this->success[] = "اتصال به API گیت‌هاب برقرار است - ✅";
                $this->success[] = "Rate limit باقیمانده: {$rateLimit['remaining']} درخواست - 📊";
                $this->log("GitHub API connected, rate limit: {$rateLimit['remaining']}", "success");
            } else {
                $this->errors[] = "اتصال به API گیت‌هاب ناموفق - ❌";
                $this->errors[] = "لطفاً GITHUB_TOKEN را در config.php بررسی کنید - 🔧";
                $this->log("GitHub API connection failed", "error");
            }
        } catch (Exception $e) {
            $this->errors[] = "خطا در اتصال به API گیت‌هاب: " . $e->getMessage() . " - ❌";
            $this->log("GitHub API error: " . $e->getMessage(), "error");
        }
    }
    
    /**
     * مرحله 8: تنظیم Webhook در بله
     */
    private function stepSetWebhook() {
        $this->log("📌 مرحله 8: تنظیم Webhook", "step");
        
        $webhookUrl = BASE_URL . '/router.php';
        
        try {
            // حذف Webhook قبلی
            $this->bale->deleteWebhook();
            sleep(1);
            
            // تنظیم Webhook جدید
            $result = $this->bale->setWebhook($webhookUrl);
            
            if ($result) {
                $this->success[] = "Webhook با موفقیت تنظیم شد - ✅";
                $this->success[] = "آدرس Webhook: {$webhookUrl} - 🔗";
                $this->log("Webhook set to: {$webhookUrl}", "success");
            } else {
                $this->errors[] = "تنظیم Webhook ناموفق - ❌";
                $this->log("Webhook setting failed", "error");
            }
        } catch (Exception $e) {
            $this->errors[] = "خطا در تنظیم Webhook: " . $e->getMessage() . " - ❌";
            $this->log("Webhook error: " . $e->getMessage(), "error");
        }
    }
    
    /**
     * مرحله 9: ایجاد کاربر ادمین
     */
    private function stepCreateAdminUser() {
        $this->log("📌 مرحله 9: ایجاد کاربر ادمین", "step");
        
        try {
            $adminExists = $this->db->fetchOne("SELECT id FROM users WHERE id = ?", [ADMIN_USER_ID]);
            
            if ($adminExists) {
                $this->success[] = "کاربر ادمین (ID: " . ADMIN_USER_ID . ") وجود دارد - ✅";
                $this->log("Admin user already exists", "success");
            } else {
                $inserted = $this->db->insert('users', [
                    'id' => ADMIN_USER_ID,
                    'first_name' => 'Admin',
                    'username' => 'admin',
                    'sponsor_checked' => true,
                    'is_premium' => true,
                    'subscription_type' => 'premium_yearly',
                    'created_at' => date('Y-m-d H:i:s')
                ]);
                
                if ($inserted) {
                    $this->success[] = "کاربر ادمین (ID: " . ADMIN_USER_ID . ") با موفقیت ایجاد شد - ✅";
                    $this->log("Admin user created", "success");
                } else {
                    $this->errors[] = "ایجاد کاربر ادمین ناموفق - ❌";
                }
            }
        } catch (Exception $e) {
            $this->errors[] = "خطا در ایجاد کاربر ادمین: " . $e->getMessage() . " - ❌";
            $this->log("Admin user creation failed: " . $e->getMessage(), "error");
        }
    }
    
    /**
     * مرحله 10: ایجاد فایل نصب شده
     */
    private function stepCreateInstalledFlag() {
        $this->log("📌 مرحله 10: نهایی‌سازی نصب", "step");
        
        $content = "Installed: " . date('Y-m-d H:i:s') . "\n";
        $content .= "PHP Version: " . phpversion() . "\n";
        $content .= "Admin ID: " . ADMIN_USER_ID . "\n";
        
        if (file_put_contents(__DIR__ . '/.installed', $content)) {
            $this->success[] = "فایل نصب با موفقیت ایجاد شد - ✅";
            $this->log("Installed flag created", "success");
        } else {
            $this->warnings[] = "امکان ایجاد فایل .installed وجود ندارد - ⚠️";
            $this->log("Cannot create installed flag", "warning");
        }
    }
    
    /**
     * نمایش نتیجه نهایی نصب
     */
    private function showResults() {
        echo '<!DOCTYPE html>
        <html dir="rtl" lang="fa">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>نصب ربات DownloadHub</title>
            <style>
                body {
                    font-family: Tahoma, sans-serif;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    min-height: 100vh;
                    margin: 0;
                    padding: 20px;
                }
                .container {
                    max-width: 800px;
                    margin: 0 auto;
                    background: white;
                    border-radius: 20px;
                    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                    overflow: hidden;
                }
                .header {
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    color: white;
                    padding: 30px;
                    text-align: center;
                }
                .header h1 {
                    margin: 0;
                    font-size: 28px;
                }
                .header p {
                    margin: 10px 0 0;
                    opacity: 0.9;
                }
                .content {
                    padding: 30px;
                }
                .section {
                    margin-bottom: 25px;
                    border: 1px solid #e0e0e0;
                    border-radius: 12px;
                    overflow: hidden;
                }
                .section-title {
                    background: #f5f5f5;
                    padding: 12px 20px;
                    font-weight: bold;
                    font-size: 18px;
                    border-bottom: 1px solid #e0e0e0;
                }
                .section-content {
                    padding: 15px 20px;
                }
                .success-list, .error-list, .warning-list {
                    list-style: none;
                    padding: 0;
                    margin: 0;
                }
                .success-list li {
                    color: #2e7d32;
                    padding: 8px 0;
                    border-bottom: 1px solid #e8f5e9;
                    font-family: monospace;
                }
                .error-list li {
                    color: #c62828;
                    padding: 8px 0;
                    border-bottom: 1px solid #ffebee;
                    font-family: monospace;
                }
                .warning-list li {
                    color: #f57c00;
                    padding: 8px 0;
                    border-bottom: 1px solid #fff3e0;
                    font-family: monospace;
                }
                .summary {
                    background: #f5f5f5;
                    padding: 15px 20px;
                    border-radius: 12px;
                    margin-top: 20px;
                    text-align: center;
                }
                .status-success {
                    background: #4caf50;
                    color: white;
                    padding: 10px 20px;
                    border-radius: 30px;
                    display: inline-block;
                    font-weight: bold;
                }
                .status-error {
                    background: #f44336;
                    color: white;
                    padding: 10px 20px;
                    border-radius: 30px;
                    display: inline-block;
                    font-weight: bold;
                }
                .status-warning {
                    background: #ff9800;
                    color: white;
                    padding: 10px 20px;
                    border-radius: 30px;
                    display: inline-block;
                    font-weight: bold;
                }
                .next-steps {
                    margin-top: 20px;
                    background: #e3f2fd;
                    padding: 15px 20px;
                    border-radius: 12px;
                }
                .next-steps h4 {
                    margin: 0 0 10px 0;
                    color: #1565c0;
                }
                .next-steps ul {
                    margin: 0;
                    padding-right: 20px;
                }
                .next-steps li {
                    margin: 8px 0;
                }
                .footer {
                    background: #f5f5f5;
                    padding: 15px;
                    text-align: center;
                    font-size: 12px;
                    color: #666;
                }
                code {
                    background: #f0f0f0;
                    padding: 2px 6px;
                    border-radius: 4px;
                    font-family: monospace;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>🤖 DownloadHub</h1>
                    <p>ربات دانلودر حرفه‌ای برای پیام‌رسان بله</p>
                </div>
                <div class="content">';
        
        // وضعیت کلی
        $hasErrors = !empty($this->errors);
        $hasWarnings = !empty($this->warnings);
        
        if (!$hasErrors) {
            echo '<div class="summary"><span class="status-success">✅ نصب با موفقیت کامل شد!</span></div>';
        } elseif ($hasWarnings && !$hasErrors) {
            echo '<div class="summary"><span class="status-warning">⚠️ نصب با هشدار کامل شد</span></div>';
        } else {
            echo '<div class="summary"><span class="status-error">❌ نصب با خطا مواجه شد</span></div>';
        }
        
        // موفقیت‌ها
        if (!empty($this->success)) {
            echo '<div class="section">
                    <div class="section-title">✅ موفقیت‌ها</div>
                    <div class="section-content">
                        <ul class="success-list">';
            foreach ($this->success as $item) {
                echo "<li>• {$item}</li>";
            }
            echo '      </ul>
                    </div>
                </div>';
        }
        
        // هشدارها
        if (!empty($this->warnings)) {
            echo '<div class="section">
                    <div class="section-title">⚠️ هشدارها</div>
                    <div class="section-content">
                        <ul class="warning-list">';
            foreach ($this->warnings as $item) {
                echo "<li>• {$item}</li>";
            }
            echo '      </ul>
                    </div>
                </div>';
        }
        
        // خطاها
        if (!empty($this->errors)) {
            echo '<div class="section">
                    <div class="section-title">❌ خطاها</div>
                    <div class="section-content">
                        <ul class="error-list">';
            foreach ($this->errors as $item) {
                echo "<li>• {$item}</li>";
            }
            echo '      </ul>
                    </div>
                </div>';
        }
        
        // مراحل بعدی
        echo '<div class="next-steps">
                    <h4>📋 مراحل بعدی</h4>
                    <ul>';
        
        if ($hasErrors) {
            echo '<li>🔧 خطاهای بالا را برطرف کنید و دوباره این صفحه را بارگذاری کنید.</li>';
            echo '<li>📝 فایل <code>config.php</code> را بررسی کنید و توکن‌های معتبر وارد کنید.</li>';
        } else {
            echo '<li>🎉 ربات شما آماده استفاده است!</li>';
            echo '<li>📱 ربات را در بله استارت کنید: <code>@' . ($this->getBotUsername() ?: 'bot_username') . '</code></li>';
            echo '<li>🔒 برای امنیت بیشتر، فایل <code>install.php</code> را حذف کنید یا دسترسی به آن را محدود کنید.</li>';
            echo '<li>⏰ تنظیم Cron Job در سی‌پنل: <code>* * * * * php ' . __DIR__ . '/cron.php</code></li>';
            echo '<li>📊 برای ورود به پنل ادمین، ربات را استارت کنید و از منوی ادمین استفاده کنید.</li>';
        }
        
        echo '      </ul>
                </div>';
        
        echo '    </div>
                <div class="footer">
                    DownloadHub Bot v1.0 | نصب شده در: ' . date('Y-m-d H:i:s') . '
                </div>
            </div>
        </body>
        </html>';
    }
    
    /**
     * دریافت نام کاربری ربات
     */
    private function getBotUsername() {
        try {
            $result = $this->bale->getMe();
            return $result['username'] ?? null;
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * ثبت لاگ ساده در خروجی (برای خط فرمان)
     * @param string $message
     * @param string $type
     */
    private function log($message, $type = 'info') {
        $prefix = match($type) {
            'success' => '✅',
            'error' => '❌',
            'warning' => '⚠️',
            'step' => '📌',
            default => 'ℹ️'
        };
        
        // اگر در خط فرمان اجرا می‌شود، اکو کن
        if (php_sapi_name() === 'cli') {
            echo $prefix . ' ' . $message . PHP_EOL;
        }
    }
}

// ==================== اجرای نصب ====================

// بررسی مجوز اجرا از طریق مرورگر یا خط فرمان
$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    // هدرهای امنیتی برای مرورگر
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
}

$installer = new Installer();
$success = $installer->run();

if ($isCli) {
    exit($success ? 0 : 1);
}
