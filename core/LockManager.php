<?php
/**
 * core/LockManager.php - مدیریت قفلگذاری روی URL‌ها برای جلوگیری از دانلود همزمان
 * 
 * مسئولیت‌ها:
 * 1. ایجاد قفل روی URL (با هش SHA-256)
 * 2. بررسی وجود قفل فعال برای یک URL
 * 3. آزادسازی قفل بعد از اتمام دانلود یا انقضای زمان
 * 4. پاک کردن خودکار قفل‌های منقضی شده
 * 5. مدیریت Race Condition هنگام ایجاد قفل (با استفاده از تراکنش دیتابیس)
 * 6. آمار قفل‌های فعال برای پنل ادمین
 * 7. جلوگیری از Deadlock با timeout خودکار
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Logger.php';

class LockManager {
    
    private $db;
    private $logger;
    private $lockExpireSeconds;
    
    // حداکثر تعداد دفعات تلاش برای گرفتن قفل
    private $maxRetries = 5;
    
    // فاصله بین تلاش‌ها (میلی‌ثانیه)
    private $retryIntervalMs = 200;
    
    public function __construct($lockExpireSeconds = null) {
        $this->db = Database::getInstance();
        $this->logger = logger();
        $this->lockExpireSeconds = $lockExpireSeconds ?? LOCK_EXPIRE_SECONDS;
        
        // پاک کردن خودکار قفل‌های منقضی شده در زمان ساخت (سبک)
        $this->cleanExpiredLocks();
    }
    
    /**
     * پاک کردن قفل‌های منقضی شده
     * @return int تعداد قفل‌های پاک شده
     */
    public function cleanExpiredLocks() {
        $sql = "DELETE FROM processing_locks WHERE expires_at < NOW()";
        $result = $this->db->execute($sql);
        
        if ($result !== false && $result > 0) {
            $this->logger->debug("Cleaned expired locks", ['count' => $result]);
        }
        
        return $result !== false ? $result : 0;
    }
    
    /**
     * تلاش برای گرفتن قفل روی یک URL
     * @param string $url URL اصلی (قبل از نرمال‌سازی)
     * @param int $userId شناسه کاربر درخواست‌دهنده
     * @param int $queueId شناسه درخواست در صف
     * @return array|null نتیجه قفل (success, lock_info) یا null در صورت خطا
     */
    public function acquireLock($url, $userId, $queueId) {
        // نرمال‌سازی URL برای قفلگذاری یکسان
        $normalizedUrl = $this->normalizeUrlForLock($url);
        $urlHash = hash('sha256', $normalizedUrl);
        
        // ثبت لاگ برای دیباگ
        $this->logger->debug("Attempting to acquire lock", [
            'url_hash' => substr($urlHash, 0, 8),
            'user_id' => $userId,
            'queue_id' => $queueId
        ]);
        
        $attempt = 0;
        
        while ($attempt < $this->maxRetries) {
            $attempt++;
            
            // بررسی وجود قفل فعال
            $existingLock = $this->getLock($urlHash);
            
            if ($existingLock) {
                // قفل وجود دارد
                $expiresAt = strtotime($existingLock['expires_at']);
                $now = time();
                
                if ($expiresAt > $now) {
                    // قفل هنوز فعال است
                    $remainingSeconds = $expiresAt - $now;
                    $this->logger->info("Lock already held", [
                        'url_hash' => substr($urlHash, 0, 8),
                        'held_by_user' => $existingLock['user_id'],
                        'remaining_seconds' => $remainingSeconds
                    ]);
                    
                    return [
                        'success' => false,
                        'reason' => 'already_locked',
                        'held_by_user_id' => $existingLock['user_id'],
                        'expires_in_seconds' => $remainingSeconds,
                        'lock' => $existingLock
                    ];
                } else {
                    // قفل منقضی شده ولی در دیتابیس باقی مانده
                    $this->releaseLock($urlHash);
                }
            }
            
            // تلاش برای درج قفل جدید
            $expiresAt = date('Y-m-d H:i:s', time() + $this->lockExpireSeconds);
            
            $sql = "INSERT INTO processing_locks (url_hash, user_id, queue_id, original_url, expires_at, created_at) 
                    VALUES (?, ?, ?, ?, ?, NOW())";
            
            $result = $this->db->execute($sql, [
                $urlHash,
                $userId,
                $queueId,
                $normalizedUrl,
                $expiresAt
            ]);
            
            if ($result !== false && $result > 0) {
                // قفل با موفقیت گرفته شد
                $this->logger->info("Lock acquired successfully", [
                    'url_hash' => substr($urlHash, 0, 8),
                    'user_id' => $userId,
                    'queue_id' => $queueId,
                    'expires_at' => $expiresAt
                ]);
                
                return [
                    'success' => true,
                    'url_hash' => $urlHash,
                    'expires_at' => $expiresAt,
                    'expires_in_seconds' => $this->lockExpireSeconds
                ];
            }
            
            // اگر خطای duplicate بود (مسابقه)، کمی صبر کن و دوباره تلاش کن
            $lastError = $this->db->getLastError();
            if (strpos($lastError, 'Duplicate') !== false) {
                $this->logger->debug("Lock race condition, retrying", [
                    'attempt' => $attempt,
                    'url_hash' => substr($urlHash, 0, 8)
                ]);
                usleep($this->retryIntervalMs * 1000);
                continue;
            }
            
            // خطای دیگر
            $this->logger->error("Failed to acquire lock", [
                'url_hash' => substr($urlHash, 0, 8),
                'error' => $lastError
            ]);
            
            return [
                'success' => false,
                'reason' => 'database_error',
                'error' => $lastError
            ];
        }
        
        // بعد از تمام تلاش‌ها ناموفق بود
        $this->logger->warning("Failed to acquire lock after max retries", [
            'url_hash' => substr($urlHash, 0, 8),
            'max_retries' => $this->maxRetries
        ]);
        
        return [
            'success' => false,
            'reason' => 'max_retries_exceeded'
        ];
    }
    
    /**
     * دریافت اطلاعات قفل موجود برای یک URL
     * @param string $urlHash هش URL (یا خود URL که هش می‌شود)
     * @return array|null اطلاعات قفل یا null
     */
    public function getLock($urlHash) {
        // اگر URL وارد شده باشد، هش کن
        if (strlen($urlHash) !== 64) {
            $urlHash = hash('sha256', $this->normalizeUrlForLock($urlHash));
        }
        
        $sql = "SELECT * FROM processing_locks WHERE url_hash = ? AND expires_at > NOW()";
        $result = $this->db->fetchOne($sql, [$urlHash]);
        
        if ($result) {
            return [
                'url_hash' => $result['url_hash'],
                'user_id' => $result['user_id'],
                'queue_id' => $result['queue_id'],
                'original_url' => $result['original_url'],
                'created_at' => $result['created_at'],
                'expires_at' => $result['expires_at']
            ];
        }
        
        return null;
    }
    
    /**
     * بررسی是否 قفل فعال برای یک URL وجود دارد
     * @param string $url
     * @return bool
     */
    public function isLocked($url) {
        $urlHash = hash('sha256', $this->normalizeUrlForLock($url));
        $sql = "SELECT COUNT(*) as count FROM processing_locks WHERE url_hash = ? AND expires_at > NOW()";
        $result = $this->db->fetchOne($sql, [$urlHash]);
        
        return $result && $result['count'] > 0;
    }
    
    /**
     * آزادسازی قفل (بعد از اتمام دانلود یا لغو)
     * @param string $urlHash هش URL (یا خود URL)
     * @return bool موفقیت
     */
    public function releaseLock($urlHash) {
        // اگر URL وارد شده باشد، هش کن
        if (strlen($urlHash) !== 64) {
            $urlHash = hash('sha256', $this->normalizeUrlForLock($urlHash));
        }
        
        $sql = "DELETE FROM processing_locks WHERE url_hash = ?";
        $result = $this->db->execute($sql, [$urlHash]);
        
        if ($result !== false) {
            $this->logger->debug("Lock released", [
                'url_hash' => substr($urlHash, 0, 8),
                'affected_rows' => $result
            ]);
            return true;
        }
        
        return false;
    }
    
    /**
     * آزادسازی تمام قفل‌های یک کاربر (در صورت خروج کاربر)
     * @param int $userId
     * @return int تعداد قفل‌های آزاد شده
     */
    public function releaseUserLocks($userId) {
        $sql = "DELETE FROM processing_locks WHERE user_id = ?";
        $result = $this->db->execute($sql, [$userId]);
        
        $count = $result !== false ? $result : 0;
        
        if ($count > 0) {
            $this->logger->info("Released all locks for user", [
                'user_id' => $userId,
                'count' => $count
            ]);
        }
        
        return $count;
    }
    
    /**
     * آزادسازی قفل بر اساس queue_id (وقتی درخواست از صف حذف می‌شود)
     * @param int $queueId
     * @return bool
     */
    public function releaseLockByQueueId($queueId) {
        $sql = "DELETE FROM processing_locks WHERE queue_id = ?";
        $result = $this->db->execute($sql, [$queueId]);
        
        if ($result !== false && $result > 0) {
            $this->logger->debug("Lock released by queue_id", [
                'queue_id' => $queueId,
                'affected_rows' => $result
            ]);
            return true;
        }
        
        return false;
    }
    
    /**
     * تمدید زمان انقضای قفل (برای دانلودهای طولانی)
     * @param string $urlHash
     * @param int $additionalSeconds ثانیه اضافه (پیش‌فرض: LOCK_EXPIRE_SECONDS)
     * @return bool
     */
    public function renewLock($urlHash, $additionalSeconds = null) {
        if (strlen($urlHash) !== 64) {
            $urlHash = hash('sha256', $this->normalizeUrlForLock($urlHash));
        }
        
        $additionalSeconds = $additionalSeconds ?? $this->lockExpireSeconds;
        $newExpiresAt = date('Y-m-d H:i:s', time() + $additionalSeconds);
        
        $sql = "UPDATE processing_locks SET expires_at = ? WHERE url_hash = ? AND expires_at > NOW()";
        $result = $this->db->execute($sql, [$newExpiresAt, $urlHash]);
        
        if ($result !== false && $result > 0) {
            $this->logger->debug("Lock renewed", [
                'url_hash' => substr($urlHash, 0, 8),
                'new_expires_at' => $newExpiresAt
            ]);
            return true;
        }
        
        return false;
    }
    
    /**
     * دریافت لیست تمام قفل‌های فعال (برای پنل ادمین)
     * @param int $limit حداکثر تعداد
     * @return array
     */
    public function getActiveLocks($limit = 50) {
        $sql = "SELECT l.*, u.first_name, u.username 
                FROM processing_locks l
                LEFT JOIN users u ON l.user_id = u.id
                WHERE l.expires_at > NOW()
                ORDER BY l.created_at DESC
                LIMIT ?";
        
        $results = $this->db->fetchAll($sql, [$limit]);
        
        $locks = [];
        foreach ($results as $row) {
            $locks[] = [
                'url_hash' => substr($row['url_hash'], 0, 16) . '...',
                'original_url' => $row['original_url'],
                'user_id' => $row['user_id'],
                'user_name' => $row['first_name'] ?? $row['username'] ?? 'Unknown',
                'queue_id' => $row['queue_id'],
                'created_at' => $row['created_at'],
                'expires_at' => $row['expires_at'],
                'remaining_seconds' => max(0, strtotime($row['expires_at']) - time())
            ];
        }
        
        return $locks;
    }
    
    /**
     * آمار قفل‌ها (تعداد فعال، میانگین زمان ماندگاری)
     * @return array
     */
    public function getLockStats() {
        // تعداد قفل‌های فعال
        $sqlActive = "SELECT COUNT(*) as active FROM processing_locks WHERE expires_at > NOW()";
        $activeResult = $this->db->fetchOne($sqlActive);
        $activeCount = $activeResult ? (int) $activeResult['active'] : 0;
        
        // میانگین زمان ماندگاری قفل‌های منقضی شده (برای تحلیل)
        $sqlAvg = "SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, expires_at)) as avg_duration 
                   FROM processing_locks WHERE expires_at < NOW()";
        $avgResult = $this->db->fetchOne($sqlAvg);
        $avgDuration = $avgResult ? round($avgResult['avg_duration'], 2) : 0;
        
        // قفل‌های با بیشترین تکرار (URLهای داغ)
        $sqlTop = "SELECT original_url, COUNT(*) as count 
                   FROM processing_locks 
                   WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
                   GROUP BY original_url
                   ORDER BY count DESC
                   LIMIT 5";
        $topUrls = $this->db->fetchAll($sqlTop);
        
        return [
            'active_locks' => $activeCount,
            'average_lock_duration_seconds' => $avgDuration,
            'total_locks_7days' => $this->getTotalLocksLast7Days(),
            'top_locked_urls' => $topUrls,
            'lock_expire_seconds' => $this->lockExpireSeconds
        ];
    }
    
    /**
     * تعداد کل قفل‌های ایجاد شده در ۷ روز اخیر
     * @return int
     */
    private function getTotalLocksLast7Days() {
        $sql = "SELECT COUNT(*) as total FROM processing_locks WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)";
        $result = $this->db->fetchOne($sql);
        return $result ? (int) $result['total'] : 0;
    }
    
    /**
     * نرمال‌سازی URL برای قفلگذاری (حذف پارامترهای متغیر)
     * @param string $url
     * @return string
     */
    private function normalizeUrlForLock($url) {
        // حذف پارامترهای ردیابی
        $url = preg_replace('/[?&](utm_|ref_|source_|fbclid|_ga|_gl|mc_cid|mc_eid|si=|feature=)[^&]*/', '', $url);
        
        // برای یوتیوب: نرمال‌سازی به فرم استاندارد
        if (strpos($url, 'youtube.com') !== false || strpos($url, 'youtu.be') !== false) {
            // استخراج video ID
            if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/', $url, $matches)) {
                return "https://www.youtube.com/watch?v=" . $matches[1];
            }
        }
        
        // برای اینستاگرام: حذف پارامترهای اضافی
        if (strpos($url, 'instagram.com') !== false) {
            $url = preg_replace('/\?.*$/', '', $url);
        }
        
        // برای تیک‌تاک: حذف پارامترهای اضافی
        if (strpos($url, 'tiktok.com') !== false) {
            $url = preg_replace('/\?.*$/', '', $url);
        }
        
        // حذف trailing slash
        $url = rtrim($url, '/');
        
        // تبدیل به حروف کوچک
        $url = strtolower($url);
        
        return $url;
    }
    
    /**
     * پاک کردن قفل‌های قدیمی (اجرای دستی برای نگهداری)
     * @param int $olderThanHours حذف قفل‌های قدیمی‌تر از این تعداد ساعت
     * @return int تعداد قفل‌های پاک شده
     */
    public function purgeOldLocks($olderThanHours = 24) {
        $sql = "DELETE FROM processing_locks WHERE created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)";
        $result = $this->db->execute($sql, [$olderThanHours]);
        
        $count = $result !== false ? $result : 0;
        
        if ($count > 0) {
            $this->logger->info("Purged old locks", [
                'older_than_hours' => $olderThanHours,
                'count' => $count
            ]);
        }
        
        return $count;
    }
    
    /**
     * تنظیم حداکثر تعداد تلاش برای گرفتن قفل
     * @param int $maxRetries
     */
    public function setMaxRetries($maxRetries) {
        $this->maxRetries = max(1, $maxRetries);
    }
    
    /**
     * تنظیم فاصله بین تلاش‌ها
     * @param int $intervalMs میلی‌ثانیه
     */
    public function setRetryInterval($intervalMs) {
        $this->retryIntervalMs = max(50, $intervalMs);
    }
}

/**
 * تابع کمکی برای دسترسی سریع به LockManager
 * @param int|null $lockExpireSeconds
 * @return LockManager
 */
function lockManager($lockExpireSeconds = null) {
    static $manager = null;
    if ($manager === null) {
        $manager = new LockManager($lockExpireSeconds);
    }
    return $manager;
}
