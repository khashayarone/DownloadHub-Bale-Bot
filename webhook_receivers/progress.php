<?php
/**
 * webhook_receivers/progress.php - دریافت پیشرفت دانلود از GitHub Actions
 * 
 * این فایل توسط GitHub Action در میانه کار صدا زده می‌شود
 * تا وضعیت پیشرفت را به ربات ارسال کند.
 * 
 * نحوه فراخوانی از اکشن:
 * curl -X POST "https://khashayar.one/downloadhub/webhook_receivers/progress.php" \
 *   -H "Content-Type: application/json" \
 *   -H "X-Progress-Secret: YOUR_SECRET_KEY" \
 *   -d '{"run_id": 123456789, "percent": 45, "stage": "downloading", "message": "در حال دانلود ویدیو..."}'
 * 
 * امنیت: درخواست‌ها با secret key اعتبارسنجی می‌شوند
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Logger.php';
require_once dirname(__DIR__) . '/bale/Client.php';
require_once dirname(__DIR__) . '/core/StateManager.php';

class ProgressReceiver {
    
    private $db;
    private $logger;
    private $bale;
    private $secretKey;
    
    // مراحل مختلف دانلود
    private $stageMessages = [
        'start' => '🚀 شروع فرآیند دانلود...',
        'fetching_metadata' => '🔍 در حال دریافت اطلاعات...',
        'downloading' => '⬇️ در حال دانلود فایل...',
        'converting' => '🔄 در حال تبدیل فرمت...',
        'uploading' => '📤 در حال آپلود به کانال شما...',
        'completed' => '✅ دانلود با موفقیت انجام شد!',
        'failed' => '❌ خطا در دانلود',
        'cached' => '⚡ ارسال از حافظه کش...'
    ];
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->logger = logger();
        $this->bale = bale();
        $this->secretKey = PROGRESS_WEBHOOK_SECRET;
    }
    
    /**
     * اجرای اصلی
     */
    public function run() {
        // 1. اعتبارسنجی متد درخواست
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendResponse(405, ['error' => 'Method not allowed']);
            return;
        }
        
        // 2. اعتبارسنجی secret key
        $headers = getallheaders();
        $receivedSecret = $headers['X-Progress-Secret'] ?? $headers['x-progress-secret'] ?? '';
        
        if ($receivedSecret !== $this->secretKey) {
            $this->logger->warning("Invalid progress webhook secret", [
                'received' => substr($receivedSecret, 0, 10) . '...'
            ]);
            $this->sendResponse(401, ['error' => 'Unauthorized']);
            return;
        }
        
        // 3. دریافت و اعتبارسنجی داده‌ها
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (!$data || !isset($data['run_id'])) {
            $this->sendResponse(400, ['error' => 'Invalid data']);
            return;
        }
        
        $runId = $data['run_id'];
        $percent = isset($data['percent']) ? (int) $data['percent'] : 0;
        $stage = $data['stage'] ?? 'downloading';
        $message = $data['message'] ?? ($this->stageMessages[$stage] ?? 'در حال پردازش...');
        
        // 4. پیدا کردن درخواست مرتبط
        $queueItem = $this->getQueueByRunId($runId);
        
        if (!$queueItem) {
            $this->logger->warning("No queue item found for run_id", ['run_id' => $runId]);
            $this->sendResponse(404, ['error' => 'Queue item not found']);
            return;
        }
        
        // 5. بروزرسانی پیشرفت در دیتابیس
        $this->updateProgress($queueItem['id'], $percent, $stage, $message);
        
        // 6. ارسال به‌روزرسانی به کاربر (ادیت پیام)
        $this->notifyUser($queueItem, $percent, $stage, $message);
        
        // 7. اگر تکمیل شد، لاگ کن
        if ($percent >= 100 || $stage === 'completed') {
            $this->logger->info("Download completed", [
                'queue_id' => $queueItem['id'],
                'run_id' => $runId,
                'user_id' => $queueItem['user_id']
            ]);
        }
        
        // 8. پاسخ موفق به اکشن
        $this->sendResponse(200, ['status' => 'ok', 'message' => 'Progress updated']);
    }
    
    /**
     * پیدا کردن درخواست در صف بر اساس run_id
     * @param int $runId
     * @return array|null
     */
    private function getQueueByRunId($runId) {
        $sql = "SELECT * FROM queue WHERE workflow_run_id = ?";
        return $this->db->fetchOne($sql, [(string) $runId]);
    }
    
    /**
     * بروزرسانی پیشرفت در دیتابیس
     * @param int $queueId
     * @param int $percent
     * @param string $stage
     * @param string $message
     */
    private function updateProgress($queueId, $percent, $stage, $message) {
        $sql = "UPDATE queue SET progress_percent = ?, updated_at = NOW() WHERE id = ?";
        $this->db->execute($sql, [$percent, $queueId]);
        
        // ذخیره stage در temp_data یا لاگ
        $this->logger->debug("Progress updated", [
            'queue_id' => $queueId,
            'percent' => $percent,
            'stage' => $stage,
            'message' => $message
        ]);
    }
    
    /**
     * ارسال به‌روزرسانی به کاربر
     * @param array $queueItem
     * @param int $percent
     * @param string $stage
     * @param string $message
     */
    private function notifyUser($queueItem, $percent, $stage, $message) {
        $userId = $queueItem['user_id'];
        $platform = $queueItem['platform'];
        
        // دریافت last_message_id از state کاربر
        $stateManager = stateManager($userId);
        $lastMessageId = $stateManager->getLastMessageId();
        
        if (!$lastMessageId) {
            // اگر پیام قبلی وجود ندارد، پیام جدید بفرست
            $this->sendNewProgressMessage($userId, $queueItem, $percent, $stage, $message);
            return;
        }
        
        // ساخت نوار پیشرفت
        $progressBar = $this->makeProgressBar($percent);
        
        // آیکون مرحله
        $stageIcon = $this->getStageIcon($stage);
        
        $text = "{$stageIcon} *{$message}*\n\n";
        $text .= "{$progressBar} {$percent}%\n\n";
        $text .= "📊 *وضعیت:* " . $this->getStageText($stage) . "\n";
        $text .= "🎬 *پلتفرم:* " . ucfirst($platform) . "\n";
        $text .= "🆔 *شناسه درخواست:* #{$queueItem['id']}\n\n";
        
        if ($percent < 100 && $stage !== 'completed' && $stage !== 'failed') {
            $text .= "🔄 در حال پردازش... لطفاً شکیبا باشید.";
            
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🔄 بروزرسانی وضعیت', 'callback_data' => "request:track:{$queueItem['workflow_run_id']}"]],
                    [['text' => '🏠 منوی اصلی', 'callback_data' => 'nav:home']]
                ]
            ];
        } elseif ($stage === 'completed' || $percent >= 100) {
            $text .= "✅ *فایل با موفقیت به کانال آرشیو شما ارسال شد!*\n\n";
            $text .= "📁 برای مشاهده فایل به کانال خود مراجعه کنید.";
            
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '📊 مشاهده وضعیت درخواست‌ها', 'callback_data' => 'nav:status']],
                    [['text' => '🏠 منوی اصلی', 'callback_data' => 'nav:home']]
                ]
            ];
        } elseif ($stage === 'failed') {
            $text .= "❌ *متأسفانه دانلود با خطا مواجه شد.*\n\n";
            $text .= "لطفاً دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.";
            
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '↩️ تلاش مجدد', 'callback_data' => 'nav:retry_url']],
                    [['text' => '📞 پشتیبانی', 'callback_data' => 'nav:support']]
                ]
            ];
        } else {
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🔄 بروزرسانی', 'callback_data' => 'status:refresh']],
                    [['text' => '🏠 خانه', 'callback_data' => 'nav:home']]
                ]
            ];
        }
        
        // ادیت پیام موجود
        $this->bale->editMessageText($userId, $lastMessageId, $text, 'Markdown', $keyboard);
        
        // اگر stage completed است، state را ریست کن
        if ($stage === 'completed') {
            $stateManager->setState(StateManager::STATE_MAIN_MENU);
        }
    }
    
    /**
     * ارسال پیام جدید پیشرفت (اگر پیام قبلی وجود نداشته باشد)
     */
    private function sendNewProgressMessage($userId, $queueItem, $percent, $stage, $message) {
        $progressBar = $this->makeProgressBar($percent);
        $stageIcon = $this->getStageIcon($stage);
        
        $text = "{$stageIcon} *{$message}*\n\n";
        $text .= "{$progressBar} {$percent}%\n\n";
        $text .= "🎬 *پلتفرم:* " . ucfirst($queueItem['platform']) . "\n";
        $text .= "🆔 *شناسه درخواست:* #{$queueItem['id']}\n\n";
        $text .= "🔄 در حال پردازش... لطفاً شکیبا باشید.";
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🔄 بروزرسانی وضعیت', 'callback_data' => "request:track:{$queueItem['workflow_run_id']}"]],
                [['text' => '🏠 منوی اصلی', 'callback_data' => 'nav:home']]
            ]
        ];
        
        $result = $this->bale->sendMessage($userId, $text, 'Markdown', null, $keyboard);
        
        if ($result && isset($result['message_id'])) {
            $stateManager = stateManager($userId);
            $stateManager->updateLastMessageId($result['message_id']);
        }
    }
    
    /**
     * ساخت نوار پیشرفت متنی
     * @param int $percent
     * @return string
     */
    private function makeProgressBar($percent) {
        $width = 20;
        $filled = round($width * $percent / 100);
        $empty = $width - $filled;
        
        return '[' . str_repeat('█', $filled) . str_repeat('░', $empty) . ']';
    }
    
    /**
     * دریافت آیکون مرحله
     * @param string $stage
     * @return string
     */
    private function getStageIcon($stage) {
        $icons = [
            'start' => '🚀',
            'fetching_metadata' => '🔍',
            'downloading' => '⬇️',
            'converting' => '🔄',
            'uploading' => '📤',
            'completed' => '✅',
            'failed' => '❌',
            'cached' => '⚡'
        ];
        return $icons[$stage] ?? '⏳';
    }
    
    /**
     * دریافت متن مرحله
     * @param string $stage
     * @return string
     */
    private function getStageText($stage) {
        $texts = [
            'start' => 'آماده‌سازی',
            'fetching_metadata' => 'دریافت اطلاعات',
            'downloading' => 'دانلود فایل',
            'converting' => 'تبدیل فرمت',
            'uploading' => 'آپلود به کانال',
            'completed' => 'تکمیل شده',
            'failed' => 'ناموفق',
            'cached' => 'ارسال از کش'
        ];
        return $texts[$stage] ?? $stage;
    }
    
    /**
     * ارسال پاسخ JSON
     * @param int $statusCode
     * @param array $data
     */
    private function sendResponse($statusCode, $data) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
    }
}

// ==================== اجرا ====================
$receiver = new ProgressReceiver();
$receiver->run();
