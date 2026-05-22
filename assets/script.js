/**
 * assets/script.js - جاوااسکریپت‌های اصلی پنل ادمین و صفحات عمومی
 * 
 * این فایل شامل توابع زیر است:
 * 1. Auto-refresh صفحات
 * 2. تأییدیه‌های حذف و عملیات حساس
 * 3. نمودارها (Chart.js)
 * 4. فیلترهای داینامیک جداول
 * 5. نمایش/مخفی کردن المان‌ها
 * 6. اعتبارسنجی فرم‌ها
 * 7. توابع کمکی
 */

// ============================================================
// تنظیمات کلی
// ============================================================
const DOWNLOADHUB = {
    // زمان بروزرسانی خودکار (میلی‌ثانیه)
    refreshInterval: 60000, // 60 ثانیه
    
    // آستانه هشدار (مثلاً برای rate limit)
    warningThreshold: {
        rateLimit: 100,
        queuePending: 50
    },
    
    // وضعیت‌های مختلف
    statusColors: {
        pending: '#f57c00',
        processing: '#1976d2',
        completed: '#2e7d32',
        failed: '#c62828',
        cancelled: '#757575'
    },
    
    // پیام‌های تأیید
    confirmMessages: {
        delete: 'آیا از حذف این مورد اطمینان دارید؟',
        clearQueue: 'آیا از پاک کردن تمام درخواست‌های در انتظار اطمینان دارید؟',
        cancelRequest: 'آیا از لغو این درخواست اطمینان دارید؟',
        logout: 'آیا از خروج از پنل اطمینان دارید؟'
    }
};

// ============================================================
// توابع عمومی
// ============================================================

/**
 * نمایش پیام toast (نوتیفیکیشن موقتی)
 * @param {string} message - متن پیام
 * @param {string} type - نوع پیام (success, error, warning, info)
 */
function showToast(message, type = 'info') {
    // ایجاد المان toast
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `
        <div class="toast-icon">${getToastIcon(type)}</div>
        <div class="toast-message">${message}</div>
        <button class="toast-close" onclick="this.parentElement.remove()">×</button>
    `;
    
    // استایل toast
    toast.style.cssText = `
        position: fixed;
        bottom: 20px;
        right: 20px;
        background: white;
        border-radius: 12px;
        padding: 12px 20px;
        display: flex;
        align-items: center;
        gap: 12px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        z-index: 1000;
        animation: slideIn 0.3s ease;
        direction: rtl;
        font-family: inherit;
    `;
    
    // رنگ‌های مختلف بر اساس نوع
    const colors = {
        success: '#38a169',
        error: '#e53e3e',
        warning: '#ed8936',
        info: '#4299e1'
    };
    toast.style.borderRight = `4px solid ${colors[type] || colors.info}`;
    
    document.body.appendChild(toast);
    
    // حذف خودکار بعد از 3 ثانیه
    setTimeout(() => {
        if (toast && toast.parentElement) {
            toast.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }
    }, 3000);
}

/**
 * دریافت آیکون toast
 * @param {string} type
 * @returns {string}
 */
function getToastIcon(type) {
    const icons = {
        success: '✅',
        error: '❌',
        warning: '⚠️',
        info: 'ℹ️'
    };
    return icons[type] || 'ℹ️';
}

/**
 * تأیید عملیات حساس
 * @param {string} message
 * @param {function} callback
 */
function confirmAction(message, callback) {
    if (confirm(message)) {
        callback();
    }
}

/**
 * بارگذاری نمودارها (Chart.js)
 * @param {string} canvasId - آیدی canvas
 * @param {object} data - داده‌های نمودار
 * @param {string} type - نوع نمودار (line, bar, pie)
 */
function loadChart(canvasId, data, type = 'line') {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;
    
    const defaultOptions = {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                position: 'top',
                rtl: true,
                labels: {
                    font: { family: 'Tahoma, sans-serif' }
                }
            },
            tooltip: {
                rtl: true
            }
        },
        scales: type === 'line' ? {
            y: {
                beginAtZero: true,
                ticks: { stepSize: 1 }
            }
        } : {}
    };
    
    const options = { ...defaultOptions, ...data.options };
    
    return new Chart(ctx, {
        type: type,
        data: data.datasets,
        options: options
    });
}

/**
 * بروزرسانی خودکار صفحه
 * @param {number} interval - فاصله زمانی (میلی‌ثانیه)
 */
function autoRefresh(interval = DOWNLOADHUB.refreshInterval) {
    let refreshTimer;
    
    function refresh() {
        if (!document.hidden) {
            location.reload();
        }
    }
    
    // شروع تایمر
    function start() {
        refreshTimer = setInterval(refresh, interval);
    }
    
    // توقف تایمر
    function stop() {
        if (refreshTimer) {
            clearInterval(refreshTimer);
        }
    }
    
    // شنود تغییر visibility
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            stop();
        } else {
            start();
        }
    });
    
    start();
}

/**
 * فیلتر داینامیک جدول
 * @param {string} inputId - آیدی input جستجو
 * @param {string} tableId - آیدی جدول
 * @param {number} columnIndex - ستون مورد نظر برای جستجو (اختیاری)
 */
function setupTableFilter(inputId, tableId, columnIndex = null) {
    const input = document.getElementById(inputId);
    const table = document.getElementById(tableId);
    
    if (!input || !table) return;
    
    input.addEventListener('keyup', function() {
        const filter = this.value.toLowerCase();
        const rows = table.getElementsByTagName('tr');
        
        for (let i = 1; i < rows.length; i++) {
            const cells = rows[i].getElementsByTagName('td');
            let found = false;
            
            // اگر ستون مشخص شده باشد، فقط آن ستون را جستجو کن
            if (columnIndex !== null && cells[columnIndex]) {
                const text = cells[columnIndex].textContent.toLowerCase();
                found = text.includes(filter);
            } else {
                // تمام ستون‌ها را جستجو کن
                for (let j = 0; j < cells.length; j++) {
                    const text = cells[j].textContent.toLowerCase();
                    if (text.includes(filter)) {
                        found = true;
                        break;
                    }
                }
            }
            
            rows[i].style.display = found ? '' : 'none';
        }
    });
}

/**
 * کپی متن در کلیپ‌بورد
 * @param {string} text
 */
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        showToast('متن با موفقیت کپی شد', 'success');
    }).catch(() => {
        showToast('خطا در کپی متن', 'error');
    });
}

/**
 * دریافت پارامترهای URL
 * @param {string} name
 * @returns {string|null}
 */
function getUrlParameter(name) {
    const urlParams = new URLSearchParams(window.location.search);
    return urlParams.get(name);
}

/**
 * فرمت کردن اعداد با کاما
 * @param {number} num
 * @returns {string}
 */
function formatNumber(num) {
    return new Intl.NumberFormat('fa-IR').format(num);
}

/**
 * فرمت کردن تاریخ به شمسی (اختیاری - در صورت نیاز)
 * @param {string} dateString
 * @returns {string}
 */
function formatPersianDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('fa-IR', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

// ============================================================
// توابع اختصاصی دشبورد
// ============================================================

/**
 * بارگذاری نمودار دشبورد
 * @param {object} dailyData - داده‌های روزانه
 */
function loadDashboardChart(dailyData) {
    if (!dailyData || !dailyData.dates || dailyData.dates.length === 0) return;
    
    const ctx = document.getElementById('requestsChart');
    if (!ctx) return;
    
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
                    tension: 0.4,
                    pointBackgroundColor: '#667eea',
                    pointBorderColor: '#fff',
                    pointRadius: 4,
                    pointHoverRadius: 6
                },
                {
                    label: 'درخواست‌های موفق',
                    data: dailyData.completed,
                    borderColor: '#38a169',
                    backgroundColor: 'rgba(56, 161, 105, 0.1)',
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#38a169',
                    pointBorderColor: '#fff',
                    pointRadius: 4,
                    pointHoverRadius: 6
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    position: 'top',
                    rtl: true,
                    labels: {
                        font: { family: 'Tahoma, sans-serif', size: 12 }
                    }
                },
                tooltip: {
                    rtl: true,
                    bodyFont: { family: 'Tahoma, sans-serif' }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1,
                        callback: function(value) {
                            return formatNumber(value);
                        }
                    },
                    grid: { color: '#e2e8f0' }
                },
                x: {
                    grid: { display: false },
                    ticks: { font: { family: 'Tahoma, sans-serif', size: 11 } }
                }
            },
            interaction: {
                intersect: false,
                mode: 'index'
            }
        }
    });
}

/**
 * بارگذاری نمودار توزیع پلتفرم‌ها (دایره‌ای)
 * @param {object} platformData - داده‌های پلتفرم‌ها
 */
function loadPlatformChart(platformData) {
    const ctx = document.getElementById('platformChart');
    if (!ctx) return;
    
    const labels = Object.keys(platformData);
    const data = Object.values(platformData);
    
    new Chart(ctx, {
        type: 'pie',
        data: {
            labels: labels,
            datasets: [{
                data: data,
                backgroundColor: ['#667eea', '#764ba2', '#38a169', '#ed8936', '#e53e3e'],
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    position: 'top',
                    rtl: true,
                    labels: { font: { family: 'Tahoma, sans-serif' } }
                },
                tooltip: {
                    rtl: true,
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.raw || 0;
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percent = ((value / total) * 100).toFixed(1);
                            return `${label}: ${formatNumber(value)} (${percent}%)`;
                        }
                    }
                }
            }
        }
    });
}

// ============================================================
// راه‌اندازی خودکار (DOMContentLoaded)
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    // 1. تنظیم auto-refresh برای صفحات خاص
    if (document.querySelector('.auto-refresh')) {
        const interval = document.querySelector('.auto-refresh')?.dataset?.interval || 60000;
        autoRefresh(interval);
    }
    
    // 2. تنظیم فیلتر جداول
    const filterInput = document.getElementById('tableSearch');
    if (filterInput) {
        setupTableFilter('tableSearch', 'dataTable');
    }
    
    // 3. تنظیم دکمه‌های حذف با تأیید
    document.querySelectorAll('.confirm-delete').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const message = this.dataset.confirmMessage || DOWNLOADHUB.confirmMessages.delete;
            if (confirm(message)) {
                window.location.href = this.href;
            }
        });
    });
    
    // 4. تنظیم دکمه‌های لغو درخواست
    document.querySelectorAll('.confirm-cancel').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            if (confirm(DOWNLOADHUB.confirmMessages.cancelRequest)) {
                window.location.href = this.href;
            }
        });
    });
    
    // 5. تنظیم دکمه خروج
    const logoutBtn = document.querySelector('.logout');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (confirm(DOWNLOADHUB.confirmMessages.logout)) {
                window.location.href = this.href;
            }
        });
    }
    
    // 6. نمایش پیام‌های flash (در صورت وجود)
    const flashMessage = document.querySelector('.flash-message');
    if (flashMessage) {
        const type = flashMessage.dataset.type || 'info';
        const message = flashMessage.textContent;
        showToast(message, type);
        flashMessage.remove();
    }
});

// ============================================================
// انیمیشن‌های CSS (اضافه کردن به head)
// ============================================================
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    
    @keyframes slideOut {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }
    
    .toast-close {
        background: none;
        border: none;
        font-size: 1.2rem;
        cursor: pointer;
        color: #999;
        padding: 0 5px;
    }
    
    .toast-close:hover {
        color: #333;
    }
`;
document.head.appendChild(style);
