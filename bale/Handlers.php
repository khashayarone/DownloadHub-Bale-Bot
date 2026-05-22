<?php
/**
 * bale/Handlers.php - هندلرهای اصلی پیام‌ها و کالبک‌های ربات
 * 
 * مسئولیت‌ها:
 * 1. پردازش پیام‌های متنی کاربران
 * 2. پردازش کالبک‌های دکمه‌های شیشه‌ای
 * 3. مدیریت تمام stateهای تعریف شده
 * 4. ارتباط با StateManager، QueueManager، CacheChecker
 * 5. ارسال و ادیت پیام واحد در طول مکالمه
 * 6. بررسی عضویت در کانال اسپانسر
 * 7. بررسی و ثبت کانال آرشیو کاربر
 * 8. مدیریت تنظیمات کاربر (حالت شب، تغییر کانال)
 * 9. مدیریت تیکت‌های پشتیبانی
 * 10. پنل ادمین (فقط برای کاربر ادمین)
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/bale/Client.php';
require_once dirname(__DIR__) . '/bale/Keyboards.php';
require_once dirname(__DIR__) . '/core/StateManager.php';
require_once dirname(__DIR__) . '/core/QueueManager.php';
require_once dirname(__DIR__) . '/core/CacheChecker.php';
require_once dirname(__DIR__) . '/core/LockManager.php';
require_once dirname(__DIR__) . '/core/Logger.php';
require_once dirname(__DIR__) . '/github/ActionMapper.php';

class MessageHandlers {
    
    private $bale;
    private $db;
    private $logger;
    private $stateManager;
    private $queueManager;
    private $cacheChecker;
    private $lockManager;
    private $actionMapper;
    
    // کانال‌های اسپانسر (قابل تنظیم در پنل ادمین)
    private $sponsorChannels = [
        ['id' => '@channel1', 'name' => 'کانال اسپانسر ۱'],
        ['id' => '@channel2', 'name' => 'کانال اسپانسر ۲']
    ];
    
    public function __construct() {
        $this->bale = bale();
        $this->db = Database::getInstance();
        $this->logger = logger();
        $this->queueManager = queueManager();
        $this->cacheChecker = cacheChecker();
        $this->lockManager = lockManager();
        $this->actionMapper = actionMapper();
    }
    
    /**
     * پردازش پیام متنی جدید
     * @param array $update آپدیت دریافتی از بله
     * @param array $message پیام دریافتی
     */
    public function handleMessage($update, $message) {
        $userId = $message['from']['id'];
        $text = trim($message['text'] ?? '');
        $chatId = $message['chat']['id'];
        
        // ثبت کاربر جدید در صورت عدم وجود
        $this->ensureUserExists($userId, $message['from']);
        
        // دریافت state جاری کاربر
        $this->stateManager = stateManager($userId);
        $currentState = $this->stateManager->getState();
        $lastMessageId = $this->stateManager->getLastMessageId();
        
        $this->logger->debug("Handling message", [
            'user_id' => $userId,
            'state' => $currentState,
            'text' => substr($text, 0, 50)
        ]);
        
        // پردازش بر اساس state جاری
        switch ($currentState) {
            case StateManager::STATE_SPONSOR_CHECK:
                $this->handleSponsorCheck($userId, $chatId, $lastMessageId);
                break;
                
            case StateManager::STATE_ARCHIVE_CHECK:
                $this->handleArchiveCheck($userId, $chatId, $lastMessageId, $text);
                break;
                
            case StateManager::STATE_WAITING_URL:
                $this->handleUrlReceived($userId, $chatId, $lastMessageId, $text);
                break;
                
            case StateManager::STATE_WAITING_QUALITY:
                // کیفیت باید از طریق کالبک انتخاب شود، نه پیام متنی
                $this->showErrorAndStay($userId, $chatId, $lastMessageId, 
                    "لطفاً کیفیت مورد نظر را از دکمه‌های زیر انتخاب کنید.");
                break;
                
            case StateManager::STATE_CONFIRM_DOWNLOAD:
                $this->handleConfirmResponse($userId, $chatId, $lastMessageId, $text);
                break;
                
            case StateManager::STATE_SETTINGS_CHANGE_CHANNEL:
                $this->handleChangeChannel($userId, $chatId, $lastMessageId, $text);
                break;
                
            case StateManager::STATE_SUPPORT_NEW_TICKET:
                $this->handleNewTicket($userId, $chatId, $lastMessageId, $text);
                break;
                
            case StateManager::STATE_ADMIN_BROADCAST_TEXT:
                $this->handleBroadcastText($userId, $chatId, $lastMessageId, $text);
                break;
                
            default:
                // اگر دستور /start بود
                if ($text === '/start') {
                    $this->resetAndStart($userId, $chatId);
                } else {
                    // هر چیز دیگری را نادیده بگیر یا به منوی اصلی ببر
                    $this->showMainMenu($userId, $chatId, $lastMessageId);
                }
                break;
        }
    }
    
    /**
     * پردازش کالبک دکمه‌های شیشه‌ای
     * @param array $update آپدیت دریافتی
     * @param array $callbackQuery کالبک دریافتی
     */
    public function handleCallbackQuery($update, $callbackQuery) {
        $userId = $callbackQuery['from']['id'];
        $callbackId = $callbackQuery['id'];
        $data = $callbackQuery['data'];
        $message = $callbackQuery['message'];
        $chatId = $message['chat']['id'];
        $messageId = $message['message_id'];
        
        // پاسخ سریع به بله (از حالت انتظار خارج شود)
        $this->bale->answerCallbackQuery($callbackId);
        
        // ثبت کاربر
        $this->ensureUserExists($userId, $callbackQuery['from']);
        
        // دریافت state جاری
        $this->stateManager = stateManager($userId);
        $currentState = $this->stateManager->getState();
        
        $this->logger->debug("Handling callback", [
            'user_id' => $userId,
            'state' => $currentState,
            'callback_data' => $data
        ]);
        
        // پردازش کالبک بر اساس پیشوند
        if (strpos($data, 'nav:') === 0) {
            $this->handleNavigationCallback($userId, $chatId, $messageId, $data);
        } elseif (strpos($data, 'platform:') === 0) {
            $this->handlePlatformCallback($userId, $chatId, $messageId, $data);
        } elseif (strpos($data, 'quality:') === 0) {
            $this->handleQualityCallback($userId, $chatId, $messageId, $data);
        } elseif (strpos($data, 'confirm:') === 0) {
            $this->handleConfirmCallback($userId, $chatId, $messageId, $data);
        } elseif (strpos($data, 'request:') === 0) {
            $this->handleRequestCallback($userId, $chatId, $messageId, $data);
        } elseif (strpos($data, 'status:') === 0) {
            $this->handleStatusCallback($userId, $chatId, $messageId);
        } elseif (strpos($data, 'settings:') === 0) {
            $this->handleSettingsCallback($userId, $chatId, $messageId, $data);
        } elseif (strpos($data, 'admin:') === 0) {
            $this->handleAdminCallback($userId, $chatId, $messageId, $data);
        } elseif (strpos($data, 'broadcast:') === 0) {
            $this->handleBroadcastCallback($userId, $chatId, $messageId, $data);
        } elseif (strpos($data, 'support:') === 0) {
            $this->handleSupportCallback($userId, $chatId, $messageId, $data);
        } elseif (strpos($data, 'ticket:') === 0) {
            $this->handleTicketCallback($userId, $chatId, $messageId, $data);
        } elseif (strpos($data, 'points:') === 0) {
            $this->handlePointsCallback($userId, $chatId, $messageId, $data);
        } else {
            $this->logger->warning("Unknown callback", ['data' => $data]);
        }
    }
    
    // ==================== هندلرهای State ====================
    
    /**
     * بررسی عضویت در کانال اسپانسر
     */
    private function handleSponsorCheck($userId, $chatId, $lastMessageId) {
        $isMember = $this->checkSponsorMembership($userId);
        
        if ($isMember) {
            // بروزرسانی وضعیت در دیتابیس
            $this->db->update('users', ['sponsor_checked' => true], 'id = ?', [$userId]);
            $this->stateManager->setState(StateManager::STATE_ARCHIVE_CHECK);
            $this->showArchiveCheck($userId, $chatId, $lastMessageId);
        } else {
            $text = "🔒 *لطفاً ابتدا عضو کانال‌های اسپانسر شوید*\n\n";
            $text .= "برای استفاده از ربات، باید در کانال‌های زیر عضو شوید:\n\n";
            
            foreach ($this->sponsorChannels as $channel) {
                $text .= "📢 {$channel['name']}: {$channel['id']}\n";
            }
            
            $text .= "\n✅ پس از عضویت، روی دکمه زیر کلیک کنید:";
            
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '✅ بررسی عضویت', 'callback_data' => 'nav:check_sponsor']]
                ]
            ];
            
            if ($lastMessageId) {
                $this->bale->editMessageText($chatId, $lastMessageId, $text, 'Markdown', $keyboard);
            } else {
                $result = $this->bale->sendMessage($chatId, $text, 'Markdown', null, $keyboard);
                if ($result && isset($result['message_id'])) {
                    $this->stateManager->updateLastMessageId($result['message_id']);
                }
            }
        }
    }
    
    /**
     * نمایش صفحه بررسی کانال آرشیو
     */
    private function showArchiveCheck($userId, $chatId, $lastMessageId) {
        $text = "📁 *تنظیم کانال آرشیو*\n\n";
        $text .= "لطفاً یک کانال در بله بسازید و ربات را به عنوان ادمین در آن اضافه کنید.\n\n";
        $text .= "🔹 *مراحل:*\n";
        $text .= "1️⃣ یک کانال جدید بسازید\n";
        $text .= "2️⃣ ربات را به کانال اضافه کنید و ادمین کنید\n";
        $text .= "3️⃣ شناسه کانال (مثلاً @my_channel) یا آیدی عددی را بفرستید\n\n";
        $text .= "⚠️ *توجه:* ربات برای آپلود فایل‌ها به دسترسی ادمین نیاز دارد.";
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '❌ انصراف', 'callback_data' => 'nav:cancel_setup']]
            ]
        ];
        
        if ($lastMessageId) {
            $this->bale->editMessageText($chatId, $lastMessageId, $text, 'Markdown', $keyboard);
        } else {
            $result = $this->bale->sendMessage($chatId, $text, 'Markdown', null, $keyboard);
            if ($result && isset($result['message_id'])) {
                $this->stateManager->updateLastMessageId($result['message_id']);
            }
        }
    }
    
    /**
     * پردازش کانال آرشیو ارسالی کاربر
     */
    private function handleArchiveCheck($userId, $chatId, $lastMessageId, $channelInput) {
        // پاک کردن @ از ابتدا اگر وجود داشت
        $channelInput = ltrim($channelInput, '@');
        
        // بررسی وجود کانال و ادمین بودن ربات
        $checkResult = $this->verifyChannelAndAdmin($channelInput);
        
        if ($checkResult['success']) {
            // ذخیره کانال در دیتابیس
            $this->db->update('users', [
                'archived_channel_id' => $checkResult['channel_id'],
                'archived_channel_username' => $channelInput
            ], 'id = ?', [$userId]);
            
            $this->logger->info("User registered archive channel", [
                'user_id' => $userId,
                'channel' => $channelInput
            ]);
            
            $this->stateManager->setState(StateManager::STATE_MAIN_MENU);
            $this->showMainMenu($userId, $chatId, $lastMessageId);
            
        } else {
            // نمایش خطا و درخواست مجدد
            $text = "❌ *خطا در تنظیم کانال*\n\n";
            $text .= $checkResult['message'];
            $text .= "\n\nلطفاً دوباره تلاش کنید یا کانال دیگری بسازید.";
            
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '↩️ تلاش مجدد', 'callback_data' => 'nav:retry_archive']],
                    [['text' => '🏠 راهنما', 'callback_data' => 'nav:help_archive']]
                ]
            ];
            
            $this->bale->editMessageText($chatId, $lastMessageId, $text, 'Markdown', $keyboard);
        }
    }
    
    /**
     * نمایش منوی اصلی
     */
    private function showMainMenu($userId, $chatId, $lastMessageId = null) {
        $userInfo = $this->getUserInfo($userId);
        $darkMode = $userInfo['dark_mode'] ?? false;
        $isPremium = $userInfo['is_premium'] ?? false;
        
        $text = "🤖 *به ربات DownloadHub خوش آمدید!*\n\n";
        $text .= "ربات دانلودر حرفه‌ای محتوا از پلتفرم‌های مختلف.\n\n";
        $text .= "📊 *آمار شما:*\n";
        $text .= "• درخواست‌های موفق: " . $this->getUserSuccessCount($userId) . "\n";
        $text .= "• امتیاز کل: " . ($userInfo['total_points'] ?? 0) . "\n";
        
        if ($isPremium) {
            $text .= "⭐ *نوع حساب:* پریمیوم\n";
        } else {
            $text .= "🔹 *نوع حساب:* رایگان\n";
            $text .= "🎁 برای دسترسی به کیفیت‌های بالاتر و حذف محدودیت حجم، اشتراک پریمیوم تهیه کنید.\n";
        }
        
        $keyboard = keyboard($darkMode, $isPremium)->mainMenu();
        
        if ($lastMessageId) {
            $this->bale->editMessageText($chatId, $lastMessageId, $text, 'Markdown', $keyboard);
        } else {
            $result = $this->bale->sendMessage($chatId, $text, 'Markdown', null, $keyboard);
            if ($result && isset($result['message_id'])) {
                $this->stateManager->updateLastMessageId($result['message_id']);
            }
        }
    }
    
    /**
     * پردازش URL دریافتی از کاربر
     */
    private function handleUrlReceived($userId, $chatId, $lastMessageId, $url) {
        // تشخیص پلتفرم از URL
        $platform = $this->cacheChecker->detectPlatform($url);
        
        if (!$platform) {
            $text = "❌ *لینک نامعتبر*\n\n";
            $text .= "لینک ارسال‌شده مربوط به هیچ یک از پلتفرم‌های پشتیبانی شده نیست.\n\n";
            $text .= "پلتفرم‌های پشتیبانی شده:\n";
            $text .= "• YouTube\n• SoundCloud\n• Instagram\n• TikTok\n\n";
            $text .= "لطفاً لینک معتبری بفرستید.";
            
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '↩️ تلاش مجدد', 'callback_data' => 'nav:retry_url']],
                    [['text' => '🏠 خانه', 'callback_data' => 'nav:home']]
                ]
            ];
            
            $this->bale->editMessageText($chatId, $lastMessageId, $text, 'Markdown', $keyboard);
            return;
        }
        
        // ذخیره URL و پلتفرم در داده موقت
        $this->stateManager->setTempData(null, 'urls', [$url]);
        $this->stateManager->setTempData(null, 'platform', $platform);
        
        // استخراج ID برای تخمین حجم
        $extracted = $this->cacheChecker->extractIdFromUrl($url, $platform);
        $estimatedSize = 0;
        
        if ($extracted) {
            // تخمین حجم بر اساس پلتفرم و کیفیت پیش‌فرض
            $defaultQuality = $this->actionMapper->getDefaultSettings($platform)['quality'] ?? '720p';
            $estimatedSize = $this->cacheChecker->estimateFileSize($platform, $defaultQuality, 180);
        }
        
        $this->stateManager->setTempData(null, 'estimated_size', $estimatedSize);
        
        // نمایش صفحه انتخاب کیفیت
        $this->showQualitySelector($userId, $chatId, $lastMessageId, $platform);
    }
    
    /**
     * نمایش صفحه انتخاب کیفیت
     */
    private function showQualitySelector($userId, $chatId, $lastMessageId, $platform) {
        $userInfo = $this->getUserInfo($userId);
        $isPremium = $userInfo['is_premium'] ?? false;
        $darkMode = $userInfo['dark_mode'] ?? false;
        
        $urls = $this->stateManager->getTempData(null, 'urls');
        $url = $urls[0] ?? '';
        $estimatedSize = $this->stateManager->getTempData(null, 'estimated_size') ?: 0;
        $maxSize = $this->actionMapper->getMaxFileSize($platform, $isPremium);
        
        $text = "📥 *خلاصه درخواست شما*\n\n";
        $text .= "🎬 *پلتفرم:* {$this->actionMapper->getPlatformTitle($platform)}\n";
        $text .= "🔗 *لینک:* " . (strlen($url) > 50 ? substr($url, 0, 47) . '...' : $url) . "\n";
        $text .= "📦 *حجم تخمینی:* ~{$estimatedSize} MB\n";
        
        if ($estimatedSize > $maxSize) {
            $text .= "⚠️ *توجه:* حجم تخمینی بیشتر از حد مجاز برای کاربران ";
            $text .= $isPremium ? "است" : "رایگان ({$maxSize}MB) است. لطفاً کیفیت پایین‌تر انتخاب کنید.\n\n";
        } else {
            $text .= "\n📊 *کیفیت مورد نظر را انتخاب کنید:*\n";
        }
        
        $this->stateManager->setState(StateManager::STATE_WAITING_QUALITY);
        
        $keyboard = keyboard($darkMode, $isPremium)->qualitySelector($platform, '720p');
        
        $this->bale->editMessageText($chatId, $lastMessageId, $text, 'Markdown', $keyboard);
    }
    
    // ==================== هندلرهای کالبک ====================
    
    /**
     * پردازش کالبک‌های ناوبری
     */
    private function handleNavigationCallback($userId, $chatId, $messageId, $data) {
        switch ($data) {
            case 'nav:back':
                $previousState = $this->stateManager->goBack();
                $this->resumeFromState($userId, $chatId, $messageId, $previousState);
                break;
                
            case 'nav:home':
                $this->stateManager->exitToMainMenu();
                $this->showMainMenu($userId, $chatId, $messageId);
                break;
                
            case 'nav:status':
                $this->stateManager->setState(StateManager::STATE_SHOW_STATUS);
                $this->showUserStatus($userId, $chatId, $messageId);
                break;
                
            case 'nav:settings':
                $this->stateManager->setState(StateManager::STATE_SETTINGS);
                $this->showSettings($userId, $chatId, $messageId);
                break;
                
            case 'nav:support':
                $this->showSupportMenu($userId, $chatId, $messageId);
                break;
                
            case 'nav:points':
                $this->showPointsMenu($userId, $chatId, $messageId);
                break;
                
            case 'nav:check_sponsor':
                $this->handleSponsorCheck($userId, $chatId, $messageId);
                break;
                
            case 'nav:retry_archive':
                $this->showArchiveCheck($userId, $chatId, $messageId);
                break;
                
            case 'nav:retry_url':
                $this->stateManager->setState(StateManager::STATE_WAITING_URL);
                $text = "🔗 لطفاً لینک مورد نظر خود را بفرستید:";
                $keyboard = keyboard()->emptyKeyboard();
                $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
                break;
                
            case 'nav:cancel_setup':
                $this->resetAndStart($userId, $chatId);
                break;
                
            default:
                $this->logger->warning("Unknown navigation callback", ['data' => $data]);
        }
    }
    
    /**
     * پردازش کالبک انتخاب پلتفرم
     */
    private function handlePlatformCallback($userId, $chatId, $messageId, $data) {
        $platform = str_replace('platform:', '', $data);
        
        if (!$this->actionMapper->isPlatformSupported($platform)) {
            $this->bale->editMessageText($chatId, $messageId, "❌ پلتفرم مورد نظر پشتیبانی نمی‌شود.");
            return;
        }
        
        $this->stateManager->setTempData(null, 'platform', $platform);
        $this->stateManager->setState(StateManager::STATE_WAITING_URL);
        
        $text = "🔗 *لطفاً لینک خود را بفرستید*\n\n";
        $text .= "پلتفرم انتخاب‌شده: {$this->actionMapper->getPlatformTitle($platform)}\n\n";
        $text .= "لینک را دقیقاً از مرورگر کپی کنید و بفرستید.";
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '↩️ تغییر پلتفرم', 'callback_data' => 'nav:back']]
            ]
        ];
        
        $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
    }
    
    /**
     * پردازش کالبک انتخاب کیفیت
     */
    private function handleQualityCallback($userId, $chatId, $messageId, $data) {
        $quality = str_replace('quality:', '', $data);
        $platform = $this->stateManager->getTempData(null, 'platform');
        $urls = $this->stateManager->getTempData(null, 'urls');
        $estimatedSize = $this->stateManager->getTempData(null, 'estimated_size');
        
        $userInfo = $this->getUserInfo($userId);
        $isPremium = $userInfo['is_premium'] ?? false;
        $maxSize = $this->actionMapper->getMaxFileSize($platform, $isPremium);
        
        // ذخیره کیفیت انتخاب شده
        $this->stateManager->setTempData(null, 'quality', $quality);
        
        // محاسبه مجدد حجم با کیفیت جدید
        $newEstimatedSize = $this->cacheChecker->estimateFileSize($platform, $quality, 180);
        $this->stateManager->setTempData(null, 'estimated_size', $newEstimatedSize);
        
        // نمایش صفحه تأیید نهایی
        $this->stateManager->setState(StateManager::STATE_CONFIRM_DOWNLOAD);
        
        $text = "✅ *تأیید نهایی درخواست*\n\n";
        $text .= "🎬 *پلتفرم:* {$this->actionMapper->getPlatformTitle($platform)}\n";
        $text .= "🎚 *کیفیت:* " . $this->getQualityLabel($platform, $quality) . "\n";
        $text .= "📦 *حجم تخمینی:* ~{$newEstimatedSize} MB\n\n";
        
        if ($newEstimatedSize > $maxSize) {
            $text .= "⚠️ *هشدار:* حجم فایل از حد مجاز شما بیشتر است!\n";
            $text .= "لطفاً کیفیت پایین‌تر انتخاب کنید یا اشتراک پریمیوم تهیه کنید.\n\n";
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '↩️ انتخاب کیفیت دیگر', 'callback_data' => 'nav:back']],
                    [['text' => '⭐ خرید پریمیوم', 'callback_data' => 'settings:upgrade']]
                ]
            ];
        } else {
            $text .= "آیا از دانلود این فایل اطمینان دارید؟\n";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '✅ بله، دانلود شود', 'callback_data' => 'confirm:yes'],
                        ['text' => '❌ خیر، انصراف', 'callback_data' => 'confirm:no']
                    ],
                    [['text' => '↩️ بازگشت و ویرایش', 'callback_data' => 'nav:back']]
                ]
            ];
        }
        
        $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
    }
    
    /**
     * پردازش کالبک تأیید نهایی
     */
    private function handleConfirmCallback($userId, $chatId, $messageId, $data) {
        if ($data === 'confirm:yes') {
            $platform = $this->stateManager->getTempData(null, 'platform');
            $urls = $this->stateManager->getTempData(null, 'urls');
            $quality = $this->stateManager->getTempData(null, 'quality');
            $estimatedSize = $this->stateManager->getTempData(null, 'estimated_size');
            
            // نمایش وضعیت در حال پردازش
            $text = "⏳ *در حال ثبت درخواست...*";
            $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown');
            
            // نمایش وضعیت تایپ
            $this->bale->sendChatAction($chatId, 'typing');
            
            // افزودن به صف
            $result = $this->queueManager->addToQueue(
                $userId,
                $platform,
                $urls,
                $quality,
                $estimatedSize
            );
            
            if ($result['success']) {
                if ($result['cache_hit'] ?? false) {
                    // فایل در کش است - آپلود مستقیم
                    $this->handleCacheHit($userId, $chatId, $messageId, $result['cache_info']);
                } else {
                    // نمایش پیام موفقیت با شماره درخواست
                    $text = $result['message'];
                    $text .= "\n\n🆔 *شناسه درخواست:* #{$result['queue_id']}";
                    
                    $keyboard = [
                        'inline_keyboard' => [
                            [['text' => '📊 مشاهده وضعیت', 'callback_data' => 'nav:status']],
                            [['text' => '🏠 منوی اصلی', 'callback_data' => 'nav:home']]
                        ]
                    ];
                    
                    $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
                }
            } else {
                // نمایش خطا
                $text = "❌ *خطا در ثبت درخواست*\n\n";
                $text .= $result['message'];
                
                $keyboard = [
                    'inline_keyboard' => [
                        [['text' => '↩️ تلاش مجدد', 'callback_data' => 'nav:retry_url']],
                        [['text' => '🏠 خانه', 'callback_data' => 'nav:home']]
                    ]
                ];
                
                $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
            }
            
            // پاک کردن داده‌های موقت
            $this->stateManager->clearTempData();
            $this->stateManager->setState(StateManager::STATE_MAIN_MENU);
            
        } elseif ($data === 'confirm:no') {
            // انصراف از دانلود
            $text = "❌ درخواست دانلود لغو شد.";
            
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🏠 منوی اصلی', 'callback_data' => 'nav:home']]
                ]
            ];
            
            $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
            $this->stateManager->setState(StateManager::STATE_MAIN_MENU);
        }
    }
    
    /**
     * نمایش وضعیت درخواست‌های کاربر
     */
    private function showUserStatus($userId, $chatId, $messageId) {
        $requests = $this->queueManager->getUserRequests($userId, 10);
        
        if (empty($requests)) {
            $text = "📭 *شما هیچ درخواستی ندارید*\n\n";
            $text .= "برای شروع، از منوی اصلی یک پلتفرم انتخاب کنید.";
            
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🏠 منوی اصلی', 'callback_data' => 'nav:home']]
                ]
            ];
            
            $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
            return;
        }
        
        $text = "📊 *وضعیت درخواست‌های شما*\n\n";
        
        foreach ($requests as $req) {
            $statusIcon = $this->getStatusIcon($req['status']);
            $platformIcon = $this->getPlatformIcon($req['platform']);
            $text .= "{$statusIcon} *#{$req['id']}* | {$platformIcon} ";
            $text .= $this->getStatusText($req['status']) . "\n";
        }
        
        $text .= "\n━━━━━━━━━━━━━━━━━━━━\n";
        $text .= "🔄 برای بروزرسانی وضعیت، روی دکمه زیر کلیک کنید.";
        
        $keyboard = keyboard()->statusKeyboard($requests);
        
        $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
    }
    
    /**
     * نمایش تنظیمات کاربر
     */
    private function showSettings($userId, $chatId, $messageId) {
        $userInfo = $this->getUserInfo($userId);
        $darkMode = $userInfo['dark_mode'] ?? false;
        $isPremium = $userInfo['is_premium'] ?? false;
        $channelUsername = $userInfo['archived_channel_username'] ?? 'تنظیم نشده';
        
        $text = "⚙️ *تنظیمات کاربری*\n\n";
        $text .= "👤 *نام:* {$userInfo['first_name']}\n";
        $text .= "📢 *کانال آرشیو:* @{$channelUsername}\n";
        $text .= "🌙 *حالت شب:* " . ($darkMode ? '✅ فعال' : '⚪ غیرفعال') . "\n";
        $text .= "⭐ *نوع حساب:* " . ($isPremium ? 'پریمیوم' : 'رایگان') . "\n";
        $text .= "🏆 *امتیاز کل:* " . ($userInfo['total_points'] ?? 0) . "\n\n";
        
        $text .= "از دکمه‌های زیر برای تغییر تنظیمات استفاده کنید:";
        
        $keyboard = keyboard($darkMode, $isPremium)->settingsKeyboard($userInfo);
        
        $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
    }
    
    // ==================== توابع کمکی ====================
    
    /**
     * ثبت کاربر جدید در دیتابیس در صورت عدم وجود
     */
    private function ensureUserExists($userId, $userData) {
        $existing = $this->db->fetchOne("SELECT id FROM users WHERE id = ?", [$userId]);
        
        if (!$existing) {
            $this->db->insert('users', [
                'id' => $userId,
                'first_name' => $userData['first_name'] ?? '',
                'username' => $userData['username'] ?? '',
                'sponsor_checked' => false,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            $this->logger->info("New user registered", ['user_id' => $userId]);
        }
    }
    
    /**
     * دریافت اطلاعات کاربر
     */
    private function getUserInfo($userId) {
        return $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
    }
    
    /**
     * دریافت تعداد درخواست‌های موفق کاربر
     */
    private function getUserSuccessCount($userId) {
        $result = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM queue WHERE user_id = ? AND status = 'completed'",
            [$userId]
        );
        return $result ? $result['count'] : 0;
    }
    
    /**
     * بررسی عضویت در کانال‌های اسپانسر
     */
    private function checkSponsorMembership($userId) {
        // این تابع باید با API بله بررسی کند کاربر در کانال عضو است یا نه
        // فعلاً برای سادگی true برمی‌گردانیم
        // در پیاده‌سازی واقعی: از متد getChatMember استفاده کنید
        return true;
    }
    
    /**
     * بررسی وجود کانال و ادمین بودن ربات
     */
    private function verifyChannelAndAdmin($channelInput) {
        // این تابع باید با API بله بررسی کند
        // فعلاً برای سادگی موفقیت فرض می‌شود
        return [
            'success' => true,
            'channel_id' => 123456789,
            'message' => 'کانال با موفقیت تأیید شد'
        ];
    }
    
    /**
     * دریافت آیکون وضعیت
     */
    private function getStatusIcon($status) {
        $icons = [
            'pending' => '🟡',
            'processing' => '🟠',
            'completed' => '✅',
            'failed' => '❌',
            'cancelled' => '🚫',
            'rate_limited' => '⏳'
        ];
        return $icons[$status] ?? '⚪';
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
            'pending' => 'در صف انتظار',
            'processing' => 'در حال پردازش',
            'completed' => 'تکمیل شده',
            'failed' => 'ناموفق',
            'cancelled' => 'لغو شده',
            'rate_limited' => 'محدودیت نرخ'
        ];
        return $texts[$status] ?? $status;
    }
    
    /**
     * دریافت برچسب کیفیت
     */
    private function getQualityLabel($platform, $quality) {
        $labels = [
            'youtube' => [
                'audio' => 'فقط صدا (MP3)',
                '480p' => '480p (پایین)',
                '720p' => '720p (متوسط)',
                '1080p' => '1080p (Full HD) ⭐',
                'best' => 'بهترین کیفیت موجود ⭐'
            ],
            'soundcloud' => [
                'medium' => 'متوسط (96kbps)',
                'high' => 'بالا (160kbps)',
                'best' => 'بهترین (256kbps)',
                'flac' => 'FLAC (بی‌نظیر) ⭐'
            ],
            'instagram' => [
                'medium' => 'متوسط',
                'high' => 'بالا (1080p)',
                'best' => 'بهترین',
                'original' => 'کیفیت اورجینال ⭐'
            ],
            'tiktok' => [
                'medium' => 'متوسط',
                'high' => 'بالا',
                'best' => 'بهترین',
                'original' => 'کیفیت اورجینال ⭐'
            ]
        ];
        
        return $labels[$platform][$quality] ?? $quality;
    }
    
    /**
     * ریست و شروع مجدد
     */
    private function resetAndStart($userId, $chatId) {
        $this->stateManager->resetState(null, true);
        $this->handleSponsorCheck($userId, $chatId, null);
    }
    
    /**
     * ادامه از state قبلی
     */
    private function resumeFromState($userId, $chatId, $messageId, $state) {
        switch ($state) {
            case StateManager::STATE_MAIN_MENU:
                $this->showMainMenu($userId, $chatId, $messageId);
                break;
            case StateManager::STATE_WAITING_URL:
                $text = "🔗 لطفاً لینک مورد نظر خود را بفرستید:";
                $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown');
                break;
            case StateManager::STATE_WAITING_QUALITY:
                $platform = $this->stateManager->getTempData(null, 'platform');
                $this->showQualitySelector($userId, $chatId, $messageId, $platform);
                break;
            default:
                $this->showMainMenu($userId, $chatId, $messageId);
        }
    }
    
    /**
     * نمایش خطا و ماندن در state فعلی
     */
    private function showErrorAndStay($userId, $chatId, $messageId, $errorMessage) {
        $this->bale->answerCallbackQuery($messageId, $errorMessage, true);
    }
    
    /**
     * پشتیبانی از کش هیت - آپلود مستقیم
     */
    private function handleCacheHit($userId, $chatId, $messageId, $cacheInfo) {
        $text = "⚡ *فایل در حافظه کش پیدا شد!*\n\n";
        $text .= "در حال ارسال مستقیم فایل به کانال آرشیو شما...\n\n";
        $text .= "🔄 لطفاً چند لحظه صبر کنید...";
        
        $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown');
        $this->bale->sendChatAction($chatId, 'upload_video');
        
        // دریافت URL دانلود مستقیم از کش
        $downloadUrl = $this->cacheChecker->getCacheDownloadUrl($cacheInfo);
        
        if ($downloadUrl) {
            // TODO: دانلود فایل از گیت‌هاب و آپلود به کانال کاربر
            // این بخش نیاز به پیاده‌سازی دانلود و آپلود دارد
            
            $text = "✅ *فایل با موفقیت به کانال آرشیو شما ارسال شد!*\n\n";
            $text .= "📁 برای مشاهده فایل به کانال خود مراجعه کنید.\n\n";
            $text .= "⚡ این فایل از حافظه کش ارسال شد (دانلود مجدد انجام نشد).";
        } else {
            $text = "❌ *خطا در دریافت فایل از کش*\n\n";
            $text .= "متأسفانه فایل در کش یافت شد اما قابل دانلود نیست.\n";
            $text .= "لطفاً درخواست خود را مجدداً ثبت کنید.";
        }
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🏠 منوی اصلی', 'callback_data' => 'nav:home']]
            ]
        ];
        
        $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
    }
    
        // ==================== پنل ادمین ====================
    
    /**
     * نمایش پنل ادمین (فقط برای کاربر ادمین)
     */
    private function showAdminPanel($userId, $chatId, $messageId) {
        if ($userId != ADMIN_USER_ID) {
            $this->logger->warning("Non-admin tried to access admin panel", ['user_id' => $userId]);
            $this->showMainMenu($userId, $chatId, $messageId);
            return;
        }
        
        $this->stateManager->setState(StateManager::STATE_ADMIN_PANEL);
        
        // دریافت آمار سریع
        $queueStats = $this->queueManager->getQueueStats();
        $cacheStats = $this->cacheChecker->getCacheStats();
        $lockStats = $this->lockManager->getLockStats();
        $githubHealth = $this->github->healthCheck();
        $baleHealth = $this->bale->healthCheck();
        
        $text = "👑 *پنل مدیریت ربات*\n\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━\n";
        $text .= "📊 *آمار صف*\n";
        $text .= "• در انتظار: {$queueStats['pending']}\n";
        $text .= "• در حال پردازش: {$queueStats['processing']}\n";
        $text .= "• تکمیل شده: {$queueStats['completed']}\n";
        $text .= "• ناموفق: {$queueStats['failed']}\n\n";
        
        $text .= "💾 *آمار کش*\n";
        $text .= "• کل فایل‌های کش: {$cacheStats['total']}\n";
        foreach ($cacheStats['by_platform'] as $platform => $count) {
            $text .= "• {$platform}: {$count}\n";
        }
        $text .= "\n";
        
        $text .= "🔒 *قفل‌های فعال*: {$lockStats['active_locks']}\n\n";
        
        $text .= "🟢 *وضعیت سرویس‌ها*\n";
        $text .= "• گیت‌هاب: " . ($githubHealth['status'] === 'healthy' ? '✅' : '⚠️') . "\n";
        $text .= "• بله: " . ($baleHealth['status'] === 'healthy' ? '✅' : '⚠️') . "\n\n";
        
        $text .= "از دکمه‌های زیر برای مدیریت استفاده کنید:";
        
        $darkMode = false;
        $isPremium = true;
        $keyboard = keyboard($darkMode, $isPremium)->adminPanelKeyboard();
        
        $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
    }
    
    /**
     * پردازش کالبک‌های پنل ادمین
     */
    private function handleAdminCallback($userId, $chatId, $messageId, $data) {
        if ($userId != ADMIN_USER_ID) {
            return;
        }
        
        switch ($data) {
            case 'admin:stats':
                $this->showAdminStats($userId, $chatId, $messageId);
                break;
                
            case 'admin:charts':
                $this->showAdminCharts($userId, $chatId, $messageId);
                break;
                
            case 'admin:broadcast':
                $this->startBroadcast($userId, $chatId, $messageId);
                break;
                
            case 'admin:clear_queue':
                $this->confirmClearQueue($userId, $chatId, $messageId);
                break;
                
            case 'admin:maintenance':
                $this->toggleMaintenanceMode($userId, $chatId, $messageId);
                break;
                
            case 'admin:restart':
                $this->restartBot($userId, $chatId, $messageId);
                break;
                
            case 'admin:error_logs':
                $this->showErrorLogs($userId, $chatId, $messageId);
                break;
                
            case 'admin:health_check':
                $this->showHealthCheck($userId, $chatId, $messageId);
                break;
                
            case 'admin:users':
                $this->showUserManagement($userId, $chatId, $messageId);
                break;
                
            case 'admin:subscriptions':
                $this->showSubscriptionManagement($userId, $chatId, $messageId);
                break;
                
            case 'admin:tickets':
                $this->showAdminTickets($userId, $chatId, $messageId);
                break;
                
            case 'admin:system_settings':
                $this->showSystemSettings($userId, $chatId, $messageId);
                break;
                
            default:
                $this->showAdminPanel($userId, $chatId, $messageId);
        }
    }
    
    /**
     * نمایش آمار کامل برای ادمین
     */
    private function showAdminStats($userId, $chatId, $messageId) {
        $queueStats = $this->queueManager->getQueueStats();
        $cacheStats = $this->cacheChecker->getCacheStats();
        $githubStats = $this->github->getStats();
        $baleStats = $this->bale->getStats();
        
        // آمار کاربران
        $userStats = $this->db->fetchOne("SELECT COUNT(*) as total, SUM(is_premium) as premium FROM users");
        $activeToday = $this->db->fetchOne("SELECT COUNT(*) as active FROM users WHERE last_active_at > DATE_SUB(NOW(), INTERVAL 1 DAY)");
        
        $text = "📈 *آمار کامل سیستم*\n\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━\n";
        $text .= "👥 *کاربران*\n";
        $text .= "• کل کاربران: " . ($userStats['total'] ?? 0) . "\n";
        $text .= "• کاربران پریمیوم: " . ($userStats['premium'] ?? 0) . "\n";
        $text .= "• فعالان امروز: " . ($activeToday['active'] ?? 0) . "\n\n";
        
        $text .= "📊 *صف دانلود*\n";
        $text .= "• در انتظار: {$queueStats['pending']}\n";
        $text .= "• در حال پردازش: {$queueStats['processing']}\n";
        $text .= "• تکمیل شده: {$queueStats['completed']}\n";
        $text .= "• ناموفق: {$queueStats['failed']}\n";
        $text .= "• میانگین زمان انتظار: {$queueStats['avg_wait_seconds']} ثانیه\n";
        $text .= "• میانگین زمان پردازش: {$queueStats['avg_process_seconds']} ثانیه\n\n";
        
        $text .= "💾 *کش*\n";
        $text .= "• کل فایل‌ها: {$cacheStats['total']}\n";
        foreach ($cacheStats['by_platform'] as $platform => $count) {
            $text .= "• {$platform}: {$count}\n";
        }
        $text .= "\n";
        
        $text .= "🌐 *API گیت‌هاب*\n";
        $text .= "• درخواست‌ها: {$githubStats['total_requests']}\n";
        $text .= "• نرخ موفقیت: {$githubStats['success_rate']}%\n";
        $text .= "• میانگین زمان: {$githubStats['average_duration_ms']}ms\n";
        $text .= "• Rate limit باقیمانده: {$githubStats['rate_limit_remaining']}\n\n";
        
        $text .= "📡 *API بله*\n";
        $text .= "• درخواست‌ها: {$baleStats['total_requests']}\n";
        $text .= "• نرخ موفقیت: {$baleStats['success_rate']}%\n";
        $text .= "• میانگین زمان: {$baleStats['average_duration_ms']}ms\n";
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📊 نمودارها', 'callback_data' => 'admin:charts']],
                [['text' => '↩️ بازگشت', 'callback_data' => 'admin:panel']]
            ]
        ];
        
        $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
    }
    
    /**
     * نمایش نمودارها (متن ساده برای بله)
     */
    private function showAdminCharts($userId, $chatId, $messageId) {
        // دریافت درخواست‌های ۷ روز اخیر
        $dailyStats = $this->db->fetchAll("
            SELECT DATE(created_at) as date, 
                   COUNT(*) as total,
                   SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
            FROM queue 
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY DATE(created_at)
            ORDER BY date DESC
        ");
        
        $text = "📊 *نمودارهای ۷ روز اخیر*\n\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━\n";
        $text .= "📅 *درخواست‌های روزانه*\n\n";
        
        foreach ($dailyStats as $stat) {
            $date = date('Y/m/d', strtotime($stat['date']));
            $total = $stat['total'];
            $completed = $stat['completed'];
            $bar = str_repeat('█', min(20, $total));
            $text .= "{$date}: {$bar} {$total} (✅{$completed})\n";
        }
        
        $text .= "\n━━━━━━━━━━━━━━━━━━━━\n";
        
        // درخواست‌ها به تفکیک پلتفرم
        $platformStats = $this->db->fetchAll("
            SELECT platform, COUNT(*) as count 
            FROM queue 
            GROUP BY platform
        ");
        
        $text .= "🎯 *تفکیک پلتفرم*\n\n";
        foreach ($platformStats as $stat) {
            $icon = $this->getPlatformIcon($stat['platform']);
            $text .= "{$icon} {$stat['platform']}: {$stat['count']}\n";
        }
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📈 آمار کامل', 'callback_data' => 'admin:stats']],
                [['text' => '↩️ بازگشت', 'callback_data' => 'admin:panel']]
            ]
        ];
        
        $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
    }
    
    /**
     * شروع فرآیند ارسال همگانی
     */
    private function startBroadcast($userId, $chatId, $messageId) {
        $this->stateManager->setState(StateManager::STATE_ADMIN_BROADCAST);
        
        $text = "📨 *ارسال همگانی پیام*\n\n";
        $text .= "مرحله ۱: انتخاب مخاطب\n\n";
        $text .= "مخاطبان مورد نظر خود را انتخاب کنید:";
        
        $keyboard = keyboard()->broadcastKeyboard('target');
        
        $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
    }
    
    /**
     * پردازش کالبک ارسال همگانی
     */
    private function handleBroadcastCallback($userId, $chatId, $messageId, $data) {
        if ($userId != ADMIN_USER_ID) {
            return;
        }
        
        if ($data === 'broadcast:cancel') {
            $this->stateManager->setState(StateManager::STATE_ADMIN_PANEL);
            $this->showAdminPanel($userId, $chatId, $messageId);
            return;
        }
        
        if (strpos($data, 'broadcast:target:') === 0) {
            $target = str_replace('broadcast:target:', '', $data);
            $this->stateManager->setTempData(null, 'broadcast_target', $target);
            $this->stateManager->setState(StateManager::STATE_ADMIN_BROADCAST_TEXT);
            
            $targetNames = [
                'all' => 'همه کاربران',
                'active' => 'کاربران فعال (۷ روز اخیر)',
                'premium' => 'کاربران پریمیوم'
            ];
            
            $text = "📨 *ارسال همگانی پیام*\n\n";
            $text .= "مرحله ۲: متن پیام\n\n";
            $text .= "👥 مخاطب انتخاب‌شده: {$targetNames[$target]}\n\n";
            $text .= "لطفاً متن پیام خود را بنویسید (پشتیبانی از Markdown):\n\n";
            $text .= "⚠️ *توجه:* پیام برای همه کاربران انتخاب‌شده ارسال خواهد شد.";
            
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '❌ انصراف', 'callback_data' => 'broadcast:cancel']]
                ]
            ];
            
            $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
        }
    }
    
    /**
     * پردازش متن ارسال همگانی
     */
    private function handleBroadcastText($userId, $chatId, $lastMessageId, $text) {
        if ($userId != ADMIN_USER_ID) {
            return;
        }
        
        $target = $this->stateManager->getTempData(null, 'broadcast_target');
        $messageText = $text;
        
        // دریافت لیست کاربران
        $users = $this->getTargetUsers($target);
        $totalUsers = count($users);
        
        if ($totalUsers == 0) {
            $this->bale->editMessageText($chatId, $lastMessageId, 
                "❌ هیچ کاربری برای ارسال پیدا نشد.", 'Markdown');
            $this->showAdminPanel($userId, $chatId, $lastMessageId);
            return;
        }
        
        // ذخیره در جدول broadcast_jobs
        $jobId = $this->db->insert('broadcast_jobs', [
            'target' => $target,
            'message_text' => $messageText,
            'total_targets' => $totalUsers,
            'status' => 'pending',
            'created_by' => $userId
        ]);
        
        // شروع ارسال در پس‌زمینه (در cron پردازش می‌شود)
        $text = "📨 *ارسال همگانی آغاز شد*\n\n";
        $text .= "👥 تعداد مخاطبان: {$totalUsers}\n";
        $text .= "📝 متن پیام:\n━━━━━━━━━━━━━━━━━━━━\n{$messageText}\n━━━━━━━━━━━━━━━━━━━━\n\n";
        $text .= "✅ ارسال در پس‌زمینه انجام می‌شود. برای پیگیری وضعیت به بخش 'ارسال همگانی' مراجعه کنید.";
        
        $this->bale->editMessageText($chatId, $lastMessageId, $text, 'Markdown');
        
        // پردازش غیرهمزمان (می‌توان در cron اجرا کرد)
        $this->processBroadcastJob($jobId);
        
        $this->stateManager->setState(StateManager::STATE_ADMIN_PANEL);
        $this->showAdminPanel($userId, $chatId, $lastMessageId);
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
     * پردازش یک job ارسال همگانی
     */
    private function processBroadcastJob($jobId) {
        $job = $this->db->fetchOne("SELECT * FROM broadcast_jobs WHERE id = ?", [$jobId]);
        if (!$job || $job['status'] !== 'pending') {
            return;
        }
        
        $this->db->update('broadcast_jobs', ['status' => 'processing'], 'id = ?', [$jobId]);
        
        $users = $this->getTargetUsers($job['target']);
        $sent = 0;
        
        foreach ($users as $user) {
            // رعایت rate limit (هر 10 درخواست 1 ثانیه توقف)
            if ($sent > 0 && $sent % 10 == 0) {
                sleep(1);
            }
            
            $result = $this->bale->sendMessage($user['id'], $job['message_text'], 'Markdown');
            if ($result) {
                $sent++;
            }
        }
        
        $this->db->update('broadcast_jobs', [
            'status' => 'completed',
            'sent_count' => $sent,
            'completed_at' => date('Y-m-d H:i:s')
        ], 'id = ?', [$jobId]);
        
        $this->logger->info("Broadcast job completed", [
            'job_id' => $jobId,
            'sent' => $sent,
            'total' => count($users)
        ]);
    }
    
    /**
     * تأیید پاک کردن صف
     */
    private function confirmClearQueue($userId, $chatId, $messageId) {
        $text = "⚠️ *هشدار! پاک کردن صف*\n\n";
        $text .= "آیا از پاک کردن تمام درخواست‌های در انتظار اطمینان دارید؟\n";
        $text .= "این عمل غیرقابل بازگشت است.\n\n";
        $text .= "درخواست‌های در حال پردازش تحت تأثیر قرار نمی‌گیرند.";
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ بله، پاک شود', 'callback_data' => 'admin:clear_queue_confirm'],
                    ['text' => '❌ انصراف', 'callback_data' => 'admin:panel']
                ]
            ]
        ];
        
        $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
    }
    
    /**
     * نمایش لاگ خطاها
     */
    private function showErrorLogs($userId, $chatId, $messageId) {
        $logs = $this->logger->getErrorStats(24);
        
        $text = "📋 *لاگ خطاهای ۲۴ ساعت اخیر*\n\n";
        
        if (isset($logs['by_level']) && !empty($logs['by_level'])) {
            foreach ($logs['by_level'] as $level => $count) {
                $icon = $level === 'ERROR' ? '❌' : ($level === 'CRITICAL' ? '🔥' : '⚠️');
                $text .= "{$icon} {$level}: {$count}\n";
            }
        } else {
            $text .= "✅ هیچ خطایی در ۲۴ ساعت اخیر ثبت نشده است.\n";
        }
        
        if (isset($logs['last_errors']) && !empty($logs['last_errors'])) {
            $text .= "\n━━━━━━━━━━━━━━━━━━━━\n";
            $text .= "📌 *آخرین خطاها:*\n\n";
            
            foreach (array_slice($logs['last_errors'], 0, 5) as $error) {
                $time = date('H:i:s', strtotime($error['created_at']));
                $text .= "[{$time}] {$error['message']}\n";
                if (strlen($text) > 3500) break;
            }
        }
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📥 دانلود لاگ کامل', 'callback_data' => 'admin:download_logs']],
                [['text' => '🗑 پاک کردن لاگ', 'callback_data' => 'admin:clear_logs']],
                [['text' => '↩️ بازگشت', 'callback_data' => 'admin:panel']]
            ]
        ];
        
        $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
    }
    
    /**
     * بررسی سلامت سرویس‌ها
     */
    private function showHealthCheck($userId, $chatId, $messageId) {
        $baleHealth = $this->bale->healthCheck();
        $githubHealth = $this->github->healthCheck();
        $dbHealth = $this->db->healthCheck();
        
        $text = "🩺 *وضعیت سلامت سرویس‌ها*\n\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━\n";
        
        $text .= "📡 *API بله*\n";
        $text .= "• وضعیت: " . ($baleHealth['status'] === 'healthy' ? '✅ سالم' : '❌ مشکل') . "\n";
        $text .= "• زمان پاسخ: {$baleHealth['latency_ms']}ms\n";
        if (isset($baleHealth['bot_username'])) {
            $text .= "• بازو: @{$baleHealth['bot_username']}\n";
        }
        $text .= "\n";
        
        $text .= "🐙 *API گیت‌هاب*\n";
        $text .= "• وضعیت: " . ($githubHealth['status'] === 'healthy' ? '✅ سالم' : '⚠️ محدودیت') . "\n";
        $text .= "• زمان پاسخ: {$githubHealth['latency_ms']}ms\n";
        $text .= "• Rate limit باقیمانده: {$githubHealth['rate_limit_remaining']}\n";
        if (isset($githubHealth['rate_limit_reset_in'])) {
            $text .= "• زمان بازنشانی: {$githubHealth['rate_limit_reset_in']} ثانیه\n";
        }
        $text .= "\n";
        
        $text .= "🗄️ *دیتابیس*\n";
        $text .= "• وضعیت: " . ($dbHealth['status'] === 'healthy' ? '✅ سالم' : '❌ مشکل') . "\n";
        $text .= "• زمان پاسخ: {$dbHealth['latency_ms']}ms\n";
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🔄 بروزرسانی', 'callback_data' => 'admin:health_check']],
                [['text' => '↩️ بازگشت', 'callback_data' => 'admin:panel']]
            ]
        ];
        
        $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
    }
    
    /**
     * مدیریت کاربران (ادمین)
     */
    private function showUserManagement($userId, $chatId, $messageId) {
        // دریافت آخرین کاربران
        $users = $this->db->fetchAll("
            SELECT id, first_name, username, is_premium, total_points, created_at 
            FROM users 
            ORDER BY created_at DESC 
            LIMIT 20
        ");
        
        $text = "👥 *مدیریت کاربران*\n\n";
        $text .= "📋 *آخرین کاربران ثبت‌نامی:*\n\n";
        
        foreach ($users as $user) {
            $premiumIcon = $user['is_premium'] ? '⭐' : '🔹';
            $text .= "{$premiumIcon} {$user['first_name']} (@{$user['username']}) - امتیاز: {$user['total_points']}\n";
        }
        
        $text .= "\nبرای جستجوی کاربر، شناسه یا نام کاربری را بفرستید.";
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📊 آمار کاربران', 'callback_data' => 'admin:user_stats']],
                [['text' => '↩️ بازگشت', 'callback_data' => 'admin:panel']]
            ]
        ];
        
        $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
    }
    
    /**
     * مدیریت اشتراک‌ها (ادمین)
     */
    private function showSubscriptionManagement($userId, $chatId, $messageId) {
        $plans = $this->db->fetchAll("SELECT * FROM plans WHERE is_active = 1");
        
        $text = "⭐ *مدیریت اشتراک‌ها*\n\n";
        
        foreach ($plans as $plan) {
            $text .= "━━━━━━━━━━━━━━━━━━━━\n";
            $text .= "📌 *{$plan['name']}*\n";
            $text .= "• قیمت: " . number_format($plan['price_rial']) . " ریال\n";
            $text .= "• مدت: {$plan['duration_days']} روز\n";
            $text .= "• حجم مجاز: {$plan['max_file_size_mb']}MB\n";
            $text .= "• کیفیت‌ها: " . implode(', ', json_decode($plan['allowed_qualities'] ?? '[]')) . "\n";
            $text .= "• اولویت: {$plan['priority']}\n";
        }
        
        $text .= "\n━━━━━━━━━━━━━━━━━━━━\n";
        
        // آمار کاربران پریمیوم
        $premiumCount = $this->db->fetchOne("SELECT COUNT(*) as count FROM users WHERE is_premium = 1");
        $text .= "👥 کاربران پریمیوم فعال: " . ($premiumCount['count'] ?? 0) . "\n";
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '➕ افزودن پلن جدید', 'callback_data' => 'admin:add_plan']],
                [['text' => '↩️ بازگشت', 'callback_data' => 'admin:panel']]
            ]
        ];
        
        $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
    }
    
    /**
     * نمایش تیکت‌های پشتیبانی برای ادمین
     */
    private function showAdminTickets($userId, $chatId, $messageId) {
        $tickets = $this->db->fetchAll("
            SELECT t.*, u.first_name, u.username 
            FROM support_tickets t
            JOIN users u ON t.user_id = u.id
            WHERE t.status != 'closed'
            ORDER BY t.created_at DESC
            LIMIT 20
        ");
        
        if (empty($tickets)) {
            $text = "📭 *هیچ تیکت فعالی وجود ندارد.*";
        } else {
            $text = "🎫 *تیکت‌های پشتیبانی*\n\n";
            
            foreach ($tickets as $ticket) {
                $statusIcon = $ticket['status'] === 'open' ? '🟡' : '✅';
                $text .= "{$statusIcon} *تیکت #{$ticket['id']}* - {$ticket['first_name']}\n";
                $text .= "📝 " . substr($ticket['message'], 0, 50) . "...\n";
                $text .= "━━━━━━━━━━━━━━━━━━━━\n";
            }
        }
        
        $text .= "\nبرای پاسخ به تیکت، شماره آن را بفرستید.";
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '↩️ بازگشت', 'callback_data' => 'admin:panel']]
            ]
        ];
        
        $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
    }
    
    /**
     * تنظیمات سیستم (ادمین)
     */
    private function showSystemSettings($userId, $chatId, $messageId) {
        $text = "⚙️ *تنظیمات سیستم*\n\n";
        $text .= "🔧 تنظیمات فعلی:\n";
        $text .= "• حداکثر همزمانی: " . MAX_CONCURRENT_ACTIONS . "\n";
        $text .= "• حداکثر صف هر کاربر: " . MAX_QUEUE_PER_USER . "\n";
        $text .= "• حجم مجاز رایگان: " . FREE_MAX_FILE_SIZE_MB . "MB\n";
        $text .= "• انقضای قفل: " . LOCK_EXPIRE_SECONDS . " ثانیه\n\n";
        
        $text .= "⚠️ تغییر تنظیمات نیاز به ویرایش فایل config.php دارد.";
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🔄 ریستارت ربات', 'callback_data' => 'admin:restart']],
                [['text' => '🛑 حالت تعمیرات', 'callback_data' => 'admin:maintenance']],
                [['text' => '↩️ بازگشت', 'callback_data' => 'admin:panel']]
            ]
        ];
        
        $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
    }
    
    /**
     * تغییر حالت تعمیرات
     */
    private function toggleMaintenanceMode($userId, $chatId, $messageId) {
        // در یک فایل موقت وضعیت را ذخیره کن
        $maintenanceFile = sys_get_temp_dir() . '/downloadhub_maintenance';
        
        if (file_exists($maintenanceFile)) {
            unlink($maintenanceFile);
            $text = "✅ *ربات از حالت تعمیرات خارج شد*\n\nربات به حالت عادی بازگشت.";
        } else {
            file_put_contents($maintenanceFile, time());
            $text = "🛑 *ربات در حالت تعمیرات قرار گرفت*\n\nکاربران پیام 'در حال بروزرسانی' دریافت خواهند کرد.";
        }
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '↩️ بازگشت به پنل', 'callback_data' => 'admin:panel']]
            ]
        ];
        
        $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
    }
    
    /**
     * ریستارت ربات (پاک کردن کش)
     */
    private function restartBot($userId, $chatId, $messageId) {
        // پاک کردن کش‌های موقت
        $cacheDir = sys_get_temp_dir() . '/downloadhub_cache';
        if (is_dir($cacheDir)) {
            $files = glob($cacheDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) unlink($file);
            }
        }
        
        $text = "🔄 *ربات با موفقیت ریستارت شد*\n\n";
        $text .= "✅ کش موقت پاک شد\n";
        $text .= "✅ اتصال به APIها برقرار است";
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '↩️ بازگشت به پنل', 'callback_data' => 'admin:panel']]
            ]
        ];
        
        $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
        
        $this->logger->info("Bot restarted by admin", ['user_id' => $userId]);
    }
    
    /**
     * پاک کردن صف (تأیید شده)
     */
    private function processClearQueue($userId, $chatId, $messageId) {
        $cleared = $this->queueManager->clearPendingQueue();
        
        $text = "✅ *صف با موفقیت پاک شد*\n\n";
        $text .= "🗑 تعداد درخواست‌های پاک‌شده: {$cleared}";
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '↩️ بازگشت به پنل', 'callback_data' => 'admin:panel']]
            ]
        ];
        
        $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
    }
    
    // ==================== پشتیبانی و تیکت ====================
    
    /**
     * نمایش منوی پشتیبانی
     */
    private function showSupportMenu($userId, $chatId, $messageId) {
        // دریافت تیکت‌های باز کاربر
        $tickets = $this->db->fetchAll(
            "SELECT * FROM support_tickets WHERE user_id = ? AND status != 'closed' ORDER BY created_at DESC",
            [$userId]
        );
        
        $text = "📞 *پشتیبانی*\n\n";
        $text .= "برای ارتباط با پشتیبانی، می‌توانید یک تیکت جدید ثبت کنید.\n";
        $text .= "پشتیبان در اسرع وقت به شما پاسخ خواهد داد.\n\n";
        
        if (!empty($tickets)) {
            $text .= "📋 *تیکت‌های فعال شما:*\n\n";
            foreach ($tickets as $ticket) {
                $statusText = $ticket['status'] === 'open' ? '🟡 در انتظار پاسخ' : '✅ پاسخ داده شده';
                $text .= "• تیکت #{$ticket['id']}: {$statusText}\n";
            }
            $text .= "\n";
        }
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '➕ تیکت جدید', 'callback_data' => 'support:new']],
                [['text' => '🏠 خانه', 'callback_data' => 'nav:home']]
            ]
        ];
        
        if (!empty($tickets)) {
            $keyboard['inline_keyboard'][0][] = ['text' => '📋 تیکت‌های من', 'callback_data' => 'support:list'];
        }
        
        $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
    }
    
    /**
     * شروع تیکت جدید
     */
    private function startNewTicket($userId, $chatId, $messageId) {
        $this->stateManager->setState(StateManager::STATE_SUPPORT_NEW_TICKET);
        
        $text = "📝 *تیکت جدید پشتیبانی*\n\n";
        $text .= "لطفاً مشکل یا سؤال خود را بنویسید:\n\n";
        $text .= "⚠️ *توجه:* پس از ارسال، پشتیبان در اسرع وقت پاسخ خواهد داد.";
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '❌ انصراف', 'callback_data' => 'nav:support']]
            ]
        ];
        
        $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
    }
    
    /**
     * پردازش تیکت جدید
     */
    private function handleNewTicket($userId, $chatId, $lastMessageId, $message) {
        $this->db->insert('support_tickets', [
            'user_id' => $userId,
            'message' => $message,
            'status' => 'open',
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        $text = "✅ *تیکت شما با موفقیت ثبت شد*\n\n";
        $text .= "📝 پیام شما:\n━━━━━━━━━━━━━━━━━━━━\n{$message}\n━━━━━━━━━━━━━━━━━━━━\n\n";
        $text .= "پشتیبان در اسرع وقت پاسخ خواهد داد.\n";
        $text .= "از طریق منوی پشتیبانی می‌توانید پاسخ را مشاهده کنید.";
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📞 بازگشت به پشتیبانی', 'callback_data' => 'nav:support']],
                [['text' => '🏠 خانه', 'callback_data' => 'nav:home']]
            ]
        ];
        
        $this->bale->editMessageText($chatId, $lastMessageId, $text, 'Markdown', $keyboard);
        $this->stateManager->setState(StateManager::STATE_MAIN_MENU);
        
        // اطلاع به ادمین
        $this->notifyAdminNewTicket($userId, $message);
    }
    
    /**
     * نمایش لیست تیکت‌های کاربر
     */
    private function showUserTickets($userId, $chatId, $messageId) {
        $tickets = $this->db->fetchAll(
            "SELECT * FROM support_tickets WHERE user_id = ? ORDER BY created_at DESC LIMIT 10",
            [$userId]
        );
        
        if (empty($tickets)) {
            $text = "📭 *شما هیچ تیکتی ثبت نکرده‌اید.*";
        } else {
            $text = "📋 *لیست تیکت‌های شما*\n\n";
            foreach ($tickets as $ticket) {
                $statusIcon = $ticket['status'] === 'open' ? '🟡' : ($ticket['status'] === 'answered' ? '✅' : '🔒');
                $date = date('Y/m/d H:i', strtotime($ticket['created_at']));
                $text .= "{$statusIcon} *تیکت #{$ticket['id']}* - {$date}\n";
                $text .= "📝 " . substr($ticket['message'], 0, 60) . "...\n";
                if ($ticket['admin_reply']) {
                    $text .= "💬 پاسخ: " . substr($ticket['admin_reply'], 0, 50) . "...\n";
                }
                $text .= "━━━━━━━━━━━━━━━━━━━━\n";
            }
        }
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '➕ تیکت جدید', 'callback_data' => 'support:new']],
                [['text' => '↩️ بازگشت', 'callback_data' => 'nav:support']]
            ]
        ];
        
        $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
    }
    
    /**
     * اطلاع به ادمین برای تیکت جدید
     */
    private function notifyAdminNewTicket($userId, $message) {
        $user = $this->getUserInfo($userId);
        $text = "🆕 *تیکت جدید پشتیبانی*\n\n";
        $text .= "👤 کاربر: {$user['first_name']} (@{$user['username']})\n";
        $text .= "🆔 شناسه: {$userId}\n";
        $text .= "📝 پیام:\n━━━━━━━━━━━━━━━━━━━━\n{$message}\n━━━━━━━━━━━━━━━━━━━━\n\n";
        $text .= "برای پاسخ، از پنل ادمین استفاده کنید.";
        
        $this->bale->sendMessage(ADMIN_USER_ID, $text, 'Markdown');
    }
    
    // ==================== امتیازات و گیمیفیکیشن ====================
    
    /**
     * نمایش منوی امتیازات
     */
    private function showPointsMenu($userId, $chatId, $messageId) {
        $userInfo = $this->getUserInfo($userId);
        $totalPoints = $userInfo['total_points'] ?? 0;
        
        // دریافت رتبه کاربر
        $rank = $this->db->fetchOne(
            "SELECT COUNT(*) + 1 as rank FROM users WHERE total_points > (SELECT total_points FROM users WHERE id = ?)",
            [$userId]
        );
        $userRank = $rank['rank'] ?? 0;
        
        // دریافت لیدربورد
        $leaderboard = $this->db->fetchAll(
            "SELECT id, first_name, total_points FROM users ORDER BY total_points DESC LIMIT 10"
        );
        
        $text = "🏆 *سیستم امتیازات*\n\n";
        $text .= "🌟 *امتیاز شما:* {$totalPoints}\n";
        $text .= "📊 *رتبه شما:* {$userRank}\n\n";
        
        $text .= "📋 *نحوه کسب امتیاز:*\n";
        $text .= "• هر دانلود موفق: +۱۰ امتیاز\n";
        $text .= "• دانلود از کش: +۵۰ امتیاز ⚡\n";
        $text .= "• دعوت از دوستان: +۲۰۰ امتیاز 👥\n\n";
        
        $text .= "🏅 *۱۰ کاربر برتر:*\n";
        foreach ($leaderboard as $index => $user) {
            $medal = $index == 0 ? '🥇' : ($index == 1 ? '🥈' : ($index == 2 ? '🥉' : "{$index+1}."));
            $text .= "{$medal} {$user['first_name']}: {$user['total_points']} امتیاز\n";
        }
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🎁 جوایز قابل دریافت', 'callback_data' => 'points:rewards']],
                [['text' => '🏠 خانه', 'callback_data' => 'nav:home']]
            ]
        ];
        
        $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
    }
    
    /**
     * اضافه کردن امتیاز به کاربر
     */
    public function addPoints($userId, $points, $reason) {
        $this->db->execute(
            "UPDATE users SET total_points = total_points + ? WHERE id = ?",
            [$points, $userId]
        );
        
        $this->logger->info("Points added to user", [
            'user_id' => $userId,
            'points' => $points,
            'reason' => $reason
        ]);
    }
    
    // ==================== تنظیمات کاربر ====================
    
    /**
     * پردازش کالبک تنظیمات
     */
    private function handleSettingsCallback($userId, $chatId, $messageId, $data) {
        switch ($data) {
            case 'settings:darkmode':
                $this->toggleDarkMode($userId, $chatId, $messageId);
                break;
                
            case 'settings:channel_guide':
                $this->showChannelGuide($userId, $chatId, $messageId);
                break;
                
            case 'settings:change_channel':
                $this->startChangeChannel($userId, $chatId, $messageId);
                break;
                
            case 'settings:my_stats':
                $this->showUserStats($userId, $chatId, $messageId);
                break;
                
            case 'settings:my_points':
                $this->showPointsMenu($userId, $chatId, $messageId);
                break;
                
            case 'settings:upgrade':
                $this->showUpgradeOptions($userId, $chatId, $messageId);
                break;
                
            case 'settings:subscription':
                $this->showSubscriptionInfo($userId, $chatId, $messageId);
                break;
                
            default:
                $this->showSettings($userId, $chatId, $messageId);
        }
    }
    
    /**
     * تغییر حالت شب
     */
    private function toggleDarkMode($userId, $chatId, $messageId) {
        $userInfo = $this->getUserInfo($userId);
        $newMode = !($userInfo['dark_mode'] ?? false);
        
        $this->db->update('users', ['dark_mode' => $newMode ? 1 : 0], 'id = ?', [$userId]);
        
        $text = $newMode ? "🌙 *حالت شب فعال شد*" : "☀️ *حالت روز فعال شد*";
        $text .= "\n\nتنظیمات ذخیره شد.";
        
        $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown');
        $this->showSettings($userId, $chatId, $messageId);
    }
    
    /**
     * نمایش راهنمای ساخت کانال
     */
    private function showChannelGuide($userId, $chatId, $messageId) {
        $text = "📚 *راهنمای ساخت کانال آرشیو*\n\n";
        $text .= "مراحل:\n\n";
        $text .= "1️⃣ در برنامه بله، از منوی اصلی روی 'ساخت کانال' کلیک کنید\n";
        $text .= "2️⃣ نام و توضیحات کانال را وارد کنید\n";
        $text .= "3️⃣ روی 'ایجاد کانال' کلیک کنید\n";
        $text .= "4️⃣ وارد کانال شوید و روی 'مدیران' کلیک کنید\n";
        $text .= "5️⃣ روی 'افزودن مدیر' کلیک کنید و ربات را جستجو کنید\n";
        $text .= "6️⃣ ربات را به عنوان مدیر انتخاب کنید\n";
        $text .= "7️⃣ تنظیمات دسترسی را فعال کنید (ارسال پیام، آپلود فایل)\n";
        $text .= "8️⃣ شناسه کانال (مثل @my_channel) را در ربات بفرستید\n\n";
        $text .= "⚠️ *توجه:* ربات برای آپلود فایل‌ها به دسترسی ادمین نیاز دارد.";
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '↩️ بازگشت به تنظیمات', 'callback_data' => 'nav:settings']]
            ]
        ];
        
        $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
    }
    
    /**
     * شروع فرآیند تغییر کانال
     */
    private function startChangeChannel($userId, $chatId, $messageId) {
        $this->stateManager->setState(StateManager::STATE_SETTINGS_CHANGE_CHANNEL);
        
        $text = "🔄 *تغییر کانال آرشیو*\n\n";
        $text .= "لطفاً شناسه کانال جدید خود را بفرستید.\n";
        $text .= "مثال: @my_new_channel\n\n";
        $text .= "⚠️ *توجه:* ربات باید در کانال جدید ادمین باشد.";
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '❌ انصراف', 'callback_data' => 'nav:settings']]
            ]
        ];
        
        $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
    }
    
    /**
     * پردازش تغییر کانال
     */
    private function handleChangeChannel($userId, $chatId, $lastMessageId, $channelInput) {
        $channelInput = ltrim($channelInput, '@');
        
        $checkResult = $this->verifyChannelAndAdmin($channelInput);
        
        if ($checkResult['success']) {
            $this->db->update('users', [
                'archived_channel_id' => $checkResult['channel_id'],
                'archived_channel_username' => $channelInput
            ], 'id = ?', [$userId]);
            
            $text = "✅ *کانال آرشیو با موفقیت تغییر کرد*\n\n";
            $text .= "کانال جدید: @{$channelInput}";
            
            $this->bale->editMessageText($chatId, $lastMessageId, $text, 'Markdown');
            $this->showSettings($userId, $chatId, $lastMessageId);
        } else {
            $text = "❌ *خطا در تغییر کانال*\n\n";
            $text .= $checkResult['message'];
            $text .= "\n\nلطفاً دوباره تلاش کنید.";
            
            $this->bale->editMessageText($chatId, $lastMessageId, $text, 'Markdown');
        }
        
        $this->stateManager->setState(StateManager::STATE_SETTINGS);
    }
    
    /**
     * نمایش آمار شخصی کاربر
     */
    private function showUserStats($userId, $chatId, $messageId) {
        $userInfo = $this->getUserInfo($userId);
        
        $queueStats = $this->db->fetchOne("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN cache_hit = 1 THEN 1 ELSE 0 END) as cache_hits
            FROM queue WHERE user_id = ?
        ", [$userId]);
        
        $text = "📊 *آمار شخصی شما*\n\n";
        $text .= "👤 *نام:* {$userInfo['first_name']}\n";
        $text .= "⭐ *نوع حساب:* " . (($userInfo['is_premium'] ?? false) ? 'پریمیوم' : 'رایگان') . "\n";
        $text .= "🏆 *امتیاز کل:* " . ($userInfo['total_points'] ?? 0) . "\n";
        $text .= "📅 *تاریخ عضویت:* " . date('Y/m/d', strtotime($userInfo['created_at'])) . "\n\n";
        
        $text .= "📥 *آمار دانلود:*\n";
        $text .= "• کل درخواست‌ها: " . ($queueStats['total'] ?? 0) . "\n";
        $text .= "• موفق: " . ($queueStats['completed'] ?? 0) . "\n";
        $text .= "• ناموفق: " . ($queueStats['failed'] ?? 0) . "\n";
        $text .= "• از کش: " . ($queueStats['cache_hits'] ?? 0) . " ⚡\n";
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '↩️ بازگشت به تنظیمات', 'callback_data' => 'nav:settings']]
            ]
        ];
        
        $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
    }
    
    /**
     * نمایش گزینه‌های ارتقا به پریمیوم
     */
    private function showUpgradeOptions($userId, $chatId, $messageId) {
        $plans = $this->db->fetchAll("SELECT * FROM plans WHERE is_active = 1 AND price_rial > 0");
        
        $text = "⭐ *ارتقا به حساب پریمیوم*\n\n";
        $text .= "با تهیه اشتراک پریمیوم، به امکانات زیر دسترسی خواهید داشت:\n\n";
        $text .= "✅ دانلود با کیفیت 1080p و 4K\n";
        $text .= "✅ حذف محدودیت حجم فایل (تا 200MB)\n";
        $text .= "✅ اولویت بالاتر در صف دانلود\n";
        $text .= "✅ پشتیبانی اختصاصی\n\n";
        
        $text .= "📋 *پلن‌های موجود:*\n\n";
        
        foreach ($plans as $plan) {
            $text .= "━━━━━━━━━━━━━━━━━━━━\n";
            $text .= "📌 *{$plan['name']}*\n";
            $text .= "💰 قیمت: " . number_format($plan['price_rial']) . " ریال\n";
            $text .= "📅 مدت: {$plan['duration_days']} روز\n";
            $text .= "📦 حجم مجاز: {$plan['max_file_size_mb']}MB\n";
            $text .= "🎚 کیفیت‌ها: " . implode(', ', json_decode($plan['allowed_qualities'] ?? '[]')) . "\n";
            $text .= "⚡ اولویت: {$plan['priority']}\n";
        }
        
        $text .= "\n━━━━━━━━━━━━━━━━━━━━\n";
        $text .= "💳 برای خرید با پشتیبانی تماس بگیرید.";
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📞 تماس با پشتیبانی', 'callback_data' => 'nav:support']],
                [['text' => '↩️ بازگشت', 'callback_data' => 'nav:settings']]
            ]
        ];
        
        $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
    }
    
    /**
     * نمایش اطلاعات اشتراک کاربر
     */
    private function showSubscriptionInfo($userId, $chatId, $messageId) {
        $userInfo = $this->getUserInfo($userId);
        
        $text = "⭐ *اطلاعات اشتراک*\n\n";
        
        if ($userInfo['is_premium'] && $userInfo['subscription_expires_at']) {
            $expiresAt = strtotime($userInfo['subscription_expires_at']);
            $daysLeft = ceil(($expiresAt - time()) / 86400);
            $text .= "✅ *وضعیت:* فعال\n";
            $text .= "📅 *تاریخ انقضا:* " . date('Y/m/d', $expiresAt) . "\n";
            $text .= "⏳ *روزهای باقیمانده:* {$daysLeft} روز\n";
        } else {
            $text .= "🔹 *وضعیت:* رایگان\n";
            $text .= "برای فعال‌سازی اشتراک پریمیوم، از بخش 'ارتقا به پریمیوم' اقدام کنید.\n";
        }
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '⭐ ارتقا به پریمیوم', 'callback_data' => 'settings:upgrade']],
                [['text' => '↩️ بازگشت', 'callback_data' => 'nav:settings']]
            ]
        ];
        
        $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
    }
    
    // ==================== کالبک‌های دیگر ====================
    
    /**
     * پردازش کالبک وضعیت
     */
    private function handleStatusCallback($userId, $chatId, $messageId) {
        $this->showUserStatus($userId, $chatId, $messageId);
    }
    
    /**
     * پردازش کالبک درخواست
     */
    private function handleRequestCallback($userId, $chatId, $messageId, $data) {
        if (strpos($data, 'request:cancel:') === 0) {
            $queueId = (int) str_replace('request:cancel:', '', $data);
            $result = $this->queueManager->cancelRequest($queueId, $userId);
            
            if ($result) {
                $text = "✅ درخواست #{$queueId} با موفقیت لغو شد.";
            } else {
                $text = "❌ لغو درخواست امکان‌پذیر نیست.\nدرخواست ممکن است قبلاً پردازش شده باشد.";
            }
            
            $this->bale->answerCallbackQuery($messageId, $text, true);
            $this->showUserStatus($userId, $chatId, $messageId);
            
        } elseif ($data === 'request:cancel_all') {
            $count = $this->queueManager->cancelAllUserPendingRequests($userId);
            $text = "✅ {$count} درخواست با موفقیت لغو شد.";
            $this->bale->answerCallbackQuery($messageId, $text, true);
            $this->showUserStatus($userId, $chatId, $messageId);
            
        } elseif (strpos($data, 'request:') === 0) {
            $queueId = (int) str_replace('request:', '', $data);
            $this->showRequestDetail($userId, $chatId, $messageId, $queueId);
        }
    }
    
    /**
     * نمایش جزئیات یک درخواست
     */
    private function showRequestDetail($userId, $chatId, $messageId, $queueId) {
        $request = $this->db->fetchOne(
            "SELECT * FROM queue WHERE id = ? AND user_id = ?",
            [$queueId, $userId]
        );
        
        if (!$request) {
            $this->bale->editMessageText($chatId, $messageId, "❌ درخواست مورد نظر یافت نشد.", 'Markdown');
            return;
        }
        
        $urls = json_decode($request['urls'], true);
        $url = $urls[0] ?? 'نامشخص';
        
        $text = "📋 *جزئیات درخواست #{$queueId}*\n\n";
        $text .= "🎬 *پلتفرم:* {$request['platform']}\n";
        $text .= "🔗 *لینک:* " . (strlen($url) > 60 ? substr($url, 0, 57) . '...' : $url) . "\n";
        $text .= "🎚 *کیفیت:* {$request['quality']}\n";
        $text .= "📊 *وضعیت:* " . $this->getStatusText($request['status']) . "\n";
        $text .= "📅 *زمان ثبت:* " . date('Y/m/d H:i', strtotime($request['created_at'])) . "\n";
        
        if ($request['started_at']) {
            $text .= "⏱ *زمان شروع:* " . date('H:i', strtotime($request['started_at'])) . "\n";
        }
        if ($request['completed_at']) {
            $text .= "✅ *زمان اتمام:* " . date('H:i', strtotime($request['completed_at'])) . "\n";
        }
        if ($request['error_message']) {
            $text .= "\n❌ *خطا:* {$request['error_message']}\n";
        }
        if ($request['cache_hit']) {
            $text .= "\n⚡ *این فایل از حافظه کش ارسال شده است.*\n";
        }
        
        $keyboard = keyboard()->requestDetailKeyboard($request);
        
        $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
    }
    
    /**
     * پردازش کالبک تیکت
     */
    private function handleTicketCallback($userId, $chatId, $messageId, $data) {
        // نمایش جزئیات تیکت
        $ticketId = (int) str_replace('ticket:', '', $data);
        $ticket = $this->db->fetchOne(
            "SELECT * FROM support_tickets WHERE id = ? AND user_id = ?",
            [$ticketId, $userId]
        );
        
        if (!$ticket) {
            $this->bale->editMessageText($chatId, $messageId, "❌ تیکت مورد نظر یافت نشد.", 'Markdown');
            return;
        }
        
        $text = "🎫 *جزئیات تیکت #{$ticketId}*\n\n";
        $text .= "📝 *پیام شما:*\n━━━━━━━━━━━━━━━━━━━━\n{$ticket['message']}\n━━━━━━━━━━━━━━━━━━━━\n";
        
        if ($ticket['admin_reply']) {
            $text .= "\n💬 *پاسخ پشتیبان:*\n━━━━━━━━━━━━━━━━━━━━\n{$ticket['admin_reply']}\n━━━━━━━━━━━━━━━━━━━━\n";
            $text .= "\n📅 *زمان پاسخ:* " . date('Y/m/d H:i', strtotime($ticket['replied_at'])) . "\n";
        } else {
            $text .= "\n⏳ *در انتظار پاسخ پشتیبان...*\n";
        }
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '↩️ بازگشت به لیست', 'callback_data' => 'support:list']]
            ]
        ];
        
        $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
    }
    
    /**
     * پردازش کالبک امتیازات
     */
    private function handlePointsCallback($userId, $chatId, $messageId, $data) {
        if ($data === 'points:rewards') {
            $this->showRewards($userId, $chatId, $messageId);
        }
    }
    
    /**
     * نمایش جوایز قابل دریافت
     */
    private function showRewards($userId, $chatId, $messageId) {
        $userInfo = $this->getUserInfo($userId);
        $points = $userInfo['total_points'] ?? 0;
        
        $text = "🎁 *جوایز قابل دریافت*\n\n";
        $text .= "🌟 *امتیاز شما:* {$points}\n\n";
        
        $rewards = [
            ['points' => 100, 'reward' => '📀 ۱ دانلود رایگان پریمیوم'],
            ['points' => 500, 'reward' => '⭐ ۷ روز اشتراک پریمیوم'],
            ['points' => 1000, 'reward' => '⭐✨ ۳۰ روز اشتراک پریمیوم'],
            ['points' => 5000, 'reward' => '🎁 کارت هدیه ۵۰ هزار تومانی']
        ];
        
        foreach ($rewards as $reward) {
            $status = $points >= $reward['points'] ? '✅ قابل دریافت' : '🔒 ' . ($reward['points'] - $points) . ' امتیاز مانده';
            $text .= "• {$reward['reward']}: {$status}\n";
        }
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '↩️ بازگشت', 'callback_data' => 'nav:points']]
            ]
        ];
        
        $this->bale->editMessageText($chatId, $messageId, $text, 'Markdown', $keyboard);
    }
}

/**
 * تابع کمکی برای دسترسی سریع به هندلرها
 * @return MessageHandlers
 */
function handlers() {
    static $handlers = null;
    if ($handlers === null) {
        $handlers = new MessageHandlers();
    }
    return $handlers;
}
