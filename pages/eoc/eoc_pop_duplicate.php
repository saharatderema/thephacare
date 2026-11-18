<?php
// ============================================================================
// eoc_pop_duplicate.php — v5.3 ROLE-BASED NAVCARDS (2026-10-29)
// ----------------------------------------------------------------------------
// ✅ admin: แสดง 5 การ์ด (จัดการกลุ่ม/เพิ่ม/ยกเลิก/ตรวจซ้ำ/gstatus ผิด)
// ✅ user : แสดง 2 การ์ด (จัดการกลุ่ม/เพิ่ม)
// ✅ Performance: cache 60s + 3 lightweight queries (คงจาก v5.2)
// ✅ FIX v5.1 ทั้งหมดยังอยู่ (array-safe, alias-safe, respect search)
// ============================================================================

require_once __DIR__ . '/includes/bootstrap.php';

try { $pdo->exec("SET SESSION group_concat_max_len = 4096"); } catch (PDOException $e) {}

$timeout_duration = (isset($timeout_duration) && is_numeric($timeout_duration) && (int)$timeout_duration > 0) ? (int)$timeout_duration : 900;
$per_page         = 50;
$dedupe_batch_max = 5000;

/* ---------- All groups ---------- */
require_once __DIR__ . '/includes/group_context.php';
$all_groups = function_exists('eoc_all_groups') ? eoc_all_groups($pdo) : array();
if (empty($all_groups)) {
    try {
        $rs = $pdo->query("SELECT gstatus_id, gstatus_name FROM eoc_gstatus ORDER BY gstatus_id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rs as $r) {
            $all_groups[$r['gstatus_id']] = array(
                'gstatus_id' => $r['gstatus_id'],
                'gstatus_name' => $r['gstatus_name'],
                'icon' => 'fas fa-users', 'color' => '#F59E0B',
            );
        }
    } catch (PDOException $e) { error_log('[dup] groups: ' . $e->getMessage()); }
}
$current_group = eoc_resolve_group($pdo);

/* ---------- User context ---------- */
$user_name       = (isset($user_name) && $user_name !== '') ? $user_name : 'ไม่ระบุชื่อ';
$user_hospcode   = (isset($user_hospcode) && $user_hospcode !== '') ? (string)$user_hospcode
                    : (isset($_SESSION['user_hospcode']) ? (string)$_SESSION['user_hospcode'] : '');
$status_user     = (isset($status_user) && $status_user !== '') ? $status_user : 'ไม่ระบุสถานะ';
$current_user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$is_admin        = (strtolower(trim((string)$status_user)) === 'admin');
$is_province     = empty($user_hospcode) || $user_hospcode === '00860';
$see_all         = ($is_admin || $is_province);
$table_exists    = is_array($table_exists ?? null) ? $table_exists : array();

$summary_cache_key = 'dup_summary_v5';
$fingerprint = md5(implode(',', array_keys($all_groups)) . '|' . $user_hospcode . '|' . ($see_all ? '1' : '0'));

/* ---------- CSRF ---------- */
if (empty($_SESSION['dup_csrf'])) {
    $_SESSION['dup_csrf'] = function_exists('random_bytes')
        ? bin2hex(random_bytes(32))
        : (function_exists('openssl_random_pseudo_bytes')
            ? bin2hex(openssl_random_pseudo_bytes(32))
            : md5(uniqid(mt_rand(), true)) . md5(uniqid(mt_rand(), true)));
}
$dup_csrf = $_SESSION['dup_csrf'];

/* ---------- Helpers ---------- */
if (!function_exists('eoc_h')) { function eoc_h($v) { return htmlspecialchars((string)($v === null ? '' : $v), ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('eoc_nf')) { function eoc_nf($n) { return number_format((int)($n ?? 0)); } }
function build_back_url($groups, $search, $onlySvc, $page) {
    $p = array();
    if (!empty($groups)) $p['groups'] = implode(',', $groups);
    if ($search !== '') $p['search'] = $search;
    if ($onlySvc) $p['only_with_service'] = 1;
    if ((int)$page > 1) $p['page'] = (int)$page;
    return 'eoc_pop_duplicate.php' . ($p ? '?' . http_build_query($p) : '');
}

/* ---------- Parse group selection ---------- */
$selected_groups = array();
if (isset($_GET['groups'])) {
    $gp = $_GET['groups'];
    if (is_array($gp)) {
        foreach ($gp as $g) if (is_string($g) && isset($all_groups[$g])) $selected_groups[] = $g;
    } elseif (is_string($gp)) {
        if ($gp === 'all') $selected_groups = array_keys($all_groups);
        elseif ($gp !== '') foreach (explode(',', $gp) as $g) { $g = trim($g); if (isset($all_groups[$g])) $selected_groups[] = $g; }
    }
}
$selected_groups = array_values(array_unique($selected_groups));
$has_selection   = !empty($selected_groups);
$is_all_selected = ($has_selection && count($selected_groups) === count($all_groups));

$searchIdcard = trim((string)(isset($_GET['search']) ? $_GET['search'] : ''));
$searchIdcard = preg_replace('/\D/', '', $searchIdcard);
if (strlen($searchIdcard) > 13) $searchIdcard = substr($searchIdcard, 0, 13);
$onlyWithSvc = isset($_GET['only_with_service']);
$page        = max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1));

/* ============================================================
 * Summary per group — cache 60s
 * ============================================================ */
if (!isset($_SESSION[$summary_cache_key]) || !is_array($_SESSION[$summary_cache_key])) {
    $_SESSION[$summary_cache_key] = array();
}
$cache_hit = (
    isset($_SESSION[$summary_cache_key][$fingerprint])
    && isset($_SESSION[$summary_cache_key][$fingerprint]['t'])
    && (time() - $_SESSION[$summary_cache_key][$fingerprint]['t']) < 60
);

if ($cache_hit) {
    $group_stats = $_SESSION[$summary_cache_key][$fingerprint]['s'];
} else {
    $group_stats = array();
    foreach ($all_groups as $gid => $ginfo) {
        $group_stats[$gid] = array(
            'gstatus' => $gid,
            'name'    => isset($ginfo['gstatus_name']) ? $ginfo['gstatus_name'] : $gid,
            'icon'    => isset($ginfo['icon']) ? $ginfo['icon'] : 'fas fa-users',
            'color'   => (isset($ginfo['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $ginfo['color'])) ? $ginfo['color'] : '#F59E0B',
            'total' => 0, 'dup' => 0, 'excess' => 0,
        );
    }

    $hc_where = $see_all ? '' : " AND EXISTS (SELECT 1 FROM person px WHERE px.idcard = e.idcard AND px.hospcode = " . $pdo->quote($user_hospcode) . ")";

    if (!empty($all_groups)) {
        $gquoted = implode(',', array_map(function ($g) use ($pdo) { return $pdo->quote((string)$g); }, array_keys($all_groups)));

        try {
            $sql = "SELECT e.gstatus, COUNT(*) AS total_rows
                    FROM eoc_pop e
                    WHERE e.gstatus IN ($gquoted) AND e.idcard IS NOT NULL AND e.idcard <> '' $hc_where
                    GROUP BY e.gstatus";
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (isset($group_stats[$r['gstatus']])) $group_stats[$r['gstatus']]['total'] = (int)$r['total_rows'];
            }
        } catch (PDOException $e) { error_log('[dup] totals: ' . $e->getMessage()); }

        try {
            $sql = "SELECT t.gstatus, COUNT(*) AS dup_groups, SUM(t.cnt - 1) AS excess_rows
                    FROM (
                        SELECT e.gstatus, e.idcard, COUNT(*) AS cnt
                        FROM eoc_pop e
                        WHERE e.gstatus IN ($gquoted) AND e.idcard IS NOT NULL AND e.idcard <> '' $hc_where
                        GROUP BY e.gstatus, e.idcard
                        HAVING cnt > 1
                    ) t
                    GROUP BY t.gstatus";
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (isset($group_stats[$r['gstatus']])) {
                    $group_stats[$r['gstatus']]['dup']    = (int)$r['dup_groups'];
                    $group_stats[$r['gstatus']]['excess'] = (int)$r['excess_rows'];
                }
            }
        } catch (PDOException $e) { error_log('[dup] dups: ' . $e->getMessage()); }
    }

    $_SESSION[$summary_cache_key][$fingerprint] = array('t' => time(), 's' => $group_stats);
    foreach ($_SESSION[$summary_cache_key] as $k => $v) {
        if (!isset($v['t']) || (time() - $v['t']) >= 60) unset($_SESSION[$summary_cache_key][$k]);
    }
}

/* ============================================================
 * Dedupe POST
 * ============================================================ */
if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST'
    && (isset($_POST['action']) ? $_POST['action'] : '') === 'dedupe') {

    $mode      = (isset($_POST['mode']) && $_POST['mode'] === 'listed') ? 'listed' : 'all_selected';
    $q_back    = preg_replace('/\D/', '', isset($_POST['q']) ? trim((string)$_POST['q']) : '');
    if (strlen($q_back) > 13) $q_back = substr($q_back, 0, 13);
    $page_back = isset($_POST['page']) ? max(1, (int)$_POST['page']) : 1;
    $flag_back = !empty($_POST['flag_only_svc']);

    $back_groups = array();
    if (!empty($_POST['groups_back'])) {
        foreach (explode(',', (string)$_POST['groups_back']) as $g) { $g = trim($g); if (isset($all_groups[$g])) $back_groups[] = $g; }
    }

    $flash = array('err', 'เกิดข้อผิดพลาด');
    try {
        if (!isset($_POST['csrf']) || !hash_equals($dup_csrf, (string)$_POST['csrf'])) {
            throw new Exception('เซสชันหมดอายุหรือคำขอไม่ถูกต้อง กรุณาโหลดหน้าใหม่');
        }

        $batch = function_exists('random_bytes')
            ? date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8)
            : date('Ymd_His') . '_' . substr(md5(uniqid(mt_rand(), true)), 0, 8);
        $actor = (string)($user_name ? $user_name : 'system');

        $filter_parts = array();

        if ($mode === 'listed') {
            $pairs_raw = isset($_POST['pairs']) ? $_POST['pairs'] : array();
            if (is_string($pairs_raw)) $pairs_raw = array($pairs_raw);
            $clean = array();
            foreach ((array)$pairs_raw as $p) {
                $p = (string)$p;
                if (strpos($p, '|') === false) continue;
                list($g, $idc) = explode('|', $p, 2);
                if (isset($all_groups[$g]) && preg_match('/^\d{13}$/', $idc)) $clean[] = array('g' => $g, 'idc' => $idc);
            }
            if (empty($clean)) throw new RuntimeException('ไม่ได้เลือกผู้ป่วย');
            $orParts = array();
            foreach ($clean as $p) {
                $orParts[] = "({T}.gstatus = " . $pdo->quote($p['g']) . " AND {T}.idcard = " . $pdo->quote($p['idc']) . ")";
            }
            $filter_parts[] = "(" . implode(' OR ', $orParts) . ")";
        } else {
            if (empty($back_groups)) throw new RuntimeException('ไม่ได้เลือกกลุ่ม');
            $gquoted = implode(',', array_map(function ($g) use ($pdo) { return $pdo->quote((string)$g); }, $back_groups));
            $filter_parts[] = "{T}.gstatus IN ($gquoted)";
            $filter_parts[] = "{T}.idcard IS NOT NULL";
            $filter_parts[] = "{T}.idcard <> ''";
            if ($q_back !== '') $filter_parts[] = "{T}.idcard = " . $pdo->quote($q_back);
            if ($flag_back)    $filter_parts[] = "EXISTS (SELECT 1 FROM eoc_services sx WHERE sx.idcard = {T}.idcard)";
        }

        if (!$see_all) {
            $filter_parts[] = "EXISTS (SELECT 1 FROM person px WHERE px.idcard = {T}.idcard AND px.hospcode = " . $pdo->quote($user_hospcode) . ")";
        }

        $filter_tpl   = implode(' AND ', $filter_parts);
        $filter_outer = str_replace('{T}', 'e', $filter_tpl);
        $filter_inner = str_replace('{T}', 'e2', $filter_tpl);

        $pdo->beginTransaction();

        $countSql = "
            SELECT COUNT(*)
            FROM eoc_pop e
            INNER JOIN (
                SELECT e2.gstatus, e2.idcard, MAX(e2.id) AS keep_id
                FROM eoc_pop e2
                WHERE $filter_inner
                GROUP BY e2.gstatus, e2.idcard
                HAVING COUNT(*) > 1
            ) mx ON mx.gstatus = e.gstatus AND mx.idcard = e.idcard
            WHERE $filter_outer
              AND e.id < mx.keep_id
        ";
        $pre_count = (int)$pdo->query($countSql)->fetchColumn();

        if ($pre_count === 0) {
            $pdo->rollBack();
            $_SESSION['eoc_dedupe_flash'] = array('ok', 'ไม่พบข้อมูลซ้ำที่ต้องดำเนินการ');
            $_SESSION[$summary_cache_key] = array();
            header('Location: ' . build_back_url($back_groups, $q_back, $flag_back, $page_back));
            exit;
        }
        if ($pre_count > $dedupe_batch_max) {
            throw new RuntimeException("พบแถวที่จะลบ " . number_format($pre_count) . " แถว เกินขีดจำกัด (" . number_format($dedupe_batch_max) . " แถว)");
        }

        $pdo->exec("DROP TEMPORARY TABLE IF EXISTS tmp_dedupe");
        $pdo->exec("CREATE TEMPORARY TABLE tmp_dedupe (del_id INT NOT NULL PRIMARY KEY, gstatus VARCHAR(20), idcard VARCHAR(20), keep_id INT) ENGINE=InnoDB");

        $pdo->query("
            INSERT INTO tmp_dedupe (del_id, gstatus, idcard, keep_id)
            SELECT e.id, e.gstatus, e.idcard, mx.keep_id
            FROM eoc_pop e
            INNER JOIN (
                SELECT e2.gstatus, e2.idcard, MAX(e2.id) AS keep_id
                FROM eoc_pop e2
                WHERE $filter_inner
                GROUP BY e2.gstatus, e2.idcard
                HAVING COUNT(*) > 1
            ) mx ON mx.gstatus = e.gstatus AND mx.idcard = e.idcard
            WHERE $filter_outer
              AND e.id < mx.keep_id
        ");

        $tmp = $pdo->query("SELECT COUNT(*) AS c, COUNT(DISTINCT CONCAT(gstatus,'|',idcard)) AS g FROM tmp_dedupe")->fetch(PDO::FETCH_ASSOC);
        $deleted = (int)$tmp['c'];
        $groups  = (int)$tmp['g'];

        if ($deleted === 0) {
            $pdo->rollBack();
            $pdo->exec("DROP TEMPORARY TABLE IF EXISTS tmp_dedupe");
            $flash = array('ok', 'ไม่พบข้อมูลซ้ำ');
        } else {
            $has_backup = false;
            try { $pdo->query("SELECT 1 FROM eoc_pop_duplicate LIMIT 1"); $has_backup = true; } catch (PDOException $e) {}

            if (!$has_backup) {
                $pdo->exec("CREATE TABLE IF NOT EXISTS eoc_pop_duplicate LIKE eoc_pop");
                foreach (array('backup_at DATETIME NULL','backup_by VARCHAR(255) NULL','backup_batch VARCHAR(100) NULL','backup_note TEXT NULL') as $col) {
                    try { $pdo->exec("ALTER TABLE eoc_pop_duplicate ADD COLUMN $col"); } catch (PDOException $e) {}
                }
            }

            $cols = array();
            foreach ($pdo->query("SHOW COLUMNS FROM eoc_pop")->fetchAll(PDO::FETCH_ASSOC) as $c) $cols[] = $c['Field'];
            if (!$cols) throw new RuntimeException('อ่าน schema eoc_pop ไม่ได้');

            $srcCols  = implode(', ', array_map(function ($c) { return "e.`$c`"; }, $cols));
            $destCols = implode(', ', array_map(function ($c) { return "`$c`"; }, $cols)) . ', backup_at, backup_by, backup_batch, backup_note';

            $stIns = $pdo->prepare("
                INSERT INTO eoc_pop_duplicate ($destCols)
                SELECT $srcCols, NOW(), :by, :batch, CONCAT('dedupe keep_id=', t.keep_id)
                FROM eoc_pop e JOIN tmp_dedupe t ON t.del_id = e.id
            ");
            $stIns->execute(array(':by' => $actor, ':batch' => $batch));

            $stChk = $pdo->prepare("SELECT COUNT(*) FROM eoc_pop_duplicate WHERE backup_batch = ?");
            $stChk->execute(array($batch));
            if ((int)$stChk->fetchColumn() !== $deleted) throw new RuntimeException("สำรองข้อมูลไม่ครบ ยกเลิกการลบ");

            $pdo->exec("DELETE e FROM eoc_pop e JOIN tmp_dedupe t ON t.del_id = e.id");
            $pdo->commit();
            $pdo->exec("DROP TEMPORARY TABLE IF EXISTS tmp_dedupe");

            try {
                $desc = 'ลบซ้ำ eoc_pop: ' . $deleted . ' แถว · ' . $groups . ' idcard · โหมด: ' . $mode . ' | groups=' . implode(',', $back_groups) . ' | batch: ' . $batch;
                $lg = $pdo->prepare("INSERT INTO system_logs (user_id, action, description, ip_address, created_at) VALUES (:u, 'dedupe_eoc_pop', :d, :ip, NOW())");
                $lg->execute(array(':u' => $current_user_id, ':d' => $desc, ':ip' => (string)(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '')));
            } catch (Exception $e) { error_log('[dup] log: ' . $e->getMessage()); }

            $flash = array('ok', 'สำรอง ' . eoc_nf($deleted) . ' แถว · ลบ ' . eoc_nf($deleted) . ' แถว จาก ' . eoc_nf($groups) . ' idcard · batch: ' . $batch);
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        try { $pdo->exec("DROP TEMPORARY TABLE IF EXISTS tmp_dedupe"); } catch (Exception $e2) {}
        error_log('[dup] dedupe: ' . $e->getMessage());
        $flash = array('err', 'ดำเนินการไม่สำเร็จ: ' . $e->getMessage());
    }

    $_SESSION['eoc_dedupe_flash'] = $flash;
    $_SESSION[$summary_cache_key] = array();
    header('Location: ' . build_back_url($back_groups, $q_back, $flag_back, $page_back));
    exit;
}

$flash = isset($_SESSION['eoc_dedupe_flash']) ? $_SESSION['eoc_dedupe_flash'] : null;
unset($_SESSION['eoc_dedupe_flash']);

/* ============================================================
 * Main list — 3 lightweight queries
 * ============================================================ */
$duplicates    = array();
$total_dup     = 0;
$total_rows    = 0;
$total_pages   = 1;
$error_message = '';

if ($has_selection) {
    try {
        $gquoted = implode(',', array_map(function ($g) use ($pdo) { return $pdo->quote((string)$g); }, $selected_groups));

        $where = "WHERE e.gstatus IN ($gquoted) AND e.idcard IS NOT NULL AND e.idcard <> ''";
        if ($searchIdcard !== '')  $where .= " AND e.idcard = " . $pdo->quote($searchIdcard);
        if ($onlyWithSvc)          $where .= " AND EXISTS (SELECT 1 FROM eoc_services s WHERE s.idcard = e.idcard)";
        if (!$see_all)             $where .= " AND EXISTS (SELECT 1 FROM person px WHERE px.idcard = e.idcard AND px.hospcode = " . $pdo->quote($user_hospcode) . ")";

        $agg = $pdo->query("
            SELECT COUNT(*) AS dup_groups, COALESCE(SUM(c),0) AS dup_rows
            FROM (
                SELECT COUNT(*) AS c FROM eoc_pop e
                $where
                GROUP BY e.gstatus, e.idcard
                HAVING COUNT(*) > 1
            ) t
        ")->fetch(PDO::FETCH_ASSOC);
        $total_dup  = (int)($agg['dup_groups'] ?? 0);
        $total_rows = (int)($agg['dup_rows'] ?? 0);

        $total_pages = max(1, (int)ceil($total_dup / $per_page));
        if ($page > $total_pages) $page = $total_pages;
        $offset = ($page - 1) * $per_page;

        if ($total_dup > 0) {
            $pageSql = "
                SELECT e.gstatus, e.idcard
                FROM eoc_pop e
                $where
                GROUP BY e.gstatus, e.idcard
                HAVING COUNT(*) > 1
                ORDER BY e.gstatus, e.idcard
                LIMIT " . (int)$per_page . " OFFSET " . (int)$offset;
            $pageRows = $pdo->query($pageSql)->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($pageRows)) {
                $pairsUnion = array();
                $idSet = array();
                foreach ($pageRows as $pr) {
                    $pairsUnion[] = "SELECT " . $pdo->quote($pr['gstatus']) . " AS gstatus, " . $pdo->quote($pr['idcard']) . " AS idcard";
                    $idSet[$pr['idcard']] = true;
                }
                $pairsSql = implode(' UNION ALL ', $pairsUnion);
                $idQuoted = implode(',', array_map(function ($v) use ($pdo) { return $pdo->quote((string)$v); }, array_keys($idSet)));

                $detailSql = "
                    SELECT e.gstatus, e.idcard, COUNT(*) AS dup_count, MAX(e.d_update) AS last_update
                    FROM eoc_pop e
                    INNER JOIN ( $pairsSql ) p ON p.gstatus = e.gstatus AND p.idcard = e.idcard
                    GROUP BY e.gstatus, e.idcard
                ";
                $detailRows = $pdo->query($detailSql)->fetchAll(PDO::FETCH_ASSOC);

                $personMap = array();
                if ($idQuoted !== '') {
                    $personSql = "
                        SELECT idcard, MAX(name) AS name, MAX(hospcode) AS hospcode
                        FROM person
                        WHERE idcard IN ($idQuoted) AND idcard IS NOT NULL AND idcard <> ''
                        GROUP BY idcard
                    ";
                    foreach ($pdo->query($personSql)->fetchAll(PDO::FETCH_ASSOC) as $p) $personMap[$p['idcard']] = $p;
                }

                $svcMap = array();
                if ($idQuoted !== '') {
                    $svcSql = "
                        SELECT s.idcard, COUNT(*) AS svc_count,
                               GROUP_CONCAT(DISTINCT s.service_no ORDER BY s.id SEPARATOR ', ') AS svc_list
                        FROM eoc_services s
                        WHERE s.idcard IN ($idQuoted)
                        GROUP BY s.idcard
                    ";
                    foreach ($pdo->query($svcSql)->fetchAll(PDO::FETCH_ASSOC) as $s) $svcMap[$s['idcard']] = $s;
                }

                foreach ($detailRows as $d) {
                    $idc = $d['idcard'];
                    $p = isset($personMap[$idc]) ? $personMap[$idc] : null;
                    $s = isset($svcMap[$idc]) ? $svcMap[$idc] : null;
                    $duplicates[] = array(
                        'gstatus' => $d['gstatus'], 'idcard' => $idc,
                        'dup_count' => $d['dup_count'], 'last_update' => $d['last_update'],
                        'name' => $p ? $p['name'] : null, 'hospcode' => $p ? $p['hospcode'] : null,
                        'service_count' => $s ? $s['svc_count'] : 0,
                        'service_list' => $s ? $s['svc_list'] : '',
                    );
                }
            }
        }
    } catch (PDOException $e) {
        error_log('[dup] main: ' . $e->getMessage());
        $error_message = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
    }
}

/* ---------- Summary ---------- */
$groups_with_data = $groups_with_dup = $sum_all_total = $sum_all_dup = $sum_all_excess = 0;
foreach ($group_stats as $gs) {
    if ($gs['total'] > 0) $groups_with_data++;
    if ($gs['dup'] > 0)   $groups_with_dup++;
    $sum_all_total += $gs['total']; $sum_all_dup += $gs['dup']; $sum_all_excess += $gs['excess'];
}

$last_activity = isset($_SESSION['last_activity']) ? (int)$_SESSION['last_activity'] : time();
$time_left = max(0, $timeout_duration - (time() - $last_activity));
$time_left_formatted = sprintf('%02d:%02d', floor($time_left / 60), $time_left % 60);
$g_back = implode(',', $selected_groups);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#0F172A">
<title>ตรวจข้อมูลซ้ำ | VOC – Thepha Care</title>
<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" media="print" onload="this.media='all'">
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
<noscript>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600&display=swap" rel="stylesheet">
</noscript>
<style>
:root{--primary:#2563EB;--primary-d:#1D4ED8;--danger:#EF4444;--danger-d:#DC2626;--warning:#F59E0B;--warning-d:#B45309;--success:#10B981;--success-d:#059669;--ink:#0F172A;--ink-2:#334155;--ink-3:#64748B;--ink-4:#94A3B8;--line:#E2E8F0;--line-2:#CBD5E1;--bg:#F8FAFC;--card:#FFFFFF;--shadow-sm:0 1px 2px rgba(15,23,42,.04);--shadow:0 1px 3px rgba(15,23,42,.06),0 1px 2px rgba(15,23,42,.04);--shadow-md:0 4px 6px -1px rgba(15,23,42,.08),0 2px 4px -1px rgba(15,23,42,.04);--radius:12px;--radius-lg:16px;--radius-sm:8px}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{-webkit-text-size-adjust:100%}
body{font-family:'Kanit',system-ui,-apple-system,sans-serif;font-size:15px;background:var(--bg);color:var(--ink);line-height:1.5;overflow-x:hidden;min-height:100vh;min-height:100dvh}
body.nav-open{overflow:hidden}
a{color:inherit;text-decoration:none}
button,input,select{font-family:inherit}
button{cursor:pointer;border:0;background:none;color:inherit}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
.main-content{min-width:0;overflow-x:clip;display:flex;flex-direction:column;min-height:100vh;min-height:100dvh}
.main-content>.hdr{flex-shrink:0}
.main-content>.main{flex:1 0 auto;width:100%;min-width:0}
.main-content>.foot{flex-shrink:0;margin-top:auto}
.eoc-side-top,.eoc-backdrop{display:none}
@media(min-width:1024px){.main-content{margin-left:288px}.eoc-side{display:contents}}
@media(max-width:1023.98px){
.eoc-side{position:fixed;top:0;bottom:0;left:0;width:min(86vw,20rem);z-index:60;background:#fff;overflow-y:auto;overscroll-behavior:contain;padding:0 .75rem calc(1rem + env(safe-area-inset-bottom,0px));transform:translateX(-100%);visibility:hidden}
.eoc-side.open{transform:none;visibility:visible;box-shadow:0 0 40px rgba(0,0,0,.35)}
.eoc-side-top{display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:1;background:#fff;padding:calc(.75rem + env(safe-area-inset-top,0px)) 0 .75rem;margin-bottom:.5rem;border-bottom:1px solid #E5E7EB;font-weight:600;color:#1E293B}
.eoc-side-top button{width:44px;height:44px;border:1px solid #E5E7EB;border-radius:10px;background:#F8FAFC}
.eoc-side>*:not(.eoc-side-top){position:static!important;inset:auto!important;transform:none!important;width:100%!important;max-width:100%!important;height:auto!important}
.eoc-side>.hidden{display:block!important}
.eoc-backdrop{display:block;position:fixed;inset:0;z-index:55;background:rgba(15,23,42,.5)}
.eoc-backdrop[hidden]{display:none}
.main-content{margin-left:0}
.hide-sm{display:none!important}}
.hdr{background:linear-gradient(180deg,#0F172A 0%,#1E293B 100%);color:#fff;padding:env(safe-area-inset-top,0px) 0 0;position:relative;z-index:40;box-shadow:0 4px 6px -1px rgba(0,0,0,.1)}
@media(min-width:1024px){.hdr{position:sticky;top:0}}
.hdr-in{padding:.85rem max(1rem,env(safe-area-inset-right,0px)) .85rem max(1rem,env(safe-area-inset-left,0px))}
@media(min-width:640px){.hdr-in{padding-left:1.5rem;padding-right:1.5rem}}
@media(min-width:1024px){.hdr-in{padding:1rem 2rem}}
.glass{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12)}
.hdr-row{display:flex;align-items:center;justify-content:space-between;gap:.75rem}
.hdr-l{display:flex;align-items:center;gap:.75rem;min-width:0}
.hdr h1{font-size:1rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
@media(min-width:640px){.hdr h1{font-size:1.25rem}}
.hdr small{display:none;color:#94A3B8;font-size:.75rem}
@media(min-width:640px){.hdr small{display:block}}
.ibtn{width:44px;height:44px;flex:none;display:flex;align-items:center;justify-content:center;border-radius:var(--radius-sm);color:#fff}
@media(min-width:1024px){.ibtn.nav{display:none}}
.logo{display:none;padding:.6rem .7rem;border-radius:var(--radius-sm);font-size:1.15rem}
@media(min-width:640px){.logo{display:block}}
.hdr-user{display:none;align-items:center;gap:.65rem;padding:.4rem .75rem;border-radius:var(--radius-sm);max-width:16rem;font-size:.8rem}
@media(min-width:768px){.hdr-user{display:flex}}
.hdr-user b{display:block;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hdr-user span{display:block;color:#94A3B8;font-size:.72rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.avatar{width:34px;height:34px;flex:none;border-radius:50%;background:linear-gradient(135deg,#3B82F6,#2563EB);display:flex;align-items:center;justify-content:center;font-size:.85rem}
.btn-ic{width:40px;height:40px;flex:none;display:inline-flex;align-items:center;justify-content:center;border-radius:var(--radius-sm);color:#fff;box-shadow:var(--shadow-sm)}
.btn-ic:hover{filter:brightness(1.1)}
.hdr-sub{margin-top:.5rem;padding-top:.5rem;border-top:1px solid rgba(255,255,255,.08);font-size:.72rem;color:#94A3B8;display:flex;flex-wrap:wrap;justify-content:space-between;gap:.25rem 1rem}
.hdr-sub span{display:inline-flex;align-items:center;gap:.35rem}
.main{padding:1rem max(1rem,env(safe-area-inset-right,0px)) 2rem max(1rem,env(safe-area-inset-left,0px))}
@media(min-width:640px){.main{padding:1.5rem}}
@media(min-width:1024px){.main{padding:2rem}}
.wrap{max-width:80rem;margin:0 auto;display:flex;flex-direction:column;gap:1.25rem;width:100%}
.bar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.75rem}
.crumb{font-size:.8rem;color:var(--ink-3);display:flex;align-items:center;gap:.35rem;flex-wrap:wrap}
.crumb a{color:var(--ink-3);transition:color .1s}
.crumb a:hover{color:var(--ink-2)}
.crumb i{font-size:.55rem;color:var(--ink-4)}
.crumb b{color:var(--ink-2);font-weight:600}
.btn-ic-back{width:40px;height:40px;flex:none;display:inline-flex;align-items:center;justify-content:center;border-radius:var(--radius-sm);background:#6B7280;color:#fff;box-shadow:var(--shadow-sm);transition:filter .15s}
.btn-ic-back:hover{filter:brightness(1.08)}

/* ✅ Navcards — flex wrap แบบสม่ำเสมอกับหน้าอื่น */
.navcards{display:flex;flex-wrap:wrap;gap:.75rem}
/* ✅ navcards-user: จำกัดความกว้างสูงสุด (สำหรับ user) */
.navcards.navcards-user{max-width:600px}
@media(max-width:480px){.navcards.navcards-user{max-width:none}}

.nav-card{display:flex;flex-direction:row;align-items:flex-start;gap:.5rem;background:#fff;border:2px solid #F0F0F0;border-radius:10px;padding:.75rem;transition:border-color .15s,background .15s,box-shadow .15s;min-width:150px;flex:1 1 150px;text-align:left}
.nav-card:hover{border-color:var(--gc)}
.nav-card.active{background:#ECFDF5;border-color:#10B981;box-shadow:0 0 0 3px rgba(16,185,129,.2)}
.nav-card .ic{font-size:1.25rem;line-height:1;margin-top:.05rem;flex-shrink:0}
.nav-card .info{flex:1;min-width:0}
.nav-card .info b{display:block;font-size:.75rem;font-weight:700;color:#1F293B;margin:0 0 .1rem 0;line-height:1.2}
.nav-card .info small{display:block;font-size:.65rem;font-weight:400;color:#6B7280;margin:0;line-height:1.3}
.note{padding:.85rem 1rem;border-radius:var(--radius);font-size:.875rem;display:flex;gap:.75rem;align-items:flex-start;box-shadow:var(--shadow-sm)}
.note.ok{background:#F0FDF4;border:1px solid #BBF7D0;color:#166534}
.note.err{background:#FEF2F2;border:1px solid #FECACA;color:#B91C1C}
.note.warn{background:#FFFBEB;border:1px solid #FDE68A;color:#B45309}
.note i{flex-shrink:0;margin-top:2px}
.note b{font-weight:600}
.note small{display:block;font-size:.75rem;opacity:.9;margin-top:.25rem}
.kpi-strip{display:grid;grid-template-columns:repeat(2,1fr);gap:.75rem}
@media(min-width:640px){.kpi-strip{grid-template-columns:repeat(4,1fr)}}
.kpi{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:.85rem 1rem;box-shadow:var(--shadow-sm);display:flex;align-items:center;gap:.75rem}
.kpi-icon{width:38px;height:38px;flex:none;border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;font-size:1rem}
.kpi-label{font-size:.7rem;font-weight:600;color:var(--ink-3);text-transform:uppercase;letter-spacing:.03em}
.kpi-value{font-size:1.35rem;font-weight:700;color:var(--ink);line-height:1.1;margin-top:.1rem;font-variant-numeric:tabular-nums}
.panel{background:var(--card);border-radius:var(--radius-lg);border:1px solid var(--line);box-shadow:var(--shadow);padding:1.25rem}
@media(min-width:640px){.panel{padding:1.5rem}}
.panel-h{display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:.75rem;margin-bottom:1.15rem}
.panel-title{display:flex;align-items:center;gap:.65rem;font-size:1.1rem;font-weight:600;color:var(--ink)}
@media(min-width:640px){.panel-title{font-size:1.2rem}}
.step-num{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--primary-d));color:#fff;font-weight:700;font-size:.85rem;flex-shrink:0}
.panel-sub{color:var(--ink-3);font-size:.85rem;margin-top:.3rem;margin-left:calc(28px + .65rem)}
@media(max-width:480px){.panel-sub{margin-left:0}}
.group-toolbar{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;margin-bottom:1rem;padding:.75rem 1rem;background:var(--bg);border:1px solid var(--line);border-radius:var(--radius-sm)}
.group-toolbar .info{font-size:.85rem;color:var(--ink-2);margin-right:auto}
.group-toolbar .info b{color:var(--ink);font-weight:600}
.group-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:.75rem}
@media(min-width:640px){.group-grid{grid-template-columns:repeat(3,1fr)}}
@media(min-width:1024px){.group-grid{grid-template-columns:repeat(4,1fr)}}
@media(min-width:1280px){.group-grid{grid-template-columns:repeat(5,1fr)}}
.g-card{position:relative;background:var(--card);border:2px solid var(--line);border-radius:var(--radius);padding:.9rem;cursor:pointer;transition:all .15s ease;display:flex;flex-direction:column;gap:.6rem;user-select:none;overflow:hidden}
.g-card:hover{border-color:var(--line-2);box-shadow:var(--shadow-md);transform:translateY(-1px)}
.g-card.is-selected{border-color:var(--gc);box-shadow:0 0 0 3px rgba(245,158,11,.15),var(--shadow-md)}
.g-card.is-selected::after{content:'\2713';position:absolute;top:.5rem;right:.5rem;width:22px;height:22px;background:var(--gc);color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;box-shadow:var(--shadow-sm)}
.g-card.is-empty{opacity:.55;cursor:not-allowed}
.g-card input{position:absolute;opacity:0;pointer-events:none}
.g-head{display:flex;align-items:center;gap:.6rem;min-width:0;padding-right:1.5rem}
.g-icon{width:36px;height:36px;flex:none;border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;font-size:1rem}
.g-title{font-weight:600;font-size:.9rem;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.2}
.g-id{font-size:.7rem;color:var(--ink-4);font-family:ui-monospace,monospace;margin-top:.1rem}
.g-stats{display:flex;flex-direction:column;gap:.25rem;font-size:.75rem;color:var(--ink-2);padding-top:.5rem;border-top:1px dashed var(--line)}
.g-stats > div{display:flex;justify-content:space-between;align-items:center;gap:.5rem}
.g-stats b{color:var(--ink);font-weight:600;font-variant-numeric:tabular-nums}
.g-stats .s-dup b{color:var(--warning-d)}
.g-stats .s-exc b{color:var(--danger)}
.g-stats .s-none{color:var(--ink-4);font-style:italic;justify-content:center;padding:.25rem 0}
.tools{background:var(--bg);border:1px solid var(--line);border-radius:var(--radius);padding:1rem;margin-top:1rem}
.search-row{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center}
.fld{width:100%;font-size:16px;color:var(--ink);background:var(--card);border:1.5px solid var(--line-2);border-radius:var(--radius-sm);padding:.6rem .85rem;outline:none;transition:border-color .15s,box-shadow .15s}
.fld:focus{border-color:var(--primary);box-shadow:0 0 0 4px rgba(37,99,235,.12)}
.search-row .fld{flex:1 1 200px;min-width:0}
.chk-wrap{display:inline-flex;align-items:center;gap:.5rem;padding:0 .9rem;min-height:44px;border:1.5px solid var(--line-2);border-radius:var(--radius-sm);font-size:.85rem;color:var(--ink-2);cursor:pointer;white-space:nowrap;background:var(--card);transition:border-color .15s}
.chk-wrap:hover{border-color:var(--ink-4)}
.chk-wrap input{width:16px;height:16px;accent-color:var(--primary);cursor:pointer}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:.5rem;min-height:44px;padding:0 1rem;border-radius:var(--radius-sm);font-size:.875rem;font-weight:600;color:#fff;white-space:nowrap;box-shadow:var(--shadow-sm);transition:filter .15s,transform .1s}
.btn:active{transform:scale(.98)}
.btn:disabled{opacity:.55;cursor:not-allowed}
.btn-primary{background:linear-gradient(180deg,var(--primary) 0%,var(--primary-d) 100%)}
.btn-primary:hover:not(:disabled){filter:brightness(1.08)}
.btn-danger{background:linear-gradient(180deg,var(--danger) 0%,var(--danger-d) 100%)}
.btn-danger:hover:not(:disabled){filter:brightness(1.08)}
.btn-warning{background:linear-gradient(180deg,var(--warning) 0%,var(--warning-d) 100%)}
.btn-warning:hover:not(:disabled){filter:brightness(1.08)}
.btn-ghost{background:var(--card);border:1px solid var(--line-2);color:var(--ink-2)}
.btn-ghost:hover:not(:disabled){background:var(--bg);border-color:var(--ink-4)}
.btn-sm{min-height:36px;padding:0 .75rem;font-size:.8rem}
.filter-chips{display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem}
.chip{display:inline-flex;align-items:center;gap:.4rem;padding:.35rem .75rem;border-radius:99px;font-size:.75rem;font-weight:500;background:var(--bg);border:1px solid var(--line);color:var(--ink-2)}
.chip i{font-size:.65rem;color:var(--ink-4)}
.chip strong{color:var(--ink);font-weight:600}
.dedupe-bar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.75rem;padding:.85rem 1rem;background:linear-gradient(180deg,#FEF2F2 0%,#FFF 100%);border:1px solid #FECACA;border-radius:var(--radius);margin-bottom:1rem}
.sel-info{font-size:.85rem;color:var(--ink-2)}
.sel-info b{color:var(--danger);font-weight:700;font-size:1rem}
.dedupe-btns{display:flex;flex-wrap:wrap;gap:.5rem}
.tbl-wrap{overflow-x:auto;border:1px solid var(--line);border-radius:var(--radius);max-height:70vh;overflow-y:auto;-webkit-overflow-scrolling:touch}
@media(max-width:767.98px){.tbl-wrap{max-height:none}}
table{width:100%;border-collapse:collapse;min-width:900px;font-size:.875rem}
thead{background:var(--bg);color:var(--ink-3);position:sticky;top:0;z-index:2}
th{padding:.75rem .85rem;text-align:left;font-weight:600;font-size:.78rem;white-space:nowrap;border-bottom:1px solid var(--line);text-transform:uppercase;letter-spacing:.03em}
td{padding:.7rem .85rem;vertical-align:middle;border-bottom:1px solid #F1F5F9}
tbody tr{transition:background .1s}
tbody tr:hover{background:#F9FAFB}
tbody tr:last-child td{border-bottom:0}
tbody tr.row-selected{background:rgba(37,99,235,.04)}
.chk{width:18px;height:18px;accent-color:var(--primary);cursor:pointer}
.sub{font-size:.7rem;color:var(--ink-4);margin-top:.15rem}
.badge-group{display:inline-flex;align-items:center;gap:.35rem;padding:3px 9px;border-radius:99px;font-size:.72rem;font-weight:600;white-space:nowrap}
.badge-dup{display:inline-flex;align-items:center;justify-content:center;min-width:26px;padding:2px 8px;border-radius:99px;font-size:.72rem;font-weight:700;background:rgba(245,158,11,.15);color:var(--warning-d);font-variant-numeric:tabular-nums}
.badge-svc{display:inline-flex;align-items:center;padding:2px 8px;border-radius:99px;font-weight:600;background:rgba(220,38,38,.10);color:#B91C1C;font-size:.72rem;white-space:nowrap}
.empty{text-align:center;color:var(--ink-3);padding:3rem 1rem;background:var(--card);border:1px dashed var(--line-2);border-radius:var(--radius)}
.empty .empty-icon{width:64px;height:64px;border-radius:50%;background:var(--bg);display:flex;align-items:center;justify-content:center;margin:0 auto .75rem;font-size:1.5rem;color:var(--ink-4)}
.empty h3{font-size:1rem;color:var(--ink-2);font-weight:600;margin-bottom:.35rem}
.empty p{font-size:.85rem;color:var(--ink-3);max-width:30rem;margin:0 auto}
.pager{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.75rem;margin-top:1rem;font-size:.85rem;color:var(--ink-3)}
.pager b{color:var(--ink);font-weight:600;font-variant-numeric:tabular-nums}
.pg{display:flex;flex-wrap:wrap;gap:.3rem}
.pg a,.pg span{min-width:38px;height:38px;padding:0 .6rem;display:inline-flex;align-items:center;justify-content:center;border-radius:var(--radius-sm);border:1px solid var(--line-2);background:var(--card);color:var(--ink-2);font-weight:600;font-size:.85rem;transition:border-color .15s}
.pg a:hover{border-color:var(--primary);color:var(--primary)}
.pg .cur{background:var(--primary);border-color:var(--primary);color:#fff}
.pg .dis{opacity:.35;pointer-events:none}
.foot{background:#0F172A;color:#fff;padding:1.5rem 1rem calc(1.5rem + env(safe-area-inset-bottom,0px))}
.foot-in{max-width:80rem;margin:0 auto;display:flex;flex-direction:column;align-items:center;justify-content:space-between;gap:1rem;text-align:center}
@media(min-width:768px){.foot-in{flex-direction:row;text-align:left}}
.foot-l{display:flex;align-items:center;gap:1rem}
.foot-logo{width:40px;height:40px;flex:none;background:linear-gradient(135deg,#3B82F6,#2563EB);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem}
.foot-r{display:flex;flex-wrap:wrap;justify-content:center;gap:.25rem 1.5rem;color:#94A3B8;font-size:.8rem}
::-webkit-scrollbar{width:8px;height:8px}
::-webkit-scrollbar-track{background:var(--bg)}
::-webkit-scrollbar-thumb{background:var(--line-2);border-radius:4px}
::-webkit-scrollbar-thumb:hover{background:var(--ink-4)}
a:focus-visible,button:focus-visible,input:focus-visible{outline:2px solid var(--primary);outline-offset:2px}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{transition:none!important;animation:none!important}}
@media print{.eoc-side,.eoc-backdrop,.group-toolbar,.dedupe-bar,.foot,.no-print,.tools,.navcards{display:none!important}.main-content{margin:0!important}body{background:#fff}}
</style>
</head>
<body>

<div class="eoc-backdrop" id="eocBackdrop" hidden></div>
<aside class="eoc-side" id="eocSide" aria-label="เมนูด้านข้าง">
    <div class="eoc-side-top">
        <span><i class="fas fa-bars" aria-hidden="true"></i>&nbsp; เมนู</span>
        <button type="button" id="eocNavClose" aria-label="ปิดเมนู"><i class="fas fa-xmark" aria-hidden="true"></i></button>
    </div>
    <?php $sidebar_drawer = true; $sidebar_skip_assets = true; include __DIR__ . '/sidebar_eoc.php'; ?>
</aside>

<div class="main-content">
<header class="hdr">
    <div class="hdr-in">
        <div class="hdr-row">
            <div class="hdr-l">
                <button type="button" id="eocNavToggle" class="ibtn nav glass" aria-controls="eocSide" aria-expanded="false" aria-label="เปิดเมนู"><i class="fas fa-bars" aria-hidden="true"></i></button>
                <div class="logo glass"><i class="fas fa-copy" style="color:#FBBF24" aria-hidden="true"></i></div>
                <div style="min-width:0">
                    <h1>ตรวจข้อมูลซ้ำ</h1>
                    <small>VOC – Vulnerable Operations Center</small>
                </div>
            </div>
            <div class="hdr-row" style="gap:.5rem">
                <div class="hdr-user glass">
                    <div class="avatar"><i class="fas fa-user-doctor" aria-hidden="true"></i></div>
                    <div style="min-width:0"><b><?php echo eoc_h($user_name); ?></b><span><?php echo eoc_h($status_user); ?> · <?php echo eoc_h($user_hospcode ?: 'ไม่ระบุ'); ?></span></div>
                </div>
                <a href="../../logout.php" class="btn-ic" style="background:var(--danger-d)" aria-label="ออกจากระบบ" title="ออกจากระบบ"><i class="fas fa-sign-out-alt" aria-hidden="true"></i></a>
            </div>
        </div>
        <div class="hdr-sub">
            <span class="hide-sm"><i class="fas fa-shield-halved" aria-hidden="true"></i> <?php echo $see_all ? 'เห็นทุกสถานบริการ' : 'เห็นเฉพาะสถานบริการ ' . eoc_h($user_hospcode); ?></span>
            <span><i class="fas fa-clock" aria-hidden="true"></i><?php echo date('H:i'); ?> · <?php echo date('d/m/Y'); ?> · หมดเขต <b id="timeout-timer" class="mono"><?php echo $time_left_formatted; ?></b></span>
        </div>
    </div>
</header>

<main class="main">
<div class="wrap">

    <!-- BAR: breadcrumb + ปุ่มย้อนกลับ -->
    <div class="bar">
        <nav class="crumb" aria-label="breadcrumb">
            <a href="eoc_data.php?g=<?php echo urlencode($current_group['gstatus_id']); ?>">ทะเบียนผู้ป่วย</a>
            <i class="fas fa-chevron-right" aria-hidden="true"></i>
            <a href="eoc_pop_setup.php?g=<?php echo urlencode($current_group['gstatus_id']); ?>">ตั้งค่าผู้ป่วย</a>
            <i class="fas fa-chevron-right" aria-hidden="true"></i>
            <b>ตรวจข้อมูลซ้ำ</b>
        </nav>
        <a href="eoc_pop_setup.php?g=<?php echo urlencode($current_group['gstatus_id']); ?>" class="btn-ic-back" title="ย้อนกลับ" aria-label="ย้อนกลับ">
            <i class="fas fa-arrow-left" aria-hidden="true"></i>
        </a>
    </div>

    <!-- ✅ NAVCARDS: Role-based visibility -->
    <div class="navcards<?php echo $is_admin ? '' : ' navcards-user'; ?>">
        <!-- การ์ด 1: จัดการกลุ่มผู้ป่วย (ทุก role) -->
        <a href="eoc_pop_setup.php?g=<?php echo urlencode($current_group['gstatus_id']); ?>" class="nav-card">
            <div class="ic" style="color:#F59E0B"><i class="fas fa-users-cog"></i></div>
            <div class="info"><b>จัดการกลุ่มผู้ป่วย</b><small>ตรวจสอบและกำหนดกลุ่ม</small></div>
        </a>
        <!-- การ์ด 2: เพิ่มผู้ป่วยใหม่ (ทุก role) -->
        <a href="eoc_pop_new.php?g=<?php echo urlencode($current_group['gstatus_id']); ?>" class="nav-card">
            <div class="ic" style="color:#3B82F6"><i class="fas fa-user-plus"></i></div>
            <div class="info"><b>เพิ่มผู้ป่วยใหม่</b><small>บันทึกข้อมูลผู้ป่วยใหม่</small></div>
        </a>
        <?php if ($is_admin): ?>
        <!-- การ์ด 3: ยกเลิกผู้ป่วย (admin only) -->
        <a href="eoc_pop_cancel.php?g=<?php echo urlencode($current_group['gstatus_id']); ?>" class="nav-card">
            <div class="ic" style="color:#EF4444"><i class="fas fa-user-times"></i></div>
            <div class="info"><b>ยกเลิกผู้ป่วย</b><small>จัดการการยกเลิกข้อมูล</small></div>
        </a>
        <!-- การ์ด 4: ตรวจข้อมูลซ้ำ (admin only, active) -->
        <a href="eoc_pop_duplicate.php?g=<?php echo urlencode($current_group['gstatus_id']); ?>" class="nav-card active" aria-current="page">
            <div class="ic" style="color:#F59E0B"><i class="fas fa-copy"></i></div>
            <div class="info"><b>ตรวจข้อมูลซ้ำ</b><small>ตรวจสอบ eoc_pop ซ้ำในกลุ่ม</small></div>
        </a>
        <!-- การ์ด 5: ตรวจ gstatus ผิด (admin only) -->
        <a href="eoc_pop_g99.php?g=<?php echo urlencode($current_group['gstatus_id']); ?>" class="nav-card">
            <div class="ic" style="color:#EF4444"><i class="fas fa-tags"></i></div>
            <div class="info"><b>ตรวจ gstatus ผิด</b><small>ตรวจสอบ gstatus ที่ผิดปกติ</small></div>
        </a>
        <?php endif; ?>
    </div>

    <?php if ($flash): ?>
    <div class="note <?php echo $flash[0] === 'ok' ? 'ok' : 'err'; ?>" role="status" aria-live="polite">
        <i class="fas <?php echo $flash[0] === 'ok' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>" aria-hidden="true"></i>
        <div><?php echo eoc_h($flash[1]); ?></div>
    </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
    <div class="note err" role="alert">
        <i class="fas fa-exclamation-circle" aria-hidden="true"></i>
        <div><b>เกิดข้อผิดพลาด:</b> <?php echo eoc_h($error_message); ?></div>
    </div>
    <?php endif; ?>

    <?php if (empty($all_groups)): ?>
    <div class="note err" role="alert">
        <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
        <div><b>ไม่พบกลุ่มเป้าหมาย</b><small>กรุณาตรวจสอบว่ามีการตั้งค่ากลุ่มในระบบ (eoc_all_groups / eoc_gstatus)</small></div>
    </div>
    <?php endif; ?>

    <?php if (!$see_all): ?>
    <div class="note warn">
        <i class="fas fa-shield-halved" aria-hidden="true"></i>
        <div>
            <b>ขอบเขตข้อมูลถูกจำกัด</b>
            <small>คุณเห็นเฉพาะผู้ป่วยที่มี person.hospcode = <?php echo eoc_h($user_hospcode); ?> · ผู้ดูแลระบบและจังหวัด 00860 เห็นทุกแห่ง</small>
        </div>
    </div>
    <?php endif; ?>

    <!-- KPI Strip -->
    <div class="kpi-strip">
        <div class="kpi">
            <div class="kpi-icon" style="background:rgba(37,99,235,.12);color:var(--primary)"><i class="fas fa-layer-group" aria-hidden="true"></i></div>
            <div style="min-width:0"><div class="kpi-label">กลุ่มทั้งหมด</div><div class="kpi-value"><?php echo eoc_nf(count($all_groups)); ?></div></div>
        </div>
        <div class="kpi">
            <div class="kpi-icon" style="background:rgba(16,185,129,.12);color:var(--success-d)"><i class="fas fa-database" aria-hidden="true"></i></div>
            <div style="min-width:0"><div class="kpi-label">ผู้ป่วยในระบบ</div><div class="kpi-value"><?php echo eoc_nf($sum_all_total); ?></div></div>
        </div>
        <div class="kpi">
            <div class="kpi-icon" style="background:rgba(245,158,11,.12);color:var(--warning-d)"><i class="fas fa-copy" aria-hidden="true"></i></div>
            <div style="min-width:0"><div class="kpi-label">กลุ่มที่พบซ้ำ</div><div class="kpi-value"><?php echo eoc_nf($groups_with_dup); ?> <span style="font-size:.75rem;color:var(--ink-4);font-weight:400">/ <?php echo eoc_nf($groups_with_data); ?></span></div></div>
        </div>
        <div class="kpi">
            <div class="kpi-icon" style="background:rgba(239,68,68,.12);color:var(--danger)"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i></div>
            <div style="min-width:0"><div class="kpi-label">แถวเกินทั้งหมด</div><div class="kpi-value"><?php echo eoc_nf($sum_all_excess); ?></div></div>
        </div>
    </div>

    <!-- STEP 1: Group selection -->
    <section class="panel">
        <div class="panel-h">
            <div>
                <div class="panel-title"><span class="step-num">1</span> เลือกกลุ่มที่ต้องการตรวจ</div>
                <p class="panel-sub">ติ๊กเลือกกลุ่ม (หรือ "เลือกทั้งหมด") แล้วกด "ตรวจสอบ" ในขั้นตอนถัดไป</p>
            </div>
        </div>

        <form method="GET" action="eoc_pop_duplicate.php" id="groupsForm">
            <div class="group-toolbar">
                <div class="info">เลือก <b id="selCnt"><?php echo count($selected_groups); ?></b> จาก <b><?php echo count($all_groups); ?></b> กลุ่ม</div>
                <button type="button" class="btn btn-ghost btn-sm" id="btnSelectAll"><i class="fas fa-check-double" aria-hidden="true"></i> เลือกทั้งหมด</button>
                <button type="button" class="btn btn-ghost btn-sm" id="btnClearAll"><i class="fas fa-xmark" aria-hidden="true"></i> ล้าง</button>
            </div>

            <div class="group-grid">
            <?php foreach ($group_stats as $gs):
                $sel = in_array($gs['gstatus'], $selected_groups, true);
                $isEmpty = ($gs['total'] === 0);
            ?>
                <label class="g-card <?php echo $sel ? 'is-selected' : ''; ?> <?php echo $isEmpty ? 'is-empty' : ''; ?>" style="--gc:<?php echo eoc_h($gs['color']); ?>">
                    <input type="checkbox" name="groups[]" value="<?php echo eoc_h($gs['gstatus']); ?>" <?php echo $sel ? 'checked' : ''; ?> <?php echo $isEmpty ? 'disabled' : ''; ?> data-group-check>
                    <div class="g-head">
                        <div class="g-icon" style="background:<?php echo eoc_h($gs['color']); ?>22;color:<?php echo eoc_h($gs['color']); ?>">
                            <i class="<?php echo eoc_h($gs['icon']); ?>" aria-hidden="true"></i>
                        </div>
                        <div style="min-width:0">
                            <div class="g-title"><?php echo eoc_h($gs['name']); ?></div>
                            <div class="g-id"><?php echo eoc_h($gs['gstatus']); ?></div>
                        </div>
                    </div>
                    <?php if ($gs['total'] > 0): ?>
                    <div class="g-stats">
                        <div><span>ทั้งหมด</span><b><?php echo eoc_nf($gs['total']); ?></b></div>
                        <div class="s-dup"><span>ซ้ำ (idcard)</span><b><?php echo eoc_nf($gs['dup']); ?></b></div>
                        <div class="s-exc"><span>แถวเกิน</span><b><?php echo eoc_nf($gs['excess']); ?></b></div>
                    </div>
                    <?php else: ?>
                    <div class="g-stats"><div class="s-none">— ไม่มีข้อมูล —</div></div>
                    <?php endif; ?>
                </label>
            <?php endforeach; ?>
            </div>

            <div class="tools">
                <div class="panel-title" style="margin-bottom:.75rem"><span class="step-num">2</span> ตัวกรองเพิ่มเติม (ไม่บังคับ)</div>
                <div class="search-row">
                    <input name="search" type="search" class="fld" inputmode="numeric" maxlength="13"
                           value="<?php echo eoc_h($searchIdcard); ?>" placeholder="กรองเฉพาะเลขบัตรประชาชน 13 หลัก">
                    <label class="chk-wrap">
                        <input type="checkbox" name="only_with_service" value="1" <?php echo $onlyWithSvc ? 'checked' : ''; ?>>
                        <span><i class="fas fa-bell" aria-hidden="true" style="color:var(--ink-4);margin-right:.25rem"></i>เฉพาะที่มีคำขอ</span>
                    </label>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-magnifying-glass" aria-hidden="true"></i> ตรวจสอบ</button>
                    <?php if ($has_selection): ?>
                        <a href="eoc_pop_duplicate.php" class="btn btn-ghost" title="ล้างทั้งหมด"><i class="fas fa-rotate-left" aria-hidden="true"></i></a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </section>

    <!-- STEP 3: Results -->
    <?php if (!$has_selection): ?>
        <div class="empty">
            <div class="empty-icon"><i class="fas fa-hand-pointer" aria-hidden="true"></i></div>
            <h3>ยังไม่ได้เลือกกลุ่ม</h3>
            <p>เลือกกลุ่มด้านบน (หรือ "เลือกทั้งหมด") แล้วกด "ตรวจสอบ" เพื่อประมวลผลข้อมูล</p>
        </div>
    <?php else: ?>
    <section class="panel">
        <div class="panel-h">
            <div>
                <div class="panel-title"><span class="step-num">3</span> ผลการตรวจสอบ</div>
                <p class="panel-sub">
                    พบ <strong><?php echo eoc_nf($total_dup); ?></strong> idcard ซ้ำ ·
                    <strong><?php echo eoc_nf($total_rows); ?></strong> แถว ·
                    แถวเกิน <strong style="color:var(--danger)"><?php echo eoc_nf($total_rows - $total_dup); ?></strong>
                </p>
            </div>
        </div>

        <div class="filter-chips">
            <span class="chip"><i class="fas fa-layer-group" aria-hidden="true"></i> กลุ่ม: <strong><?php echo count($selected_groups); ?><?php echo $is_all_selected ? ' (ทั้งหมด)' : ''; ?></strong></span>
            <?php if ($searchIdcard !== ''): ?>
            <span class="chip"><i class="fas fa-id-card" aria-hidden="true"></i> idcard: <strong class="mono"><?php echo eoc_h($searchIdcard); ?></strong></span>
            <?php endif; ?>
            <?php if ($onlyWithSvc): ?>
            <span class="chip"><i class="fas fa-bell" aria-hidden="true"></i> เฉพาะที่มีคำขอ</span>
            <?php endif; ?>
        </div>

        <form method="POST" id="dedupeForm" action="eoc_pop_duplicate.php">
            <input type="hidden" name="action" value="dedupe">
            <input type="hidden" name="csrf" value="<?php echo eoc_h($dup_csrf); ?>">
            <input type="hidden" name="mode" id="dedupeMode" value="listed">
            <input type="hidden" name="groups_back" value="<?php echo eoc_h($g_back); ?>">
            <input type="hidden" name="q" value="<?php echo eoc_h($searchIdcard); ?>">
            <input type="hidden" name="flag_only_svc" value="<?php echo $onlyWithSvc ? '1' : ''; ?>">
            <input type="hidden" name="page" value="<?php echo (int)$page; ?>">

            <?php if (empty($duplicates)): ?>
                <div class="empty">
                    <div class="empty-icon" style="background:#F0FDF4;color:var(--success-d)"><i class="fas fa-check-circle" aria-hidden="true"></i></div>
                    <h3>ไม่พบข้อมูลซ้ำ</h3>
                    <p>กลุ่มที่เลือกไม่มี idcard ซ้ำตามเงื่อนไขที่กำหนด</p>
                </div>
            <?php else: ?>
                <div class="dedupe-bar">
                    <div class="sel-info">เลือกแล้ว <b id="selCount">0</b> ราย</div>
                    <div class="dedupe-btns">
                        <button type="submit" data-mode="listed" id="btnListed" class="btn btn-danger" disabled>
                            <i class="fas fa-broom" aria-hidden="true"></i> ลบเฉพาะที่เลือก
                        </button>
                        <?php if ($total_dup > 0): ?>
                        <button type="submit" data-mode="all_selected" id="btnAll" class="btn btn-warning">
                            <i class="fas fa-layer-group" aria-hidden="true"></i> ลบทั้งหมดในกลุ่มที่เลือก (<?php echo eoc_nf($total_dup); ?> idcard)
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="tbl-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th style="width:44px"><input type="checkbox" class="chk" id="chkAll" aria-label="เลือกทั้งหน้า"></th>
                                <th>กลุ่ม</th>
                                <th>เลขบัตรประชาชน</th>
                                <th style="text-align:center">แถว</th>
                                <th>ชื่อ-สกุล</th>
                                <th>สถานบริการ</th>
                                <th style="text-align:center">คำขอ</th>
                                <th>อัปเดตล่าสุด</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($duplicates as $d):
                            $gk     = $d['gstatus'];
                            $gname  = isset($all_groups[$gk]['gstatus_name']) ? $all_groups[$gk]['gstatus_name'] : $gk;
                            $gcolor = isset($all_groups[$gk]['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $all_groups[$gk]['color']) ? $all_groups[$gk]['color'] : '#6B7280';
                            $gicon  = isset($all_groups[$gk]['icon']) ? $all_groups[$gk]['icon'] : 'fas fa-users';
                            $hasSvc = (int)$d['service_count'] > 0;
                        ?>
                            <tr>
                                <td><input type="checkbox" class="chk row-chk" name="pairs[]" value="<?php echo eoc_h($d['gstatus'] . '|' . $d['idcard']); ?>" aria-label="เลือก"></td>
                                <td>
                                    <span class="badge-group" style="background:<?php echo eoc_h($gcolor); ?>18;color:<?php echo eoc_h($gcolor); ?>">
                                        <i class="<?php echo eoc_h($gicon); ?>" aria-hidden="true"></i> <?php echo eoc_h($gname); ?>
                                    </span>
                                </td>
                                <td class="mono" style="font-weight:600;color:var(--ink)"><?php echo eoc_h($d['idcard']); ?></td>
                                <td style="text-align:center"><span class="badge-dup"><?php echo (int)$d['dup_count']; ?></span></td>
                                <td style="font-size:.82rem;color:var(--ink)"><?php echo eoc_h($d['name'] ?: '—'); ?></td>
                                <td class="mono" style="font-size:.8rem;color:var(--ink-2)"><?php echo eoc_h($d['hospcode'] ?: '—'); ?></td>
                                <td style="text-align:center;font-size:.78rem">
                                    <?php if ($hasSvc): ?>
                                        <span class="badge-svc"><?php echo (int)$d['service_count']; ?> รายการ</span>
                                        <?php if (!empty($d['service_list'])): ?>
                                            <div class="sub mono" style="margin-top:2px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo eoc_h($d['service_list']); ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color:var(--ink-4)">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="mono" style="font-size:.78rem;color:var(--ink-3)"><?php echo eoc_h($d['last_update'] ?: '—'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </form>

        <?php if ($total_dup > 0): ?>
        <div class="pager">
            <div>
                พบ <b><?php echo eoc_nf($total_dup); ?></b> idcard ซ้ำ (<b><?php echo eoc_nf($total_rows); ?></b> แถว)
                <?php if ($total_pages > 1): ?> · หน้า <b><?php echo $page; ?></b> จาก <b><?php echo $total_pages; ?></b><?php endif; ?>
            </div>
            <?php if ($total_pages > 1):
                $params_base = array('groups' => implode(',', $selected_groups), 'search' => $searchIdcard, 'only_with_service' => $onlyWithSvc ? 1 : 0);
                $from = max(1, $page - 2);
                $to   = min($total_pages, $page + 2);
                $mk = function($p) use ($params_base) {
                    $q = $params_base; $q['page'] = $p;
                    if ($q['search'] === '') unset($q['search']);
                    if (!$q['only_with_service']) unset($q['only_with_service']);
                    return 'eoc_pop_duplicate.php?' . http_build_query($q);
                };
            ?>
            <nav class="pg" aria-label="แบ่งหน้า">
                <?php if ($page > 1): ?><a href="<?php echo eoc_h($mk($page - 1)); ?>" aria-label="ก่อนหน้า"><i class="fas fa-chevron-left"></i></a><?php else: ?><span class="dis"><i class="fas fa-chevron-left"></i></span><?php endif; ?>
                <?php if ($from > 1): ?><a href="<?php echo eoc_h($mk(1)); ?>">1</a><?php if ($from > 2): ?><span class="dis">…</span><?php endif; endif; ?>
                <?php for ($i = $from; $i <= $to; $i++): ?>
                    <?php if ($i === $page): ?><span class="cur" aria-current="page"><?php echo $i; ?></span><?php else: ?><a href="<?php echo eoc_h($mk($i)); ?>"><?php echo $i; ?></a><?php endif; ?>
                <?php endfor; ?>
                <?php if ($to < $total_pages): if ($to < $total_pages - 1): ?><span class="dis">…</span><?php endif; ?><a href="<?php echo eoc_h($mk($total_pages)); ?>"><?php echo $total_pages; ?></a><?php endif; ?>
                <?php if ($page < $total_pages): ?><a href="<?php echo eoc_h($mk($page + 1)); ?>" aria-label="ถัดไป"><i class="fas fa-chevron-right"></i></a><?php else: ?><span class="dis"><i class="fas fa-chevron-right"></i></span><?php endif; ?>
            </nav>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

</div>
</main>

<footer class="foot">
    <div class="foot-in">
        <div class="foot-l">
            <div class="foot-logo"><i class="fas fa-hand-holding-heart" aria-hidden="true"></i></div>
            <div><div style="font-weight:600">VOC Thepha Care</div><div style="color:#94A3B8;font-size:.85rem">Vulnerable Operations Center</div></div>
        </div>
        <div class="foot-r"><span><i class="fas fa-copyright" aria-hidden="true"></i> <?php echo ((int)date('Y') + 543); ?> Thepha Care</span></div>
    </div>
</footer>
</div>

<script>
(function () {
'use strict';
function $(id) { return document.getElementById(id); }

var side = $('eocSide'), bd = $('eocBackdrop'), openBtn = $('eocNavToggle'), closeBtn = $('eocNavClose');
if (side && openBtn) {
    var setDrawer = function (o) {
        side.classList.toggle('open', o);
        if (bd) bd.hidden = !o;
        document.body.classList.toggle('nav-open', o);
        openBtn.setAttribute('aria-expanded', String(o));
    };
    openBtn.addEventListener('click', function () { setDrawer(!side.classList.contains('open')); });
    if (closeBtn) closeBtn.addEventListener('click', function () { setDrawer(false); openBtn.focus(); });
    if (bd) bd.addEventListener('click', function () { setDrawer(false); });
    side.addEventListener('click', function (e) { if (e.target.closest('a[href]')) setDrawer(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && side.classList.contains('open')) setDrawer(false); });
    var mq = window.matchMedia('(min-width:1024px)');
    var onDesk = function (e) { if (e.matches) setDrawer(false); };
    if (mq.addEventListener) mq.addEventListener('change', onDesk); else mq.addListener(onDesk);
}

var groupChecks = Array.prototype.slice.call(document.querySelectorAll('[data-group-check]'));
var selCnt = $('selCnt');
function refreshGroupUI() {
    var n = 0;
    groupChecks.forEach(function (cb) {
        var card = cb.closest ? cb.closest('.g-card') : null;
        if (card) card.classList.toggle('is-selected', cb.checked);
        if (cb.checked) n++;
    });
    if (selCnt) selCnt.textContent = n;
}
groupChecks.forEach(function (cb) {
    cb.addEventListener('change', refreshGroupUI);
    var card = cb.closest ? cb.closest('.g-card') : null;
    if (card && !cb.disabled) {
        card.addEventListener('click', function (e) {
            if (e.target === cb || cb.disabled) return;
            cb.checked = !cb.checked;
            refreshGroupUI();
        });
    }
});
var btnSelectAll = $('btnSelectAll'), btnClearAll = $('btnClearAll');
if (btnSelectAll) btnSelectAll.addEventListener('click', function () {
    groupChecks.forEach(function (cb) { if (!cb.disabled) cb.checked = true; });
    refreshGroupUI();
});
if (btnClearAll) btnClearAll.addEventListener('click', function () {
    groupChecks.forEach(function (cb) { cb.checked = false; });
    refreshGroupUI();
});
var groupsForm = $('groupsForm');
if (groupsForm) {
    groupsForm.addEventListener('submit', function (e) {
        var n = 0;
        groupChecks.forEach(function (cb) { if (cb.checked) n++; });
        if (n === 0) { e.preventDefault(); alert('กรุณาเลือกกลุ่มอย่างน้อย 1 กลุ่ม'); }
    });
}

var form = $('dedupeForm');
if (!form) return;
var modeEl = $('dedupeMode'), selCountEl = $('selCount'), chkAll = $('chkAll');
var btnListed = $('btnListed'), btnAll = $('btnAll');
var rowChks = Array.prototype.slice.call(document.querySelectorAll('.row-chk'));
var TOTAL_IDCARDS = <?php echo (int)$total_dup; ?>;
var TOTAL_ROWS    = <?php echo (int)$total_rows; ?>;
var EXCESS_ROWS   = Math.max(0, TOTAL_ROWS - TOTAL_IDCARDS);

[btnListed, btnAll].forEach(function (b) {
    if (!b) return;
    b.addEventListener('click', function () { modeEl.value = b.getAttribute('data-mode'); });
});
function countChecked() {
    var n = 0;
    for (var i = 0; i < rowChks.length; i++) if (rowChks[i].checked) n++;
    return n;
}
function refreshDedupeUI() {
    var n = countChecked();
    if (selCountEl) selCountEl.textContent = n.toLocaleString('th-TH');
    if (btnListed) btnListed.disabled = (n === 0);
    if (chkAll) {
        chkAll.checked = (n > 0 && n === rowChks.length);
        chkAll.indeterminate = (n > 0 && n < rowChks.length);
    }
    rowChks.forEach(function (cb) {
        var tr = cb.closest ? cb.closest('tr') : null;
        if (tr) tr.classList.toggle('row-selected', cb.checked);
    });
}
if (chkAll) chkAll.addEventListener('change', function () {
    rowChks.forEach(function (cb) { cb.checked = chkAll.checked; });
    refreshDedupeUI();
});
rowChks.forEach(function (cb) { cb.addEventListener('change', refreshDedupeUI); });
refreshDedupeUI();

form.addEventListener('submit', function (e) {
    var mode = modeEl.value;
    if (mode === 'listed') {
        var n = countChecked();
        if (n === 0) { e.preventDefault(); alert('กรุณาเลือกอย่างน้อย 1 ราย'); return; }
        if (!confirm('ยืนยันลบข้อมูลซ้ำ?\n\nจะดำเนินการ ' + n + ' idcard ที่เลือก\nระบบจะสำรองแถวส่วนเกินไปยัง eoc_pop_duplicate ก่อนลบทุกครั้ง')) { e.preventDefault(); return; }
    } else {
        if (!confirm('ยืนยันลบข้อมูลซ้ำทั้งหมด?\n\nจะลบ ' + EXCESS_ROWS.toLocaleString('th-TH') + ' แถว จาก ' + TOTAL_IDCARDS.toLocaleString('th-TH') + ' idcard\nระบบจะสำรองแถวส่วนเกินไปยัง eoc_pop_duplicate ก่อนลบทุกครั้ง')) { e.preventDefault(); return; }
    }
    var btns = form.querySelectorAll('button[type=submit]');
    for (var i = 0; i < btns.length; i++) btns[i].disabled = true;
});
})();
</script>

<?php require_once __DIR__ . '/includes/timeout_guard.php'; ?>
</body>
</html>