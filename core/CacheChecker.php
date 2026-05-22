<?php
/**
 * core/CacheChecker.php - مدیریت کش هوشمند فایل‌ها در ریپازیتوری گیت‌هاب
 * 
 * مسئولیت‌ها:
 * 1. بررسی وجود فایل در کش ریپازیتوری (قبل از ارسال به صف)
 * 2. استخراج اطلاعات فایل کش شده (مسیر، حجم، تام‌نیل، متادیتا)
 * 3. به‌روزرسانی کش ایندکس محلی (دیتابیس)
 * 4. همگام‌سازی کش ایندکس با ریپازیتوری گیت‌هاب
 * 5. محاسبه هش URL برای قفلگذاری
 * 6. تخمین حجم فایل قبل از دانلود (برای اعمال محدودیت‌ها)
 * 7. شناسایی پلتفرم و استخراج ID از URL
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/github/Client.php';
require_once dirname(__DIR__) . '/core/Logger.php';

class CacheChecker {
    
    private $db;
    private $github;
    private $logger;
    
    // نگاشت پلتفرم به الگوی استخراج ID از URL
    private $platformPatterns = [
        'youtube' => [
            'pattern' => '/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/|youtube\.com\/v\/)([a-zA-Z0-9_-]{11})/',
            'id_group' => 1
        ],
        'soundcloud' => [
            'pattern' => '/soundcloud\.com\/(?:[^\/]+\/)([0-9]+)/',
            'id_group' => 1
        ],
        'instagram' => [
            'pattern' => '/instagram\.com\/(?:p|reel|tv)\/([a-zA-Z0-9_-]{10,12})/',
            'id_group' => 1
        ],
        'tiktok' => [
            'pattern' => '/tiktok\.com\/@[\w.-]+\/video\/(\d+)/',
            'id_group' => 1
        ]
    ];
    
    // نگاشت پسوند فایل به نوع محتوا
    private $mimeTypes = [
        'mp4' => 'video/mp4',
        'mp3' => 'audio/mpeg',
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'm4a' => 'audio/mp4a-latm',
        'opus' => 'audio/opus',
        'flac' => 'audio/flac',
        'webm' => 'video/webm',
        'mkv' => 'video/x-matroska'
    ];
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->github = github();
        $this->logger = logger();
    }
    
    /**
     * استخراج شناسه محتوا از URL بر اساس پلتفرم
     * @param string $url URL محتوا
     * @param string $platform پلتفرم (اختیاری - اگر null باشد خودکار شناسایی می‌شود)
     * @return array|null ['platform' => 'youtube', 'id' => 'xxxxx'] یا null
     */
    public function extractIdFromUrl($url, $platform = null) {
        // اگر پلتفرم مشخص نشده، ابتدا پلتفرم را شناسایی کن
        if ($platform === null) {
            $platform = $this->detectPlatform($url);
            if (!$platform) {
                return null;
            }
        }
        
        if (!isset($this->platformPatterns[$platform])) {
            return null;
        }
        
        $pattern = $this->platformPatterns[$platform]['pattern'];
        $idGroup = $this->platformPatterns[$platform]['id_group'];
        
        if (preg_match($pattern, $url, $matches)) {
            return [
                'platform' => $platform,
                'id' => $matches[$idGroup],
                'url' => $url
            ];
        }
        
        return null;
    }
    
    /**
     * شناسایی پلتفرم از روی URL
     * @param string $url
     * @return string|null youtube, soundcloud, instagram, tiktok یا null
     */
    public function detectPlatform($url) {
        $url = strtolower($url);
        
        if (strpos($url, 'youtube.com') !== false || strpos($url, 'youtu.be') !== false) {
            return 'youtube';
        }
        
        if (strpos($url, 'soundcloud.com') !== false) {
            return 'soundcloud';
        }
        
        if (strpos($url, 'instagram.com') !== false) {
            return 'instagram';
        }
        
        if (strpos($url, 'tiktok.com') !== false) {
            return 'tiktok';
        }
        
        return null;
    }
    
    /**
     * بررسی وجود فایل در کش (با جستجو در دیتابیس محلی و ریپازیتوری)
     * @param string $platform
     * @param string $externalId
     * @return array|null اطلاعات فایل کش شده یا null
     */
    public function checkCache($platform, $externalId) {
        // مرحله 1: جستجو در دیتابیس محلی (سریع)
        $localCache = $this->getFromLocalCache($platform, $externalId);
        
        if ($localCache && $this->verifyFileExists($localCache['file_path'])) {
            $this->logger->info("Cache hit (local)", [
                'platform' => $platform,
                'external_id' => $externalId,
                'file_path' => $localCache['file_path']
            ]);
            return $localCache;
        }
        
        // مرحله 2: جستجو در ریپازیتوری گیت‌هاب (اگر در دیتابیس محلی نبود)
        $remoteCache = $this->searchInRepository($platform, $externalId);
        
        if ($remoteCache) {
            // ذخیره در دیتابیس محلی برای دفعات بعد
            $this->saveToLocalCache($remoteCache);
            
            $this->logger->info("Cache hit (remote)", [
                'platform' => $platform,
                'external_id' => $externalId,
                'file_path' => $remoteCache['file_path']
            ]);
            return $remoteCache;
        }
        
        $this->logger->debug("Cache miss", [
            'platform' => $platform,
            'external_id' => $externalId
        ]);
        
        return null;
    }
    
    /**
     * جستجو در دیتابیس محلی کش
     * @param string $platform
     * @param string $externalId
     * @return array|null
     */
    private function getFromLocalCache($platform, $externalId) {
        $sql = "SELECT * FROM cache_index WHERE platform = ? AND external_id = ?";
        $result = $this->db->fetchOne($sql, [$platform, $externalId]);
        
        if ($result) {
            return [
                'platform' => $result['platform'],
                'external_id' => $result['external_id'],
                'file_path' => $result['file_path'],
                'creator_name' => $result['creator_name'],
                'title' => $result['title'],
                'thumbnail_url' => $result['thumbnail_url'],
                'file_size_mb' => $result['file_size_mb'],
                'cached_at' => $result['cached_at']
            ];
        }
        
        return null;
    }
    
    /**
     * ذخیره اطلاعات کش در دیتابیس محلی
     * @param array $cacheInfo
     * @return bool
     */
    private function saveToLocalCache($cacheInfo) {
        $sql = "INSERT INTO cache_index (platform, external_id, file_path, creator_name, title, thumbnail_url, file_size_mb, cached_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                file_path = VALUES(file_path),
                creator_name = VALUES(creator_name),
                title = VALUES(title),
                thumbnail_url = VALUES(thumbnail_url),
                file_size_mb = VALUES(file_size_mb),
                cached_at = NOW()";
        
        $result = $this->db->execute($sql, [
            $cacheInfo['platform'],
            $cacheInfo['external_id'],
            $cacheInfo['file_path'],
            $cacheInfo['creator_name'] ?? null,
            $cacheInfo['title'] ?? null,
            $cacheInfo['thumbnail_url'] ?? null,
            $cacheInfo['file_size_mb'] ?? 0
        ]);
        
        return $result !== false;
    }
    
    /**
     * جستجو در ریپازیتوری گیت‌هاب برای فایل کش شده
     * @param string $platform
     * @param string $externalId
     * @return array|null
     */
    private function searchInRepository($platform, $externalId) {
        // روش 1: جستجو با API گیت‌هاب
        $searchResult = $this->github->searchCacheFile($platform, $externalId);
        
        if ($searchResult && isset($searchResult['path'])) {
            // استخراج اطلاعات اضافی از مسیر فایل
            // فرمت مسیر: {platform}/{creator_slug}/{filename}.{ext}
            $pathParts = explode('/', $searchResult['path']);
            $filename = end($pathParts);
            $filenameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);
            $creatorSlug = $pathParts[1] ?? null;
            
            // تخمین حجم فایل (از API نمی‌توانیم مستقیم بگیریم، بعداً از HEAD درخواست می‌گیریم)
            $downloadUrl = $this->github->getFileDownloadUrl($searchResult['path']);
            $fileSize = $downloadUrl ? $this->getRemoteFileSize($downloadUrl) : 0;
            
            return [
                'platform' => $platform,
                'external_id' => $externalId,
                'file_path' => $searchResult['path'],
                'creator_name' => $creatorSlug,
                'title' => $filenameWithoutExt,
                'thumbnail_url' => null,
                'file_size_mb' => round($fileSize / 1024 / 1024, 2),
                'download_url' => $downloadUrl,
                'cached_at' => date('Y-m-d H:i:s')
            ];
        }
        
        // روش 2: لیست کردن محتویات پوشه پلتفرم و جستجوی دستی (در صورت لزوم)
        // این روش سنگین است، فقط در صورتی استفاده شود که روش 1 جواب نداد
        if (GITHUB_TOKEN !== 'github_pat_xxxx') { // فقط اگر توکن واقعی داریم
            $folderContents = $this->github->listFolderContents($platform);
            if ($folderContents && is_array($folderContents)) {
                foreach ($folderContents as $item) {
                    if ($item['type'] === 'dir') {
                        // داخل پوشه کریتور را چک کن
                        $creatorFiles = $this->github->listFolderContents($platform . '/' . $item['name']);
                        foreach ($creatorFiles as $file) {
                            if (strpos($file['name'], $externalId) !== false || 
                                strpos($file['name'], $externalId . '_') !== false) {
                                return [
                                    'platform' => $platform,
                                    'external_id' => $externalId,
                                    'file_path' => $file['path'],
                                    'creator_name' => $item['name'],
                                    'title' => pathinfo($file['name'], PATHINFO_FILENAME),
                                    'thumbnail_url' => null,
                                    'file_size_mb' => round(($file['size'] ?? 0) / 1024 / 1024, 2),
                                    'download_url' => $this->github->getFileDownloadUrl($file['path']),
                                    'cached_at' => date('Y-m-d H:i:s')
                                ];
                            }
                        }
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * بررسی وجود فایل در ریپازیتوری با HEAD درخواست
     * @param string $filePath
     * @return bool
     */
    private function verifyFileExists($filePath) {
        $url = "https://raw.githubusercontent.com/{$this->github->owner}/{$this->github->repo}/main/{$filePath}";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $httpCode === 200;
    }
    
    /**
     * دریافت حجم فایل از راه دور (با HEAD درخواست)
     * @param string $url
     * @return int حجم فایل به بایت
     */
    private function getRemoteFileSize($url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_exec($ch);
        $fileSize = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        curl_close($ch);
        
        return (int) $fileSize;
    }
    
    /**
     * تخمین حجم فایل قبل از دانلود (بر اساس پلتفرم، کیفیت و مدت زمان)
     * @param string $platform
     * @param string $quality
     * @param int $durationSeconds مدت زمان محتوا (ثانیه) - در صورت وجود
     * @return int حجم تخمینی به مگابایت
     */
    public function estimateFileSize($platform, $quality, $durationSeconds = 0) {
        // نرخ بیت بر حسب مگابیت بر ثانیه (تخمینی)
        $bitrateMap = [
            'youtube' => [
                'audio' => 0.128,   // 128 kbps
                '480p' => 1.5,      // 1.5 Mbps
                '720p' => 3.0,      // 3 Mbps
                '1080p' => 5.0,     // 5 Mbps
                'best' => 8.0       // 8 Mbps
            ],
            'soundcloud' => [
                'medium' => 0.096,   // 96 kbps
                'high' => 0.160,     // 160 kbps
                'best' => 0.256,     // 256 kbps
                'flac' => 1.0        // ~1000 kbps
            ],
            'instagram' => [
                'medium' => 1.0,
                'high' => 2.0,
                'best' => 3.0,
                'original' => 5.0
            ],
            'tiktok' => [
                'medium' => 1.0,
                'high' => 2.0,
                'best' => 3.0,
                'original' => 4.0
            ]
        ];
        
        $durationSeconds = max($durationSeconds, 60); // حداقل 60 ثانیه
        
        $bitrate = $bitrateMap[$platform][$quality] ?? 1.0;
        
        // فرمول: (bitrate_mbps * duration_seconds) / 8 = حجم به مگابایت
        $estimatedSizeMb = round(($bitrate * $durationSeconds) / 8, 2);
        
        // حداقل و حداکثر منطقی
        return min(max($estimatedSizeMb, 1), 500);
    }
    
    /**
     * محاسبه هش URL برای قفلگذاری (جلوگیری از دانلود همزمان)
     * @param string $url
     * @return string هش SHA-256
     */
    public function getUrlHash($url) {
        // نرمال‌سازی URL قبل از هش
        $normalized = $this->normalizeUrl($url);
        return hash('sha256', $normalized);
    }
    
    /**
     * نرمال‌سازی URL برای حذف پارامترهای بی‌ربط
     * @param string $url
     * @return string
     */
    public function normalizeUrl($url) {
        // حذف پارامترهای ردیابی
        $url = preg_replace('/[?&](utm_|ref_|source_|fbclid|_ga|_gl|mc_cid|mc_eid)[^&]*/', '', $url);
        
        // برای یوتیوب: حذف پارامترهای اضافی اما حفظ video ID
        if (strpos($url, 'youtube.com') !== false || strpos($url, 'youtu.be') !== false) {
            $extracted = $this->extractIdFromUrl($url, 'youtube');
            if ($extracted) {
                return "https://www.youtube.com/watch?v=" . $extracted['id'];
            }
        }
        
        // حذف trailing slash
        $url = rtrim($url, '/');
        
        return $url;
    }
    
    /**
     * همگام‌سازی کش ایندکس محلی با ریپازیتوری (برای پنل ادمین)
     * @return array آمار همگام‌سازی
     */
    public function syncCacheIndex() {
        $this->logger->info("Starting cache index sync");
        
        $stats = [
            'total_found' => 0,
            'new_added' => 0,
            'errors' => 0
        ];
        
        $platforms = ['youtube', 'soundcloud', 'instagram', 'tiktok'];
        
        foreach ($platforms as $platform) {
            $folders = $this->github->listFolderContents($platform);
            
            if (!$folders || !is_array($folders)) {
                continue;
            }
            
            foreach ($folders as $folder) {
                if ($folder['type'] !== 'dir') {
                    continue;
                }
                
                $creatorSlug = $folder['name'];
                $files = $this->github->listFolderContents($platform . '/' . $creatorSlug);
                
                foreach ($files as $file) {
                    if ($file['type'] !== 'file') {
                        continue;
                    }
                    
                    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                    // فقط فایل‌های رسانه‌ای را در نظر بگیر
                    if (!in_array($extension, ['mp4', 'mp3', 'jpg', 'png', 'm4a', 'opus', 'flac', 'webm', 'mkv'])) {
                        continue;
                    }
                    
                    $stats['total_found']++;
                    
                    // سعی کن external_id را از نام فایل استخراج کنی
                    $filename = pathinfo($file['name'], PATHINFO_FILENAME);
                    $externalId = $this->extractIdFromFilename($filename, $platform);
                    
                    if (!$externalId) {
                        // اگر نتوانستیم ID را استخراج کنیم، از hash filename استفاده کن
                        $externalId = md5($filename);
                    }
                    
                    // چک کن آیا قبلاً در دیتابیس هست
                    $existing = $this->getFromLocalCache($platform, $externalId);
                    
                    if (!$existing) {
                        $cacheInfo = [
                            'platform' => $platform,
                            'external_id' => $externalId,
                            'file_path' => $file['path'],
                            'creator_name' => $creatorSlug,
                            'title' => $filename,
                            'thumbnail_url' => null,
                            'file_size_mb' => round(($file['size'] ?? 0) / 1024 / 1024, 2),
                            'cached_at' => date('Y-m-d H:i:s')
                        ];
                        
                        if ($this->saveToLocalCache($cacheInfo)) {
                            $stats['new_added']++;
                        } else {
                            $stats['errors']++;
                        }
                    }
                }
            }
        }
        
        $this->logger->info("Cache index sync completed", $stats);
        
        return $stats;
    }
    
    /**
     * استخراج ID از نام فایل (بر اساس الگوهای مختلف)
     * @param string $filename
     * @param string $platform
     * @return string|null
     */
    private function extractIdFromFilename($filename, $platform) {
        switch ($platform) {
            case 'youtube':
                // الگوی: Video Title [dQw4w9WgXcQ] یا حاوی ID 11 کاراکتری
                if (preg_match('/[\[\(]([a-zA-Z0-9_-]{11})[\]\)]/', $filename, $matches)) {
                    return $matches[1];
                }
                if (preg_match('/([a-zA-Z0-9_-]{11})$/', $filename, $matches)) {
                    return $matches[1];
                }
                break;
                
            case 'soundcloud':
                // الگوی: Track Name [123456789]
                if (preg_match('/[\[\(](\d+)[\]\)]/', $filename, $matches)) {
                    return $matches[1];
                }
                break;
                
            case 'instagram':
                // الگوی: Post Name [CxXxXxXxXxX]
                if (preg_match('/[\[\(]([a-zA-Z0-9_-]{10,12})[\]\)]/', $filename, $matches)) {
                    return $matches[1];
                }
                break;
                
            case 'tiktok':
                // الگوی: Video Name [123456789012345]
                if (preg_match('/[\[\(](\d{15,20})[\]\)]/', $filename, $matches)) {
                    return $matches[1];
                }
                break;
        }
        
        return null;
    }
    
    /**
     * دریافت URL دانلود مستقیم فایل کش شده
     * @param array $cacheInfo
     * @return string|null
     */
    public function getCacheDownloadUrl($cacheInfo) {
        if (isset($cacheInfo['download_url']) && $cacheInfo['download_url']) {
            return $cacheInfo['download_url'];
        }
        
        return $this->github->getFileDownloadUrl($cacheInfo['file_path']);
    }
    
    /**
     * حذف یک ورودی از کش (در صورت حذف فایل از ریپازیتوری)
     * @param string $platform
     * @param string $externalId
     * @return bool
     */
    public function removeFromCache($platform, $externalId) {
        $sql = "DELETE FROM cache_index WHERE platform = ? AND external_id = ?";
        $result = $this->db->execute($sql, [$platform, $externalId]);
        
        if ($result !== false) {
            $this->logger->info("Cache entry removed", [
                'platform' => $platform,
                'external_id' => $externalId
            ]);
            return true;
        }
        
        return false;
    }
    
    /**
     * آمار کش (تعداد کل، به تفکیک پلتفرم)
     * @return array
     */
    public function getCacheStats() {
        $sql = "SELECT platform, COUNT(*) as count FROM cache_index GROUP BY platform";
        $results = $this->db->fetchAll($sql);
        
        $stats = [
            'total' => 0,
            'by_platform' => []
        ];
        
        foreach ($results as $row) {
            $stats['by_platform'][$row['platform']] = (int) $row['count'];
            $stats['total'] += (int) $row['count'];
        }
        
        return $stats;
    }
}

/**
 * تابع کمکی برای دسترسی سریع به CacheChecker
 * @return CacheChecker
 */
function cacheChecker() {
    static $checker = null;
    if ($checker === null) {
        $checker = new CacheChecker();
    }
    return $checker;
}
