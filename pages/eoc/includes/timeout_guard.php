<?php
/**
 * ============================================================================
 * timeout_guard.php — ระบบตรวจจับการไม่ใช้งาน (Idle Timeout) 15 นาที
 * ============================================================================
 * วิธีใช้: วางแทรกไว้ก่อนปิด </body> ของทุกหน้าใน pages/eoc
 *     <?php require_once __DIR__ . '/includes/timeout_guard.php'; ?>
 *
 * พฤติกรรม:
 *   1) ฝั่งเซิร์ฟเวอร์ : ถ้า $_SESSION['last_activity'] เกิน 900 วินาที
 *      → ส่ง JS ให้พาไป logout.php?timeout=1 ทันที
 *        (logout.php จะทำลาย session + แสดงข้อความ แล้วพาไป index.php ที่ root)
 *   2) ฝั่ง client     : นาฬิกานับถอยหลัง 15 นาที
 *      - ใช้ <span id="timeout-timer"> ที่หน้ามีอยู่แล้ว ถ้าไม่มีจะสร้าง badge ลอย
 *      - เตือนด้วย Swal เมื่อเหลือ 60 วินาที
 *      - ครบ 15 นาที → logout อัตโนมัติ → index.php (ผ่าน logout.php)
 *   3) นับ "ไม่มีการใช้งาน" จริง: ทุกครั้งที่ผู้ใช้ขยับเมาส์/พิมพ์/แตะ/เลื่อน
 *      → รีเซ็ตนาฬิกา และ ping ไป api/session_ping.php เพื่อต่ออายุ
 *      $_SESSION['last_activity'] ฝั่งเซิร์ฟเวอร์ด้วย (รองรับหลาย tab ด้วย localStorage)
 * ============================================================================
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('EOC_TIMEOUT_SECONDS')) {
    define('EOC_TIMEOUT_SECONDS', 900); // 15 นาที
}

$eoc_tg_duration = (int) EOC_TIMEOUT_SECONDS;
$eoc_tg_now      = time();
$eoc_tg_last     = (int) ($_SESSION['last_activity'] ?? 0);
$eoc_tg_expired  = ($eoc_tg_last > 0 && ($eoc_tg_now - $eoc_tg_last) > $eoc_tg_duration);

if (!$eoc_tg_expired) {
    $_SESSION['last_activity'] = $eoc_tg_now;
}
$eoc_tg_time_left = $eoc_tg_expired ? 0 : $eoc_tg_duration;
?>
<script>
(function () {
    'use strict';

    var DURATION   = <?= (int) $eoc_tg_duration ?>;
    var TIME_LEFT  = <?= (int) $eoc_tg_time_left ?>;
    var EXPIRED    = <?= $eoc_tg_expired ? 'true' : 'false' ?>;
    var PING_URL   = 'api/session_ping.php';
    var LOGOUT_URL = '../../logout.php?timeout=1';
    var STORE_KEY  = 'eoc_last_activity_ms';

    // เซสชันหมดอายุฝั่งเซิร์ฟเวอร์ → ออกจากระบบทันที
    if (EXPIRED) { window.location.replace(LOGOUT_URL); return; }

    var deadline   = Date.now() + TIME_LEFT * 1000;
    var warned     = false;
    var loggingOut = false;
    var lastPing   = 0;
    var lastStore  = 0;
    var lastRender = '';

    function two(n) { return (n < 10 ? '0' : '') + n; }

    // สร้าง badge นาฬิกาลอยมุมขวาล่าง ถ้าหน้านี้ไม่มี #timeout-timer
    if (!document.getElementById('timeout-timer')) {
        var badge = document.createElement('div');
        badge.setAttribute('aria-label', 'เวลาคงเหลือก่อนออกนอกระบบอัตโนมัติ');
        badge.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:99999;'
            + 'display:flex;align-items:center;gap:8px;background:#111827;color:#fff;'
            + 'padding:8px 16px;border-radius:9999px;box-shadow:0 6px 16px rgba(0,0,0,.35);'
            + "font-family:'Kanit',system-ui,sans-serif;font-size:14px;font-weight:600;";
        badge.innerHTML = '<span aria-hidden="true">⏳</span><span id="timeout-timer">--:--</span>';
        document.body.appendChild(badge);
    }
    function render(left) {
        var el = document.getElementById('timeout-timer');
        if (!el) return;
        var txt = two(Math.floor(left / 60)) + ':' + two(left % 60);
        var col = (left <= 60) ? '#FCA5A5' : '';
        if (txt + '|' + col !== lastRender) {
            lastRender = txt + '|' + col;
            el.textContent = txt;
            el.style.color = col;
        }
    }

    function logoutNow() {
        if (loggingOut) return;
        loggingOut = true;
        render(0);
        try {
            if (typeof window.releaseUnloadGuard === 'function') { window.releaseUnloadGuard(); }
        } catch (e) {}
        window.location.href = LOGOUT_URL;
    }

    function warnSoon() {
        warned = true;
        if (window.Swal) {
            Swal.fire({
                icon: 'warning',
                title: 'เซสชันใกล้หมดอายุ',
                text: 'คุณจะถูกออกจากระบบภายใน 1 นาที หากไม่มีการใช้งาน',
                confirmButtonText: 'ตกลง',
                confirmButtonColor: '#F59E0B'
            });
        }
    }

    function readStore() {
        try { return parseInt(localStorage.getItem(STORE_KEY) || '0', 10) || 0; }
        catch (e) { return 0; }
    }

    var knownStore = readStore();

    function tick() {
        // ซิงก์กับ tab อื่น: ถ้ามี tab อื่นกำลังใช้งานอยู่ ให้ยืดเวลาออกไปด้วย
        var stored = readStore();
        if (stored > knownStore) {
            knownStore = stored;
            var storedDeadline = stored + DURATION * 1000;
            if (storedDeadline > deadline) {
                deadline = storedDeadline;
                warned = false;
            }
        }
        var left = Math.max(0, Math.round((deadline - Date.now()) / 1000));
        render(left);
        if (left <= 0) { logoutNow(); return; }
        if (!warned && left <= 60) { warnSoon(); }
    }

    // ping ต่ออายุ session ฝั่งเซิร์ฟเวอร์ (throttle ทุก 10 วินาที)
    function ping() {
        var now = Date.now();
        if (now - lastPing < 10000) return;
        lastPing = now;
        fetch(PING_URL, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (d && d.expired) { logoutNow(); } })
            .catch(function () { /* เน็ตสะดุด → ให้นาฬิกาฝั่ง client ทำงานต่อไป */ });
    }

    // ผู้ใช้ทำกิจกรรม → รีเซ็ตนาฬิกา (idle = ไม่มีการใช้งานจริง ๆ)
    function onActivity() {
        if (loggingOut) return;
        var now = Date.now();
        deadline = now + DURATION * 1000;
        warned = false;
        if (now - lastStore > 5000) {        // เขียน localStorage ให้ tab อื่นทุก 5 วินาที
            lastStore = now;
            knownStore = now;
            try { localStorage.setItem(STORE_KEY, String(now)); } catch (e) {}
        }
        tick();
        try { ping(); } catch (e) {}
    }

    ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll', 'wheel', 'click']
        .forEach(function (ev) { window.addEventListener(ev, onActivity, { passive: true }); });

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { tick(); try { ping(); } catch (e) {} }
    });

    // หน้าเพิ่งเปิด = มีการใช้งาน → เขียน localStorage ด้วย
    lastStore = Date.now();
    knownStore = lastStore;
    try { localStorage.setItem(STORE_KEY, String(lastStore)); } catch (e) {}

    render(TIME_LEFT);
    tick();
    setInterval(tick, 1000);
})();
</script>