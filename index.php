<?php
/**
 * index.php - صفحه اصلی ربات DownloadHub
 * 
 * مسئولیت‌ها:
 * 1. نمایش وضعیت کلی ربات
 * 2. نمایش آمار سریع (تعداد کاربران، درخواست‌ها، وضعیت صف)
 * 3. نمایش وضعیت سرویس‌ها (API بله، گیت‌هاب، دیتابیس)
 * 4. نمایش آخرین درخواست‌های ثبت شده
 * 5. راهنمای سریع برای کاربران
 * 6. لینک به پنل ادمین
 * 7. نمایش اطلاعات نصب و نسخه
 * 
 * این صفحه برای عموم قابل مشاهده است و اطلاعات عمومی را نمایش می‌دهد.
 * اطلاعات حساس (توکن‌ها، رمزها) در این صفحه نمایش داده نمی‌شوند.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Logger.php';
require_once __DIR__ . '/bale/Client.php';
require_once __DIR__ . '/github/Client.php';
require_once __DIR__ . '/core/QueueManager.php';
require_once __DIR__ . '/core/CacheChecker.php';

class IndexPage {
    
    private $db;
    private $logger;
    private $bale;
    private $github;
    private $queueManager;
    private $cacheChecker;
    
    // آمارهای صفحه
    private $stats = [
        'users_count' => 0,
        'premium_users' => 0,
        'requests_total' => 0,
        'requests_today' => 0,
        'requests_completed' => 0,
        'queue_pending' => 0,
        'queue_processing' => 0,
        'cache_total' => 0
    ];
    
    // وضعیت سرویس‌ها
    private $services = [
        'database' => ['status' => 'unknown', 'latency' => null],
        'bale_api' => ['status' => 'unknown', 'latency' => null, 'bot_username' => null],
        'github_api' => ['status' => 'unknown', 'latency' => null, 'rate_limit' => null]
    ];
    
    // آخرین درخواست‌ها
    private $lastRequests = [];
    
    // نسخه ربات
    private $version = '1.0.0';
    
    // تاریخ آخرین بروزرسانی
    private $lastUpdate = '2025-01-15';
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->logger = logger();
        $this->bale = bale();
        $this->github = github();
        $this->queueManager = queueManager();
        $this->cacheChecker = cacheChecker();
        
        $this->collectStats();
        $this->checkServices();
        $this->getLastRequests();
    }
    
    /**
     * جمع‌آوری آمار
     */
    private function collectStats() {
        try {
            // تعداد کاربران
            $userStats = $this->db->fetchOne("SELECT COUNT(*) as total, SUM(is_premium) as premium FROM users");
            $this->stats['users_count'] = (int) ($userStats['total'] ?? 0);
            $this->stats['premium_users'] = (int) ($userStats['premium'] ?? 0);
            
            // آمار درخواست‌ها
            $requestStats = $this->db->fetchOne("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today
                FROM queue
            ");
            $this->stats['requests_total'] = (int) ($requestStats['total'] ?? 0);
            $this->stats['requests_completed'] = (int) ($requestStats['completed'] ?? 0);
            $this->stats['requests_today'] = (int) ($requestStats['today'] ?? 0);
            
            // آمار صف
            $queueStats = $this->queueManager->getQueueStats();
            $this->stats['queue_pending'] = $queueStats['pending'] ?? 0;
            $this->stats['queue_processing'] = $queueStats['processing'] ?? 0;
            
            // آمار کش
            $cacheStats = $this->cacheChecker->getCacheStats();
            $this->stats['cache_total'] = $cacheStats['total'] ?? 0;
            
        } catch (Exception $e) {
            $this->logger->error("Failed to collect stats for index page", ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * بررسی وضعیت سرویس‌ها
     */
    private function checkServices() {
        // دیتابیس
        try {
            $start = microtime(true);
            $this->db->fetchOne("SELECT 1");
            $this->services['database']['status'] = 'healthy';
            $this->services['database']['latency'] = round((microtime(true) - $start) * 1000, 2);
        } catch (Exception $e) {
            $this->services['database']['status'] = 'unhealthy';
            $this->services['database']['error'] = $e->getMessage();
        }
        
        // API بله
        try {
            $start = microtime(true);
            $result = $this->bale->getMe();
            $this->services['bale_api']['latency'] = round((microtime(true) - $start) * 1000, 2);
            
            if ($result && isset($result['id'])) {
                $this->services['bale_api']['status'] = 'healthy';
                $this->services['bale_api']['bot_username'] = $result['username'] ?? 'unknown';
                $this->services['bale_api']['bot_id'] = $result['id'];
            } else {
                $this->services['bale_api']['status'] = 'unhealthy';
                $this->services['bale_api']['error'] = 'Invalid response';
            }
        } catch (Exception $e) {
            $this->services['bale_api']['status'] = 'unhealthy';
            $this->services['bale_api']['error'] = $e->getMessage();
        }
        
        // API گیت‌هاب
        try {
            $start = microtime(true);
            $rateLimit = $this->github->getRateLimit();
            $this->services['github_api']['latency'] = round((microtime(true) - $start) * 1000, 2);
            
            if (isset($rateLimit['remaining'])) {
                $this->services['github_api']['status'] = $rateLimit['remaining'] > 0 ? 'healthy' : 'degraded';
                $this->services['github_api']['rate_limit'] = $rateLimit['remaining'];
                $this->services['github_api']['rate_limit_total'] = $rateLimit['limit'] ?? 5000;
                $this->services['github_api']['reset_in'] = $rateLimit['reset_in_seconds'] ?? 0;
            } else {
                $this->services['github_api']['status'] = 'unhealthy';
            }
        } catch (Exception $e) {
            $this->services['github_api']['status'] = 'unhealthy';
            $this->services['github_api']['error'] = $e->getMessage();
        }
    }
    
    /**
     * دریافت آخرین درخواست‌ها
     */
    private function getLastRequests() {
        try {
            $this->lastRequests = $this->db->fetchAll("
                SELECT q.*, u.first_name, u.username
                FROM queue q
                LEFT JOIN users u ON q.user_id = u.id
                ORDER BY q.created_at DESC
                LIMIT 10
            ");
        } catch (Exception $e) {
            $this->lastRequests = [];
        }
    }
    
    /**
     * نمایش صفحه اصلی
     */
    public function render() {
        $this->sendHeaders();
        ?>
        <!DOCTYPE html>
        <html dir="rtl" lang="fa">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
            <meta name="description" content="ربات دانلودر حرفه‌ای برای پیام‌رسان بله - دانلود از YouTube، SoundCloud، Instagram، TikTok">
            <meta name="keywords" content="ربات بله, دانلودر, YouTube, SoundCloud, Instagram, TikTok">
            <meta name="author" content="DownloadHub">
            <meta name="robots" content="index, follow">
            
            <title>DownloadHub - ربات دانلودر حرفه‌ای بله</title>
            
            <!-- Open Graph / Social Media -->
            <meta property="og:title" content="DownloadHub - ربات دانلودر حرفه‌ای بله">
            <meta property="og:description" content="دانلود آسان از YouTube، SoundCloud، Instagram و TikTok با ربات DownloadHub">
            <meta property="og:type" content="website">
            <meta property="og:url" content="<?php echo BASE_URL; ?>">
            <meta property="og:site_name" content="DownloadHub">
            
            <!-- Favicon -->
            <link rel="icon" type="image/png" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🤖</text></svg>">
            
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                
                body {
                    font-family: 'Tahoma', 'Segoe UI', system-ui, -apple-system, sans-serif;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    min-height: 100vh;
                    padding: 20px;
                }
                
                .container {
                    max-width: 1200px;
                    margin: 0 auto;
                }
                
                /* Header */
                .header {
                    background: rgba(255, 255, 255, 0.95);
                    border-radius: 20px;
                    padding: 30px;
                    margin-bottom: 30px;
                    text-align: center;
                    box-shadow: 0 10px 40px rgba(0,0,0,0.1);
                }
                
                .header h1 {
                    font-size: 2.5rem;
                    color: #333;
                    margin-bottom: 10px;
                }
                
                .header h1 span {
                    background: linear-gradient(135deg, #667eea, #764ba2);
                    -webkit-background-clip: text;
                    -webkit-text-fill-color: transparent;
                    background-clip: text;
                }
                
                .header .tagline {
                    color: #666;
                    font-size: 1.1rem;
                    margin-bottom: 20px;
                }
                
                .version-badge {
                    display: inline-block;
                    background: #e0e0e0;
                    padding: 5px 12px;
                    border-radius: 20px;
                    font-size: 0.8rem;
                    color: #666;
                }
                
                /* Stats Grid */
                .stats-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                    gap: 20px;
                    margin-bottom: 30px;
                }
                
                .stat-card {
                    background: rgba(255, 255, 255, 0.95);
                    border-radius: 16px;
                    padding: 20px;
                    text-align: center;
                    transition: transform 0.3s ease;
                    box-shadow: 0 5px 20px rgba(0,0,0,0.08);
                }
                
                .stat-card:hover {
                    transform: translateY(-5px);
                }
                
                .stat-icon {
                    font-size: 2.5rem;
                    margin-bottom: 10px;
                }
                
                .stat-value {
                    font-size: 2rem;
                    font-weight: bold;
                    color: #667eea;
                    margin-bottom: 5px;
                }
                
                .stat-label {
                    color: #666;
                    font-size: 0.9rem;
                }
                
                /* Services Status */
                .services-section {
                    background: rgba(255, 255, 255, 0.95);
                    border-radius: 20px;
                    padding: 25px;
                    margin-bottom: 30px;
                }
                
                .section-title {
                    font-size: 1.3rem;
                    color: #333;
                    margin-bottom: 20px;
                    padding-bottom: 10px;
                    border-bottom: 2px solid #e0e0e0;
                }
                
                .services-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                    gap: 15px;
                }
                
                .service-card {
                    background: #f8f9fa;
                    border-radius: 12px;
                    padding: 15px;
                    border-right: 4px solid;
                }
                
                .service-card.healthy {
                    border-right-color: #4caf50;
                }
                
                .service-card.unhealthy {
                    border-right-color: #f44336;
                }
                
                .service-card.degraded {
                    border-right-color: #ff9800;
                }
                
                .service-name {
                    font-weight: bold;
                    font-size: 1.1rem;
                    margin-bottom: 10px;
                }
                
                .service-status {
                    display: inline-block;
                    padding: 3px 10px;
                    border-radius: 20px;
                    font-size: 0.75rem;
                    margin-top: 5px;
                }
                
                .status-healthy {
                    background: #e8f5e9;
                    color: #2e7d32;
                }
                
                .status-unhealthy {
                    background: #ffebee;
                    color: #c62828;
                }
                
                .status-degraded {
                    background: #fff3e0;
                    color: #f57c00;
                }
                
                .service-latency {
                    font-size: 0.8rem;
                    color: #666;
                    margin-top: 8px;
                }
                
                /* Last Requests Table */
                .requests-section {
                    background: rgba(255, 255, 255, 0.95);
                    border-radius: 20px;
                    padding: 25px;
                    margin-bottom: 30px;
                    overflow-x: auto;
                }
                
                .requests-table {
                    width: 100%;
                    border-collapse: collapse;
                    font-size: 0.85rem;
                }
                
                .requests-table th,
                .requests-table td {
                    padding: 12px;
                    text-align: right;
                    border-bottom: 1px solid #e0e0e0;
                }
                
                .requests-table th {
                    background: #f5f5f5;
                    font-weight: bold;
                    color: #555;
                }
                
                .requests-table tr:hover {
                    background: #f8f9fa;
                }
                
                .status-badge {
                    display: inline-block;
                    padding: 3px 10px;
                    border-radius: 20px;
                    font-size: 0.7rem;
                }
                
                .status-pending { background: #fff3e0; color: #f57c00; }
                .status-processing { background: #e3f2fd; color: #1976d2; }
                .status-completed { background: #e8f5e9; color: #2e7d32; }
                .status-failed { background: #ffebee; color: #c62828; }
                .status-cancelled { background: #f5f5f5; color: #757575; }
                
                /* Bot Info */
                .bot-info {
                    background: linear-gradient(135deg, #667eea, #764ba2);
                    border-radius: 20px;
                    padding: 30px;
                    text-align: center;
                    color: white;
                    margin-bottom: 30px;
                }
                
                .bot-info h3 {
                    font-size: 1.5rem;
                    margin-bottom: 15px;
                }
                
                .bot-info p {
                    margin-bottom: 20px;
                    opacity: 0.9;
                }
                
                .bot-link {
                    display: inline-block;
                    background: white;
                    color: #667eea;
                    padding: 12px 30px;
                    border-radius: 30px;
                    text-decoration: none;
                    font-weight: bold;
                    transition: transform 0.3s ease;
                }
                
                .bot-link:hover {
                    transform: scale(1.05);
                }
                
                /* Features */
                .features-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                    gap: 20px;
                    margin-bottom: 30px;
                }
                
                .feature-card {
                    background: rgba(255, 255, 255, 0.95);
                    border-radius: 16px;
                    padding: 20px;
                    text-align: center;
                }
                
                .feature-icon {
                    font-size: 2rem;
                    margin-bottom: 10px;
                }
                
                .feature-title {
                    font-weight: bold;
                    margin-bottom: 8px;
                    color: #333;
                }
                
                .feature-desc {
                    font-size: 0.8rem;
                    color: #666;
                }
                
                /* Footer */
                .footer {
                    text-align: center;
                    padding: 20px;
                    color: rgba(255,255,255,0.7);
                    font-size: 0.8rem;
                }
                
                .footer a {
                    color: white;
                    text-decoration: none;
                }
                
                /* Responsive */
                @media (max-width: 768px) {
                    .header h1 {
                        font-size: 1.8rem;
                    }
                    
                    .stat-value {
                        font-size: 1.5rem;
                    }
                    
                    .requests-table {
                        font-size: 0.7rem;
                    }
                    
                    .requests-table th,
                    .requests-table td {
                        padding: 8px;
                    }
                }
                
                /* Loading animation */
                .loading {
                    display: inline-block;
                    width: 20px;
                    height: 20px;
                    border: 2px solid #f3f3f3;
                    border-top: 2px solid #667eea;
                    border-radius: 50%;
                    animation: spin 1s linear infinite;
                }
                
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
                
                /* Refresh button */
                .refresh-btn {
                    background: none;
                    border: none;
                    cursor: pointer;
                    font-size: 1.2rem;
                    margin-right: 10px;
                    padding: 5px;
                    border-radius: 50%;
                    transition: background 0.3s;
                }
                
                .refresh-btn:hover {
                    background: rgba(0,0,0,0.05);
                }
            </style>
        </head>
        <body>
            <div class="container">
                <!-- Header -->
                <div class="header">
                    <h1>🤖 <span>DownloadHub</span></h1>
                    <div class="tagline">ربات دانلودر حرفه‌ای برای پیام‌رسان بله</div>
                    <div class="version-badge">نسخه <?php echo $this->version; ?> | آخرین بروزرسانی: <?php echo $this->lastUpdate; ?></div>
                </div>
                
                <!-- Stats Grid -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">👥</div>
                        <div class="stat-value"><?php echo number_format($this->stats['users_count']); ?></div>
                        <div class="stat-label">کاربران فعال</div>
                        <div style="font-size:0.75rem; color:#888; margin-top:5px;">⭐ <?php echo number_format($this->stats['premium_users']); ?> پریمیوم</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">📥</div>
                        <div class="stat-value"><?php echo number_format($this->stats['requests_total']); ?></div>
                        <div class="stat-label">کل درخواست‌ها</div>
                        <div style="font-size:0.75rem; color:#888; margin-top:5px;">✅ <?php echo number_format($this->stats['requests_completed']); ?> موفق</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">📅</div>
                        <div class="stat-value"><?php echo number_format($this->stats['requests_today']); ?></div>
                        <div class="stat-label">درخواست امروز</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">⏳</div>
                        <div class="stat-value"><?php echo number_format($this->stats['queue_pending']); ?></div>
                        <div class="stat-label">در انتظار پردازش</div>
                        <div style="font-size:0.75rem; color:#888; margin-top:5px;">🟠 <?php echo number_format($this->stats['queue_processing']); ?> در حال پردازش</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">💾</div>
                        <div class="stat-value"><?php echo number_format($this->stats['cache_total']); ?></div>
                        <div class="stat-label">فایل در کش</div>
                    </div>
                </div>
                
                <!-- Services Status -->
                <div class="services-section">
                    <div class="section-title">
                        🟢 وضعیت سرویس‌ها
                        <button class="refresh-btn" onclick="location.reload()" title="بروزرسانی">🔄</button>
                    </div>
                    <div class="services-grid">
                        <div class="service-card <?php echo $this->services['database']['status']; ?>">
                            <div class="service-name">🗄️ دیتابیس</div>
                            <div class="service-status status-<?php echo $this->services['database']['status']; ?>">
                                <?php echo $this->services['database']['status'] === 'healthy' ? '✅ سالم' : '❌ مشکل'; ?>
                            </div>
                            <?php if ($this->services['database']['latency']): ?>
                            <div class="service-latency">⏱ زمان پاسخ: <?php echo $this->services['database']['latency']; ?> ms</div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="service-card <?php echo $this->services['bale_api']['status']; ?>">
                            <div class="service-name">📡 API بله</div>
                            <div class="service-status status-<?php echo $this->services['bale_api']['status']; ?>">
                                <?php echo $this->services['bale_api']['status'] === 'healthy' ? '✅ سالم' : '❌ مشکل'; ?>
                            </div>
                            <?php if ($this->services['bale_api']['latency']): ?>
                            <div class="service-latency">⏱ زمان پاسخ: <?php echo $this->services['bale_api']['latency']; ?> ms</div>
                            <?php endif; ?>
                            <?php if ($this->services['bale_api']['bot_username']): ?>
                            <div class="service-latency">🤖 ربات: @<?php echo $this->services['bale_api']['bot_username']; ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="service-card <?php echo $this->services['github_api']['status']; ?>">
                            <div class="service-name">🐙 API گیت‌هاب</div>
                            <div class="service-status status-<?php echo $this->services['github_api']['status']; ?>">
                                <?php 
                                echo $this->services['github_api']['status'] === 'healthy' ? '✅ سالم' : 
                                    ($this->services['github_api']['status'] === 'degraded' ? '⚠️ محدودیت' : '❌ مشکل');
                                ?>
                            </div>
                            <?php if ($this->services['github_api']['latency']): ?>
                            <div class="service-latency">⏱ زمان پاسخ: <?php echo $this->services['github_api']['latency']; ?> ms</div>
                            <?php endif; ?>
                            <?php if ($this->services['github_api']['rate_limit'] !== null): ?>
                            <div class="service-latency">📊 Rate limit: <?php echo number_format($this->services['github_api']['rate_limit']); ?> / <?php echo number_format($this->services['github_api']['rate_limit_total']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Last Requests -->
                <div class="requests-section">
                    <div class="section-title">📋 آخرین درخواست‌ها</div>
                    <?php if (!empty($this->lastRequests)): ?>
                    <table class="requests-table">
                        <thead>
                            <tr>
                                <th>شناسه</th>
                                <th>کاربر</th>
                                <th>پلتفرم</th>
                                <th>کیفیت</th>
                                <th>وضعیت</th>
                                <th>زمان</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($this->lastRequests as $req): ?>
                            <tr>
                                <td>#<?php echo $req['id']; ?></td>
                                <td><?php echo htmlspecialchars($req['first_name'] ?? $req['username'] ?? 'کاربر ناشناس'); ?></td>
                                <td><?php echo $this->getPlatformIcon($req['platform']) . ' ' . ucfirst($req['platform']); ?></td>
                                <td><?php echo htmlspecialchars($req['quality']); ?></td>
                                <td><span class="status-badge status-<?php echo $req['status']; ?>"><?php echo $this->getStatusText($req['status']); ?></span></td>
                                <td><?php echo date('H:i', strtotime($req['created_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <p style="text-align: center; color: #999; padding: 30px;">هیچ درخواستی یافت نشد</p>
                    <?php endif; ?>
                </div>
                
                <!-- Bot Info -->
                <div class="bot-info">
                    <h3>🎯 شروع استفاده از ربات</h3>
                    <p>برای استفاده از ربات، کافی است روی دکمه زیر کلیک کنید و در بله استارت کنید.</p>
                    <?php if ($this->services['bale_api']['bot_username']): ?>
                    <a href="https://ble.ir/<?php echo $this->services['bale_api']['bot_username']; ?>" class="bot-link" target="_blank">
                        🚀 شروع استفاده از ربات
                    </a>
                    <?php else: ?>
                    <a href="https://ble.ir/downloadhub_bot" class="bot-link" target="_blank">
                        🚀 شروع استفاده از ربات
                    </a>
                    <?php endif; ?>
                </div>
                
                <!-- Features -->
                <div class="features-grid">
                    <div class="feature-card">
                        <div class="feature-icon">🎬</div>
                        <div class="feature-title">YouTube</div>
                        <div class="feature-desc">دانلود ویدیو و صدا با کیفیت‌های مختلف</div>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon">🎵</div>
                        <div class="feature-title">SoundCloud</div>
                        <div class="feature-desc">دانلود موزیک از ساوندکلاود</div>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon">📸</div>
                        <div class="feature-title">Instagram</div>
                        <div class="feature-desc">دانلود ویدیو، ریل و استوری</div>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon">🎵</div>
                        <div class="feature-title">TikTok</div>
                        <div class="feature-desc">دانلود ویدیو بدون واترمارک</div>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon">⚡</div>
                        <div class="feature-title">کش هوشمند</div>
                        <div class="feature-desc">ارسال آنی فایل‌های قبلاً دانلود شده</div>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon">⭐</div>
                        <div class="feature-title">نسخه پریمیوم</div>
                        <div class="feature-desc">کیفیت 4K، حذف محدودیت حجم، اولویت در صف</div>
                    </div>
                </div>
                
                <!-- Footer -->
                <div class="footer">
                    <p>DownloadHub Bot v<?php echo $this->version; ?> | توسعه داده شده با ❤️ توسط خشایار</p>
                    <p>
                        <a href="<?php echo BASE_URL; ?>">خانه</a> | 
                        <a href="<?php echo BASE_URL; ?>/admin/dashboard.php">پنل ادمین</a> | 
                        <a href="https://github.com/khashayardev/DownloadHub" target="_blank">گیت‌هاب</a>
                    </p>
                    <p style="margin-top: 10px; font-size: 0.7rem;">
                        آخرین بروزرسانی: <?php echo $this->lastUpdate; ?>
                    </p>
                </div>
            </div>
            
            <script>
                // Auto-refresh every 30 seconds (only if page is visible)
                let refreshInterval = setInterval(function() {
                    if (!document.hidden) {
                        location.reload();
                    }
                }, 30000);
                
                // Stop refresh when page is hidden
                document.addEventListener('visibilitychange', function() {
                    if (document.hidden) {
                        clearInterval(refreshInterval);
                    } else {
                        refreshInterval = setInterval(function() {
                            location.reload();
                        }, 30000);
                    }
                });
            </script>
        </body>
        </html>
        <?php
    }
    
    /**
     * دریافت آیکون پلتفرم
     */
    private function getPlatformIcon($platform) {
        $icons = [
            'youtube' => '🎬',
            'soundcloud' => '🎵',
            'instagram' => '📸',
            'tiktok' => '🎵'
        ];
        return $icons[$platform] ?? '📁';
    }
    
    /**
     * دریافت متن وضعیت
     */
    private function getStatusText($status) {
        $texts = [
            'pending' => 'در صف',
            'processing' => 'در حال پردازش',
            'completed' => 'تکمیل شده',
            'failed' => 'ناموفق',
            'cancelled' => 'لغو شده',
            'rate_limited' => 'محدودیت نرخ'
        ];
        return $texts[$status] ?? $status;
    }
    
    /**
     * ارسال هدرهای HTTP
     */
    private function sendHeaders() {
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
    }
}

// ==================== اجرای صفحه ====================
$page = new IndexPage();
$page->render();
