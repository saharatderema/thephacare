<?php
/**
 * Cron Job: Refresh eoc_group_stats ทุก 1 นาที
 * 
 * เรียกใช้: curl -s "https://yourdomain.com/pages/eoc/api/cron_refresh_stats.php?key=YOUR_SECRET_KEY"
 * 
 * ตั้งค่า Cron (cPanel / Linux):
 * * * * * * /usr/bin/curl -s "https://yourdomain.com/pages/eoc/api/cron_refresh_stats.php?key=YOUR_SECRET_KEY" > /dev/null 2>&1
 */

require_once __DIR__ . '/../includes/bootstrap.php';

// ===== CONFIG: ใส่ Secret Key ของคุณ =====
define('CRON_SECRET_KEY', '5F24FFA329C5C5004BA7053C3DAF46DA9CD761EB517328FEDF9E0C7FF0289CA7');
// ========================================

// Security check
$providedKey = $_GET['key'] ?? $_POST['key'] ?? '';
if (!hash_equals(CRON_SECRET_KEY, $providedKey)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    die('Forbidden: Invalid key');
}

// Allow only CLI or cron (optional)
// if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

try {
    $startTime = microtime(true);
    
    // Call stored procedure
    $pdo->exec("CALL sp_eoc_refresh_group_stats()");
    
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    $timestamp = date('Y-m-d H:i:s');
    
    // Log success
    $logMsg = "[$timestamp] OK - Stats refreshed in {$duration}ms\n";
    error_log($logMsg, 3, __DIR__ . '/../logs/cron_refresh_stats.log');
    
    // Output for cron log
    header('Content-Type: text/plain; charset=utf-8');
    echo $logMsg;
    
} catch (PDOException $e) {
    $timestamp = date('Y-m-d H:i:s');
    $logMsg = "[$timestamp] ERROR - " . $e->getMessage() . "\n";
    error_log($logMsg, 3, __DIR__ . '/../logs/cron_refresh_stats.log');
    
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $logMsg;
}