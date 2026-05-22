<?php
/**
 * bale/Client.php - کلاینت کامل API بله
 * 
 * مسئولیت‌ها:
 * 1. ارسال درخواست‌های HTTP به API بله
 * 2. مدیریت rate limit و retry خودکار
 * 3. پشتیبانی از تمام متدهای مورد نیاز: sendMessage, editMessage, sendPhoto, sendVideo, sendAudio, sendDocument, sendMediaGroup, sendSticker, sendChatAction, answerCallbackQuery, getMe, getUpdates, setWebhook, deleteWebhook, getWebhookInfo, getFile, sendInvoice, answerPreCheckoutQuery
 * 4. مدیریت زمان‌های اجرا و لاگینگ
 * 5. مدیریت خطاها با retry و backoff
 * 6. ارسال فایل با multipart/form-data
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/core/Logger.php';

class BaleClient {
    
    private $token;
    private $apiBase;
    private $timeout;
    private $maxRetries;
    private $lastRequestTime = 0;
    private $requestsInThisSecond = 0;
    private $logger;
    
    // آمار درخواست‌ها (برای پنل ادمین)
    private $stats = [
        'total_requests' => 0,
        'successful_requests' => 0,
        'failed_requests' => 0,
        'total_duration_ms' => 0
    ];
    
    /**
     * سازنده
     * @param string|null $token توکن بازو (اگر null باشد از constant استفاده می‌شود)
     * @param int $timeout زمان انتظار برای هر درخواست (ثانیه)
     * @param int $maxRetries حداکثر تعداد تلاش مجدد
     */
    public function __construct($token = null, $timeout = 30, $maxRetries = 3) {
        $this->token = $token ?: BALE_BOT_TOKEN;
        $this->apiBase = BALE_API_BASE;
        $this->timeout = $timeout;
        $this->maxRetries = $maxRetries;
        $this->logger = logger();
        
        $this->logger->debug("BaleClient initialized", [
            'token_masked' => substr($this->token, 0, 10) . '...',
            'timeout' => $timeout
        ]);
    }
    
    /**
     * رعایت محدودیت نرخ ارسال (Rate Limit)
     * بله اجازه حداکثر 30 درخواست در ثانیه می‌دهد
     */
    private function rateLimitWait() {
        $now = microtime(true);
        
        // اگر در ثانیه جاری به حد مجاز رسیده‌ایم، صبر کن
        if ($this->requestsInThisSecond >= BALE_RATE_LIMIT_PER_SECOND) {
            $sleepTime = 1 - ($now - $this->lastRequestTime);
            if ($sleepTime > 0) {
                usleep($sleepTime * 1000000);
            }
            $this->requestsInThisSecond = 0;
            $this->lastRequestTime = microtime(true);
        }
        
        // حداقل فاصله 0.03 ثانیه بین درخواست‌ها (برای احتیاط بیشتر)
        $elapsed = $now - $this->lastRequestTime;
        if ($elapsed < 0.03 && $this->requestsInThisSecond > 0) {
            usleep((0.03 - $elapsed) * 1000000);
        }
        
        $this->requestsInThisSecond++;
        $this->lastRequestTime = microtime(true);
    }
    
    /**
     * ارسال درخواست به API بله
     * @param string $method نام متد (sendMessage, getMe, etc.)
     * @param array $params پارامترهای درخواست
     * @param array $files فایل‌ها برای آپلود (multipart)
     * @return array|null پاسخ دیکد شده JSON یا null در صورت خطا
     */
    public function request($method, $params = [], $files = []) {
        $url = $this->apiBase . "/" . $method;
        $startTime = microtime(true);
        $attempt = 0;
        $lastError = null;
        
        while ($attempt < $this->maxRetries) {
            $attempt++;
            
            // رعایت rate limit قبل از هر درخواست
            $this->rateLimitWait();
            
            try {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
                
                // تنظیم هدرها
                $headers = [
                    'User-Agent: DownloadHub-Bot/1.0',
                    'Accept: application/json'
                ];
                
                if (empty($files)) {
                    // درخواست JSON معمولی
                    $headers[] = 'Content-Type: application/json';
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
                } else {
                    // درخواست multipart/form-data برای آپلود فایل
                    curl_setopt($ch, CURLOPT_POST, true);
                    
                    $postFields = [];
                    foreach ($params as $key => $value) {
                        if (is_array($value)) {
                            $postFields[$key] = json_encode($value);
                        } else {
                            $postFields[$key] = $value;
                        }
                    }
                    
                    foreach ($files as $key => $filePath) {
                        if (file_exists($filePath)) {
                            $postFields[$key] = new CURLFile($filePath);
                        } elseif (is_string($filePath) && strpos($filePath, 'http') === 0) {
                            $postFields[$key] = $filePath;
                        }
                    }
                    
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
                }
                
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                curl_close($ch);
                
                $duration = round((microtime(true) - $startTime) * 1000, 2);
                
                // به‌روزرسانی آمار
                $this->stats['total_requests']++;
                $this->stats['total_duration_ms'] += $duration;
                
                if ($response === false) {
                    throw new Exception("cURL error: " . $error);
                }
                
                $decoded = json_decode($response, true);
                
                // بررسی خطای HTTP
                if ($httpCode !== 200) {
                    throw new Exception("HTTP {$httpCode}: " . ($decoded['description'] ?? $response));
                }
                
                // بررسی خطای API بله
                if (isset($decoded['ok']) && $decoded['ok'] !== true) {
                    $errorCode = $decoded['error_code'] ?? 0;
                    $description = $decoded['description'] ?? 'Unknown error';
                    
                    // اگر rate limit بود، با backoff بیشتری تلاش مجدد کن
                    if ($errorCode === 429) {
                        $retryAfter = $decoded['parameters']['retry_after'] ?? 5;
                        $this->logger->warning("Rate limit hit, waiting {$retryAfter}s", [
                            'method' => $method,
                            'retry_after' => $retryAfter
                        ]);
                        sleep($retryAfter);
                        continue;
                    }
                    
                    throw new Exception("API Error {$errorCode}: {$description}");
                }
                
                // درخواست موفق
                $this->stats['successful_requests']++;
                $this->logger->apiCall($method, $duration, true);
                
                // اگر متد getMe است و نتیجه دارد، لاگ ویژه
                if ($method === 'getMe' && isset($decoded['result'])) {
                    $this->logger->info("Bale API authenticated", [
                        'bot' => $decoded['result']['username'] ?? 'unknown'
                    ]);
                }
                
                return isset($decoded['result']) ? $decoded['result'] : $decoded;
                
            } catch (Exception $e) {
                $duration = round((microtime(true) - $startTime) * 1000, 2);
                $lastError = $e->getMessage();
                
                $this->stats['failed_requests']++;
                $this->logger->apiCall($method, $duration, false);
                $this->logger->error("Bale API request failed", [
                    'method' => $method,
                    'attempt' => $attempt,
                    'error' => $lastError
                ]);
                
                if ($attempt < $this->maxRetries) {
                    $waitTime = pow(2, $attempt); // 2, 4, 8 ثانیه
                    $this->logger->debug("Retrying in {$waitTime}s", ['method' => $method]);
                    sleep($waitTime);
                }
            }
        }
        
        $this->logger->error("Bale API request failed after {$this->maxRetries} attempts", [
            'method' => $method,
            'last_error' => $lastError
        ]);
        
        return null;
    }
    
    /**
     * ارسال پیام متنی (با قابلیت ادیت بعدی)
     * @param int|string $chatId
     * @param string $text
     * @param string $parseMode 'Markdown' یا 'HTML'
     * @param int|null $replyToMessageId
     * @param array|null $replyMarkup صفحه کلید شیشه‌ای (inline_keyboard)
     * @return array|null
     */
    public function sendMessage($chatId, $text, $parseMode = 'Markdown', $replyToMessageId = null, $replyMarkup = null) {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode
        ];
        
        if ($replyToMessageId) {
            $params['reply_to_message_id'] = $replyToMessageId;
        }
        
        if ($replyMarkup) {
            $params['reply_markup'] = $replyMarkup;
        }
        
        return $this->request('sendMessage', $params);
    }
    
    /**
     * ادیت پیام موجود
     * @param int|string $chatId
     * @param int $messageId
     * @param string $text
     * @param string $parseMode
     * @param array|null $replyMarkup
     * @return array|null
     */
    public function editMessageText($chatId, $messageId, $text, $parseMode = 'Markdown', $replyMarkup = null) {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => $parseMode
        ];
        
        if ($replyMarkup) {
            $params['reply_markup'] = $replyMarkup;
        }
        
        return $this->request('editMessageText', $params);
    }
    
    /**
     * ارسال عکس (با پشتیبانی از URL، file_id یا آپلود مستقیم)
     * @param int|string $chatId
     * @param string $photo URL، file_id یا مسیر فایل
     * @param string|null $caption
     * @param string $parseMode
     * @param int|null $replyToMessageId
     * @param array|null $replyMarkup
     * @return array|null
     */
    public function sendPhoto($chatId, $photo, $caption = null, $parseMode = 'Markdown', $replyToMessageId = null, $replyMarkup = null) {
        $params = ['chat_id' => $chatId];
        $files = [];
        
        // تشخیص نوع عکس
        if (filter_var($photo, FILTER_VALIDATE_URL)) {
            $params['photo'] = $photo;
        } elseif (file_exists($photo)) {
            $files['photo'] = $photo;
            $params['photo'] = 'attach://photo';
        } else {
            $params['photo'] = $photo; // file_id
        }
        
        if ($caption) {
            $params['caption'] = $caption;
            $params['parse_mode'] = $parseMode;
        }
        
        if ($replyToMessageId) {
            $params['reply_to_message_id'] = $replyToMessageId;
        }
        
        if ($replyMarkup) {
            $params['reply_markup'] = $replyMarkup;
        }
        
        return $this->request('sendPhoto', $params, $files);
    }
    
    /**
     * ارسال ویدیو
     * @param int|string $chatId
     * @param string $video URL، file_id یا مسیر فایل
     * @param string|null $caption
     * @param int|null $duration
     * @param int|null $width
     * @param int|null $height
     * @param string|null $thumbnail
     * @param string $parseMode
     * @param int|null $replyToMessageId
     * @param array|null $replyMarkup
     * @return array|null
     */
    public function sendVideo($chatId, $video, $caption = null, $duration = null, $width = null, $height = null, $thumbnail = null, $parseMode = 'Markdown', $replyToMessageId = null, $replyMarkup = null) {
        $params = ['chat_id' => $chatId];
        $files = [];
        
        // تشخیص نوع ویدیو
        if (filter_var($video, FILTER_VALIDATE_URL)) {
            $params['video'] = $video;
        } elseif (file_exists($video)) {
            $files['video'] = $video;
            $params['video'] = 'attach://video';
        } else {
            $params['video'] = $video; // file_id
        }
        
        if ($caption) {
            $params['caption'] = $caption;
            $params['parse_mode'] = $parseMode;
        }
        
        if ($duration) $params['duration'] = $duration;
        if ($width) $params['width'] = $width;
        if ($height) $params['height'] = $height;
        if ($thumbnail) $params['thumbnail'] = $thumbnail;
        if ($replyToMessageId) $params['reply_to_message_id'] = $replyToMessageId;
        if ($replyMarkup) $params['reply_markup'] = $replyMarkup;
        
        return $this->request('sendVideo', $params, $files);
    }
    
    /**
     * ارسال فایل صوتی (برای پخش در پلیر بله)
     * @param int|string $chatId
     * @param string $audio URL، file_id یا مسیر فایل
     * @param string|null $caption
     * @param int|null $duration
     * @param string|null $title
     * @param string|null $performer
     * @param string $parseMode
     * @param int|null $replyToMessageId
     * @param array|null $replyMarkup
     * @return array|null
     */
    public function sendAudio($chatId, $audio, $caption = null, $duration = null, $title = null, $performer = null, $parseMode = 'Markdown', $replyToMessageId = null, $replyMarkup = null) {
        $params = ['chat_id' => $chatId];
        $files = [];
        
        if (filter_var($audio, FILTER_VALIDATE_URL)) {
            $params['audio'] = $audio;
        } elseif (file_exists($audio)) {
            $files['audio'] = $audio;
            $params['audio'] = 'attach://audio';
        } else {
            $params['audio'] = $audio;
        }
        
        if ($caption) {
            $params['caption'] = $caption;
            $params['parse_mode'] = $parseMode;
        }
        
        if ($duration) $params['duration'] = $duration;
        if ($title) $params['title'] = $title;
        if ($performer) $params['performer'] = $performer;
        if ($replyToMessageId) $params['reply_to_message_id'] = $replyToMessageId;
        if ($replyMarkup) $params['reply_markup'] = $replyMarkup;
        
        return $this->request('sendAudio', $params, $files);
    }
    
    /**
     * ارسال فایل عمومی (سند)
     * @param int|string $chatId
     * @param string $document URL، file_id یا مسیر فایل
     * @param string|null $caption
     * @param string|null $thumb
     * @param string $parseMode
     * @param int|null $replyToMessageId
     * @param array|null $replyMarkup
     * @return array|null
     */
    public function sendDocument($chatId, $document, $caption = null, $thumb = null, $parseMode = 'Markdown', $replyToMessageId = null, $replyMarkup = null) {
        $params = ['chat_id' => $chatId];
        $files = [];
        
        if (filter_var($document, FILTER_VALIDATE_URL)) {
            $params['document'] = $document;
        } elseif (file_exists($document)) {
            $files['document'] = $document;
            $params['document'] = 'attach://document';
        } else {
            $params['document'] = $document;
        }
        
        if ($caption) {
            $params['caption'] = $caption;
            $params['parse_mode'] = $parseMode;
        }
        
        if ($thumb) $params['thumb'] = $thumb;
        if ($replyToMessageId) $params['reply_to_message_id'] = $replyToMessageId;
        if ($replyMarkup) $params['reply_markup'] = $replyMarkup;
        
        return $this->request('sendDocument', $params, $files);
    }
    
    /**
     * ارسال آلبوم (چند عکس/ویدیو به صورت گروهی)
     * @param int|string $chatId
     * @param array $media آرایه ای از رسانه‌ها (هر کدام با type, media, caption)
     * @param int|null $replyToMessageId
     * @return array|null
     */
    public function sendMediaGroup($chatId, $media, $replyToMessageId = null) {
        $params = [
            'chat_id' => $chatId,
            'media' => json_encode($media)
        ];
        
        if ($replyToMessageId) {
            $params['reply_to_message_id'] = $replyToMessageId;
        }
        
        return $this->request('sendMediaGroup', $params);
    }
    
    /**
     * ارسال استیکر
     * @param int|string $chatId
     * @param string $sticker file_id یا URL
     * @param int|null $replyToMessageId
     * @param array|null $replyMarkup
     * @return array|null
     */
    public function sendSticker($chatId, $sticker, $replyToMessageId = null, $replyMarkup = null) {
        $params = [
            'chat_id' => $chatId,
            'sticker' => $sticker
        ];
        
        if ($replyToMessageId) {
            $params['reply_to_message_id'] = $replyToMessageId;
        }
        
        if ($replyMarkup) {
            $params['reply_markup'] = $replyMarkup;
        }
        
        return $this->request('sendSticker', $params);
    }
    
    /**
     * نمایش وضعیت تایپ/آپلود به کاربر
     * @param int|string $chatId
     * @param string $action typing, upload_photo, record_video, upload_video, record_voice, upload_voice, choose_sticker, find_location
     * @return bool
     */
    public function sendChatAction($chatId, $action = 'typing') {
        $params = [
            'chat_id' => $chatId,
            'action' => $action
        ];
        
        $result = $this->request('sendChatAction', $params);
        return $result === true;
    }
    
    /**
     * پاسخ به callback query (وقتی کاربر روی دکمه شیشه‌ای کلیک می‌کند)
     * @param string $callbackQueryId
     * @param string|null $text متن اعلان (اختیاری)
     * @param bool $showAlert نمایش به صورت هشدار
     * @param string|null $url باز کردن URL
     * @param int|null $cacheTime
     * @return bool
     */
    public function answerCallbackQuery($callbackQueryId, $text = null, $showAlert = false, $url = null, $cacheTime = null) {
        $params = [
            'callback_query_id' => $callbackQueryId
        ];
        
        if ($text) {
            $params['text'] = $text;
        }
        
        if ($showAlert) {
            $params['show_alert'] = true;
        }
        
        if ($url) {
            $params['url'] = $url;
        }
        
        if ($cacheTime) {
            $params['cache_time'] = $cacheTime;
        }
        
        $result = $this->request('answerCallbackQuery', $params);
        return $result === true;
    }
    
    /**
     * دریافت اطلاعات بازو (تست توکن)
     * @return array|null
     */
    public function getMe() {
        return $this->request('getMe');
    }
    
    /**
     * دریافت آپدیت‌ها (برای polling - در این پروژه استفاده نمی‌شود، اما برای کامل بودن اضافه شد)
     * @param int|null $offset
     * @param int $limit
     * @param int $timeout
     * @return array|null
     */
    public function getUpdates($offset = null, $limit = 100, $timeout = 30) {
        $params = [
            'limit' => $limit,
            'timeout' => $timeout
        ];
        
        if ($offset !== null) {
            $params['offset'] = $offset;
        }
        
        return $this->request('getUpdates', $params);
    }
    
    /**
     * تنظیم Webhook
     * @param string $url
     * @param string|null $certificate
     * @param int|null $maxConnections
     * @return bool
     */
    public function setWebhook($url, $certificate = null, $maxConnections = null) {
        $params = ['url' => $url];
        $files = [];
        
        if ($certificate && file_exists($certificate)) {
            $files['certificate'] = $certificate;
        }
        
        if ($maxConnections) {
            $params['max_connections'] = $maxConnections;
        }
        
        $result = $this->request('setWebhook', $params, $files);
        return $result === true;
    }
    
    /**
     * حذف Webhook
     * @return bool
     */
    public function deleteWebhook() {
        $result = $this->request('deleteWebhook');
        return $result === true;
    }
    
    /**
     * دریافت اطلاعات Webhook
     * @return array|null
     */
    public function getWebhookInfo() {
        return $this->request('getWebhookInfo');
    }
    
    /**
     * دریافت اطلاعات فایل (برای دانلود)
     * @param string $fileId
     * @return array|null
     */
    public function getFile($fileId) {
        return $this->request('getFile', ['file_id' => $fileId]);
    }
    
    /**
     * دانلود فایل از سرور بله
     * @param string $filePath مسیر فایل برگشتی از getFile
     * @return string|null محتوای فایل یا null در صورت خطا
     */
    public function downloadFile($filePath) {
        $url = BALE_FILE_BASE . '/' . $filePath;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 && $response !== false) {
            return $response;
        }
        
        $this->logger->error("Failed to download file", ['file_path' => $filePath, 'http_code' => $httpCode]);
        return null;
    }
    
    /**
     * ارسال درخواست پرداخت (Invoice)
     * @param int|string $chatId
     * @param string $title
     * @param string $description
     * @param string $payload
     * @param string $providerToken
     * @param array $prices آرایه‌ای از ['label' => '...', 'amount' => 1000]
     * @param string|null $photoUrl
     * @param int|null $replyToMessageId
     * @return array|null
     */
    public function sendInvoice($chatId, $title, $description, $payload, $providerToken, $prices, $photoUrl = null, $replyToMessageId = null) {
        $params = [
            'chat_id' => $chatId,
            'title' => $title,
            'description' => $description,
            'payload' => $payload,
            'provider_token' => $providerToken,
            'prices' => json_encode($prices)
        ];
        
        if ($photoUrl) {
            $params['photo_url'] = $photoUrl;
        }
        
        if ($replyToMessageId) {
            $params['reply_to_message_id'] = $replyToMessageId;
        }
        
        return $this->request('sendInvoice', $params);
    }
    
    /**
     * پاسخ به درخواست پیش از پرداخت (PreCheckoutQuery)
     * @param string $preCheckoutQueryId
     * @param bool $ok
     * @param string|null $errorMessage
     * @return bool
     */
    public function answerPreCheckoutQuery($preCheckoutQueryId, $ok, $errorMessage = null) {
        $params = [
            'pre_checkout_query_id' => $preCheckoutQueryId,
            'ok' => $ok
        ];
        
        if ($errorMessage) {
            $params['error_message'] = $errorMessage;
        }
        
        $result = $this->request('answerPreCheckoutQuery', $params);
        return $result === true;
    }
    
    /**
     * دریافت آمار عملکرد کلاینت
     * @return array
     */
    public function getStats() {
        $avgDuration = $this->stats['total_requests'] > 0 
            ? round($this->stats['total_duration_ms'] / $this->stats['total_requests'], 2) 
            : 0;
        
        return [
            'total_requests' => $this->stats['total_requests'],
            'successful_requests' => $this->stats['successful_requests'],
            'failed_requests' => $this->stats['failed_requests'],
            'success_rate' => $this->stats['total_requests'] > 0 
                ? round(($this->stats['successful_requests'] / $this->stats['total_requests']) * 100, 2) 
                : 0,
            'average_duration_ms' => $avgDuration
        ];
    }
    
    /**
     * بررسی سلامت اتصال به API بله
     * @return array
     */
    public function healthCheck() {
        $start = microtime(true);
        $result = $this->getMe();
        $latency = round((microtime(true) - $start) * 1000, 2);
        
        if ($result && isset($result['id'])) {
            return [
                'status' => 'healthy',
                'latency_ms' => $latency,
                'bot_id' => $result['id'],
                'bot_username' => $result['username'] ?? 'unknown'
            ];
        }
        
        return [
            'status' => 'unhealthy',
            'latency_ms' => $latency,
            'error' => 'Failed to authenticate with Bale API'
        ];
    }
}

/**
 * تابع کمکی برای دسترسی سریع به کلاینت بله
 * @return BaleClient
 */
function bale() {
    static $client = null;
    if ($client === null) {
        $client = new BaleClient();
    }
    return $client;
}
