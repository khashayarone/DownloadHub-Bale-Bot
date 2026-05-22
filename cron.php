<?php
/**
 * cron.php - پردازشگر زمانبندی شده (Cron Job)
 * 
 * مسئولیت‌ها:
 * 1. پردازش صف درخواست‌ها (هر 60 ثانیه)
 * 2. بروزرسانی وضعیت workflowهای در حال اجرا
 * 3. پاک کردن قفل‌های منقضی شده
 * 4. پاک کردن لاگ‌های قدیمی
 * 5. همگام‌سازی کش ایندکس با ریپازیتوری
 * 6. ارسال اعلان‌های زمانبندی شده
 * 7. بررسی سلامت APIها و ارسال هشدار به ادمین
 * 8. پردازش jobهای ارسال همگانی
 * 9. بروزرسانی آمار روزانه
 * 10. پاک کردن درخواست‌های منقضی شده
 * 
 * نحوه اجرا در crontab:
 * */1 * * * * php /home/khashayar/public_html/downloadhub/cron.php >> /home/khashayar/logs/cron.log 2>&1
 * 
 * یا برای اجرای هر 30 ثانیه (با استفاده از sleep):
 * * * * * * php /home/khashayar/public_html/downloadhub/cron.php
 * * * * * * sleep 30; php /home/khashayar/public_html/downloadhub/cron.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Logger.php';
require_once __DIR__ . '/core/QueueManager.php';
require_once __DIR__ . '/core/LockManager.php';
require_once __DIR__ . '/core/CacheChecker.php';
require_once __DIR__ . '/github/Client.php';
require_once __DIR__ . '/bale/Client.php';

class CronProcessor {
    
    private $db;
    private $logger;
    private $queueManager;
    private $lockManager;
    private $cacheChecker;
    private $github;
    private $bale;
    
    // آمار اجرا
    private $stats = [
        'start_time' => null,
        'end_time' => null,
        'queue_processed' => 0,
        'queue_success' => 0,
        'queue_failed' => 0,
        'locks_cleaned' => 0,
        'cache_synced' => false,
        'broadcast_processed' => 0,
        'errors' => 0
    ];
    
    // زمان شروع اجرا
    private $executionId;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->logger = logger();
        $this->queueManager = queueManager();
        $this->lockManager = lockManager();
        $this->cacheChecker = cacheChecker();
        $this->github = github();
        $this->bale = bale();
        
        $this->executionId = uniqid('cron_', true);
        $this->stats['start_time'] = microtime(true);
        
        $this->logger->info("Cron job started", [
            'execution_id' => $this->executionId
        ]);
    }
    
    /**
     * اجرای تمام وظایف cron
     */
    public function run() {
        try {
            // 1. پردازش صف درخواست‌ها (اولویت اصلی)
            $this->processQueue();
            
            // 2. بروزرسانی وضعیت workflowهای در حال اجرا
            $this->updateWorkflowStatuses();
            
            // 3. پاک کردن قفل‌های منقضی شده
            $this->cleanExpiredLocks();
            
            // 4. پردازش jobهای ارسال همگانی
            $this->processBroadcastJobs();
            
            // 5. همگام‌سازی کش ایندکس (هر 6 ساعت یکبار)
            $this->syncCacheIfNeeded();
            
            // 6. پاک کردن لاگ‌های قدیمی (هر روز یکبار)
            $this->cleanOldLogsIfNeeded();
            
            // 7. پاک کردن درخواست‌های منقضی شده
            $this->cleanExpiredRequests();
            
            // 8. بررسی سلامت APIها و ارسال هشدار
            $this->checkApiHealth();
            
            // 9. بروزرسانی آمار روزانه
            $this->updateDailyStats();
            
            // 10. پاک کردن آپدیت‌های قدیمی
            $this->cleanOldUpdates();
            
        } catch (Exception $e) {
            $this->stats['errors']++;
            $this->logger->exception($e);
        }
        
        $this->stats['end_time'] = microtime(true);
        $this->logExecutionStats();
    }
    
    /**
     * پردازش صف درخواست‌ها
     */
    private function processQueue() {
        try {
            $result = $this->queueManager->processQueue();
            
            $this->stats['queue_processed'] = $result['processed'] ?? 0;
            $this->stats['queue_success'] = $result['success'] ?? 0;
            $this->stats['queue_failed'] = $result['failed'] ?? 0;
            
            if ($result['processed'] > 0) {
                $this->logger->info("Queue processed", $result);
            }
        } catch (Exception $e) {
            $this->stats['errors']++;
            $this->logger->error("Queue processing failed", ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * بروزرسانی وضعیت workflowهای در حال اجرا
     */
    private function updateWorkflowStatuses() {
        try {
            // دریافت درخواست‌های در حال پردازش
            $sql = "SELECT id, workflow_run_id FROM queue 
                    WHERE status = 'processing' 
                    AND workflow_run_id IS NOT NULL
                    AND started_at IS NOT NULL";
            $processingRequests = $this->db->fetchAll($sql);
            
            $updated = 0;
            foreach ($processingRequests as $request) {
                $status = $this->github->getWorkflowRunStatus($request['workflow_run_id']);
                
                if ($status && $status['status'] === 'completed') {
                    $conclusion = $status['conclusion'] ?? 'unknown';
                    $newStatus = ($conclusion === 'success') ? 'completed' : 'failed';
                    
                    $this->queueManager->updateQueueStatus(
                        $request['id'],
                        $newStatus,
                        null,
                        $conclusion === 'success' ? null : "Workflow concluded with: {$conclusion}"
                    );
                    
                    $updated++;
                    $this->logger->debug("Workflow status updated", [
                        'queue_id' => $request['id'],
                        'run_id' => $request['workflow_run_id'],
                        'new_status' => $newStatus
                    ]);
                }
            }
            
            if ($updated > 0) {
                $this->logger->info("Updated workflow statuses", ['count' => $updated]);
            }
            
        } catch (Exception $e) {
            $this->stats['errors']++;
            $this->logger->error("Workflow status update failed", ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * پاک کردن قفل‌های منقضی شده
     */
    private function cleanExpiredLocks() {
        try {
            $cleaned = $this->lockManager->cleanExpiredLocks();
            $this->stats['locks_cleaned'] = $cleaned;
            
            if ($cleaned > 0) {
                $this->logger->debug("Cleaned expired locks", ['count' => $cleaned]);
            }
        } catch (Exception $e) {
            $this->stats['errors']++;
            $this->logger->error("Lock cleanup failed", ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * پردازش jobهای ارسال همگانی
     */
    private function processBroadcastJobs() {
        try {
            // دریافت jobهای در انتظار
            $sql = "SELECT * FROM broadcast_jobs WHERE status = 'pending' ORDER BY created_at ASC LIMIT 1";
            $job = $this->db->fetchOne($sql);
            
            if (!$job) {
                return;
            }
            
            $this->logger->info("Processing broadcast job", ['job_id' => $job['id']]);
            
            // بروزرسانی وضعیت به processing
            $this->db->update('broadcast_jobs', ['status' => 'processing'], 'id = ?', [$job['id']]);
            
            // دریافت لیست کاربران
            $users = $this->getTargetUsers($job['target']);
            $totalUsers = count($users);
            $sent = 0;
            
            $this->db->update('broadcast_jobs', ['total_targets' => $totalUsers], 'id = ?', [$job['id']]);
            
            foreach ($users as $user) {
                // رعایت rate limit (هر 10 درخواست 1 ثانیه توقف)
                if ($sent > 0 && $sent % 10 == 0) {
                    sleep(1);
                }
                
                $result = $this->bale->sendMessage($user['id'], $job['message_text'], 'Markdown');
                if ($result) {
                    $sent++;
                }
                
                // بروزرسانی پیشرفت هر 100 پیام
                if ($sent % 100 == 0) {
                    $this->db->update('broadcast_jobs', ['sent_count' => $sent], 'id = ?', [$job['id']]);
                    $this->logger->debug("Broadcast progress", [
                        'job_id' => $job['id'],
                        'sent' => $sent,
                        'total' => $totalUsers
                    ]);
                }
            }
            
            // تکمیل job
            $this->db->update('broadcast_jobs', [
                'status' => 'completed',
                'sent_count' => $sent,
                'completed_at' => date('Y-m-d H:i:s')
            ], 'id = ?', [$job['id']]);
            
            $this->stats['broadcast_processed'] = 1;
            $this->logger->info("Broadcast job completed", [
                'job_id' => $job['id'],
                'sent' => $sent,
                'total' => $totalUsers
            ]);
            
        } catch (Exception $e) {
            $this->stats['errors']++;
            $this->logger->error("Broadcast processing failed", ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * دریافت لیست کاربران بر اساس هدف
     */
    private function getTargetUsers($target) {
        switch ($target) {
            case 'all':
                return $this->db->fetchAll("SELECT id FROM users");
            case 'active':
                return $this->db->fetchAll("SELECT id FROM users WHERE last_active_at > DATE_SUB(NOW(), INTERVAL 7 DAY)");
            case 'premium':
                return $this->db->fetchAll("SELECT id FROM users WHERE is_premium = 1");
            default:
                return [];
        }
    }
    
    /**
     * همگام‌سازی کش ایندکس (هر 6 ساعت یکبار)
     */
    private function syncCacheIfNeeded() {
        $lastSyncFile = sys_get_temp_dir() . '/downloadhub_last_cache_sync';
        $lastSync = 0;
        
        if (file_exists($lastSyncFile)) {
            $lastSync = (int) file_get_contents($lastSyncFile);
        }
        
        $sixHours = 6 * 3600;
        
        if (time() - $lastSync > $sixHours) {
            try {
                $result = $this->cacheChecker->syncCacheIndex();
                $this->stats['cache_synced'] = true;
                
                file_put_contents($lastSyncFile, time());
                
                $this->logger->info("Cache index synced", [
                    'total_found' => $result['total_found'],
                    'new_added' => $result['new_added'],
                    'errors' => $result['errors']
                ]);
            } catch (Exception $e) {
                $this->stats['errors']++;
                $this->logger->error("Cache sync failed", ['error' => $e->getMessage()]);
            }
        }
    }
    
    /**
     * پاک کردن لاگ‌های قدیمی (هر روز یکبار)
     */
    private function cleanOldLogsIfNeeded() {
        $lastCleanFile = sys_get_temp_dir() . '/downloadhub_last_log_clean';
        $lastClean = 0;
        
        if (file_exists($lastCleanFile)) {
            $lastClean = (int) file_get_contents($lastCleanFile);
        }
        
        $oneDay = 24 * 3600;
        
        if (time() - $lastClean > $oneDay) {
            try {
                // پاک کردن لاگ‌های دیتابیس (قدیمی‌تر از 30 روز)
                $deletedDb = $this->logger->cleanDatabaseLogs(30);
                
                // پاک کردن لاگ‌های فایل توسط Logger خودکار انجام می‌شود
                
                file_put_contents($lastCleanFile, time());
                
                $this->logger->info("Old logs cleaned", [
                    'db_logs_deleted' => $deletedDb
                ]);
            } catch (Exception $e) {
                $this->stats['errors']++;
                $this->logger->error("Log cleanup failed", ['error' => $e->getMessage()]);
            }
        }
    }
    
    /**
     * پاک کردن درخواست‌های منقضی شده
     */
    private function cleanExpiredRequests() {
        try {
            // درخواست‌های processing که بیش از 3 ساعت در این وضعیت مانده‌اند
            $sql = "UPDATE queue 
                    SET status = 'failed', 
                        error_message = 'Timeout: processing took too long',
                        completed_at = NOW()
                    WHERE status = 'processing' 
                    AND started_at < DATE_SUB(NOW(), INTERVAL 3 HOUR)";
            
            $updated = $this->db->execute($sql);
            
            if ($updated > 0) {
                $this->logger->warning("Cleaned expired processing requests", ['count' => $updated]);
            }
            
            // درخواست‌های pending که بیش از 7 روز در صف مانده‌اند
            $sql2 = "UPDATE queue 
                     SET status = 'cancelled', 
                         error_message = 'Request expired after 7 days',
                         completed_at = NOW()
                     WHERE status = 'pending' 
                     AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)";
            
            $updated2 = $this->db->execute($sql2);
            
            if ($updated2 > 0) {
                $this->logger->info("Cleaned expired pending requests", ['count' => $updated2]);
            }
            
        } catch (Exception $e) {
            $this->stats['errors']++;
            $this->logger->error("Expired requests cleanup failed", ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * بررسی سلامت APIها و ارسال هشدار
     */
    private function checkApiHealth() {
        $lastCheckFile = sys_get_temp_dir() . '/downloadhub_last_health_check';
        $lastCheck = 0;
        
        if (file_exists($lastCheckFile)) {
            $lastCheck = (int) file_get_contents($lastCheckFile);
        }
        
        $checkInterval = 15 * 60; // هر 15 دقیقه
        
        if (time() - $lastCheck > $checkInterval) {
            $issues = [];
            
            // بررسی GitHub API
            $githubHealth = $this->github->healthCheck();
            if ($githubHealth['status'] !== 'healthy') {
                $issues[] = "GitHub API: {$githubHealth['status']}";
            }
            
            // بررسی Bale API
            $baleHealth = $this->bale->healthCheck();
            if ($baleHealth['status'] !== 'healthy') {
                $issues[] = "Bale API: {$baleHealth['status']}";
            }
            
            // بررسی دیتابیس
            $dbHealth = $this->db->healthCheck();
            if ($dbHealth['status'] !== 'healthy') {
                $issues[] = "Database: {$dbHealth['status']}";
            }
            
            // بررسی Rate limit گیت‌هاب
            $rateLimit = $this->github->getRateLimit();
            if ($rateLimit['remaining'] < 100) {
                $issues[] = "GitHub rate limit low: {$rateLimit['remaining']} remaining";
            }
            
            // ارسال هشدار در صورت وجود مشکل
            if (!empty($issues)) {
                $this->sendHealthAlert($issues);
            }
            
            file_put_contents($lastCheckFile, time());
            
            $this->logger->debug("Health check completed", [
                'issues_count' => count($issues),
                'github_status' => $githubHealth['status'],
                'bale_status' => $baleHealth['status'],
                'db_status' => $dbHealth['status']
            ]);
        }
    }
    
    /**
     * ارسال هشدار سلامت به ادمین
     * @param array $issues
     */
    private function sendHealthAlert($issues) {
        $lastAlertFile = sys_get_temp_dir() . '/downloadhub_last_health_alert';
        $lastAlert = 0;
        
        if (file_exists($lastAlertFile)) {
            $lastAlert = (int) file_get_contents($lastAlertFile);
        }
        
        $alertInterval = 60 * 60; // حداکثر هر 1 ساعت یکبار هشدار بده
        
        if (time() - $lastAlert > $alertInterval) {
            $text = "⚠️ *هشدار سلامت ربات*\n\n";
            $text .= "مشکلات زیر شناسایی شده است:\n\n";
            
            foreach ($issues as $issue) {
                $text .= "• {$issue}\n";
            }
            
            $text .= "\n🕐 زمان: " . date('Y-m-d H:i:s');
            
            $this->bale->sendMessage(ADMIN_USER_ID, $text, 'Markdown');
            file_put_contents($lastAlertFile, time());
            
            $this->logger->warning("Health alert sent to admin", ['issues' => $issues]);
        }
    }
    
    /**
     * بروزرسانی آمار روزانه
     */
    private function updateDailyStats() {
        $lastStatsFile = sys_get_temp_dir() . '/downloadhub_last_daily_stats';
        $lastStats = 0;
        
        if (file_exists($lastStatsFile)) {
            $lastStats = (int) file_get_contents($lastStatsFile);
        }
        
        $oneDay = 24 * 3600;
        
        if (time() - $lastStats > $oneDay) {
            try {
                // آمار روز قبل
                $yesterday = date('Y-m-d', strtotime('-1 day'));
                
                $sql = "SELECT 
                            COUNT(*) as total_requests,
                            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                            SUM(CASE WHEN cache_hit = 1 THEN 1 ELSE 0 END) as cache_hits
                        FROM queue 
                        WHERE DATE(created_at) = ?";
                
                $stats = $this->db->fetchOne($sql, [$yesterday]);
                
                // ذخیره آمار در جدول مخصوص (اختیاری)
                // می‌توانید یک جدول daily_stats ایجاد کنید
                
                file_put_contents($lastStatsFile, time());
                
                $this->logger->info("Daily stats calculated", [
                    'date' => $yesterday,
                    'stats' => $stats
                ]);
            } catch (Exception $e) {
                $this->stats['errors']++;
                $this->logger->error("Daily stats update failed", ['error' => $e->getMessage()]);
            }
        }
    }
    
    /**
     * پاک کردن آپدیت‌های قدیمی
     */
    private function cleanOldUpdates() {
        try {
            $sql = "DELETE FROM processed_updates WHERE processed_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)";
            $deleted = $this->db->execute($sql);
            
            if ($deleted > 0) {
                $this->logger->debug("Cleaned old updates", ['count' => $deleted]);
            }
        } catch (Exception $e) {
            $this->stats['errors']++;
            $this->logger->error("Old updates cleanup failed", ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * ثبت آمار اجرای cron
     */
    private function logExecutionStats() {
        $duration = round(($this->stats['end_time'] - $this->stats['start_time']) * 1000, 2);
        
        $this->logger->info("Cron job completed", [
            'execution_id' => $this->executionId,
            'duration_ms' => $duration,
            'queue_processed' => $this->stats['queue_processed'],
            'queue_success' => $this->stats['queue_success'],
            'queue_failed' => $this->stats['queue_failed'],
            'locks_cleaned' => $this->stats['locks_cleaned'],
            'cache_synced' => $this->stats['cache_synced'],
            'broadcast_processed' => $this->stats['broadcast_processed'],
            'errors' => $this->stats['errors']
        ]);
    }
}

// ==================== اجرای اصلی ====================
// جلوگیری از اجرای همزمان چندین نمونه (با فایل قفل)

$lockFile = sys_get_temp_dir() . '/downloadhub_cron.lock';
$fp = fopen($lockFile, 'w');

if (!flock($fp, LOCK_EX | LOCK_NB)) {
    // نمونه قبلی هنوز در حال اجراست
    logger()->warning("Cron job already running, skipping");
    exit(0);
}

try {
    $cron = new CronProcessor();
    $cron->run();
} catch (Exception $e) {
    logger()->exception($e);
} finally {
    flock($fp, LOCK_UN);
    fclose($fp);
}
