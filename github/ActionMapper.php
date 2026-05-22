<?php
/**
 * github/ActionMapper.php - نگاشت پلتفرم‌ها به فایل‌های workflow گیت‌هاب
 * 
 * مسئولیت‌ها:
 * 1. نگاشت نام پلتفرم به فایل workflow مربوطه
 * 2. اعتبارسنجی پلتفرم‌های پشتیبانی شده
 * 3. دریافت تنظیمات پیش‌فرض برای هر پلتفرم
 * 4. نگاشت کیفیت‌های ربات به کیفیت‌های قابل قبول در اکشن‌ها
 * 5. مدیریت ورژن‌های مختلف workflow (برای توسعه آینده)
 * 6. دریافت لیست تمام اکشن‌های فعال
 * 7. اعتبارسنجی ورودی‌های workflow قبل از ارسال
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/core/Logger.php';

class ActionMapper {
    
    // لیست پلتفرم‌های پشتیبانی شده
    private $supportedPlatforms = ['youtube', 'soundcloud', 'instagram', 'tiktok'];
    
    // نگاشت پلتفرم به نام فایل workflow
    private $workflowFiles = [
        'youtube' => 'yt-dl.yml',
        'soundcloud' => 'soundcloud-dl.yml',
        'instagram' => 'instagram-dl.yml',
        'tiktok' => 'tiktok-dl.yml'
    ];
    
    // نگاشت پلتفرم به عنوان نمایشی
    private $platformTitles = [
        'youtube' => '🎬 YouTube',
        'soundcloud' => '🎵 SoundCloud',
        'instagram' => '📸 Instagram',
        'tiktok' => '🎵 TikTok'
    ];
    
    // تنظیمات پیش‌فرض هر پلتفرم
    private $defaultSettings = [
        'youtube' => [
            'quality' => '720p',
            'download_type' => 'single',
            'max_items' => 1,
            'download_subtitles' => false,
            'allowed_qualities' => ['audio', '480p', '720p', '1080p', 'best'],
            'premium_qualities' => ['1080p', 'best'],
            'max_file_size_mb' => 200,
            'free_max_file_size_mb' => 50,
            'description' => 'دانلود ویدیو و صدا از یوتیوب'
        ],
        'soundcloud' => [
            'quality' => 'best',
            'format_choice' => 'mp3',
            'download_type' => 'single',
            'max_tracks' => 1,
            'extract_lyrics' => false,
            'allowed_qualities' => ['medium', 'high', 'best', 'flac'],
            'premium_qualities' => ['flac'],
            'max_file_size_mb' => 150,
            'free_max_file_size_mb' => 50,
            'description' => 'دانلود موزیک و پلی‌لیست از ساوندکلاود'
        ],
        'instagram' => [
            'quality' => 'best',
            'format_choice' => 'mp4',
            'download_type' => 'single',
            'max_items' => 1,
            'allowed_qualities' => ['medium', 'high', 'best', 'original'],
            'premium_qualities' => ['original'],
            'max_file_size_mb' => 100,
            'free_max_file_size_mb' => 50,
            'description' => 'دانلود ویدیو، ریل و استوری از اینستاگرام'
        ],
        'tiktok' => [
            'quality' => 'best',
            'download_type' => 'single',
            'max_items' => 1,
            'allowed_qualities' => ['medium', 'high', 'best', 'original'],
            'premium_qualities' => ['original'],
            'max_file_size_mb' => 100,
            'free_max_file_size_mb' => 50,
            'description' => 'دانلود ویدیو بدون واترمارک از تیک‌تاک'
        ]
    ];
    
    // نگاشت کیفیت ربات به کیفیت اکشن
    private $qualityMapping = [
        'youtube' => [
            'audio' => 'audio',
            '480p' => '480',
            '720p' => '720',
            '1080p' => '1080',
            'best' => 'best'
        ],
        'soundcloud' => [
            'medium' => 'medium',
            'high' => 'high',
            'best' => 'best',
            'best_audio' => 'best',
            'flac' => 'flac'
        ],
        'instagram' => [
            'medium' => 'medium',
            'high' => 'high',
            'best' => 'best',
            'original' => 'best'
        ],
        'tiktok' => [
            'medium' => 'medium',
            'high' => 'high',
            'best' => 'best',
            'original' => 'best'
        ]
    ];
    
    private $logger;
    
    public function __construct() {
        $this->logger = logger();
        $this->logger->debug("ActionMapper initialized", [
            'supported_platforms' => $this->supportedPlatforms
        ]);
    }
    
    /**
     * دریافت نام فایل workflow برای یک پلتفرم
     * @param string $platform
     * @return string|null
     */
    public function getWorkflowFileName($platform) {
        if (!$this->isPlatformSupported($platform)) {
            $this->logger->warning("Unsupported platform requested", ['platform' => $platform]);
            return null;
        }
        
        return $this->workflowFiles[$platform];
    }
    
    /**
     * بررسی پشتیبانی از پلتفرم
     * @param string $platform
     * @return bool
     */
    public function isPlatformSupported($platform) {
        return in_array($platform, $this->supportedPlatforms);
    }
    
    /**
     * دریافت لیست تمام پلتفرم‌های پشتیبانی شده
     * @return array
     */
    public function getSupportedPlatforms() {
        return $this->supportedPlatforms;
    }
    
    /**
     * دریافت عنوان نمایشی پلتفرم
     * @param string $platform
     * @return string
     */
    public function getPlatformTitle($platform) {
        return $this->platformTitles[$platform] ?? $platform;
    }
    
    /**
     * دریافت تنظیمات پیش‌فرض یک پلتفرم
     * @param string $platform
     * @return array|null
     */
    public function getDefaultSettings($platform) {
        if (!$this->isPlatformSupported($platform)) {
            return null;
        }
        
        return $this->defaultSettings[$platform];
    }
    
    /**
     * دریافت لیست کیفیت‌های مجاز برای یک پلتفرم (بر اساس سطح دسترسی)
     * @param string $platform
     * @param bool $isPremium کاربر پریمیوم است؟
     * @return array
     */
    public function getAllowedQualities($platform, $isPremium = false) {
        $settings = $this->getDefaultSettings($platform);
        if (!$settings) {
            return [];
        }
        
        $allQualities = $settings['allowed_qualities'];
        
        if (!$isPremium) {
            // حذف کیفیت‌های پریمیوم
            $premiumQualities = $settings['premium_qualities'];
            $allQualities = array_diff($allQualities, $premiumQualities);
        }
        
        return array_values($allQualities);
    }
    
    /**
     * دریافت حداکثر حجم فایل مجاز برای یک پلتفرم (بر اساس سطح دسترسی)
     * @param string $platform
     * @param bool $isPremium
     * @return int
     */
    public function getMaxFileSize($platform, $isPremium = false) {
        $settings = $this->getDefaultSettings($platform);
        if (!$settings) {
            return FREE_MAX_FILE_SIZE_MB;
        }
        
        return $isPremium ? $settings['max_file_size_mb'] : $settings['free_max_file_size_mb'];
    }
    
    /**
     * نگاشت کیفیت ربات به کیفیت قابل قبول برای اکشن
     * @param string $platform
     * @param string $quality
     * @return string
     */
    public function mapQuality($platform, $quality) {
        if (!isset($this->qualityMapping[$platform][$quality])) {
            // اگر کیفیت در نگاشت نبود، از پیش‌فرض استفاده کن
            $defaultSettings = $this->getDefaultSettings($platform);
            $defaultQuality = $defaultSettings['quality'] ?? 'best';
            $this->logger->warning("Quality mapping not found", [
                'platform' => $platform,
                'requested_quality' => $quality,
                'fallback_to' => $defaultQuality
            ]);
            return $defaultQuality;
        }
        
        return $this->qualityMapping[$platform][$quality];
    }
    
    /**
     * آماده‌سازی ورودی‌های workflow برای ارسال به گیت‌هاب
     * @param string $platform
     * @param array $urls
     * @param string $quality
     * @param array $userInfo (chat_id, channel_id, channel_username)
     * @return array|null
     */
    public function prepareWorkflowInputs($platform, $urls, $quality, $userInfo) {
        if (!$this->isPlatformSupported($platform)) {
            return null;
        }
        
        $urlsString = implode(' ', $urls);
        $mappedQuality = $this->mapQuality($platform, $quality);
        $settings = $this->getDefaultSettings($platform);
        
        $baseInputs = [
            'chat_id' => (string) ($userInfo['id'] ?? ''),
            'channel_id' => (string) ($userInfo['archived_channel_id'] ?? ''),
            'channel_username' => $userInfo['archived_channel_username'] ?? ''
        ];
        
        switch ($platform) {
            case 'youtube':
                return array_merge($baseInputs, [
                    'youtube_urls' => $urlsString,
                    'quality' => $mappedQuality,
                    'download_type' => $settings['download_type'],
                    'max_items' => (string) $settings['max_items'],
                    'download_subtitles' => $settings['download_subtitles'] ? 'true' : 'false'
                ]);
                
            case 'soundcloud':
                return array_merge($baseInputs, [
                    'soundcloud_urls' => $urlsString,
                    'quality' => $mappedQuality,
                    'format_choice' => $settings['format_choice'],
                    'download_type' => $settings['download_type'],
                    'max_tracks' => (string) $settings['max_tracks'],
                    'extract_lyrics' => $settings['extract_lyrics'] ? 'true' : 'false'
                ]);
                
            case 'instagram':
                return array_merge($baseInputs, [
                    'instagram_urls' => $urlsString,
                    'quality' => $mappedQuality,
                    'format_choice' => $settings['format_choice'],
                    'download_type' => $settings['download_type'],
                    'max_items' => (string) $settings['max_items']
                ]);
                
            case 'tiktok':
                return array_merge($baseInputs, [
                    'tiktok_urls' => $urlsString,
                    'quality' => $mappedQuality,
                    'download_type' => $settings['download_type'],
                    'max_items' => (string) $settings['max_items']
                ]);
                
            default:
                return null;
        }
    }
    
    /**
     * اعتبارسنجی ورودی‌های workflow قبل از ارسال
     * @param string $platform
     * @param array $inputs
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validateWorkflowInputs($platform, $inputs) {
        $errors = [];
        
        if (!$this->isPlatformSupported($platform)) {
            $errors[] = "Platform '{$platform}' is not supported";
            return ['valid' => false, 'errors' => $errors];
        }
        
        $settings = $this->getDefaultSettings($platform);
        
        // اعتبارسنجی URLها
        $urlField = $platform . '_urls';
        if (empty($inputs[$urlField])) {
            $errors[] = "URL field '{$urlField}' is required";
        }
        
        // اعتبارسنجی کیفیت
        if (isset($inputs['quality'])) {
            $allowedQualities = $settings['allowed_qualities'];
            if (!in_array($inputs['quality'], $allowedQualities)) {
                $errors[] = "Quality '{$inputs['quality']}' is not allowed. Allowed: " . implode(', ', $allowedQualities);
            }
        }
        
        // اعتبارسنجی chat_id (اگر پر نشده باشد، اکشن به کسی پیام نمی‌دهد)
        if (empty($inputs['chat_id'])) {
            $this->logger->warning("Workflow inputs missing chat_id", ['platform' => $platform]);
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * دریافت توضیحات یک پلتفرم
     * @param string $platform
     * @return string|null
     */
    public function getPlatformDescription($platform) {
        $settings = $this->getDefaultSettings($platform);
        return $settings['description'] ?? null;
    }
    
    /**
     * دریافت لیست تمام اکشن‌های فعال با جزئیات کامل
     * @param bool $includePremiumInfo آیا اطلاعات پریمیوم هم نمایش داده شود؟
     * @return array
     */
    public function getAllActionsInfo($includePremiumInfo = false) {
        $actions = [];
        
        foreach ($this->supportedPlatforms as $platform) {
            $settings = $this->getDefaultSettings($platform);
            $actionInfo = [
                'platform' => $platform,
                'title' => $this->getPlatformTitle($platform),
                'description' => $settings['description'],
                'workflow_file' => $this->workflowFiles[$platform],
                'default_quality' => $settings['quality'],
                'allowed_qualities' => $settings['allowed_qualities'],
                'max_file_size_mb_free' => $settings['free_max_file_size_mb'],
                'max_file_size_mb_premium' => $settings['max_file_size_mb']
            ];
            
            if ($includePremiumInfo) {
                $actionInfo['premium_qualities'] = $settings['premium_qualities'];
            }
            
            $actions[] = $actionInfo;
        }
        
        return $actions;
    }
    
    /**
     * اضافه کردن پلتفرم جدید (برای توسعه آینده)
     * @param string $platform
     * @param string $workflowFile
     * @param string $title
     * @param array $settings
     * @return bool
     */
    public function addPlatform($platform, $workflowFile, $title, $settings) {
        if ($this->isPlatformSupported($platform)) {
            $this->logger->warning("Platform already exists", ['platform' => $platform]);
            return false;
        }
        
        $this->supportedPlatforms[] = $platform;
        $this->workflowFiles[$platform] = $workflowFile;
        $this->platformTitles[$platform] = $title;
        $this->defaultSettings[$platform] = array_merge($this->defaultSettings['youtube'], $settings);
        
        // تنظیم کیفیت مپینگ پیش‌فرض
        $this->qualityMapping[$platform] = [
            'medium' => 'medium',
            'high' => 'high',
            'best' => 'best',
            'original' => 'best'
        ];
        
        $this->logger->info("New platform added", ['platform' => $platform]);
        
        return true;
    }
    
    /**
     * بروزرسانی تنظیمات یک پلتفرم (برای پنل ادمین)
     * @param string $platform
     * @param array $newSettings
     * @return bool
     */
    public function updatePlatformSettings($platform, $newSettings) {
        if (!$this->isPlatformSupported($platform)) {
            return false;
        }
        
        $allowedKeys = ['quality', 'download_type', 'max_items', 'format_choice', 'extract_lyrics'];
        
        foreach ($newSettings as $key => $value) {
            if (in_array($key, $allowedKeys)) {
                $this->defaultSettings[$platform][$key] = $value;
            }
        }
        
        $this->logger->info("Platform settings updated", [
            'platform' => $platform,
            'changes' => array_keys($newSettings)
        ]);
        
        return true;
    }
    
    /**
     * دریافت وضعیت سلامت اکشن‌ها (آیا فایل‌های workflow وجود دارند؟)
     * این متد برای پنل ادمین - بررسی می‌کند که آیا فایل‌های YAML در ریپازیتوری وجود دارند
     * @param GitHubClient $github
     * @return array
     */
    public function checkWorkflowsHealth($github) {
        $workflows = $github->listWorkflows();
        $workflowPaths = [];
        
        foreach ($workflows as $workflow) {
            $workflowPaths[] = basename($workflow['path']);
        }
        
        $health = [];
        
        foreach ($this->supportedPlatforms as $platform) {
            $expectedFile = $this->workflowFiles[$platform];
            $exists = in_array($expectedFile, $workflowPaths);
            
            $health[$platform] = [
                'workflow_file' => $expectedFile,
                'exists' => $exists,
                'status' => $exists ? 'healthy' : 'missing'
            ];
        }
        
        return $health;
    }
}

/**
 * تابع کمکی برای دسترسی سریع به ActionMapper
 * @return ActionMapper
 */
function actionMapper() {
    static $mapper = null;
    if ($mapper === null) {
        $mapper = new ActionMapper();
    }
    return $mapper;
}
