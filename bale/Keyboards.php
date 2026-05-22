<?php
/**
 * bale/Keyboards.php - ساخت دکمه‌های شیشه‌ای (Inline Keyboards)
 * 
 * مسئولیت‌ها:
 * 1. تولید کیبوردهای پویا بر اساس state و user_type
 * 2. پشتیبانی از حالت شب (Dark Mode) برای نمایش متن
 * 3. تمام دکمه‌های بازگشت، خانه، وضعیت در یک نوار ثابت
 * 4. کیبوردهای اختصاصی برای هر پلتفرم (YouTube, SoundCloud, Instagram, TikTok)
 * 5. کیبورد انتخاب کیفیت (با توجه به سطح دسترسی کاربر)
 * 6. کیبورد مدیریت صف و درخواست‌ها
 * 7. کیبورد پنل ادمین
 */

require_once dirname(__DIR__) . '/config.php';

class KeyboardBuilder {
    
    private $darkMode = false;
    private $isPremium = false;
    
    /**
     * سازنده
     * @param bool $darkMode حالت شب (تأثیر در استایل متن دکمه‌ها)
     * @param bool $isPremium کاربر پریمیوم (نمایش دکمه‌های بیشتر)
     */
    public function __construct($darkMode = false, $isPremium = false) {
        $this->darkMode = $darkMode;
        $this->isPremium = $isPremium;
    }
    
    /**
     * تنظیم حالت شب
     * @param bool $enabled
     */
    public function setDarkMode($enabled) {
        $this->darkMode = $enabled;
    }
    
    /**
     * نوار پایین ثابت (بازگشت، خانه، وضعیت)
     * این نوار در تمام کیبوردها (به جز مرحله اول) باید نمایش داده شود
     * @param bool $showBack آیا دکمه بازگشت نمایش داده شود؟
     * @param bool $showHome آیا دکمه خانه نمایش داده شود؟
     * @param bool $showStatus آیا دکمه وضعیت نمایش داده شود؟
     * @return array
     */
    private function getBottomNavBar($showBack = true, $showHome = true, $showStatus = true) {
        $row = [];
        
        if ($showBack) {
            $row[] = ['text' => '↩️ بازگشت', 'callback_data' => 'nav:back'];
        }
        
        if ($showHome) {
            $row[] = ['text' => '🏠 خانه', 'callback_data' => 'nav:home'];
        }
        
        if ($showStatus) {
            $row[] = ['text' => '📊 وضعیت', 'callback_data' => 'nav:status'];
        }
        
        return [$row];
    }
    
    /**
     * کیبورد منوی اصلی (بعد از احراز کامل)
     * @return array
     */
    public function mainMenu() {
        $keyboard = [
            [
                ['text' => '🎵 SoundCloud', 'callback_data' => 'platform:soundcloud'],
                ['text' => '📸 Instagram', 'callback_data' => 'platform:instagram']
            ],
            [
                ['text' => '🎬 YouTube', 'callback_data' => 'platform:youtube'],
                ['text' => '🎵 TikTok', 'callback_data' => 'platform:tiktok']
            ],
            [
                ['text' => '📊 وضعیت درخواست‌ها', 'callback_data' => 'nav:status'],
                ['text' => '⚙️ تنظیمات', 'callback_data' => 'nav:settings']
            ],
            [
                ['text' => '📞 پشتیبانی', 'callback_data' => 'nav:support'],
                ['text' => '🏆 امتیازات من', 'callback_data' => 'nav:points']
            ]
        ];
        
        // اگر کاربر پریمیوم است، دکمه ویژه اضافه کن
        if ($this->isPremium) {
            $keyboard[] = [
                ['text' => '⭐ امکانات ویژه پریمیوم', 'callback_data' => 'nav:premium_features']
            ];
        }
        
        return ['inline_keyboard' => $keyboard];
    }
    
    /**
     * کیبورد انتخاب پلتفرم (قبل از دریافت لینک)
     * @param string $platform پلتفرم انتخاب شده (برای هایلایت)
     * @return array
     */
    public function platformSelector($platform = null) {
        $buttons = [
            [
                ['text' => ($platform === 'youtube' ? '✅ ' : '') . '🎬 YouTube', 'callback_data' => 'platform:youtube'],
                ['text' => ($platform === 'soundcloud' ? '✅ ' : '') . '🎵 SoundCloud', 'callback_data' => 'platform:soundcloud']
            ],
            [
                ['text' => ($platform === 'instagram' ? '✅ ' : '') . '📸 Instagram', 'callback_data' => 'platform:instagram'],
                ['text' => ($platform === 'tiktok' ? '✅ ' : '') . '🎵 TikTok', 'callback_data' => 'platform:tiktok']
            ]
        ];
        
        // اضافه کردن نوار پایین
        $buttons = array_merge($buttons, $this->getBottomNavBar(true, true, false));
        
        return ['inline_keyboard' => $buttons];
    }
    
    /**
     * کیبورد انتخاب کیفیت (بر اساس سطح دسترسی کاربر)
     * @param string $platform پلتفرم (تأثیر در گزینه‌های کیفیت)
     * @param string $currentQuality کیفیت فعلی انتخاب شده
     * @return array
     */
    public function qualitySelector($platform, $currentQuality = '720p') {
        $qualities = [];
        
        switch ($platform) {
            case 'youtube':
                $qualities = [
                    ['value' => 'audio', 'label' => '🎧 فقط صدا (MP3)', 'premium' => false],
                    ['value' => '480p', 'label' => '📹 480p (پایین)', 'premium' => false],
                    ['value' => '720p', 'label' => '📹 720p (متوسط - پیش‌فرض)', 'premium' => false]
                ];
                
                // کیفیت‌های پریمیوم
                if ($this->isPremium) {
                    $qualities[] = ['value' => '1080p', 'label' => '📹 1080p (Full HD)', 'premium' => true];
                    $qualities[] = ['value' => 'best', 'label' => '✨ بهترین کیفیت موجود', 'premium' => true];
                }
                break;
                
            case 'soundcloud':
                $qualities = [
                    ['value' => 'best', 'label' => '🎧 بهترین کیفیت (پیش‌فرض)', 'premium' => false],
                    ['value' => 'high', 'label' => '🎧 کیفیت بالا (256kbps)', 'premium' => false],
                    ['value' => 'medium', 'label' => '🎧 کیفیت متوسط (160kbps)', 'premium' => false]
                ];
                
                if ($this->isPremium) {
                    $qualities[] = ['value' => 'flac', 'label' => '💎 FLAC (بی‌نظیر)', 'premium' => true];
                }
                break;
                
            case 'instagram':
            case 'tiktok':
                $qualities = [
                    ['value' => 'best', 'label' => '📱 بهترین کیفیت (پیش‌فرض)', 'premium' => false],
                    ['value' => 'high', 'label' => '📱 کیفیت بالا (1080p)', 'premium' => false]
                ];
                
                if ($this->isPremium) {
                    $qualities[] = ['value' => 'original', 'label' => '✨ کیفیت اورجینال', 'premium' => true];
                }
                break;
        }
        
        $keyboard = [];
        $row = [];
        
        foreach ($qualities as $q) {
            $prefix = ($currentQuality === $q['value']) ? '✅ ' : '';
            $suffix = $q['premium'] ? ' ⭐' : '';
            $text = $prefix . $q['label'] . $suffix;
            
            $row[] = ['text' => $text, 'callback_data' => "quality:{$q['value']}"];
            
            if (count($row) == 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }
        
        if (!empty($row)) {
            $keyboard[] = $row;
        }
        
        // دکمه تأیید و بازگشت
        $keyboard[] = [
            ['text' => '✅ تأیید و ارسال به صف', 'callback_data' => 'confirm:send'],
            ['text' => '↩️ تغییر لینک', 'callback_data' => 'nav:back']
        ];
        
        $keyboard = array_merge($keyboard, $this->getBottomNavBar(false, true, true));
        
        return ['inline_keyboard' => $keyboard];
    }
    
    /**
     * کیبورد تأیید نهایی قبل از ارسال به صف
     * @param array $summary خلاصه درخواست (لینک، کیفیت، حجم تخمینی)
     * @return array
     */
    public function confirmDownload($summary) {
        $keyboard = [
            [
                ['text' => '✅ بله، ارسال شود', 'callback_data' => 'confirm:yes'],
                ['text' => '❌ خیر، انصراف', 'callback_data' => 'confirm:no']
            ],
            [
                ['text' => '↩️ بازگشت و ویرایش', 'callback_data' => 'nav:back']
            ]
        ];
        
        $keyboard = array_merge($keyboard, $this->getBottomNavBar(false, true, true));
        
        return ['inline_keyboard' => $keyboard];
    }
    
    /**
     * کیبورد وضعیت درخواست‌ها (با دکمه‌های مدیریت صف)
     * @param array $requests لیست درخواست‌های کاربر
     * @return array
     */
    public function statusKeyboard($requests) {
        $keyboard = [];
        
        // اگر درخواستی وجود دارد، برای هر کدام دکمه جزئیات
        if (!empty($requests)) {
            $row = [];
            foreach ($requests as $req) {
                $statusIcon = $this->getStatusIcon($req['status']);
                $row[] = ['text' => "{$statusIcon} #{$req['id']}", 'callback_data' => "request:{$req['id']}"];
                
                if (count($row) == 3) {
                    $keyboard[] = $row;
                    $row = [];
                }
            }
            if (!empty($row)) {
                $keyboard[] = $row;
            }
            
            // دکمه لغو همه درخواست‌های در انتظار
            $hasPending = false;
            foreach ($requests as $req) {
                if ($req['status'] === 'pending') {
                    $hasPending = true;
                    break;
                }
            }
            
            if ($hasPending) {
                $keyboard[] = [
                    ['text' => '🗑 لغو تمام درخواست‌های در انتظار', 'callback_data' => 'request:cancel_all']
                ];
            }
        } else {
            $keyboard[] = [
                ['text' => '📭 هیچ درخواستی ندارید', 'callback_data' => 'noop']
            ];
        }
        
        // دکمه بروزرسانی و بازگشت
        $keyboard[] = [
            ['text' => '🔄 بروزرسانی', 'callback_data' => 'status:refresh'],
            ['text' => '🏠 خانه', 'callback_data' => 'nav:home']
        ];
        
        return ['inline_keyboard' => $keyboard];
    }
    
    /**
     * کیبورد جزئیات یک درخواست خاص
     * @param array $request اطلاعات درخواست
     * @return array
     */
    public function requestDetailKeyboard($request) {
        $keyboard = [];
        
        // اگر درخواست در حالت pending است، دکمه لغو نمایش بده
        if ($request['status'] === 'pending') {
            $keyboard[] = [
                ['text' => '🗑 لغو این درخواست', 'callback_data' => "request:cancel:{$request['id']}"]
            ];
        }
        
        // اگر درخواست در حال پردازش است و workflow_run_id دارد، دکمه پیگیری
        if ($request['status'] === 'processing' && !empty($request['workflow_run_id'])) {
            $keyboard[] = [
                ['text' => '🔄 پیگیری وضعیت', 'callback_data' => "request:track:{$request['workflow_run_id']}"]
            ];
        }
        
        $keyboard[] = [
            ['text' => '↩️ بازگشت به لیست', 'callback_data' => 'nav:status'],
            ['text' => '🏠 خانه', 'callback_data' => 'nav:home']
        ];
        
        return ['inline_keyboard' => $keyboard];
    }
    
    /**
     * کیبورد تنظیمات کاربر
     * @param array $userSettings تنظیمات فعلی کاربر
     * @return array
     */
    public function settingsKeyboard($userSettings) {
        $darkModeStatus = ($userSettings['dark_mode'] ?? false) ? '✅ فعال' : '⚪ غیرفعال';
        
        $keyboard = [
            [
                ['text' => "🌙 حالت شب: {$darkModeStatus}", 'callback_data' => 'settings:darkmode']
            ],
            [
                ['text' => '📢 راهنمای ساخت کانال آرشیو', 'callback_data' => 'settings:channel_guide'],
                ['text' => '🔄 تغییر کانال آرشیو', 'callback_data' => 'settings:change_channel']
            ],
            [
                ['text' => '📊 آمار درخواست‌های من', 'callback_data' => 'settings:my_stats'],
                ['text' => '🏆 امتیازات من', 'callback_data' => 'settings:my_points']
            ]
        ];
        
        // اگر کاربر پریمیوم است، دکمه مدیریت اشتراک
        if ($this->isPremium) {
            $keyboard[] = [
                ['text' => '⭐ مدیریت اشتراک', 'callback_data' => 'settings:subscription']
            ];
        } else {
            $keyboard[] = [
                ['text' => '⭐ خرید اشتراک پریمیوم', 'callback_data' => 'settings:upgrade']
            ];
        }
        
        $keyboard[] = [
            ['text' => '🏠 خانه', 'callback_data' => 'nav:home']
        ];
        
        return ['inline_keyboard' => $keyboard];
    }
    
    /**
     * کیبورد پنل ادمین (فقط برای کاربر ادمین نمایش داده شود)
     * @return array
     */
    public function adminPanelKeyboard() {
        $keyboard = [
            [
                ['text' => '📊 آمار کلی', 'callback_data' => 'admin:stats'],
                ['text' => '📈 نمودارها', 'callback_data' => 'admin:charts']
            ],
            [
                ['text' => '📨 ارسال همگانی', 'callback_data' => 'admin:broadcast'],
                ['text' => '🗑 پاک کردن صف', 'callback_data' => 'admin:clear_queue']
            ],
            [
                ['text' => '🛑 توقف ربات', 'callback_data' => 'admin:maintenance'],
                ['text' => '🔄 ریستارت', 'callback_data' => 'admin:restart']
            ],
            [
                ['text' => '📋 لاگ خطاها', 'callback_data' => 'admin:error_logs'],
                ['text' => '🔌 وضعیت APIها', 'callback_data' => 'admin:health_check']
            ],
            [
                ['text' => '🎖 مدیریت کاربران', 'callback_data' => 'admin:users'],
                ['text' => '⭐ مدیریت اشتراک', 'callback_data' => 'admin:subscriptions']
            ],
            [
                ['text' => '📞 تیکت‌های پشتیبانی', 'callback_data' => 'admin:tickets'],
                ['text' => '⚙️ تنظیمات سیستم', 'callback_data' => 'admin:system_settings']
            ],
            [
                ['text' => '🏠 خروج از پنل', 'callback_data' => 'nav:home']
            ]
        ];
        
        return ['inline_keyboard' => $keyboard];
    }
    
    /**
     * کیبورد ارسال همگانی (مراحل مختلف)
     * @param string $step مرحله (target, preview, confirm)
     * @param array $data داده‌های موقت
     * @return array
     */
    public function broadcastKeyboard($step = 'target', $data = []) {
        switch ($step) {
            case 'target':
                return [
                    'inline_keyboard' => [
                        [
                            ['text' => '👥 همه کاربران', 'callback_data' => 'broadcast:target:all'],
                            ['text' => '📱 کاربران فعال (۷ روز)', 'callback_data' => 'broadcast:target:active']
                        ],
                        [
                            ['text' => '⭐ کاربران پریمیوم', 'callback_data' => 'broadcast:target:premium'],
                            ['text' => '❌ انصراف', 'callback_data' => 'broadcast:cancel']
                        ]
                    ]
                ];
                
            case 'confirm':
                return [
                    'inline_keyboard' => [
                        [
                            ['text' => '✅ تأیید و ارسال', 'callback_data' => 'broadcast:confirm'],
                            ['text' => '✏️ ویرایش متن', 'callback_data' => 'broadcast:edit']
                        ],
                        [
                            ['text' => '❌ انصراف', 'callback_data' => 'broadcast:cancel']
                        ]
                    ]
                ];
                
            default:
                return ['inline_keyboard' => []];
        }
    }
    
    /**
     * کیبورد تیکت پشتیبانی
     * @param array $tickets لیست تیکت‌های کاربر
     * @return array
     */
    public function supportKeyboard($tickets = []) {
        $keyboard = [];
        
        if (!empty($tickets)) {
            $row = [];
            foreach ($tickets as $ticket) {
                $statusIcon = ($ticket['status'] === 'open') ? '🟡' : (($ticket['status'] === 'answered') ? '✅' : '🔒');
                $row[] = ['text' => "{$statusIcon} تیکت #{$ticket['id']}", 'callback_data' => "ticket:{$ticket['id']}"];
                
                if (count($row) == 2) {
                    $keyboard[] = $row;
                    $row = [];
                }
            }
            if (!empty($row)) {
                $keyboard[] = $row;
            }
        }
        
        $keyboard[] = [
            ['text' => '➕ تیکت جدید', 'callback_data' => 'support:new'],
            ['text' => '🏠 خانه', 'callback_data' => 'nav:home']
        ];
        
        return ['inline_keyboard' => $keyboard];
    }
    
    /**
     * کیبورد امتیازات (گیمیفیکیشن)
     * @param array $leaderboard رتبه‌بندی کاربران
     * @param int $userRank رتبه کاربر فعلی
     * @return array
     */
    public function pointsKeyboard($leaderboard, $userRank) {
        $keyboard = [];
        
        if (!empty($leaderboard)) {
            $row = [];
            foreach ($leaderboard as $index => $user) {
                $rank = $index + 1;
                $medal = $rank == 1 ? '🥇' : ($rank == 2 ? '🥈' : ($rank == 3 ? '🥉' : "{$rank}."));
                $row[] = ['text' => "{$medal} {$user['first_name']}", 'callback_data' => "leaderboard:user:{$user['id']}"];
                
                if (count($row) == 2) {
                    $keyboard[] = $row;
                    $row = [];
                }
            }
            if (!empty($row)) {
                $keyboard[] = $row;
            }
        }
        
        $keyboard[] = [
            ['text' => '🎁 جوایز قابل دریافت', 'callback_data' => 'points:rewards'],
            ['text' => '🏠 خانه', 'callback_data' => 'nav:home']
        ];
        
        return ['inline_keyboard' => $keyboard];
    }
    
    /**
     * دریافت آیکون وضعیت درخواست
     * @param string $status
     * @return string
     */
    private function getStatusIcon($status) {
        switch ($status) {
            case 'pending':
                return '🟡';
            case 'processing':
                return '🟠';
            case 'completed':
                return '✅';
            case 'failed':
                return '❌';
            case 'cancelled':
                return '🚫';
            case 'rate_limited':
                return '⏳';
            default:
                return '⚪';
        }
    }
    
    /**
     * کیبورد خالی (برای مواقعی که نیازی به دکمه نیست)
     * @return array
     */
    public function emptyKeyboard() {
        return ['inline_keyboard' => []];
    }
    
    /**
     * کیبورد حذف صفحه کلید (برای مواقعی که باید صفحه کلید بسته شود)
     * @return array
     */
    public function removeKeyboard() {
        return ['remove_keyboard' => true];
    }
}

/**
 * تابع کمکی برای دسترسی سریع به KeyboardBuilder
 * @param bool $darkMode
 * @param bool $isPremium
 * @return KeyboardBuilder
 */
function keyboard($darkMode = false, $isPremium = false) {
    return new KeyboardBuilder($darkMode, $isPremium);
}

/**
 * تابع کمکی برای ساخت سریع یک دکمه ساده
 * @param string $text
 * @param string $callbackData
 * @return array
 */
function makeButton($text, $callbackData) {
    return ['text' => $text, 'callback_data' => $callbackData];
}

/**
 * تابع کمکی برای ساخت یک ردیف دکمه
 * @param array $buttons
 * @return array
 */
function makeRow(...$buttons) {
    return $buttons;
}
