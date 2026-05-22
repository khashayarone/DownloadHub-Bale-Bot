<?php
/**
 * admin/dashboard.php - پنل مدیریت ادمین
 * 
 * مسئولیت‌ها:
 * 1. احراز هویت ادمین (با رمز عبور یا از طریق بله)
 * 2. نمایش داشبورد کامل با نمودارها و آمار
 * 3. مدیریت کاربران (مشاهده، ارتقا، مسدودسازی)
 * 4. مدیریت درخواست‌ها (مشاهده، لغو، حذف)
 * 5. مدیریت صف (مشاهده، پاک کردن، تغییر اولویت)
 * 6. مدیریت کش (مشاهده، حذف، همگام‌سازی)
 * 7. مشاهده لاگ‌ها و خطاها
 * 8. تنظیمات سیستم
 * 9. ارسال همگانی پیام
 * 10. پشتیبانی و پاسخ به تیکت‌ها
 * 
 * امنیت: فقط کاربر ادمین (ADMIN_USER_ID) به این صفحه دسترسی دارد
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Logger.php';
require_once dirname(__DIR__) . '/core/QueueManager.php';
require_once dirname(__DIR__) . '/core/CacheChecker.php';
require_once dirname(__DIR__) . '/core/LockManager.php';
require_once dirname(__DIR__) . '/core/StateManager.php';
require_once dirname(__DIR__) . '/bale/Client.php';
require_once dirname(__DIR__) . '/github/Client.php';
require_once dirname(__DIR__) . '/github/ActionMapper.php';

session_start();

class AdminDashboard {
    
    private $db;
    private $logger;
    private $queueManager;
    private $cacheChecker;
    private $lockManager;
    private $bale;
    private $github;
    private $actionMapper;
    
    // آیا کاربر احراز هویت شده است؟
    private $authenticated = false;
    
    // کاربر فعلی
    private $currentUser = null;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->logger = logger();
        $this->queueManager = queueManager();
        $this->cacheChecker = cacheChecker();
        $this->lockManager = lockManager();
        $this->bale = bale();
        $this->github = github();
        $this->actionMapper = actionMapper();
        
        $this->checkAuthentication();
    }
    
    /**
     * بررسی احراز هویت ادمین
     */
    private function checkAuthentication() {
        // روش 1: احراز از طریق بله (callback_data)
        if (isset($_GET['auth']) && isset($_GET['user_id']) && $_GET['user_id'] == ADMIN_USER_ID) {
            $_SESSION['admin_authenticated'] = true;
            $_SESSION['admin_auth_time'] = time();
            $this->authenticated = true;
            $this->redirect('dashboard.php');
            return;
        }
        
        // روش 2: بررسی session
        if (isset($_SESSION['admin_authenticated']) && $_SESSION['admin_authenticated'] === true) {
            // بررسی انقضای session (8 ساعت)
            if (isset($_SESSION['admin_auth_time']) && (time() - $_SESSION['admin_auth_time']) < 28800) {
                $this->authenticated = true;
                return;
            } else {
                session_destroy();
            }
        }
        
        // روش 3: احراز با رمز (fallback)
        if ($this->checkPasswordAuth()) {
            return;
        }
        
        $this->showLoginPage();
        exit;
    }
    
    /**
     * بررسی احراز با رمز عبور
     */
    private function checkPasswordAuth() {
        if (isset($_POST['password']) && isset($_POST['user_id'])) {
            $userId = (int) $_POST['user_id'];
            $password = $_POST['password'];
            
            if ($userId == ADMIN_USER_ID && password_verify($password, ADMIN_PANEL_PASSWORD)) {
                $_SESSION['admin_authenticated'] = true;
                $_SESSION['admin_auth_time'] = time();
                $this->authenticated = true;
                return true;
            }
        }
        return false;
    }
    
    /**
     * نمایش صفحه ورود
     */
    private function showLoginPage() {
        ?>
        <!DOCTYPE html>
        <html dir="rtl" lang="fa">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>ورود به پنل مدیریت - DownloadHub</title>
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                
                body {
                    font-family: 'Tahoma', 'Segoe UI', sans-serif;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    min-height: 100vh;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    padding: 20px;
                }
                
                .login-container {
                    background: white;
                    border-radius: 20px;
                    padding: 40px;
                    width: 100%;
                    max-width: 400px;
                    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                }
                
                .login-header {
                    text-align: center;
                    margin-bottom: 30px;
                }
                
                .login-header h1 {
                    color: #333;
                    font-size: 1.8rem;
                }
                
                .login-header p {
                    color: #666;
                    margin-top: 10px;
                }
                
                .form-group {
                    margin-bottom: 20px;
                }
                
                .form-group label {
                    display: block;
                    margin-bottom: 8px;
                    color: #555;
                    font-weight: bold;
                }
                
                .form-group input {
                    width: 100%;
                    padding: 12px 15px;
                    border: 2px solid #e0e0e0;
                    border-radius: 10px;
                    font-size: 1rem;
                    transition: border-color 0.3s;
                }
                
                .form-group input:focus {
                    outline: none;
                    border-color: #667eea;
                }
                
                .login-btn {
                    width: 100%;
                    padding: 12px;
                    background: linear-gradient(135deg, #667eea, #764ba2);
                    color: white;
                    border: none;
                    border-radius: 10px;
                    font-size: 1rem;
                    font-weight: bold;
                    cursor: pointer;
                    transition: transform 0.3s;
                }
                
                .login-btn:hover {
                    transform: translateY(-2px);
                }
                
                .bale-login {
                    text-align: center;
                    margin-top: 20px;
                    padding-top: 20px;
                    border-top: 1px solid #e0e0e0;
                }
                
                .bale-login a {
                    color: #667eea;
                    text-decoration: none;
                }
                
                .error-msg {
                    background: #ffebee;
                    color: #c62828;
                    padding: 10px;
                    border-radius: 10px;
                    margin-bottom: 20px;
                    text-align: center;
                }
            </style>
        </head>
        <body>
            <div class="login-container">
                <div class="login-header">
                    <h1>👑 پنل مدیریت</h1>
                    <p>ورود به بخش مدیریت DownloadHub</p>
                </div>
                
                <?php if (isset($_GET['error'])): ?>
                <div class="error-msg">❌ رمز عبور یا شناسه کاربری نادرست است</div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="form-group">
                        <label>🆔 شناسه کاربری (User ID)</label>
                        <input type="number" name="user_id" placeholder="شناسه خود را وارد کنید" required>
                    </div>
                    <div class="form-group">
                        <label>🔑 رمز عبور</label>
                        <input type="password" name="password" placeholder="رمز عبور را وارد کنید" required>
                    </div>
                    <button type="submit" class="login-btn">ورود به پنل</button>
                </form>
                
                <div class="bale-login">
                    <p>یا از طریق ربات وارد شوید:</p>
                    <a href="https://ble.ir/im/p/<?php echo ADMIN_USER_ID; ?>">🔗 ورود با حساب ادمین</a>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
    
    /**
     * هدایت به صفحه دیگر
     */
    private function redirect($url) {
        header("Location: {$url}");
        exit;
    }
    
    /**
     * نمایش پنل اصلی
     */
    public function render() {
        $action = $_GET['action'] ?? 'dashboard';
        
        switch ($action) {
            case 'users':
                $this->renderUsersPage();
                break;
            case 'requests':
                $this->renderRequestsPage();
                break;
            case 'queue':
                $this->renderQueuePage();
                break;
            case 'cache':
                $this->renderCachePage();
                break;
            case 'logs':
                $this->renderLogsPage();
                break;
            case 'settings':
                $this->renderSettingsPage();
                break;
            case 'broadcast':
                $this->renderBroadcastPage();
                break;
            case 'tickets':
                $this->renderTicketsPage();
                break;
            case 'subscriptions':
                $this->renderSubscriptionsPage();
                break;
            case 'health':
                $this->renderHealthPage();
                break;
            case 'logout':
                $this->logout();
                break;
            default:
                $this->renderDashboardPage();
                break;
        }
    }
    
    /**
     * خروج از پنل
     */
    private function logout() {
        session_destroy();
        $this->redirect('dashboard.php');
    }
    
    /**
     * نمایش صفحه داشبورد
     */
    private function renderDashboardPage() {
        // جمع‌آوری آمار
        $userStats = $this->db->fetchOne("SELECT COUNT(*) as total, SUM(is_premium) as premium FROM users");
        $requestStats = $this->db->fetchOne("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today
            FROM queue
        ");
        
        $queueStats = $this->queueManager->getQueueStats();
        $cacheStats = $this->cacheChecker->getCacheStats();
        $githubHealth = $this->github->healthCheck();
        $baleHealth = $this->bale->healthCheck();
        
        // درخواست‌های ۷ روز اخیر برای نمودار
        $dailyStats = $this->db->fetchAll("
            SELECT DATE(created_at) as date, 
                   COUNT(*) as total,
                   SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
            FROM queue 
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ");
        
        // آخرین درخواست‌ها
        $lastRequests = $this->db->fetchAll("
            SELECT q.*, u.first_name, u.username
            FROM queue q
            LEFT JOIN users u ON q.user_id = u.id
            ORDER BY q.created_at DESC
            LIMIT 10
        ");
        
        ?>
        <!DOCTYPE html>
        <html dir="rtl" lang="fa">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>پنل مدیریت - DownloadHub</title>
            <link rel="icon" type="image/png" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>👑</text></svg>">
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                
                body {
                    font-family: 'Tahoma', 'Segoe UI', sans-serif;
                    background: #f5f5f5;
                    direction: rtl;
                }
                
                /* Sidebar */
                .sidebar {
                    position: fixed;
                    right: 0;
                    top: 0;
                    width: 260px;
                    height: 100%;
                    background: linear-gradient(180deg, #2d3748 0%, #1a202c 100%);
                    color: white;
                    transition: all 0.3s;
                    z-index: 100;
                }
                
                .sidebar-header {
                    padding: 25px 20px;
                    text-align: center;
                    border-bottom: 1px solid rgba(255,255,255,0.1);
                }
                
                .sidebar-header h2 {
                    font-size: 1.3rem;
                }
                
                .sidebar-header p {
                    font-size: 0.7rem;
                    opacity: 0.7;
                    margin-top: 5px;
                }
                
                .sidebar-menu {
                    padding: 20px 0;
                }
                
                .menu-item {
                    display: block;
                    padding: 12px 20px;
                    color: rgba(255,255,255,0.8);
                    text-decoration: none;
                    transition: all 0.3s;
                    border-right: 3px solid transparent;
                }
                
                .menu-item:hover {
                    background: rgba(255,255,255,0.1);
                    border-right-color: #667eea;
                }
                
                .menu-item.active {
                    background: rgba(255,255,255,0.15);
                    border-right-color: #667eea;
                    color: white;
                }
                
                .menu-item .icon {
                    margin-left: 10px;
                }
                
                /* Main Content */
                .main-content {
                    margin-right: 260px;
                    padding: 20px;
                }
                
                /* Header */
                .content-header {
                    background: white;
                    border-radius: 15px;
                    padding: 20px;
                    margin-bottom: 20px;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
                }
                
                .content-header h1 {
                    font-size: 1.5rem;
                    color: #333;
                }
                
                .content-header .logout {
                    float: left;
                    color: #e53e3e;
                    text-decoration: none;
                }
                
                /* Stats Grid */
                .stats-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                    gap: 20px;
                    margin-bottom: 30px;
                }
                
                .stat-card {
                    background: white;
                    border-radius: 15px;
                    padding: 20px;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
                }
                
                .stat-card .value {
                    font-size: 2rem;
                    font-weight: bold;
                    color: #667eea;
                }
                
                .stat-card .label {
                    color: #666;
                    margin-top: 5px;
                }
                
                /* Charts */
                .chart-container {
                    background: white;
                    border-radius: 15px;
                    padding: 20px;
                    margin-bottom: 30px;
                }
                
                .chart-title {
                    font-size: 1.1rem;
                    font-weight: bold;
                    margin-bottom: 15px;
                    padding-bottom: 10px;
                    border-bottom: 2px solid #e0e0e0;
                }
                
                .chart-bars {
                    display: flex;
                    align-items: flex-end;
                    gap: 15px;
                    justify-content: center;
                    flex-wrap: wrap;
                }
                
                .bar-item {
                    text-align: center;
                    width: 60px;
                }
                
                .bar {
                    background: linear-gradient(180deg, #667eea, #764ba2);
                    height: 0;
                    min-height: 5px;
                    border-radius: 5px;
                    transition: height 0.5s;
                }
                
                .bar-label {
                    margin-top: 8px;
                    font-size: 0.7rem;
                    color: #666;
                }
                
                /* Tables */
                .table-container {
                    background: white;
                    border-radius: 15px;
                    padding: 20px;
                    overflow-x: auto;
                }
                
                table {
                    width: 100%;
                    border-collapse: collapse;
                }
                
                th, td {
                    padding: 12px;
                    text-align: right;
                    border-bottom: 1px solid #e0e0e0;
                }
                
                th {
                    background: #f8f9fa;
                    font-weight: bold;
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
                
                .btn {
                    display: inline-block;
                    padding: 5px 12px;
                    border-radius: 8px;
                    text-decoration: none;
                    font-size: 0.8rem;
                    cursor: pointer;
                    border: none;
                }
                
                .btn-sm { padding: 3px 8px; font-size: 0.7rem; }
                .btn-danger { background: #e53e3e; color: white; }
                .btn-warning { background: #ed8936; color: white; }
                .btn-success { background: #38a169; color: white; }
                .btn-info { background: #4299e1; color: white; }
                
                /* Responsive */
                @media (max-width: 768px) {
                    .sidebar {
                        width: 70px;
                    }
                    .sidebar-header h2, .sidebar-header p, .menu-item span:not(.icon) {
                        display: none;
                    }
                    .main-content {
                        margin-right: 70px;
                    }
                }
            </style>
            <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        </head>
        <body>
            <div class="sidebar">
                <div class="sidebar-header">
                    <h2>👑 DownloadHub</h2>
                    <p>پنل مدیریت</p>
                </div>
                <div class="sidebar-menu">
                    <a href="?action=dashboard" class="menu-item <?php echo $action === 'dashboard' ? 'active' : ''; ?>">
                        <span class="icon">📊</span> <span>داشبورد</span>
                    </a>
                    <a href="?action=users" class="menu-item <?php echo $action === 'users' ? 'active' : ''; ?>">
                        <span class="icon">👥</span> <span>کاربران</span>
                    </a>
                    <a href="?action=requests" class="menu-item <?php echo $action === 'requests' ? 'active' : ''; ?>">
                        <span class="icon">📥</span> <span>درخواست‌ها</span>
                    </a>
                    <a href="?action=queue" class="menu-item <?php echo $action === 'queue' ? 'active' : ''; ?>">
                        <span class="icon">⏳</span> <span>مدیریت صف</span>
                    </a>
                    <a href="?action=cache" class="menu-item <?php echo $action === 'cache' ? 'active' : ''; ?>">
                        <span class="icon">💾</span> <span>مدیریت کش</span>
                    </a>
                    <a href="?action=broadcast" class="menu-item <?php echo $action === 'broadcast' ? 'active' : ''; ?>">
                        <span class="icon">📨</span> <span>ارسال همگانی</span>
                    </a>
                    <a href="?action=tickets" class="menu-item <?php echo $action === 'tickets' ? 'active' : ''; ?>">
                        <span class="icon">🎫</span> <span>تیکت‌ها</span>
                    </a>
                    <a href="?action=subscriptions" class="menu-item <?php echo $action === 'subscriptions' ? 'active' : ''; ?>">
                        <span class="icon">⭐</span> <span>اشتراک‌ها</span>
                    </a>
                    <a href="?action=logs" class="menu-item <?php echo $action === 'logs' ? 'active' : ''; ?>">
                        <span class="icon">📋</span> <span>لاگ خطاها</span>
                    </a>
                    <a href="?action=health" class="menu-item <?php echo $action === 'health' ? 'active' : ''; ?>">
                        <span class="icon">🩺</span> <span>وضعیت سرویس</span>
                    </a>
                    <a href="?action=settings" class="menu-item <?php echo $action === 'settings' ? 'active' : ''; ?>">
                        <span class="icon">⚙️</span> <span>تنظیمات</span>
                    </a>
                    <a href="?action=logout" class="menu-item">
                        <span class="icon">🚪</span> <span>خروج</span>
                    </a>
                </div>
            </div>
            
            <div class="main-content">
                <div class="content-header">
                    <h1>📊 داشبورد مدیریت</h1>
                    <a href="?action=logout" class="logout">🚪 خروج</a>
                    <div style="clear: both;"></div>
                </div>
                
                <!-- Stats Grid -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="value"><?php echo number_format($userStats['total'] ?? 0); ?></div>
                        <div class="label">👥 کل کاربران</div>
                        <div style="font-size:0.7rem; color:#888;">⭐ <?php echo number_format($userStats['premium'] ?? 0); ?> پریمیوم</div>
                    </div>
                    <div class="stat-card">
                        <div class="value"><?php echo number_format($requestStats['total'] ?? 0); ?></div>
                        <div class="label">📥 کل درخواست‌ها</div>
                        <div style="font-size:0.7rem; color:#888;">✅ <?php echo number_format($requestStats['completed'] ?? 0); ?> موفق</div>
                    </div>
                    <div class="stat-card">
                        <div class="value"><?php echo number_format($requestStats['today'] ?? 0); ?></div>
                        <div class="label">📅 درخواست امروز</div>
                    </div>
                    <div class="stat-card">
                        <div class="value"><?php echo number_format($queueStats['pending'] ?? 0); ?></div>
                        <div class="label">⏳ در انتظار پردازش</div>
                        <div style="font-size:0.7rem; color:#888;">🟠 <?php echo number_format($queueStats['processing'] ?? 0); ?> در حال پردازش</div>
                    </div>
                    <div class="stat-card">
                        <div class="value"><?php echo number_format($cacheStats['total'] ?? 0); ?></div>
                        <div class="label">💾 فایل در کش</div>
                    </div>
                </div>
                
                <!-- Chart -->
                <div class="chart-container">
                    <div class="chart-title">📈 روند درخواست‌های ۷ روز اخیر</div>
                    <canvas id="requestsChart" height="100"></canvas>
                </div>
                
                <!-- Last Requests -->
                <div class="table-container">
                    <div class="chart-title">📋 آخرین درخواست‌ها</div>
                    <table>
                        <thead>
                            <tr>
                                <th>شناسه</th>
                                <th>کاربر</th>
                                <th>پلتفرم</th>
                                <th>کیفیت</th>
                                <th>وضعیت</th>
                                <th>زمان</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lastRequests as $req): ?>
                            <tr>
                                <td>#<?php echo $req['id']; ?></td>
                                <td><?php echo htmlspecialchars($req['first_name'] ?? $req['username'] ?? 'کاربر ناشناس'); ?></td>
                                <td><?php echo ucfirst($req['platform']); ?></td>
                                <td><?php echo htmlspecialchars($req['quality']); ?></td>
                                <td><span class="status-badge status-<?php echo $req['status']; ?>"><?php echo $this->getStatusText($req['status']); ?></span></td>
                                <td><?php echo date('Y/m/d H:i', strtotime($req['created_at'])); ?></td>
                                <td>
                                    <a href="?action=requests&view=<?php echo $req['id']; ?>" class="btn btn-info btn-sm">🔍 جزئیات</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Service Status -->
                <div class="stats-grid" style="margin-top:20px;">
                    <div class="stat-card">
                        <div class="value"><?php echo $baleHealth['latency_ms'] ?? '—'; ?> ms</div>
                        <div class="label">📡 API بله</div>
                        <div style="font-size:0.7rem;"><?php echo $baleHealth['status'] === 'healthy' ? '✅ سالم' : '❌ مشکل'; ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="value"><?php echo $githubHealth['latency_ms'] ?? '—'; ?> ms</div>
                        <div class="label">🐙 API گیت‌هاب</div>
                        <div style="font-size:0.7rem;"><?php echo $githubHealth['status'] === 'healthy' ? '✅ سالم' : '⚠️ محدودیت'; ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="value"><?php echo $githubHealth['rate_limit_remaining'] ?? '—'; ?></div>
                        <div class="label">Rate Limit باقیمانده</div>
                    </div>
                </div>
            </div>
            
            <script>
                // نمودار درخواست‌ها
                const ctx = document.getElementById('requestsChart').getContext('2d');
                const dailyData = <?php 
                    $dates = [];
                    $totals = [];
                    $completed = [];
                    foreach ($dailyStats as $stat) {
                        $dates[] = date('Y/m/d', strtotime($stat['date']));
                        $totals[] = $stat['total'];
                        $completed[] = $stat['completed'];
                    }
                    echo json_encode(['dates' => $dates, 'totals' => $totals, 'completed' => $completed]);
                ?>;
                
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: dailyData.dates,
                        datasets: [
                            {
                                label: 'کل درخواست‌ها',
                                data: dailyData.totals,
                                borderColor: '#667eea',
                                backgroundColor: 'rgba(102, 126, 234, 0.1)',
                                fill: true,
                                tension: 0.4
                            },
                            {
                                label: 'درخواست‌های موفق',
                                data: dailyData.completed,
                                borderColor: '#38a169',
                                backgroundColor: 'rgba(56, 161, 105, 0.1)',
                                fill: true,
                                tension: 0.4
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: {
                                position: 'top',
                                rtl: true
                            }
                        }
                    }
                });
                
                // Auto-refresh every 60 seconds
                setTimeout(function() {
                    location.reload();
                }, 60000);
            </script>
        </body>
        </html>
        <?php
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
    
    // ==================== صفحه مدیریت کاربران ====================
    
    /**
     * نمایش صفحه مدیریت کاربران
     */
    private function renderUsersPage() {
        $action = $_GET['subaction'] ?? 'list';
        $userId = $_GET['user_id'] ?? null;
        
        if ($action === 'view' && $userId) {
            $this->renderUserDetail($userId);
            return;
        }
        
        if ($action === 'edit' && $userId) {
            $this->renderUserEdit($userId);
            return;
        }
        
        if ($action === 'delete' && $userId) {
            $this->deleteUser($userId);
            return;
        }
        
        // دریافت لیست کاربران
        $page = (int) ($_GET['page'] ?? 1);
        $limit = 20;
        $offset = ($page - 1) * $limit;
        $search = $_GET['search'] ?? '';
        
        $where = "";
        $params = [];
        if (!empty($search)) {
            $where = "WHERE first_name LIKE ? OR username LIKE ? OR id LIKE ?";
            $params = ["%$search%", "%$search%", "%$search%"];
        }
        
        $users = $this->db->fetchAll("
            SELECT * FROM users 
            $where
            ORDER BY created_at DESC 
            LIMIT $limit OFFSET $offset
        ", $params);
        
        $totalUsers = $this->db->count('users', $where ? substr($where, 6) : '1=1', $params);
        $totalPages = ceil($totalUsers / $limit);
        
        ?>
        <!DOCTYPE html>
        <html dir="rtl" lang="fa">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>مدیریت کاربران - DownloadHub</title>
            <style>
                .search-box {
                    margin-bottom: 20px;
                    display: flex;
                    gap: 10px;
                }
                .search-box input {
                    flex: 1;
                    padding: 10px;
                    border: 1px solid #ddd;
                    border-radius: 8px;
                }
                .search-box button {
                    padding: 10px 20px;
                    background: #667eea;
                    color: white;
                    border: none;
                    border-radius: 8px;
                    cursor: pointer;
                }
                .pagination {
                    display: flex;
                    justify-content: center;
                    gap: 10px;
                    margin-top: 20px;
                }
                .pagination a {
                    padding: 8px 12px;
                    background: white;
                    border: 1px solid #ddd;
                    border-radius: 8px;
                    text-decoration: none;
                    color: #333;
                }
                .pagination a.active {
                    background: #667eea;
                    color: white;
                    border-color: #667eea;
                }
                .user-premium {
                    color: #f59e0b;
                    font-weight: bold;
                }
                .user-free {
                    color: #6b7280;
                }
            </style>
        </head>
        <body>
            <div class="main-content">
                <div class="content-header">
                    <h1>👥 مدیریت کاربران</h1>
                    <a href="?action=logout" class="logout">🚪 خروج</a>
                    <div style="clear: both;"></div>
                </div>
                
                <div class="search-box">
                    <form method="GET" style="display: flex; gap: 10px; width: 100%;">
                        <input type="hidden" name="action" value="users">
                        <input type="text" name="search" placeholder="جستجو بر اساس نام، نام کاربری یا شناسه..." value="<?php echo htmlspecialchars($search); ?>">
                        <button type="submit">🔍 جستجو</button>
                        <?php if (!empty($search)): ?>
                        <a href="?action=users" class="btn btn-info">نمایش همه</a>
                        <?php endif; ?>
                    </form>
                </div>
                
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>شناسه</th>
                                <th>نام</th>
                                <th>نام کاربری</th>
                                <th>نوع حساب</th>
                                <th>امتیاز</th>
                                <th>تاریخ عضویت</th>
                                <th>آخرین فعالیت</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?php echo $user['id']; ?></td>
                                <td><?php echo htmlspecialchars($user['first_name']); ?></td>
                                <td><?php echo $user['username'] ? '@' . htmlspecialchars($user['username']) : '—'; ?></td>
                                <td class="<?php echo $user['is_premium'] ? 'user-premium' : 'user-free'; ?>">
                                    <?php echo $user['is_premium'] ? '⭐ پریمیوم' : '🔹 رایگان'; ?>
                                </td>
                                <td><?php echo number_format($user['total_points'] ?? 0); ?></td>
                                <td><?php echo date('Y/m/d', strtotime($user['created_at'])); ?></td>
                                <td><?php echo $user['last_active_at'] ? date('Y/m/d H:i', strtotime($user['last_active_at'])) : '—'; ?></td>
                                <td>
                                    <a href="?action=users&subaction=view&user_id=<?php echo $user['id']; ?>" class="btn btn-info btn-sm">🔍 مشاهده</a>
                                    <a href="?action=users&subaction=edit&user_id=<?php echo $user['id']; ?>" class="btn btn-warning btn-sm">✏️ ویرایش</a>
                                    <a href="?action=users&subaction=delete&user_id=<?php echo $user['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('آیا از حذف این کاربر اطمینان دارید؟')">🗑 حذف</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center;">هیچ کاربری یافت نشد</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    
                    <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="?action=users&page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>" class="<?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </body>
        </html>
        <?php
    }
    
    /**
     * نمایش جزئیات کاربر
     */
    private function renderUserDetail($userId) {
        $user = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
        if (!$user) {
            echo "<script>alert('کاربر یافت نشد'); window.location.href='?action=users';</script>";
            return;
        }
        
        $requests = $this->db->fetchAll("
            SELECT * FROM queue WHERE user_id = ? ORDER BY created_at DESC LIMIT 20
        ", [$userId]);
        
        ?>
        <!DOCTYPE html>
        <html dir="rtl" lang="fa">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>جزئیات کاربر - DownloadHub</title>
        </head>
        <body>
            <div class="main-content">
                <div class="content-header">
                    <h1>👤 جزئیات کاربر #<?php echo $user['id']; ?></h1>
                    <a href="?action=users" class="logout">↩️ بازگشت</a>
                    <div style="clear: both;"></div>
                </div>
                
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="value"><?php echo htmlspecialchars($user['first_name']); ?></div>
                        <div class="label">نام</div>
                    </div>
                    <div class="stat-card">
                        <div class="value"><?php echo $user['username'] ? '@' . htmlspecialchars($user['username']) : '—'; ?></div>
                        <div class="label">نام کاربری</div>
                    </div>
                    <div class="stat-card">
                        <div class="value"><?php echo $user['is_premium'] ? '⭐ پریمیوم' : '🔹 رایگان'; ?></div>
                        <div class="label">نوع حساب</div>
                    </div>
                    <div class="stat-card">
                        <div class="value"><?php echo number_format($user['total_points'] ?? 0); ?></div>
                        <div class="label">امتیاز کل</div>
                    </div>
                </div>
                
                <div class="table-container">
                    <div class="chart-title">📋 درخواست‌های کاربر</div>
                    <table>
                        <thead>
                            <tr>
                                <th>شناسه</th>
                                <th>پلتفرم</th>
                                <th>کیفیت</th>
                                <th>وضعیت</th>
                                <th>زمان</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requests as $req): ?>
                            <tr>
                                <td>#<?php echo $req['id']; ?></td>
                                <td><?php echo ucfirst($req['platform']); ?></td>
                                <td><?php echo htmlspecialchars($req['quality']); ?></td>
                                <td><span class="status-badge status-<?php echo $req['status']; ?>"><?php echo $this->getStatusText($req['status']); ?></span></td>
                                <td><?php echo date('Y/m/d H:i', strtotime($req['created_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <a href="?action=users&subaction=edit&user_id=<?php echo $user['id']; ?>" class="btn btn-warning">✏️ ویرایش کاربر</a>
                    <?php if (!$user['is_premium']): ?>
                    <a href="?action=subscriptions&subaction=upgrade&user_id=<?php echo $user['id']; ?>" class="btn btn-success">⭐ ارتقا به پریمیوم</a>
                    <?php endif; ?>
                </div>
            </div>
        </body>
        </html>
        <?php
    }
    
    /**
     * نمایش صفحه ویرایش کاربر
     */
    private function renderUserEdit($userId) {
        $user = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
        if (!$user) {
            echo "<script>alert('کاربر یافت نشد'); window.location.href='?action=users';</script>";
            return;
        }
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $isPremium = isset($_POST['is_premium']) ? 1 : 0;
            $totalPoints = (int) $_POST['total_points'];
            
            $this->db->update('users', [
                'is_premium' => $isPremium,
                'total_points' => $totalPoints
            ], 'id = ?', [$userId]);
            
            echo "<script>alert('اطلاعات کاربر با موفقیت به‌روزرسانی شد'); window.location.href='?action=users&subaction=view&user_id={$userId}';</script>";
            return;
        }
        
        ?>
        <!DOCTYPE html>
        <html dir="rtl" lang="fa">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>ویرایش کاربر - DownloadHub</title>
        </head>
        <body>
            <div class="main-content">
                <div class="content-header">
                    <h1>✏️ ویرایش کاربر #<?php echo $user['id']; ?></h1>
                    <a href="?action=users&subaction=view&user_id=<?php echo $user['id']; ?>" class="logout">↩️ بازگشت</a>
                    <div style="clear: both;"></div>
                </div>
                
                <div class="table-container">
                    <form method="POST">
                        <div style="margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px;">👤 نام</label>
                            <input type="text" value="<?php echo htmlspecialchars($user['first_name']); ?>" disabled style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 8px; background: #f5f5f5;">
                        </div>
                        <div style="margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px;">🔹 نوع حساب</label>
                            <label style="display: inline-block; margin-left: 20px;">
                                <input type="checkbox" name="is_premium" value="1" <?php echo $user['is_premium'] ? 'checked' : ''; ?>> ⭐ کاربر پریمیوم
                            </label>
                        </div>
                        <div style="margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px;">🏆 امتیاز</label>
                            <input type="number" name="total_points" value="<?php echo $user['total_points']; ?>" style="width: 200px; padding: 8px; border: 1px solid #ddd; border-radius: 8px;">
                        </div>
                        <div style="margin-top: 20px;">
                            <button type="submit" class="btn btn-success">💾 ذخیره تغییرات</button>
                            <a href="?action=users&subaction=view&user_id=<?php echo $user['id']; ?>" class="btn btn-danger">❌ انصراف</a>
                        </div>
                    </form>
                </div>
            </div>
        </body>
        </html>
        <?php
    }
    
    /**
     * حذف کاربر
     */
    private function deleteUser($userId) {
        // ابتدا درخواست‌های کاربر را حذف کن
        $this->db->delete('queue', 'user_id = ?', [$userId]);
        $this->db->delete('user_states', 'user_id = ?', [$userId]);
        $this->db->delete('support_tickets', 'user_id = ?', [$userId]);
        $this->db->delete('users', 'id = ?', [$userId]);
        
        $this->logger->info("User deleted by admin", ['user_id' => $userId]);
        echo "<script>alert('کاربر با موفقیت حذف شد'); window.location.href='?action=users';</script>";
    }
    
    // ==================== صفحه مدیریت درخواست‌ها ====================
    
    /**
     * نمایش صفحه مدیریت درخواست‌ها
     */
    private function renderRequestsPage() {
        $view = $_GET['view'] ?? null;
        if ($view) {
            $this->renderRequestDetail($view);
            return;
        }
        
        $page = (int) ($_GET['page'] ?? 1);
        $limit = 30;
        $offset = ($page - 1) * $limit;
        $status = $_GET['status'] ?? '';
        $platform = $_GET['platform'] ?? '';
        
        $where = "1=1";
        $params = [];
        
        if (!empty($status)) {
            $where .= " AND status = ?";
            $params[] = $status;
        }
        if (!empty($platform)) {
            $where .= " AND platform = ?";
            $params[] = $platform;
        }
        
        $requests = $this->db->fetchAll("
            SELECT q.*, u.first_name, u.username
            FROM queue q
            LEFT JOIN users u ON q.user_id = u.id
            WHERE $where
            ORDER BY q.created_at DESC
            LIMIT $limit OFFSET $offset
        ", $params);
        
        $totalRequests = $this->db->count('queue', $where, $params);
        $totalPages = ceil($totalRequests / $limit);
        
        ?>
        <!DOCTYPE html>
        <html dir="rtl" lang="fa">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>مدیریت درخواست‌ها - DownloadHub</title>
        </head>
        <body>
            <div class="main-content">
                <div class="content-header">
                    <h1>📥 مدیریت درخواست‌ها</h1>
                    <a href="?action=logout" class="logout">🚪 خروج</a>
                    <div style="clear: both;"></div>
                </div>
                
                <div class="search-box">
                    <form method="GET" style="display: flex; gap: 10px; width: 100%;">
                        <input type="hidden" name="action" value="requests">
                        <select name="status" style="padding: 10px; border-radius: 8px; border: 1px solid #ddd;">
                            <option value="">همه وضعیت‌ها</option>
                            <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>در صف</option>
                            <option value="processing" <?php echo $status === 'processing' ? 'selected' : ''; ?>>در حال پردازش</option>
                            <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>تکمیل شده</option>
                            <option value="failed" <?php echo $status === 'failed' ? 'selected' : ''; ?>>ناموفق</option>
                        </select>
                        <select name="platform" style="padding: 10px; border-radius: 8px; border: 1px solid #ddd;">
                            <option value="">همه پلتفرم‌ها</option>
                            <option value="youtube" <?php echo $platform === 'youtube' ? 'selected' : ''; ?>>YouTube</option>
                            <option value="soundcloud" <?php echo $platform === 'soundcloud' ? 'selected' : ''; ?>>SoundCloud</option>
                            <option value="instagram" <?php echo $platform === 'instagram' ? 'selected' : ''; ?>>Instagram</option>
                            <option value="tiktok" <?php echo $platform === 'tiktok' ? 'selected' : ''; ?>>TikTok</option>
                        </select>
                        <button type="submit">🔍 فیلتر</button>
                        <a href="?action=requests" class="btn btn-info">نمایش همه</a>
                    </form>
                </div>
                
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>شناسه</th>
                                <th>کاربر</th>
                                <th>پلتفرم</th>
                                <th>کیفیت</th>
                                <th>وضعیت</th>
                                <th>کش</th>
                                <th>زمان ثبت</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requests as $req): ?>
                            <tr>
                                <td>#<?php echo $req['id']; ?></td>
                                <td><?php echo htmlspecialchars($req['first_name'] ?? $req['username'] ?? 'کاربر ناشناس'); ?></td>
                                <td><?php echo ucfirst($req['platform']); ?></td>
                                <td><?php echo htmlspecialchars($req['quality']); ?></td>
                                <td><span class="status-badge status-<?php echo $req['status']; ?>"><?php echo $this->getStatusText($req['status']); ?></span></td>
                                <td><?php echo $req['cache_hit'] ? '⚡ بله' : '—'; ?></td>
                                <td><?php echo date('Y/m/d H:i', strtotime($req['created_at'])); ?></td>
                                <td>
                                    <a href="?action=requests&view=<?php echo $req['id']; ?>" class="btn btn-info btn-sm">🔍 جزئیات</a>
                                    <?php if ($req['status'] === 'pending'): ?>
                                    <a href="?action=queue&subaction=cancel&id=<?php echo $req['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('آیا از لغو این درخواست اطمینان دارید؟')">🗑 لغو</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="?action=requests&page=<?php echo $i; ?>&status=<?php echo urlencode($status); ?>&platform=<?php echo urlencode($platform); ?>" class="<?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </body>
        </html>
        <?php
    }
    
    /**
     * نمایش جزئیات یک درخواست
     */
    private function renderRequestDetail($requestId) {
        $request = $this->db->fetchOne("
            SELECT q.*, u.first_name, u.username 
            FROM queue q
            LEFT JOIN users u ON q.user_id = u.id
            WHERE q.id = ?
        ", [$requestId]);
        
        if (!$request) {
            echo "<script>alert('درخواست یافت نشد'); window.location.href='?action=requests';</script>";
            return;
        }
        
        $urls = json_decode($request['urls'], true);
        
        ?>
        <!DOCTYPE html>
        <html dir="rtl" lang="fa">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>جزئیات درخواست - DownloadHub</title>
        </head>
        <body>
            <div class="main-content">
                <div class="content-header">
                    <h1>📋 جزئیات درخواست #<?php echo $request['id']; ?></h1>
                    <a href="?action=requests" class="logout">↩️ بازگشت</a>
                    <div style="clear: both;"></div>
                </div>
                
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="value"><?php echo htmlspecialchars($request['first_name'] ?? 'کاربر ناشناس'); ?></div>
                        <div class="label">کاربر</div>
                    </div>
                    <div class="stat-card">
                        <div class="value"><?php echo ucfirst($request['platform']); ?></div>
                        <div class="label">پلتفرم</div>
                    </div>
                    <div class="stat-card">
                        <div class="value"><?php echo htmlspecialchars($request['quality']); ?></div>
                        <div class="label">کیفیت</div>
                    </div>
                    <div class="stat-card">
                        <div class="value"><?php echo $request['cache_hit'] ? '⚡ بله' : '🔹 خیر'; ?></div>
                        <div class="label">ارسال از کش</div>
                    </div>
                </div>
                
                <div class="table-container">
                    <div class="chart-title">🔗 لینک‌های درخواست</div>
                    <?php foreach ($urls as $url): ?>
                    <p style="padding: 8px; background: #f5f5f5; border-radius: 8px; margin-bottom: 8px; word-break: break-all;">
                        <a href="<?php echo htmlspecialchars($url); ?>" target="_blank"><?php echo htmlspecialchars($url); ?></a>
                    </p>
                    <?php endforeach; ?>
                </div>
                
                <div class="table-container" style="margin-top: 20px;">
                    <div class="chart-title">📊 اطلاعات تکمیلی</div>
                    <table style="width: auto;">
                        <tr><th style="width: 200px;">وضعیت</th><td><span class="status-badge status-<?php echo $request['status']; ?>"><?php echo $this->getStatusText($request['status']); ?></span></td></tr>
                        <tr><th>زمان ثبت</th><td><?php echo date('Y/m/d H:i:s', strtotime($request['created_at'])); ?></td></tr>
                        <?php if ($request['started_at']): ?>
                        <tr><th>زمان شروع</th><td><?php echo date('Y/m/d H:i:s', strtotime($request['started_at'])); ?></td></tr>
                        <?php endif; ?>
                        <?php if ($request['completed_at']): ?>
                        <tr><th>زمان اتمام</th><td><?php echo date('Y/m/d H:i:s', strtotime($request['completed_at'])); ?></td></tr>
                        <?php endif; ?>
                        <?php if ($request['error_message']): ?>
                        <tr><th>خطا</th><td style="color: #c62828;"><?php echo htmlspecialchars($request['error_message']); ?></td></tr>
                        <?php endif; ?>
                        <?php if ($request['workflow_run_id']): ?>
                        <tr><th>شناسه اجرا</th><td><?php echo $request['workflow_run_id']; ?></td></tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </body>
        </html>
        <?php
    }
    
    // ==================== صفحه مدیریت صف ====================
    
    /**
     * نمایش صفحه مدیریت صف
     */
    private function renderQueuePage() {
        $subaction = $_GET['subaction'] ?? '';
        
        if ($subaction === 'clear') {
            $this->queueManager->clearPendingQueue();
            echo "<script>alert('صف با موفقیت پاک شد'); window.location.href='?action=queue';</script>";
            return;
        }
        
        if ($subaction === 'cancel' && isset($_GET['id'])) {
            $this->queueManager->cancelRequest($_GET['id']);
            echo "<script>alert('درخواست با موفقیت لغو شد'); window.location.href='?action=queue';</script>";
            return;
        }
        
        $pendingRequests = $this->db->fetchAll("SELECT * FROM queue WHERE status = 'pending' ORDER BY priority DESC, created_at ASC");
        $processingRequests = $this->db->fetchAll("SELECT * FROM queue WHERE status = 'processing' ORDER BY started_at ASC");
        
        ?>
        <!DOCTYPE html>
        <html dir="rtl" lang="fa">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>مدیریت صف - DownloadHub</title>
        </head>
        <body>
            <div class="main-content">
                <div class="content-header">
                    <h1>⏳ مدیریت صف</h1>
                    <a href="?action=logout" class="logout">🚪 خروج</a>
                    <div style="clear: both;"></div>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <a href="?action=queue&subaction=clear" class="btn btn-danger" onclick="return confirm('آیا از پاک کردن تمام درخواست‌های در انتظار اطمینان دارید؟')">🗑 پاک کردن کل صف</a>
                </div>
                
                <div class="table-container">
                    <div class="chart-title">🟡 در انتظار پردازش (<?php echo count($pendingRequests); ?>)</div>
                    <table>
                        <thead>
                            <tr>
                                <th>شناسه</th>
                                <th>کاربر</th>
                                <th>پلتفرم</th>
                                <th>کیفیت</th>
                                <th>اولویت</th>
                                <th>زمان ثبت</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingRequests as $req): ?>
                            <tr>
                                <td>#<?php echo $req['id']; ?></td>
                                <td><?php echo $req['user_id']; ?></td>
                                <td><?php echo ucfirst($req['platform']); ?></td>
                                <td><?php echo htmlspecialchars($req['quality']); ?></td>
                                <td><?php echo $req['priority'] > 0 ? '⭐ بالا' : '🔹 عادی'; ?></td>
                                <td><?php echo date('H:i', strtotime($req['created_at'])); ?></td>
                                <td>
                                    <a href="?action=queue&subaction=cancel&id=<?php echo $req['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('آیا از لغو این درخواست اطمینان دارید؟')">🗑 لغو</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($pendingRequests)): ?>
                            <tr><td colspan="7" style="text-align: center;">هیچ درخواستی در انتظار نیست</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="table-container" style="margin-top: 20px;">
                    <div class="chart-title">🟠 در حال پردازش (<?php echo count($processingRequests); ?>)</div>
                    <table>
                        <thead>
                            <tr>
                                <th>شناسه</th>
                                <th>کاربر</th>
                                <th>پلتفرم</th>
                                <th>کیفیت</th>
                                <th>زمان شروع</th>
                                <th>مدت زمان</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($processingRequests as $req): ?>
                            <?php $duration = time() - strtotime($req['started_at']); ?>
                            <tr>
                                <td>#<?php echo $req['id']; ?></td>
                                <td><?php echo $req['user_id']; ?></td>
                                <td><?php echo ucfirst($req['platform']); ?></td>
                                <td><?php echo htmlspecialchars($req['quality']); ?></td>
                                <td><?php echo date('H:i', strtotime($req['started_at'])); ?></td>
                                <td><?php echo floor($duration / 60); ?> دقیقه <?php echo $duration % 60; ?> ثانیه</td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($processingRequests)): ?>
                            <tr><td colspan="6" style="text-align: center;">هیچ درخواستی در حال پردازش نیست</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </body>
        </html>
        <?php
    }
    
    // ==================== صفحه مدیریت کش ====================
    
    /**
     * نمایش صفحه مدیریت کش
     */
    private function renderCachePage() {
        $subaction = $_GET['subaction'] ?? '';
        
        if ($subaction === 'sync') {
            $result = $this->cacheChecker->syncCacheIndex();
            echo "<script>alert('همگام‌سازی کش انجام شد. تعداد فایل‌های جدید: {$result['new_added']}'); window.location.href='?action=cache';</script>";
            return;
        }
        
        if ($subaction === 'clear' && isset($_GET['id'])) {
            // حذف یک فایل از کش (فقط از دیتابیس)
            $this->db->delete('cache_index', 'id = ?', [$_GET['id']]);
            echo "<script>alert('فایل از کش حذف شد'); window.location.href='?action=cache';</script>";
            return;
        }
        
        $page = (int) ($_GET['page'] ?? 1);
        $limit = 30;
        $offset = ($page - 1) * $limit;
        $platform = $_GET['platform'] ?? '';
        
        $where = "1=1";
        $params = [];
        if (!empty($platform)) {
            $where .= " AND platform = ?";
            $params[] = $platform;
        }
        
        $cacheFiles = $this->db->fetchAll("
            SELECT * FROM cache_index 
            WHERE $where
            ORDER BY cached_at DESC
            LIMIT $limit OFFSET $offset
        ", $params);
        
        $totalFiles = $this->db->count('cache_index', $where, $params);
        $totalPages = ceil($totalFiles / $limit);
        
        $cacheStats = $this->cacheChecker->getCacheStats();
        
        ?>
        <!DOCTYPE html>
        <html dir="rtl" lang="fa">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>مدیریت کش - DownloadHub</title>
        </head>
        <body>
            <div class="main-content">
                <div class="content-header">
                    <h1>💾 مدیریت کش</h1>
                    <a href="?action=logout" class="logout">🚪 خروج</a>
                    <div style="clear: both;"></div>
                </div>
                
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="value"><?php echo number_format($cacheStats['total']); ?></div>
                        <div class="label">کل فایل‌های کش</div>
                    </div>
                    <?php foreach ($cacheStats['by_platform'] as $plat => $count): ?>
                    <div class="stat-card">
                        <div class="value"><?php echo number_format($count); ?></div>
                        <div class="label"><?php echo ucfirst($plat); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <div style="margin-bottom: 20px; display: flex; gap: 10px;">
                    <a href="?action=cache&subaction=sync" class="btn btn-success">🔄 همگام‌سازی کش با ریپازیتوری</a>
                </div>
                
                <div class="search-box">
                    <form method="GET" style="display: flex; gap: 10px; width: 100%;">
                        <input type="hidden" name="action" value="cache">
                        <select name="platform" style="padding: 10px; border-radius: 8px; border: 1px solid #ddd;">
                            <option value="">همه پلتفرم‌ها</option>
                            <option value="youtube" <?php echo $platform === 'youtube' ? 'selected' : ''; ?>>YouTube</option>
                            <option value="soundcloud" <?php echo $platform === 'soundcloud' ? 'selected' : ''; ?>>SoundCloud</option>
                            <option value="instagram" <?php echo $platform === 'instagram' ? 'selected' : ''; ?>>Instagram</option>
                            <option value="tiktok" <?php echo $platform === 'tiktok' ? 'selected' : ''; ?>>TikTok</option>
                        </select>
                        <button type="submit">🔍 فیلتر</button>
                        <a href="?action=cache" class="btn btn-info">نمایش همه</a>
                    </form>
                </div>
                
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>شناسه</th>
                                <th>پلتفرم</th>
                                <th>سازنده</th>
                                <th>عنوان</th>
                                <th>حجم</th>
                                <th>تاریخ کش</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cacheFiles as $file): ?>
                            <tr>
                                <td>#<?php echo $file['id']; ?></td>
                                <td><?php echo ucfirst($file['platform']); ?></td>
                                <td><?php echo htmlspecialchars(substr($file['creator_name'] ?? 'نامشخص', 0, 30)); ?></td>
                                <td><?php echo htmlspecialchars(substr($file['title'] ?? 'نامشخص', 0, 40)); ?>...</td>
                                <td><?php echo $file['file_size_mb'] ? number_format($file['file_size_mb'], 2) . ' MB' : '—'; ?></td>
                                <td><?php echo date('Y/m/d H:i', strtotime($file['cached_at'])); ?></td>
                                <td>
                                    <a href="?action=cache&subaction=clear&id=<?php echo $file['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('آیا از حذف این فایل از کش اطمینان دارید؟')">🗑 حذف</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="?action=cache&page=<?php echo $i; ?>&platform=<?php echo urlencode($platform); ?>" class="<?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </body>
        </html>
        <?php
    }
    
    // ==================== صفحه مشاهده لاگ‌ها ====================
    
    /**
     * نمایش صفحه لاگ خطاها
     */
    private function renderLogsPage() {
        $subaction = $_GET['subaction'] ?? '';
        
        if ($subaction === 'clear') {
            $this->logger->cleanDatabaseLogs(0);
            echo "<script>alert('لاگ خطاها پاک شد'); window.location.href='?action=logs';</script>";
            return;
        }
        
        if ($subaction === 'download') {
            $this->downloadLogFile();
            return;
        }
        
        $errorStats = $this->logger->getErrorStats(168); // 7 روز
        
        ?>
        <!DOCTYPE html>
        <html dir="rtl" lang="fa">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>لاگ خطاها - DownloadHub</title>
        </head>
        <body>
            <div class="main-content">
                <div class="content-header">
                    <h1>📋 لاگ خطاها</h1>
                    <a href="?action=logout" class="logout">🚪 خروج</a>
                    <div style="clear: both;"></div>
                </div>
                
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="value"><?php echo $errorStats['total_errors'] ?? 0; ?></div>
                        <div class="label">کل خطاهای ۷ روز اخیر</div>
                    </div>
                    <?php foreach (($errorStats['by_level'] ?? []) as $level => $count): ?>
                    <div class="stat-card">
                        <div class="value"><?php echo $count; ?></div>
                        <div class="label"><?php echo $level; ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <div style="margin-bottom: 20px; display: flex; gap: 10px;">
                    <a href="?action=logs&subaction=download" class="btn btn-info">📥 دانلود لاگ فایل</a>
                    <a href="?action=logs&subaction=clear" class="btn btn-danger" onclick="return confirm('آیا از پاک کردن تمام لاگ‌های خطا اطمینان دارید؟')">🗑 پاک کردن لاگ</a>
                    <a href="?action=logs" class="btn btn-success">🔄 بروزرسانی</a>
                </div>
                
                <div class="table-container">
                    <div class="chart-title">آخرین خطاها</div>
                    <table style="width: 100%;">
                        <thead>
                            <tr>
                                <th>زمان</th>
                                <th>سطح</th>
                                <th>پیام</th>
                                <th>کاربر</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (($errorStats['last_errors'] ?? []) as $error): ?>
                            <tr>
                                <td><?php echo date('Y/m/d H:i:s', strtotime($error['created_at'])); ?></td>
                                <td>
                                    <span class="status-badge" style="background: <?php 
                                        echo $error['level'] === 'ERROR' ? '#ffebee' : ($error['level'] === 'CRITICAL' ? '#ffcdd2' : '#fff3e0'); 
                                    ?>; color: <?php 
                                        echo $error['level'] === 'ERROR' ? '#c62828' : ($error['level'] === 'CRITICAL' ? '#b71c1c' : '#f57c00'); 
                                    ?>;">
                                        <?php echo $error['level']; ?>
                                    </span>
                                </td>
                                <td style="word-break: break-all;"><?php echo htmlspecialchars(substr($error['message'], 0, 200)); ?></td>
                                <td><?php echo $error['user_id'] ?? '—'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($errorStats['last_errors'])): ?>
                            <tr><td colspan="4" style="text-align: center;">هیچ خطایی ثبت نشده است</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </body>
        </html>
        <?php
    }
    
    /**
     * دانلود فایل لاگ
     */
    private function downloadLogFile() {
        $logFile = LOGS_PATH . '/app-' . date('Y-m-d') . '.log';
        
        if (file_exists($logFile)) {
            header('Content-Type: text/plain');
            header('Content-Disposition: attachment; filename="downloadhub_log_' . date('Y-m-d') . '.log"');
            readfile($logFile);
            exit;
        } else {
            echo "<script>alert('فایل لاگ وجود ندارد'); window.location.href='?action=logs';</script>";
        }
    }
    
    // ==================== صفحه تنظیمات ====================
    
    /**
     * نمایش صفحه تنظیمات
     */
    private function renderSettingsPage() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // ذخیره تنظیمات در فایل config.php (نیاز به دسترسی write)
            // برای سادگی، تنظیمات در session ذخیره می‌شوند
            $_SESSION['admin_settings'] = [
                'maintenance_mode' => isset($_POST['maintenance_mode']) ? 1 : 0,
                'debug_mode' => isset($_POST['debug_mode']) ? 1 : 0
            ];
            
            // تنظیم حالت تعمیرات
            $maintenanceFile = sys_get_temp_dir() . '/downloadhub_maintenance';
            if (isset($_POST['maintenance_mode'])) {
                file_put_contents($maintenanceFile, time());
            } else {
                if (file_exists($maintenanceFile)) unlink($maintenanceFile);
            }
            
            echo "<script>alert('تنظیمات ذخیره شد');</script>";
        }
        
        $settings = $_SESSION['admin_settings'] ?? [];
        $maintenanceMode = file_exists(sys_get_temp_dir() . '/downloadhub_maintenance');
        
        ?>
        <!DOCTYPE html>
        <html dir="rtl" lang="fa">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>تنظیمات - DownloadHub</title>
        </head>
        <body>
            <div class="main-content">
                <div class="content-header">
                    <h1>⚙️ تنظیمات سیستم</h1>
                    <a href="?action=logout" class="logout">🚪 خروج</a>
                    <div style="clear: both;"></div>
                </div>
                
                <div class="table-container">
                    <form method="POST">
                        <div style="margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 10px;">
                                <input type="checkbox" name="maintenance_mode" value="1" <?php echo $maintenanceMode ? 'checked' : ''; ?>>
                                🔧 حالت تعمیرات (ربات غیرفعال می‌شود)
                            </label>
                            <p style="font-size: 0.8rem; color: #666;">در این حالت، فقط ادمین می‌تواند از ربات استفاده کند.</p>
                        </div>
                        
                        <div style="margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 10px;">
                                <input type="checkbox" name="debug_mode" value="1" <?php echo ($settings['debug_mode'] ?? 0) ? 'checked' : ''; ?>>
                                🐛 حالت دیباگ (نمایش خطاهای دقیق)
                            </label>
                            <p style="font-size: 0.8rem; color: #666;">تنها در صورت نیاز به عیب‌یابی فعال کنید.</p>
                        </div>
                        
                        <div style="margin-top: 20px;">
                            <button type="submit" class="btn btn-success">💾 ذخیره تنظیمات</button>
                        </div>
                    </form>
                </div>
                
                <div class="table-container" style="margin-top: 20px;">
                    <div class="chart-title">ℹ️ اطلاعات سیستم</div>
                    <table style="width: auto;">
                        <tr><th style="width: 200px;">نسخه PHP</th><td><?php echo phpversion(); ?></td></tr>
                        <tr><th>حداکثر زمان اجرا</th><td><?php echo ini_get('max_execution_time'); ?> ثانیه</td></tr>
                        <tr><th>حداکثر حافظه</th><td><?php echo ini_get('memory_limit'); ?></td></tr>
                        <tr><th>مسیر نصب</th><td><?php echo __DIR__; ?></td></tr>
                    </table>
                </div>
            </div>
        </body>
        </html>
        <?php
    }
    
    // ==================== سایر صفحات (ساده شده برای brevity) ====================
    
    private function renderBroadcastPage() {
        echo "<div class='main-content'><div class='content-header'><h1>📨 ارسال همگانی</h1><a href='?action=logout' class='logout'>🚪 خروج</a><div style='clear: both;'></div></div>";
        echo "<div class='table-container'><p>برای ارسال همگانی، از بخش 'ارسال همگانی' در منوی اصلی ربات استفاده کنید.</p>";
        echo "<a href='?action=dashboard' class='btn btn-info'>↩️ بازگشت به داشبورد</a></div></div>";
    }
    
    private function renderTicketsPage() {
        echo "<div class='main-content'><div class='content-header'><h1>🎫 تیکت‌های پشتیبانی</h1><a href='?action=logout' class='logout'>🚪 خروج</a><div style='clear: both;'></div></div>";
        echo "<div class='table-container'><p>برای پاسخ به تیکت‌ها، از بخش 'پشتیبانی' در منوی اصلی ربات استفاده کنید.</p>";
        echo "<a href='?action=dashboard' class='btn btn-info'>↩️ بازگشت به داشبورد</a></div></div>";
    }
    
    private function renderSubscriptionsPage() {
        echo "<div class='main-content'><div class='content-header'><h1>⭐ مدیریت اشتراک‌ها</h1><a href='?action=logout' class='logout'>🚪 خروج</a><div style='clear: both;'></div></div>";
        echo "<div class='table-container'><p>برای مدیریت اشتراک‌ها، از بخش 'مدیریت کاربران' استفاده کنید.</p>";
        echo "<a href='?action=users' class='btn btn-info'>👥 رفتن به مدیریت کاربران</a></div></div>";
    }
    
    private function renderHealthPage() {
        $baleHealth = $this->bale->healthCheck();
        $githubHealth = $this->github->healthCheck();
        $dbHealth = $this->db->healthCheck();
        
        echo "<div class='main-content'>";
        echo "<div class='content-header'><h1>🩺 وضعیت سلامت سرویس‌ها</h1><a href='?action=logout' class='logout'>🚪 خروج</a><div style='clear: both;'></div></div>";
        
        echo "<div class='stats-grid'>";
        echo "<div class='stat-card'><div class='value'>" . ($baleHealth['latency_ms'] ?? '—') . " ms</div><div class='label'>📡 API بله</div><div>" . ($baleHealth['status'] === 'healthy' ? '✅ سالم' : '❌ مشکل') . "</div></div>";
        echo "<div class='stat-card'><div class='value'>" . ($githubHealth['latency_ms'] ?? '—') . " ms</div><div class='label'>🐙 API گیت‌هاب</div><div>" . ($githubHealth['status'] === 'healthy' ? '✅ سالم' : '⚠️ محدودیت') . "</div></div>";
        echo "<div class='stat-card'><div class='value'>" . ($dbHealth['latency_ms'] ?? '—') . " ms</div><div class='label'>🗄️ دیتابیس</div><div>" . ($dbHealth['status'] === 'healthy' ? '✅ سالم' : '❌ مشکل') . "</div></div>";
        echo "</div>";
        
        echo "<div class='table-container'><a href='?action=health' class='btn btn-success'>🔄 بروزرسانی</a></div>";
        echo "<a href='?action=dashboard' class='btn btn-info'>↩️ بازگشت به داشبورد</a>";
        echo "</div>";
    }
}

// ==================== اجرای صفحه ====================
$dashboard = new AdminDashboard();
$dashboard->render();
