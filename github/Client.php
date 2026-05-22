<?php
/**
 * github/Client.php - کلاینت کامل API گیت‌هاب
 * 
 * مسئولیت‌ها:
 * 1. فراخوانی workflow_dispatch برای اجرای اکشن‌ها
 * 2. لغو workflow در حال اجرا
 * 3. بررسی وضعیت اجرای workflow
 * 4. جستجو در ریپازیتوری برای فایل‌های کش شده
 * 5. مدیریت rate limit گیت‌هاب
 * 6. دریافت آمار مصرف اکشن‌ها (دقایق باقی‌مانده)
 * 7. لیست کردن فایل‌های یک پوشه در ریپازیتوری (برای کش)
 * 8. دریافت محتوای فایل از ریپازیتوری (برای دانلود مستقیم از کش)
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/core/Logger.php';

class GitHubClient {
    
    private $token;
    private $owner;
    private $repo;
    private $apiBase;
    private $timeout;
    private $maxRetries;
    private $logger;
    
    // آمار درخواست‌ها
    private $stats = [
        'total_requests' => 0,
        'successful_requests' => 0,
        'failed_requests' => 0,
        'total_duration_ms' => 0,
        'rate_limit_remaining' => 5000,
        'rate_limit_reset' => 0
    ];
    
    /**
     * سازنده
     * @param string|null $token توکن گیت‌هاب (اگر null باشد از constant استفاده می‌شود)
     * @param string|null $owner مالک ریپازیتوری
     * @param string|null $repo نام ریپازیتوری
     */
    public function __construct($token = null, $owner = null, $repo = null) {
        $this->token = $token ?: GITHUB_TOKEN;
        $this->owner = $owner ?: GITHUB_REPO_OWNER;
        $this->repo = $repo ?: GITHUB_REPO_NAME;
        $this->apiBase = GITHUB_API_BASE;
        $this->timeout = 30;
        $this->maxRetries = 3;
        $this->logger = logger();
        
        $this->logger->debug("GitHubClient initialized", [
            'repo' => "{$this->owner}/{$this->repo}",
            'token_masked' => substr($this->token, 0, 10) . '...'
        ]);
    }
    
    /**
     * ارسال درخواست به API گیت‌هاب
     * @param string $method متد HTTP (GET, POST, DELETE)
     * @param string $endpoint نقطه پایانی API (مثل /repos/...)
     * @param array $data داده‌های ارسالی (برای POST)
     * @return array|null پاسخ دیکد شده JSON
     */
    private function request($method, $endpoint, $data = null) {
        $url = $this->apiBase . $endpoint;
        $startTime = microtime(true);
        $attempt = 0;
        $lastError = null;
        
        while ($attempt < $this->maxRetries) {
            $attempt++;
            
            try {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                
                // هدرهای احراز هویت
                $headers = [
                    'User-Agent: DownloadHub-Bot/1.0',
                    'Accept: application/vnd.github.v3+json',
                    'Authorization: token ' . $this->token
                ];
                
                if ($data !== null) {
                    $jsonData = json_encode($data);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
                    $headers[] = 'Content-Type: application/json';
                    $headers[] = 'Content-Length: ' . strlen($jsonData);
                }
                
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                
                // دریافت هدرهای rate limit
                $rateLimitRemaining = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $headers_raw = substr($response, 0, $headerSize);
                $body = substr($response, $headerSize);
                
                // استخراج rate limit از هدرها
                if (preg_match('/x-ratelimit-remaining: (\d+)/i', $headers_raw, $matches)) {
                    $this->stats['rate_limit_remaining'] = (int) $matches[1];
                }
                if (preg_match('/x-ratelimit-reset: (\d+)/i', $headers_raw, $matches)) {
                    $this->stats['rate_limit_reset'] = (int) $matches[1];
                }
                
                curl_close($ch);
                
                $duration = round((microtime(true) - $startTime) * 1000, 2);
                
                // به‌روزرسانی آمار
                $this->stats['total_requests']++;
                $this->stats['total_duration_ms'] += $duration;
                
                if ($response === false) {
                    throw new Exception("cURL error: " . $error);
                }
                
                // بررسی خطاهای HTTP
                if ($httpCode === 204) {
                    // No content (موفق اما بدون محتوا)
                    $this->stats['successful_requests']++;
                    $this->logger->apiCall("GitHub {$method} {$endpoint}", $duration, true);
                    return ['success' => true];
                }
                
                if ($httpCode >= 400) {
                    $decoded = json_decode($body, true);
                    $errorMessage = $decoded['message'] ?? "HTTP {$httpCode}";
                    
                    // اگر rate limit بود
                    if ($httpCode === 403 && strpos($errorMessage, 'rate limit') !== false) {
                        $resetTime = $this->stats['rate_limit_reset'];
                        $waitTime = max(1, $resetTime - time());
                        $this->logger->warning("GitHub rate limit hit", [
                            'reset_in_seconds' => $waitTime,
                            'remaining' => $this->stats['rate_limit_remaining']
                        ]);
                        
                        if ($attempt < $this->maxRetries) {
                            sleep(min($waitTime, 60)); // حداکثر 60 ثانیه صبر کن
                            continue;
                        }
                    }
                    
                    throw new Exception($errorMessage);
                }
                
                $decoded = json_decode($body, true);
                $this->stats['successful_requests']++;
                $this->logger->apiCall("GitHub {$method} {$endpoint}", $duration, true);
                
                return $decoded;
                
            } catch (Exception $e) {
                $duration = round((microtime(true) - $startTime) * 1000, 2);
                $lastError = $e->getMessage();
                
                $this->stats['failed_requests']++;
                $this->logger->apiCall("GitHub {$method} {$endpoint}", $duration, false);
                $this->logger->error("GitHub API request failed", [
                    'endpoint' => $endpoint,
                    'attempt' => $attempt,
                    'error' => $lastError
                ]);
                
                if ($attempt < $this->maxRetries) {
                    $waitTime = pow(2, $attempt);
                    sleep($waitTime);
                }
            }
        }
        
        $this->logger->error("GitHub API request failed after {$this->maxRetries} attempts", [
            'endpoint' => $endpoint,
            'last_error' => $lastError
        ]);
        
        return null;
    }
    
    /**
     * فراخوانی workflow_dispatch برای اجرای یک اکشن
     * @param string $workflowFileName نام فایل workflow (مثل youtube-dl.yml)
     * @param array $inputs ورودی‌های workflow
     * @param string|null $ref شاخه (پیش‌فرض: main)
     * @return array|false نتیجه شامل run_id یا false در صورت خطا
     */
    public function triggerWorkflow($workflowFileName, $inputs, $ref = 'main') {
        $endpoint = "/repos/{$this->owner}/{$this->repo}/actions/workflows/{$workflowFileName}/dispatches";
        
        $data = [
            'ref' => $ref,
            'inputs' => $inputs
        ];
        
        $result = $this->request('POST', $endpoint, $data);
        
        // در صورت موفقیت، API گیت‌هاب کد 204 برمی‌گرداند (بدون محتوا)
        // بنابراین باید لیست آخرین workflow runs را بگیریم تا run_id را پیدا کنیم
        if ($result && isset($result['success']) && $result['success'] === true) {
            // کمی صبر کن تا workflow در سیستم ثبت شود
            sleep(2);
            
            // دریافت آخرین workflow run برای این workflow
            $runs = $this->listWorkflowRuns($workflowFileName, 1);
            if (!empty($runs) && isset($runs[0]['id'])) {
                $runId = $runs[0]['id'];
                $this->logger->info("Workflow triggered successfully", [
                    'workflow' => $workflowFileName,
                    'run_id' => $runId,
                    'inputs' => $inputs
                ]);
                return ['run_id' => $runId, 'status' => 'queued'];
            }
            
            return ['run_id' => null, 'status' => 'triggered'];
        }
        
        return false;
    }
    
    /**
     * لغو یک workflow در حال اجرا
     * @param int $runId شناسه اجرای workflow
     * @return bool موفقیت یا عدم موفقیت
     */
    public function cancelWorkflow($runId) {
        $endpoint = "/repos/{$this->owner}/{$this->repo}/actions/runs/{$runId}/cancel";
        $result = $this->request('POST', $endpoint);
        
        $success = ($result && isset($result['success']) && $result['success'] === true);
        
        if ($success) {
            $this->logger->info("Workflow cancelled", ['run_id' => $runId]);
        } else {
            $this->logger->warning("Failed to cancel workflow", ['run_id' => $runId]);
        }
        
        return $success;
    }
    
    /**
     * دریافت وضعیت یک workflow run
     * @param int $runId شناسه اجرای workflow
     * @return array|null وضعیت (status, conclusion, etc.)
     */
    public function getWorkflowRunStatus($runId) {
        $endpoint = "/repos/{$this->owner}/{$this->repo}/actions/runs/{$runId}";
        $result = $this->request('GET', $endpoint);
        
        if ($result && isset($result['status'])) {
            return [
                'status' => $result['status'], // queued, in_progress, completed
                'conclusion' => $result['conclusion'] ?? null, // success, failure, cancelled, skipped
                'created_at' => $result['created_at'],
                'updated_at' => $result['updated_at'],
                'run_started_at' => $result['run_started_at'] ?? null,
                'html_url' => $result['html_url']
            ];
        }
        
        return null;
    }
    
    /**
     * لیست کردن workflow runs اخیر
     * @param string|null $workflowFileName فیلتر بر اساس نام workflow
     * @param int $perPage تعداد در هر صفحه
     * @return array لیست runs
     */
    public function listWorkflowRuns($workflowFileName = null, $perPage = 10) {
        $endpoint = "/repos/{$this->owner}/{$this->repo}/actions/runs";
        
        if ($workflowFileName) {
            // ابتدا workflow ID را پیدا کن
            $workflows = $this->listWorkflows();
            $workflowId = null;
            foreach ($workflows as $wf) {
                if (strpos($wf['path'], $workflowFileName) !== false) {
                    $workflowId = $wf['id'];
                    break;
                }
            }
            
            if ($workflowId) {
                $endpoint .= "?workflow_id={$workflowId}&per_page={$perPage}";
            }
        } else {
            $endpoint .= "?per_page={$perPage}";
        }
        
        $result = $this->request('GET', $endpoint);
        
        if ($result && isset($result['workflow_runs'])) {
            return $result['workflow_runs'];
        }
        
        return [];
    }
    
    /**
     * لیست کردن workflow های موجود در ریپازیتوری
     * @return array لیست workflow‌ها
     */
    public function listWorkflows() {
        $endpoint = "/repos/{$this->owner}/{$this->repo}/actions/workflows";
        $result = $this->request('GET', $endpoint);
        
        if ($result && isset($result['workflows'])) {
            return $result['workflows'];
        }
        
        return [];
    }
    
    /**
     * دریافت آمار مصرف GitHub Actions (دقایق باقی‌مانده)
     * @return array آمار مصرف
     */
    public function getActionsUsage() {
        $endpoint = "/repos/{$this->owner}/{$this->repo}/actions/workflows";
        $result = $this->request('GET', $endpoint);
        
        $totalMinutes = 0;
        $usedMinutes = 0;
        
        if ($result && isset($result['workflows'])) {
            foreach ($result['workflows'] as $workflow) {
                $stats = $this->getWorkflowUsage($workflow['id']);
                if ($stats) {
                    $usedMinutes += $stats['total_minutes'];
                }
            }
        }
        
        // حساب‌های رایگان گیت‌هاب 2000 دقیقه در ماه دارند
        $freeLimit = 2000;
        $remainingMinutes = max(0, $freeLimit - $usedMinutes);
        
        return [
            'free_limit_minutes' => $freeLimit,
            'used_minutes' => round($usedMinutes, 2),
            'remaining_minutes' => round($remainingMinutes, 2),
            'usage_percent' => round(($usedMinutes / $freeLimit) * 100, 2)
        ];
    }
    
    /**
     * دریافت آمار مصرف یک workflow خاص
     * @param int $workflowId شناسه workflow
     * @return array|null آمار مصرف
     */
    public function getWorkflowUsage($workflowId) {
        $endpoint = "/repos/{$this->owner}/{$this->repo}/actions/workflows/{$workflowId}/timing";
        $result = $this->request('GET', $endpoint);
        
        if ($result && isset($result['billable'])) {
            $totalMinutes = 0;
            foreach ($result['billable'] as $os => $data) {
                $totalMinutes += $data['total_ms'] / 60000; // تبدیل میلی‌ثانیه به دقیقه
            }
            
            return [
                'total_minutes' => $totalMinutes,
                'billable' => $result['billable']
            ];
        }
        
        return null;
    }
    
    /**
     * جستجو در ریپازیتوری برای فایل‌های کش شده
     * بر اساس پلتفرم و شناسه خارجی (video_id, track_id, etc.)
     * @param string $platform پلتفرم (youtube, soundcloud, instagram, tiktok)
     * @param string $externalId شناسه خارجی محتوا
     * @return array|null مسیر فایل اگر پیدا شد
     */
    public function searchCacheFile($platform, $externalId) {
        // مسیرهای احتمالی برای فایل‌های کش شده
        // فرمت: {platform}/{creator_slug}/{filename}.{ext}
        
        // ابتدا سعی می‌کنیم با استفاده از API گیت‌هاب فایل را جستجو کنیم
        $query = "repo:{$this->owner}/{$this->repo} path:{$platform} content:{$externalId}";
        $endpoint = "/search/code?q=" . urlencode($query);
        
        $result = $this->request('GET', $endpoint);
        
        if ($result && isset($result['items']) && !empty($result['items'])) {
            // اولین نتیجه را برمی‌گردانیم
            $file = $result['items'][0];
            return [
                'path' => $file['path'],
                'url' => $file['html_url'],
                'download_url' => $file['url']
            ];
        }
        
        return null;
    }
    
    /**
     * دریافت محتوای یک فایل از ریپازیتوری (برای دانلود مستقیم از کش)
     * @param string $filePath مسیر فایل در ریپازیتوری
     * @return string|null محتوای فایل (برای فایل‌های متنی مثل JSON) یا null
     */
    public function getFileContent($filePath) {
        $endpoint = "/repos/{$this->owner}/{$this->repo}/contents/{$filePath}";
        $result = $this->request('GET', $endpoint);
        
        if ($result && isset($result['content'])) {
            // محتوا base64 encoded است
            return base64_decode($result['content']);
        }
        
        return null;
    }
    
    /**
     * دریافت URL دانلود مستقیم یک فایل از ریپازیتوری
     * @param string $filePath مسیر فایل در ریپازیتوری
     * @param string $ref شاخه (پیش‌فرض: main)
     * @return string|null URL دانلود یا null
     */
    public function getFileDownloadUrl($filePath, $ref = 'main') {
        // URL مستقیم فایل خام در گیت‌هاب
        $rawUrl = "https://raw.githubusercontent.com/{$this->owner}/{$this->repo}/{$ref}/{$filePath}";
        
        // بررسی می‌کنیم که فایل وجود دارد یا نه
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $rawUrl);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            return $rawUrl;
        }
        
        return null;
    }
    
    /**
     * لیست کردن فایل‌های یک پوشه در ریپازیتوری
     * @param string $folderPath مسیر پوشه (مثل youtube/)
     * @return array لیست فایل‌ها
     */
    public function listFolderContents($folderPath) {
        $endpoint = "/repos/{$this->owner}/{$this->repo}/contents/{$folderPath}";
        $result = $this->request('GET', $endpoint);
        
        if ($result && is_array($result)) {
            return $result;
        }
        
        return [];
    }
    
    /**
     * جستجوی فایل در cache_index.json محلی (سریع‌تر از API گیت‌هاب)
     * این متد از دیتابیس محلی استفاده می‌کند (در core/CacheChecker.php پیاده‌سازی می‌شود)
     * اما برای یکپارچگی، اینجا هم یک متد داریم
     * @param string $platform
     * @param string $externalId
     * @return array|null
     */
    public function searchLocalCache($platform, $externalId) {
        // این متد بعداً با دیتابیس تکمیل می‌شود
        // فعلاً از API گیت‌هاب استفاده می‌کنیم
        return $this->searchCacheFile($platform, $externalId);
    }
    
    /**
     * دریافت وضعیت rate limit فعلی
     * @return array
     */
    public function getRateLimit() {
        $endpoint = "/rate_limit";
        $result = $this->request('GET', $endpoint);
        
        if ($result && isset($result['resources']['core'])) {
            $core = $result['resources']['core'];
            return [
                'limit' => $core['limit'],
                'remaining' => $core['remaining'],
                'reset' => $core['reset'],
                'reset_in_seconds' => max(0, $core['reset'] - time())
            ];
        }
        
        return [
            'limit' => 5000,
            'remaining' => $this->stats['rate_limit_remaining'],
            'reset' => $this->stats['rate_limit_reset'],
            'reset_in_seconds' => max(0, $this->stats['rate_limit_reset'] - time())
        ];
    }
    
    /**
     * بررسی سلامت اتصال به API گیت‌هاب
     * @return array
     */
    public function healthCheck() {
        $start = microtime(true);
        $rateLimit = $this->getRateLimit();
        $latency = round((microtime(true) - $start) * 1000, 2);
        
        if (isset($rateLimit['remaining']) && $rateLimit['remaining'] > 0) {
            return [
                'status' => 'healthy',
                'latency_ms' => $latency,
                'rate_limit_remaining' => $rateLimit['remaining'],
                'rate_limit_reset_in' => $rateLimit['reset_in_seconds'] ?? 0
            ];
        }
        
        return [
            'status' => 'degraded',
            'latency_ms' => $latency,
            'message' => 'Rate limit may be exceeded',
            'rate_limit_remaining' => $rateLimit['remaining'] ?? 0
        ];
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
            'average_duration_ms' => $avgDuration,
            'rate_limit_remaining' => $this->stats['rate_limit_remaining'],
            'rate_limit_reset' => $this->stats['rate_limit_reset']
        ];
    }
}

/**
 * تابع کمکی برای دسترسی سریع به کلاینت گیت‌هاب
 * @return GitHubClient
 */
function github() {
    static $client = null;
    if ($client === null) {
        $client = new GitHubClient();
    }
    return $client;
}
