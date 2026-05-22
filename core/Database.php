<?php
/**
 * core/Database.php - لایه اتصال و مدیریت دیتابیس
 * 
 * مسئولیت‌ها:
 * 1. اتصال به MySQL با PDO
 * 2. متدهای کمکی برای اجرای کوئری‌ها
 * 3. مدیریت تراکنش‌ها
 * 4. ساختار جدول‌ها (از طریق متد install)
 * 5. پشتیبانی از قابلیت‌های آینده (اشتراک، امتیاز، لاک)
 */

require_once dirname(__DIR__) . '/config.php';

class Database {
    
    private static $instance = null;
    private $pdo;
    private $connected = false;
    private $lastError = null;
    
    /**
     * سازنده خصوصی (Singleton Pattern)
     */
    private function __construct() {
        $this->connect();
    }
    
    /**
     * دریافت نمونه واحد از دیتابیس (Singleton)
     * @return Database
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * اتصال به دیتابیس
     * @return bool
     */
    private function connect() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
            ]);
            $this->connected = true;
            quickLog("Database connected successfully", "INFO");
            return true;
        } catch (PDOException $e) {
            $this->lastError = $e->getMessage();
            quickLog("Database connection failed: " . $e->getMessage(), "ERROR");
            return false;
        }
    }
    
    /**
     * بررسی وضعیت اتصال
     * @return bool
     */
    public function isConnected() {
        return $this->connected;
    }
    
    /**
     * دریافت آخرین خطا
     * @return string|null
     */
    public function getLastError() {
        return $this->lastError;
    }
    
    /**
     * دریافت آبجکت PDO (برای کوئری‌های پیشرفته)
     * @return PDO
     */
    public function getPDO() {
        return $this->pdo;
    }
    
    /**
     * اجرای کوئری SELECT و برگرداندن همه رکوردها
     * @param string $sql
     * @param array $params
     * @return array
     */
    public function fetchAll($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            quickLog("fetchAll error: " . $e->getMessage() . " | SQL: $sql", "ERROR");
            return [];
        }
    }
    
    /**
     * اجرای کوئری SELECT و برگرداندن یک رکورد
     * @param string $sql
     * @param array $params
     * @return array|null
     */
    public function fetchOne($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch();
            return $result ?: null;
        } catch (PDOException $e) {
            quickLog("fetchOne error: " . $e->getMessage() . " | SQL: $sql", "ERROR");
            return null;
        }
    }
    
    /**
     * اجرای کوئری INSERT/UPDATE/DELETE
     * @param string $sql
     * @param array $params
     * @return int|false تعداد رکوردهای تحت تأثیر یا false در صورت خطا
     */
    public function execute($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            quickLog("execute error: " . $e->getMessage() . " | SQL: $sql", "ERROR");
            return false;
        }
    }
    
    /**
     * درج یک رکورد و برگرداندن ID درج‌شده
     * @param string $table
     * @param array $data
     * @return int|false
     */
    public function insert($table, $data) {
        $fields = array_keys($data);
        $placeholders = ':' . implode(', :', $fields);
        $sql = "INSERT INTO `$table` (`" . implode('`, `', $fields) . "`) VALUES ($placeholders)";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($data);
            return (int) $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            quickLog("insert error: " . $e->getMessage() . " | SQL: $sql", "ERROR");
            return false;
        }
    }
    
    /**
     * بروزرسانی رکوردها
     * @param string $table
     * @param array $data
     * @param string $where
     * @param array $whereParams
     * @return int|false
     */
    public function update($table, $data, $where, $whereParams = []) {
        $set = [];
        foreach ($data as $field => $value) {
            $set[] = "`$field` = :$field";
        }
        $sql = "UPDATE `$table` SET " . implode(', ', $set) . " WHERE $where";
        $params = array_merge($data, $whereParams);
        
        return $this->execute($sql, $params);
    }
    
    /**
     * حذف رکوردها
     * @param string $table
     * @param string $where
     * @param array $params
     * @return int|false
     */
    public function delete($table, $where, $params = []) {
        $sql = "DELETE FROM `$table` WHERE $where";
        return $this->execute($sql, $params);
    }
    
    /**
     * شروع تراکنش
     * @return bool
     */
    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }
    
    /**
     * commit تراکنش
     * @return bool
     */
    public function commit() {
        return $this->pdo->commit();
    }
    
    /**
     * rollback تراکنش
     * @return bool
     */
    public function rollback() {
        return $this->pdo->rollBack();
    }
    
    /**
     * شمارش رکوردها در یک جدول
     * @param string $table
     * @param string $where
     * @param array $params
     * @return int
     */
    public function count($table, $where = '1=1', $params = []) {
        $sql = "SELECT COUNT(*) as total FROM `$table` WHERE $where";
        $result = $this->fetchOne($sql, $params);
        return $result ? (int) $result['total'] : 0;
    }
    
    /**
     * فرار از کاراکترهای خاص (برای مواقعی که نمی‌توان از prepared statement استفاده کرد)
     * @param string $value
     * @return string
     */
    public function escape($value) {
        return $this->pdo->quote($value);
    }
    
    /**
     * نصب و ایجاد تمام جداول دیتابیس
     * این متد فقط یکبار در زمان نصب اولیه اجرا می‌شود
     * @return array لیست خطاها (خالی یعنی موفق)
     */
    public function installTables() {
        $errors = [];
        
        // 1. جدول users (کاربران)
        $sql_users = "
        CREATE TABLE IF NOT EXISTS `users` (
            `id` BIGINT PRIMARY KEY,
            `first_name` VARCHAR(100) NOT NULL,
            `username` VARCHAR(100) DEFAULT NULL,
            `archived_channel_id` BIGINT DEFAULT NULL,
            `archived_channel_username` VARCHAR(100) DEFAULT NULL,
            `sponsor_checked` BOOLEAN DEFAULT FALSE,
            `is_premium` BOOLEAN DEFAULT FALSE,
            `subscription_type` ENUM('free', 'premium_monthly', 'premium_yearly') DEFAULT 'free',
            `subscription_expires_at` TIMESTAMP NULL DEFAULT NULL,
            `subscription_features` JSON NULL DEFAULT NULL,
            `dark_mode` BOOLEAN DEFAULT FALSE,
            `total_points` INT DEFAULT 0,
            `referrer_id` BIGINT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `last_active_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_username` (`username`),
            INDEX `idx_sponsor_checked` (`sponsor_checked`),
            INDEX `idx_is_premium` (`is_premium`),
            INDEX `idx_referrer_id` (`referrer_id`),
            FOREIGN KEY (`referrer_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        
        // 2. جدول user_states (حالت‌های کاربران)
        $sql_states = "
        CREATE TABLE IF NOT EXISTS `user_states` (
            `user_id` BIGINT PRIMARY KEY,
            `state` VARCHAR(50) NOT NULL DEFAULT 'sponsor_check',
            `last_message_id` INT DEFAULT NULL,
            `temp_data` TEXT NULL DEFAULT NULL,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            INDEX `idx_state` (`state`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        
        // 3. جدول queue (صف درخواست‌ها)
        $sql_queue = "
        CREATE TABLE IF NOT EXISTS `queue` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` BIGINT NOT NULL,
            `platform` ENUM('youtube', 'soundcloud', 'instagram', 'tiktok') NOT NULL,
            `urls` TEXT NOT NULL,
            `quality` VARCHAR(20) NOT NULL DEFAULT '720p',
            `estimated_size_mb` INT DEFAULT 0,
            `status` ENUM('pending', 'processing', 'completed', 'failed', 'cancelled', 'rate_limited') DEFAULT 'pending',
            `priority` INT DEFAULT 0,
            `workflow_run_id` VARCHAR(50) DEFAULT NULL,
            `cache_hit` BOOLEAN DEFAULT FALSE,
            `progress_percent` INT DEFAULT 0,
            `error_message` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `started_at` TIMESTAMP NULL DEFAULT NULL,
            `completed_at` TIMESTAMP NULL DEFAULT NULL,
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            INDEX `idx_status_priority` (`status`, `priority`, `created_at`),
            INDEX `idx_user_id` (`user_id`),
            INDEX `idx_workflow_run_id` (`workflow_run_id`),
            INDEX `idx_platform` (`platform`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        
        // 4. جدول processing_locks (قفل روی URLها)
        $sql_locks = "
        CREATE TABLE IF NOT EXISTS `processing_locks` (
            `url_hash` VARCHAR(64) PRIMARY KEY,
            `user_id` BIGINT NOT NULL,
            `queue_id` INT NOT NULL,
            `original_url` TEXT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `expires_at` TIMESTAMP NOT NULL,
            INDEX `idx_expires_at` (`expires_at`),
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`queue_id`) REFERENCES `queue`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        
        // 5. جدول cache_index (کش فایل‌ها)
        $sql_cache = "
        CREATE TABLE IF NOT EXISTS `cache_index` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `platform` VARCHAR(20) NOT NULL,
            `external_id` VARCHAR(50) NOT NULL,
            `file_path` VARCHAR(500) NOT NULL,
            `creator_name` VARCHAR(200) DEFAULT NULL,
            `title` VARCHAR(500) DEFAULT NULL,
            `thumbnail_url` TEXT DEFAULT NULL,
            `file_size_mb` INT DEFAULT 0,
            `cached_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_platform_external` (`platform`, `external_id`),
            INDEX `idx_platform` (`platform`),
            INDEX `idx_creator` (`creator_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        
        // 6. جدول support_tickets (تیکت‌های پشتیبانی)
        $sql_tickets = "
        CREATE TABLE IF NOT EXISTS `support_tickets` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` BIGINT NOT NULL,
            `message` TEXT NOT NULL,
            `status` ENUM('open', 'answered', 'closed') DEFAULT 'open',
            `admin_reply` TEXT DEFAULT NULL,
            `replied_at` TIMESTAMP NULL DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            INDEX `idx_status` (`status`),
            INDEX `idx_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        
        // 7. جدول broadcast_jobs (ارسال همگانی)
        $sql_broadcast = "
        CREATE TABLE IF NOT EXISTS `broadcast_jobs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `target` ENUM('all', 'active_last_7days', 'premium') NOT NULL,
            `message_text` TEXT NOT NULL,
            `total_targets` INT DEFAULT 0,
            `sent_count` INT DEFAULT 0,
            `status` ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
            `created_by` BIGINT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `completed_at` TIMESTAMP NULL DEFAULT NULL,
            INDEX `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        
        // 8. جدول processed_updates (جلوگیری از پردازش تکراری webhook)
        $sql_updates = "
        CREATE TABLE IF NOT EXISTS `processed_updates` (
            `update_id` INT PRIMARY KEY,
            `processed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        
        // 9. جدول plans (برای مدیریت اشتراک‌ها - توسعه آینده)
        $sql_plans = "
        CREATE TABLE IF NOT EXISTS `plans` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(50) NOT NULL,
            `price_rial` INT NOT NULL,
            `duration_days` INT NOT NULL,
            `max_file_size_mb` INT NOT NULL,
            `allowed_qualities` JSON NOT NULL,
            `max_concurrent_requests` INT DEFAULT 5,
            `priority` INT DEFAULT 0,
            `is_active` BOOLEAN DEFAULT TRUE,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        
        // 10. جدول system_logs (لاگ‌های سیستمی)
        $sql_logs = "
        CREATE TABLE IF NOT EXISTS `system_logs` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `level` ENUM('DEBUG', 'INFO', 'WARNING', 'ERROR', 'CRITICAL') NOT NULL,
            `message` TEXT NOT NULL,
            `context` JSON NULL DEFAULT NULL,
            `user_id` BIGINT NULL DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_level` (`level`),
            INDEX `idx_created_at` (`created_at`),
            INDEX `idx_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        
        // اجرای تمام کوئری‌های CREATE TABLE
        $queries = [
            'users' => $sql_users,
            'user_states' => $sql_states,
            'queue' => $sql_queue,
            'processing_locks' => $sql_locks,
            'cache_index' => $sql_cache,
            'support_tickets' => $sql_tickets,
            'broadcast_jobs' => $sql_broadcast,
            'processed_updates' => $sql_updates,
            'plans' => $sql_plans,
            'system_logs' => $sql_logs
        ];
        
        foreach ($queries as $name => $sql) {
            try {
                $this->pdo->exec($sql);
                quickLog("Table '$name' created successfully", "INFO");
            } catch (PDOException $e) {
                $error = "Failed to create table '$name': " . $e->getMessage();
                quickLog($error, "ERROR");
                $errors[] = $error;
            }
        }
        
        // درج اطلاعات اولیه (seed data)
        if (empty($errors)) {
            $this->seedInitialData();
        }
        
        return $errors;
    }
    
    /**
     * درج اطلاعات اولیه مورد نیاز
     */
    private function seedInitialData() {
        // بررسی آیا جدول plans خالی است
        $count = $this->count('plans');
        if ($count == 0) {
            // درج پلن رایگان پیش‌فرض
            $freePlan = [
                'name' => 'رایگان',
                'price_rial' => 0,
                'duration_days' => 3650, // 10 سال
                'max_file_size_mb' => FREE_MAX_FILE_SIZE_MB,
                'allowed_qualities' => json_encode(['720p', 'best_audio']),
                'max_concurrent_requests' => 3,
                'priority' => 0,
                'is_active' => true
            ];
            $this->insert('plans', $freePlan);
            
            // درج پلن ماهیانه (برای آینده)
            $premiumMonthly = [
                'name' => 'پریمیوم ماهیانه',
                'price_rial' => 50000,
                'duration_days' => 30,
                'max_file_size_mb' => PREMIUM_MAX_FILE_SIZE_MB,
                'allowed_qualities' => json_encode(['720p', '1080p', '4k', 'best_audio']),
                'max_concurrent_requests' => 10,
                'priority' => 100,
                'is_active' => true
            ];
            $this->insert('plans', $premiumMonthly);
            
            quickLog("Initial plans seeded", "INFO");
        }
        
        // بررسی وجود ادمین در جدول users (اگر کاربر ادمین قبلاً ثبت نشده باشد)
        $adminExists = $this->fetchOne("SELECT id FROM users WHERE id = ?", [ADMIN_USER_ID]);
        if (!$adminExists) {
            // ایجاد رکورد ادمین در جدول users
            $this->insert('users', [
                'id' => ADMIN_USER_ID,
                'first_name' => 'Admin',
                'username' => 'admin',
                'sponsor_checked' => true,
                'is_premium' => true,
                'subscription_type' => 'premium_yearly',
                'total_points' => 0
            ]);
            
            // ایجاد state برای ادمین
            $this->insert('user_states', [
                'user_id' => ADMIN_USER_ID,
                'state' => 'main_menu'
            ]);
            
            quickLog("Admin user created with ID: " . ADMIN_USER_ID, "INFO");
        }
    }
    
    /**
     * بررسی سلامت اتصال دیتابیس (برای پنل ادمین)
     * @return array
     */
    public function healthCheck() {
        $start = microtime(true);
        try {
            $this->pdo->query("SELECT 1");
            $latency = round((microtime(true) - $start) * 1000, 2);
            return [
                'status' => 'healthy',
                'latency_ms' => $latency,
                'connected' => $this->connected
            ];
        } catch (PDOException $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'latency_ms' => null,
                'connected' => false
            ];
        }
    }
    
    /**
     * بستن اتصال دیتابیس
     */
    public function close() {
        $this->pdo = null;
        $this->connected = false;
    }
    
    /**
     * جلوگیری از clone شدن (Singleton)
     */
    private function __clone() {}
    
    /**
     * جلوگیری از wakeup (Singleton)
     */
    public function __wakeup() {}
}

/**
 * تابع کمکی برای دسترسی سریع به دیتابیس
 * @return Database
 */
function db() {
    return Database::getInstance();
}
