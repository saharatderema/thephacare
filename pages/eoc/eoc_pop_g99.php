<?php
/**
 * ============================================================================
 *  eoc_pop_g99.php — v3.4 (2026-10-30) — CROSS-GROUP CLARITY
 *  ตรวจว่า eoc_pop.gstatus ทุกแถว มีอยู่ใน eoc_mas_gstatus หรือไม่
 *  — ตรวจข้ามทุกกลุ่ม ไม่กรองกลุ่ม ปัจจุบัน
 * ----------------------------------------------------------------------------
 *  🎯 v3.4 CROSS-GROUP (ใหม่):
 *    - [UI]    Header: "ตรวจข้ามทุกกลุ่ม" — ลบความเข้าใจผิดว่าเจาะจงกลุ่มเดียว
 *    - [UI]    Panel ใหม่ "ความครอบคลุมของ master" — แสดง N/M/K กลุ่ม
 *    - [CACHE] ตัด $group_code ออกจาก summary_cache_key (ข้อมูลเหมือนกันทุกกลุ่ม)
 *    - [QUERY] Coverage query: นับ pop rows ต่อ valid gstatus (1 query/cache 30s)
 * ----------------------------------------------------------------------------
 *  ✅ v3.3 (คงเดิม):
 *    - [FIX] timeout_guard ก่อน session_write_close (บั๊กวิกฤต)
 *  ✅ v3.2 (คงเดิม):
 *    - [PERF] Batch service_count, skip count, cache valid_groups 5m
 *  ✅ v3.1 / v3 (คงเดิม):
 *    - [FIX] $has_del live-check, idcard filter ตรงกัน, BIGINT temp
 * ============================================================================
 */

require_once __DIR__ . '/includes/bootstrap.php';

/* ---------- Config ---------- */
if (!isset($timeout_duration) || !is_numeric($timeout_duration) || (int)$timeout_duration <= 0) {
    $timeout_duration = 900;
}
$timeout_duration = (int)$timeout_duration;
$per_page = 50;

// Performance tuning for this page
@ini_set('memory_limit', '256M');
@set_time_limit(300);

// Use unbuffered queries to reduce peak memory on large result sets
try { $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false); } catch (PDOException $e) {}

/* ---------- Group context (ใช้เฉพาะ nav/header — ไม่กรองข้อมูล) ---------- */
require_once __DIR__ . '/includes/group_context.php';
$__group     = eoc_resolve_group($pdo);
$group_code  = $__group['gstatus_id'];
$group_name  = $__group['gstatus_name'];
$group_icon  = $__group['icon'];
$group_color = $__group['color'];

if (function_exists('eoc_all_groups')) {
    $__groups_all = eoc_all_groups($pdo);
    if (!isset($__groups_all[$group_code])) {
        header('Location: eoc_data.php?g=g1');
        exit;
    }
}
if (!preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$group_color)) {
    $group_color = '#EF4444';
}
$gr = 239; $gg = 68; $gb = 68;
sscanf(ltrim($group_color, '#'), '%2x%2x%2x', $gr, $gg, $gb);

/* ---------- User context ---------- */
$user_name       = (isset($user_name) && $user_name !== '') ? $user_name : 'ไม่ระบุชื่อ';
$user_hospcode   = (isset($user_hospcode) && $user_hospcode !== '')
                    ? (string)$user_hospcode
                    : (isset($_SESSION['user_hospcode']) ? (string)$_SESSION['user_hospcode'] : '');
$status_user     = (isset($status_user) && $status_user !== '') ? $status_user : 'ไม่ระบุสถานะ';
$current_user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$is_admin        = (strtolower(trim((string)$status_user)) === 'admin');
$see_all         = ($is_admin || $user_hospcode === '00860');
$gq              = urlencode($group_code);

/* ✅ v3.4: cache key ไม่ผูก group_code — ข้อมูลเหมือนกันทุกกลุ่ม */
$summary_cache_key  = 'g99_sum_' . md5($user_hospcode . '|' . ($see_all ? '1' : '0'));
$coverage_cache_key = 'g99_cov_' . md5($user_hospcode . '|' . ($see_all ? '1' : '0'));
$summary_ttl = 30;

/* ---------- CSRF ---------- */
if (empty($_SESSION['g99_csrf'])) {
    if (function_exists('random_bytes')) {
        $_SESSION['g99_csrf'] = bin2hex(random_bytes(32));
    } elseif (function_exists('openssl_random_pseudo_bytes')) {
        $_SESSION['g99_csrf'] = bin2hex(openssl_random_pseudo_bytes(32));
    } else {
        $_SESSION['g99_csrf'] = md5(uniqid(mt_rand(), true)) . md5(uniqid(mt_rand(), true));
    }
}
$g99_csrf = $_SESSION['g99_csrf'];

/* ---------- Helpers ---------- */
if (!function_exists('eoc_h')) {
    function eoc_h($v) { return htmlspecialchars((string)($v === null ? '' : $v), ENT_QUOTES, 'UTF-8'); }
}
function g99_url($g, $search, $onlySvc, $page) {
    $a = array('g' => $g);
    if ($search !== '') $a['search'] = $search;
    if ($onlySvc)       $a['only_with_service'] = 1;
    if ($page > 1)      $a['page'] = $page;
    return 'eoc_pop_g99.php?' . http_build_query($a);
}

/* ============================================================
 * ตรวจตาราง (ใช้ cache จาก includes/bootstrap.php — ลด query ซ้ำ)
 * ============================================================ */
$has_mas = !empty($table_exists['eoc_mas_gstatus'] ?? false);
$has_pop = !empty($table_exists['eoc_pop'] ?? false);
$has_del = !empty($table_exists['eoc_pop_deleted'] ?? false);

/* ---------- Inputs ---------- */
$searchIdcard = trim((string)(isset($_GET['search']) ? $_GET['search'] : ''));
$searchIdcard = preg_replace('/\D/', '', $searchIdcard);
if (strlen($searchIdcard) > 13) $searchIdcard = substr($searchIdcard, 0, 13);
$onlyWithSvc  = isset($_GET['only_with_service']);
$page         = max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1));

$bad_rows         = array();
$total_bad        = 0;
$total_bad_groups = 0;
$search_people    = 0;
$search_performed = false;
$error_message    = '';
$total_pages      = 1;

$can_query = ($has_pop && $has_mas);
$NOTIN_SUB = "NOT EXISTS (SELECT 1 FROM eoc_mas_gstatus m WHERE m.gstatus_id = e.gstatus)";
$IDCARD_FILTER = "e.idcard IS NOT NULL AND e.idcard <> ''";

/* ============================================================================
 * POST: purge
 * ============================================================================ */
if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST'
    && (isset($_POST['action']) ? $_POST['action'] : '') === 'purge') {

    $q_back    = isset($_POST['q']) ? trim((string)$_POST['q']) : '';
    $page_back = isset($_POST['page']) ? max(1, (int)$_POST['page']) : 1;
    $flag_back = !empty($_POST['flag_only_svc']);

    $flash = array('err', 'เกิดข้อผิดพลาด');
    try {
        if (!isset($_POST['csrf']) || !hash_equals($g99_csrf, (string)$_POST['csrf'])) {
            throw new Exception('เซสชันหมดอายุหรือคำขอไม่ถูกต้อง กรุณาโหลดหน้าใหม่');
        }
        if (!$can_query) throw new Exception('ไม่พบตาราง eoc_pop หรือ eoc_mas_gstatus');
        if (!$has_del) throw new Exception('ไม่พบตาราง eoc_pop_deleted — ไม่สามารถสำรองข้อมูลก่อนลบได้');

        $mode = (isset($_POST['mode']) && $_POST['mode'] === 'listed') ? 'listed' : 'all';

        if (function_exists('random_bytes')) {
            $batch = date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
        } else {
            $batch = date('Ymd_His') . '_' . substr(md5(uniqid(mt_rand(), true)), 0, 8);
        }
        $actor = (string)($user_name ? $user_name : 'system');

        $pdo->beginTransaction();

        $filter = $NOTIN_SUB . ' AND ' . $IDCARD_FILTER;
        $pSel = array();
        if (!$see_all) {
            $filter .= " AND p.hospcode = :hc";
            $pSel[':hc'] = $user_hospcode;
        }

        if ($mode === 'listed') {
            $ids = isset($_POST['ids']) ? $_POST['ids'] : array();
            if (is_string($ids)) $ids = array($ids);
            $ids_clean = array();
            foreach ((array)$ids as $v) {
                $v = (string)$v;
                if (preg_match('/^\d{1,19}$/', $v)) $ids_clean[] = $v;
            }
            if (count($ids_clean) > 500) {
                throw new RuntimeException('เลือกได้สูงสุด 500 รายการต่อครั้ง');
            }
            if (empty($ids_clean)) throw new RuntimeException('ไม่ได้เลือกรายการที่จะดำเนินการ');
            
            $id_params = array();
            foreach ($ids_clean as $i => $idv) {
                $key = ':id' . $i;
                $id_params[] = $key;
                $pSel[$key] = $idv;
            }
            $filter .= ' AND e.id IN (' . implode(',', $id_params) . ')';
        }

        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS tmp_g99del');
        $pdo->exec('CREATE TEMPORARY TABLE tmp_g99del (del_id BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');

        $stTmp = $pdo->prepare(
            'INSERT INTO tmp_g99del (del_id)
             SELECT e.id FROM eoc_pop e
             LEFT JOIN person p ON p.idcard = e.idcard
             WHERE ' . $filter
        );
        $stTmp->execute($pSel);

        $deleted = (int)$pdo->query('SELECT COUNT(*) FROM tmp_g99del')->fetchColumn();

        $groups_cnt = 0;
        try {
            $groups_cnt = (int)$pdo->query(
                'SELECT COUNT(DISTINCT e.gstatus) FROM eoc_pop e JOIN tmp_g99del t ON t.del_id = e.id'
            )->fetchColumn();
        } catch (Exception $e) {
            error_log('[eoc_pop_g99] groups_cnt: ' . $e->getMessage());
        }

        // Batch safety: limit 'all' mode to 5,000 rows per run to avoid long table locks / page hang
        if ($mode === 'all' && $deleted > 5000) {
            try { $pdo->rollBack(); } catch (Exception $e) {}
            try { $pdo->exec('DROP TEMPORARY TABLE IF EXISTS tmp_g99del'); } catch (Exception $e) {}
            $flash = array('err',
                'ข้อมูลนอก master มีมากเกินไป (' . number_format($deleted) . ' แถว) กรุณาใช้ตัวกรองค้นหา หรือเลือกเฉพาะรายการที่ต้องการลบ (สูงสุด 5,000 รายการต่อครั้ง)'
            );
            $_SESSION['eoc_g99_flash'] = $flash;
            header('Location: ' . g99_url($group_code, $q_back, $flag_back, $page_back));
            exit;
        }

        if ($deleted === 0) {
            $pdo->commit();
            $pdo->exec('DROP TEMPORARY TABLE IF EXISTS tmp_g99del');
            $flash = array('ok', 'ไม่พบแถว gstatus ไม่ถูกต้องที่ต้องดำเนินการ');
        } else {
            $stIns = $pdo->prepare(
                "INSERT INTO eoc_pop_deleted
                   (id, idcard, gstatus, emergency_code, d_update,
                    backup_at, backup_by, backup_batch, backup_note)
                 SELECT e.id, e.idcard, e.gstatus, e.emergency_code, e.d_update,
                        NOW(), :by, :batch, CONCAT('invalid gstatus=', e.gstatus)
                 FROM eoc_pop e
                 JOIN tmp_g99del t ON t.del_id = e.id"
            );
            $stIns->execute(array(':by' => $actor, ':batch' => $batch));

            $stChk = $pdo->prepare('SELECT COUNT(*) FROM eoc_pop_deleted WHERE backup_batch = ?');
            $stChk->execute(array($batch));
            if ((int)$stChk->fetchColumn() !== $deleted) {
                throw new RuntimeException('สำรองข้อมูลไม่ครบ ยกเลิกการลบทั้งหมดแล้ว');
            }

            $pdo->exec('DELETE e FROM eoc_pop e JOIN tmp_g99del t ON t.del_id = e.id');
            $pdo->commit();
            $pdo->exec('DROP TEMPORARY TABLE IF EXISTS tmp_g99del');

            unset($_SESSION[$summary_cache_key], $_SESSION[$coverage_cache_key]);

            try {
                $desc = 'ลบ gstatus ไม่ถูกต้อง: ' . $deleted . ' แถว จาก ' . $groups_cnt . ' รหัส'
                      . ' | โหมด: ' . $mode . ' | batch: ' . $batch;
                $lg = $pdo->prepare("INSERT INTO system_logs (user_id, action, description, ip_address, created_at)
                                     VALUES (:u, 'purge_g99', :d, :ip, NOW())");
                $lg->execute(array(
                    ':u'  => $current_user_id,
                    ':d'  => $desc,
                    ':ip' => (string)(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''),
                ));
            } catch (Exception $e) {
                error_log('[eoc_pop_g99] system_logs: ' . $e->getMessage());
            }

            $flash = array('ok',
                'ลบ ' . number_format($deleted) . ' แถว จาก ' . number_format($groups_cnt) . ' รหัส'
                . ' · สำรองไป eoc_pop_deleted แล้ว · รหัสชุด: ' . $batch);
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        try { $pdo->exec('DROP TEMPORARY TABLE IF EXISTS tmp_g99del'); } catch (Exception $e2) {}
        error_log('[eoc_pop_g99] purge: ' . $e->getMessage());
        $flash = array('err', 'ดำเนินการไม่สำเร็จ: ' . $e->getMessage());
    }

    $_SESSION['eoc_g99_flash'] = $flash;
    header('Location: ' . g99_url($group_code, $q_back, $flag_back, $page_back));
    exit;
}

$flash = isset($_SESSION['eoc_g99_flash']) ? $_SESSION['eoc_g99_flash'] : null;
unset($_SESSION['eoc_g99_flash']);

/* ============================================================================
 * Summary — cache 30s
 * ============================================================================ */
$summary_stale = !isset($_SESSION[$summary_cache_key]['t'])
              || (time() - (int)$_SESSION[$summary_cache_key]['t']) > $summary_ttl;

if (!$summary_stale) {
    $cached = $_SESSION[$summary_cache_key]['data'];
    $pop_total      = (int)$cached['pop_total'];
    $bad_groups_all = $cached['bad_groups_all'];
    $bad_total_all  = (int)$cached['bad_total_all'];
    $bad_people_all = (int)$cached['bad_people_all'];
} else {
    $pop_total = 0;
    try {
        if ($see_all) {
            $pop_total = (int)$pdo->query("SELECT COUNT(*) FROM eoc_pop")->fetchColumn();
        } else {
            $st = $pdo->prepare("SELECT COUNT(*) FROM eoc_pop e INNER JOIN person p ON p.idcard = e.idcard WHERE p.hospcode = :hc");
            $st->execute(array(':hc' => $user_hospcode));
            $pop_total = (int)$st->fetchColumn();
        }
    } catch (PDOException $e) {
        error_log('[eoc_pop_g99] pop_total: ' . $e->getMessage());
    }

    $bad_groups_all = array();
    $bad_total_all  = 0;
    $bad_people_all = 0;
    if ($can_query) {
        try {
            if ($see_all) {
                $sumSql = "SELECT e.gstatus, COUNT(*) AS c, COUNT(DISTINCT e.idcard) AS people
                           FROM eoc_pop e
                           WHERE $NOTIN_SUB AND $IDCARD_FILTER
                           GROUP BY e.gstatus ORDER BY c DESC, e.gstatus ASC";
                $stSum = $pdo->query($sumSql);
            } else {
                $sumSql = "SELECT e.gstatus, COUNT(*) AS c, COUNT(DISTINCT e.idcard) AS people
                           FROM eoc_pop e
                           INNER JOIN person p ON p.idcard = e.idcard
                           WHERE $NOTIN_SUB AND $IDCARD_FILTER
                             AND p.hospcode = :hc
                           GROUP BY e.gstatus ORDER BY c DESC, e.gstatus ASC";
                $stSum = $pdo->prepare($sumSql);
                $stSum->bindValue(':hc', $user_hospcode);
                $stSum->execute();
            }

            while ($r = $stSum->fetch(PDO::FETCH_ASSOC)) {
                $bad_groups_all[(string)$r['gstatus']] = array(
                    'rows'   => (int)$r['c'],
                    'people' => (int)$r['people'],
                );
                $bad_total_all  += (int)$r['c'];
                $bad_people_all += (int)$r['people'];
            }
        } catch (PDOException $e) {
            error_log('[eoc_pop_g99] summary: ' . $e->getMessage());
        }
    }

    $_SESSION[$summary_cache_key] = array(
        't' => time(),
        'data' => array(
            'pop_total'      => $pop_total,
            'bad_groups_all' => $bad_groups_all,
            'bad_total_all'  => $bad_total_all,
            'bad_people_all' => $bad_people_all,
        )
    );
}

/* ---------- valid_groups cache 5 นาที ---------- */
$valid_groups = array();
$valid_cache_key = 'g99_mas_valid';
if (isset($_SESSION[$valid_cache_key]) && 
    (time() - (int)$_SESSION[$valid_cache_key]['t']) < 300) {
    $valid_groups = $_SESSION[$valid_cache_key]['data'];
} elseif ($has_mas) {
    try {
        $stV = $pdo->query("SELECT gstatus_id, gstatus_name FROM eoc_mas_gstatus ORDER BY gstatus_id");
        while ($r = $stV->fetch(PDO::FETCH_ASSOC)) {
            $valid_groups[(string)$r['gstatus_id']] = $r['gstatus_name'];
        }
        $_SESSION[$valid_cache_key] = array('t' => time(), 'data' => $valid_groups);
    } catch (PDOException $e) {
        error_log('[eoc_pop_g99] valid_groups: ' . $e->getMessage());
    }
}

/* ============================================================================
 * ✅ v3.4: Coverage — นับ pop rows ต่อ valid group (พิสูจน์ว่าตรวจครบทุกกลุ่ม)
 * ============================================================================ */
$coverage = array(
    'total_groups'              => count($valid_groups),
    'groups_with_pop'           => 0,
    'groups_without_pop'        => array(),
    'total_pop_in_valid_groups' => 0,
);

$coverage_stale = !isset($_SESSION[$coverage_cache_key]['t'])
               || (time() - (int)$_SESSION[$coverage_cache_key]['t']) > $summary_ttl;

if (!$coverage_stale) {
    $coverage = $_SESSION[$coverage_cache_key]['data'];
} elseif ($can_query && !empty($valid_groups)) {
    try {
        if ($see_all) {
            $covSql = "SELECT e.gstatus, COUNT(*) AS cnt
                       FROM eoc_pop e
                       WHERE EXISTS (SELECT 1 FROM eoc_mas_gstatus m WHERE m.gstatus_id = e.gstatus)
                         AND $IDCARD_FILTER
                       GROUP BY e.gstatus";
            $stCov = $pdo->query($covSql);
        } else {
            $covSql = "SELECT e.gstatus, COUNT(*) AS cnt
                       FROM eoc_pop e
                       INNER JOIN person p ON p.idcard = e.idcard
                       WHERE EXISTS (SELECT 1 FROM eoc_mas_gstatus m WHERE m.gstatus_id = e.gstatus)
                         AND $IDCARD_FILTER
                         AND p.hospcode = :hc
                       GROUP BY e.gstatus";
            $stCov = $pdo->prepare($covSql);
            $stCov->bindValue(':hc', $user_hospcode);
            $stCov->execute();
        }
        $group_counts = array();
        while ($r = $stCov->fetch(PDO::FETCH_ASSOC)) {
            $group_counts[(string)$r['gstatus']] = (int)$r['cnt'];
        }
        $coverage = array(
            'total_groups'              => count($valid_groups),
            'groups_with_pop'           => 0,
            'groups_without_pop'        => array(),
            'total_pop_in_valid_groups' => 0,
        );
        foreach ($valid_groups as $gid => $gname) {
            if (isset($group_counts[$gid]) && $group_counts[$gid] > 0) {
                $coverage['groups_with_pop']++;
                $coverage['total_pop_in_valid_groups'] += $group_counts[$gid];
            } else {
                $coverage['groups_without_pop'][] = (string)$gid;
            }
        }
    } catch (PDOException $e) {
        error_log('[eoc_pop_g99] coverage: ' . $e->getMessage());
    }
    $_SESSION[$coverage_cache_key] = array('t' => time(), 'data' => $coverage);
}

/* ============================================================================
 * Main query: search
 * ============================================================================ */
if (isset($_GET['search']) || isset($_GET['only_with_service'])) {
    $search_performed = true;
    if (!$can_query) {
        $error_message = 'ไม่พบตาราง eoc_pop หรือ eoc_mas_gstatus ที่จำเป็น';
    } else {
        try {
            if ($searchIdcard === '' && !$onlyWithSvc) {
                $total_bad        = $bad_total_all;
                $total_bad_groups = count($bad_groups_all);
                $search_people    = $bad_people_all;
            } else {
                $where_base = "WHERE $NOTIN_SUB AND $IDCARD_FILTER";
                $params_base = array();
                if ($searchIdcard !== '') { $where_base .= " AND e.idcard = :sid"; $params_base[':sid'] = $searchIdcard; }
                if ($onlyWithSvc)        { $where_base .= " AND EXISTS (SELECT 1 FROM eoc_services s WHERE s.idcard = e.idcard)"; }

                if ($see_all) {
                    $countSql = "SELECT 
                        COUNT(*)                    AS total_rows,
                        COUNT(DISTINCT e.gstatus)   AS total_groups,
                        COUNT(DISTINCT e.idcard)    AS total_people
                    FROM eoc_pop e
                    $where_base";
                    $params_count = $params_base;
                } else {
                    $countSql = "SELECT 
                        COUNT(*)                    AS total_rows,
                        COUNT(DISTINCT e.gstatus)   AS total_groups,
                        COUNT(DISTINCT e.idcard)    AS total_people
                    FROM eoc_pop e
                    INNER JOIN person p ON p.idcard = e.idcard
                    $where_base AND p.hospcode = :hc";
                    $params_count = $params_base;
                    $params_count[':hc'] = $user_hospcode;
                }

                $stC = $pdo->prepare($countSql);
                $stC->execute($params_count);
                $cnt = $stC->fetch(PDO::FETCH_ASSOC) ?: array();
                $total_bad        = (int)(isset($cnt['total_rows'])   ? $cnt['total_rows']   : 0);
                $total_bad_groups = (int)(isset($cnt['total_groups']) ? $cnt['total_groups'] : 0);
                $search_people    = (int)(isset($cnt['total_people']) ? $cnt['total_people'] : 0);
            }

            $total_pages = max(1, (int)ceil($total_bad / $per_page));
            if ($page > $total_pages) $page = $total_pages;
            $offset = ($page - 1) * $per_page;

            if ($total_bad > 0) {
                $join_type = $see_all ? 'LEFT JOIN' : 'INNER JOIN';
                $where_data = "WHERE $NOTIN_SUB AND $IDCARD_FILTER"
                            . ($searchIdcard !== '' ? " AND e.idcard = :sid" : '')
                            . ($onlyWithSvc ? " AND EXISTS (SELECT 1 FROM eoc_services s WHERE s.idcard = e.idcard)" : '')
                            . ($see_all ? '' : ' AND p.hospcode = :hc_outer');
                $params_data = array();
                if ($searchIdcard !== '') $params_data[':sid'] = $searchIdcard;
                if (!$see_all)            $params_data[':hc_outer'] = $user_hospcode;

                $sql = "SELECT e.id, e.idcard, e.gstatus, e.d_update,
                               p.name AS patient_name
                        FROM eoc_pop e
                        $join_type person p ON p.idcard = e.idcard
                        $where_data
                        ORDER BY e.gstatus ASC, e.d_update DESC, e.idcard ASC
                        LIMIT :lim OFFSET :off";

                $st = $pdo->prepare($sql);
                foreach ($params_data as $k => $v) {
                    $st->bindValue($k, $v);
                }
                $st->bindValue(':lim', (int)$per_page, PDO::PARAM_INT);
                $st->bindValue(':off', (int)$offset,   PDO::PARAM_INT);
                $st->execute();
                $bad_rows = $st->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($bad_rows)) {
                    $idcards_uniq = array();
                    foreach ($bad_rows as $r) {
                        $ic = (string)$r['idcard'];
                        if ($ic !== '' && !isset($idcards_uniq[$ic])) {
                            $idcards_uniq[$ic] = true;
                        }
                    }
                    $service_map = array();
                    if (!empty($idcards_uniq)) {
                        $ic_list = array_keys($idcards_uniq);
                        $ph = implode(',', array_fill(0, count($ic_list), '?'));
                        try {
                            $stSvc = $pdo->prepare(
                                "SELECT idcard, COUNT(*) AS cnt 
                                 FROM eoc_services 
                                 WHERE idcard IN ($ph) 
                                 GROUP BY idcard"
                            );
                            $stSvc->execute($ic_list);
                            while ($svc = $stSvc->fetch(PDO::FETCH_ASSOC)) {
                                $service_map[(string)$svc['idcard']] = (int)$svc['cnt'];
                            }
                        } catch (PDOException $e) {
                            error_log('[eoc_pop_g99] service_batch: ' . $e->getMessage());
                        }
                    }
                    foreach ($bad_rows as &$row) {
                        $ic = (string)$row['idcard'];
                        $row['service_count'] = isset($service_map[$ic]) ? $service_map[$ic] : 0;
                    }
                    unset($row);
                }
            }
        } catch (PDOException $e) {
            error_log('[eoc_pop_g99] main: ' . $e->getMessage());
            $error_message = 'เกิดข้อผิดพลาดในการตรวจสอบข้อมูล';
        }
    }
}

/* ---------- Time left ---------- */
$last_activity = isset($_SESSION['last_activity']) ? (int)$_SESSION['last_activity'] : time();
$time_left = max(0, $timeout_duration - (time() - $last_activity));
$time_left_formatted = sprintf('%02d:%02d', floor($time_left / 60), $time_left % 60);

/* ---------- timeout_guard ก่อน session_write_close + HTML ---------- */
require_once __DIR__ . '/includes/timeout_guard.php';

if (session_status() === PHP_SESSION_ACTIVE) {
    @session_write_close();
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#1E293B">
<title>ตรวจ gstatus นอก master (ข้ามทุกกลุ่ม) | VOC – Vulnerable Operations Center</title>

<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" media="print" onload="this.media='all'">
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;600&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
<noscript>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;600&display=swap" rel="stylesheet">
</noscript>

<style>
:root{--gc:<?php echo eoc_h($group_color); ?>;--gc-soft:rgba(<?php echo (int)$gr; ?>,<?php echo (int)$gg; ?>,<?php echo (int)$gb; ?>,.12);--ink:#0F172A}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{-webkit-text-size-adjust:100%}
body{font-family:'Kanit',system-ui,-apple-system,sans-serif;font-size:15px;background:#F8FAFC;color:var(--ink);line-height:1.5;overflow-x:hidden;min-height:100vh}
body.nav-open{overflow:hidden}
a{color:inherit;text-decoration:none}
button,input,select{font-family:inherit}
button{cursor:pointer;border:0;background:none;color:inherit}
.mono{font-family:ui-monospace,Menlo,monospace}

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

.hdr{background:#1E293B;color:#fff;padding:env(safe-area-inset-top,0px) 0 0;position:relative;z-index:40;box-shadow:0 1px 3px rgba(0,0,0,.2)}
@media(min-width:1024px){.hdr{position:sticky;top:0}}
.hdr-in{padding:.75rem max(1rem,env(safe-area-inset-right,0px)) .75rem max(1rem,env(safe-area-inset-left,0px))}
@media(min-width:640px){.hdr-in{padding-left:1.5rem;padding-right:1.5rem}}
@media(min-width:1024px){.hdr-in{padding:1rem 2rem}}
.glass{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.15)}
.hdr-row{display:flex;align-items:center;justify-content:space-between;gap:.75rem}
.hdr-l{display:flex;align-items:center;gap:.75rem;min-width:0}
.hdr h1{font-size:1rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
@media(min-width:640px){.hdr h1{font-size:1.25rem}}
.hdr small{display:none;color:#D1D5DB;font-size:.75rem}
@media(min-width:640px){.hdr small{display:block}}
.ibtn{width:44px;height:44px;flex:none;display:flex;align-items:center;justify-content:center;border-radius:8px;color:#fff}
@media(min-width:1024px){.ibtn.nav{display:none}}
.logo{display:none;padding:.65rem .75rem;border-radius:12px;font-size:1.25rem}
@media(min-width:640px){.logo{display:block}}
.hdr-user{display:none;align-items:center;gap:.75rem;padding:.4rem .75rem;border-radius:8px;max-width:16rem;font-size:.875rem}
@media(min-width:768px){.hdr-user{display:flex}}
.hdr-user b{display:block;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hdr-user span{display:block;color:#D1D5DB;font-size:.75rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.avatar{width:36px;height:36px;flex:none;border-radius:50%;background:#2563EB;display:flex;align-items:center;justify-content:center}
.btn-ic{width:42px;height:42px;flex:none;display:inline-flex;align-items:center;justify-content:center;border-radius:.6rem;color:#fff;box-shadow:0 1px 3px rgba(0,0,0,.12)}
.hdr-sub{margin-top:.5rem;padding-top:.5rem;border-top:1px solid rgba(255,255,255,.1);font-size:.75rem;color:#D1D5DB;display:flex;flex-wrap:wrap;justify-content:space-between;gap:.25rem 1rem}
.hdr-sub span{display:inline-flex;align-items:center;gap:.4rem}

.main{padding:1rem max(1rem,env(safe-area-inset-right,0px)) 1rem max(1rem,env(safe-area-inset-left,0px))}
@media(min-width:640px){.main{padding:1.5rem}}@media(min-width:1024px){.main{padding:2rem}}
.wrap{max-width:72rem;margin:0 auto;display:flex;flex-direction:column;gap:1rem;width:100%}
.bar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.75rem}
.crumb{font-size:.8rem;color:#6B7280;display:flex;align-items:center;gap:.25rem;flex-wrap:wrap}
.crumb a:hover{color:#1F2937}.crumb i{font-size:10px;color:#9CA3AF}.crumb b{color:#374151;font-weight:600}
.navcards{display:flex;flex-wrap:wrap;gap:.75rem}
.nav-card{display:flex;align-items:flex-start;gap:.5rem;background:#fff;border-radius:10px;padding:.75rem;border:2px solid #F0F0F0;min-width:150px;flex:1 1 150px;text-align:left;transition:border-color .15s,background .15s}
.nav-card:hover{border-color:var(--gc)}
.nav-card.active{background:#ECFDF5;border-color:#10B981;box-shadow:0 0 0 3px rgba(16,185,129,.2)}
.nav-card .ic{font-size:1.25rem;line-height:1;margin-top:.05rem;flex-shrink:0}
.nav-card .info{flex:1;min-width:0}
.nav-card .info b{display:block;font-size:.75rem;font-weight:700;color:#1F293B;margin:0 0 .1rem 0;line-height:1.2}
.nav-card .info small{display:block;font-size:.65rem;font-weight:400;color:#6B7280;margin:0;line-height:1.3}

.note{padding:.75rem 1rem;border-radius:12px;font-size:.875rem;display:flex;gap:.75rem;align-items:flex-start}
.note.info{background:#EFF6FF;border:1px solid #BFDBFE;color:#1E40AF}
.note.ok{background:#F0FDF4;border:1px solid #BBF7D0;color:#166534;border-radius:8px}
.note.err{background:#FEF2F2;border:1px solid #FECACA;color:#B91C1C;border-radius:8px}
.note.warn{background:#FFFBEB;border:1px solid #FDE68A;color:#B45309}
.note small{display:block;font-size:.75rem;opacity:.9;margin-top:.15rem}

.panel{background:#fff;border-radius:16px;box-shadow:0 1px 3px rgba(0,0,0,.06);border:1px solid rgba(0,0,0,.04);padding:1rem}
@media(min-width:640px){.panel{padding:1.5rem}}@media(min-width:1024px){.panel{padding:2rem}}
.panel-h{display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:.75rem;margin-bottom:1rem}
.panel-h h2{font-size:1.25rem;font-weight:700;color:#1F2937;display:flex;align-items:center;gap:.5rem}
@media(min-width:640px){.panel-h h2{font-size:1.5rem}}
.panel-h p{color:#6B7280;font-size:.875rem;margin-top:.25rem}
.pill{padding:.375rem .75rem;border-radius:8px;font-size:.75rem;font-weight:600;background:var(--gc-soft);color:var(--gc)}

.stats{display:grid;grid-template-columns:1fr;gap:.75rem}
@media(min-width:640px){.stats{grid-template-columns:repeat(3,1fr)}}
.stat{background:#fff;border-radius:12px;border:1px solid #E5E7EB;border-left:4px solid;padding:1rem}
.stat span{display:block;font-size:.72rem;font-weight:600;text-transform:uppercase;color:#6B7280}
.stat b{display:block;font-size:1.5rem;font-weight:700;color:#1F2937;margin-top:.25rem}
.stat small{display:block;font-size:.72rem;margin-top:.15rem}
.stat.blue{border-left-color:#2563EB}.stat.blue small{color:#2563EB}
.stat.red{border-left-color:#EF4444}.stat.red small{color:#DC2626}
.stat.amber{border-left-color:#F59E0B}.stat.amber small{color:#B45309}
.stat.green{border-left-color:#10B981}.stat.green small{color:#059669}

.coverage-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:.5rem;margin-top:.75rem}
.coverage-cell{background:#F9FAFB;border-radius:10px;padding:.75rem;text-align:center;border:1px solid #E5E7EB}
.coverage-cell b{display:block;font-size:1.25rem;font-weight:700;color:#1F2937}
.coverage-cell span{display:block;font-size:.72rem;color:#6B7280;margin-top:.15rem}

.badge-row{display:flex;flex-wrap:wrap;gap:.5rem}
.code-badge{display:inline-flex;align-items:center;gap:.35rem;padding:.3rem .7rem;border-radius:99px;font-size:.78rem;font-weight:600;background:rgba(239,68,68,.10);color:#B91C1C;border:1px solid rgba(239,68,68,.30);white-space:nowrap}
.code-badge .sub{font-size:.7rem;font-weight:400;color:#7F1D1D}

.fld{width:100%;font-size:16px;color:#0F172A;background:#fff;border:1.5px solid #CBD5E1;border-radius:.75rem;padding:.6rem .9rem;outline:none}
.fld:focus{border-color:var(--gc);box-shadow:0 0 0 4px var(--gc-soft)}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:.5rem;min-height:42px;padding:0 1rem;border-radius:.6rem;font-size:.875rem;font-weight:600;color:#fff;white-space:nowrap;box-shadow:0 1px 3px rgba(0,0,0,.12)}
.btn:active{opacity:.85}.btn:disabled{opacity:.55;cursor:not-allowed}
.btn-gray{background:#6B7280}.btn-acc{background:var(--gc)}.btn-red{background:#EF4444}
.btn-blue{background:#2563EB}.btn-amber{background:#D97706}
.btn-sm{min-height:34px;padding:0 .65rem;font-size:.8rem;border-radius:.5rem}
.search-row{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center}
.search-row .fld{flex:1 1 200px;min-width:0}
.chk-wrap{display:inline-flex;align-items:center;gap:.5rem;padding:0 .85rem;min-height:42px;border:1.5px solid #CBD5E1;border-radius:.75rem;font-size:.85rem;color:#4B5563;cursor:pointer;white-space:nowrap;background:#fff}
.chk-wrap input{width:16px;height:16px;accent-color:var(--gc);cursor:pointer}
.tools{background:#F9FAFB;border:1px solid #E5E7EB;border-radius:12px;padding:.85rem;margin-bottom:1rem}
.tools label{display:block;font-size:.8rem;font-weight:600;color:#4B5563;margin-bottom:.35rem}

.tbl-wrap{overflow-x:auto;border:1px solid #E5E7EB;border-radius:12px;-webkit-overflow-scrolling:touch;max-width:100%}
table{width:100%;border-collapse:collapse;min-width:800px;font-size:.875rem}
thead{background:#F9FAFB;color:#4B5563}
th{padding:.7rem .85rem;text-align:left;font-weight:600;font-size:.8rem;white-space:nowrap}
td{padding:.65rem .85rem;vertical-align:middle;border-top:1px solid #F1F5F9}
tbody tr:hover{background:#FEF2F2}
.chk{width:20px;height:20px;accent-color:var(--gc);cursor:pointer}
.sub{font-size:.72rem;color:#9CA3AF}
.empty{text-align:center;color:#9CA3AF;padding:2.5rem 1rem}
.empty i{font-size:2rem;display:block;margin-bottom:.5rem}
.badge-g{display:inline-block;padding:2px 8px;border-radius:99px;font-size:.72rem;font-weight:700;font-family:ui-monospace,Menlo,monospace;background:rgba(220,38,38,.10);color:#B91C1C;border:1px solid rgba(220,38,38,.30)}
.badge-svc{display:inline-block;padding:2px 8px;border-radius:99px;font-size:.72rem;font-weight:600;background:rgba(220,38,38,.10);color:#B91C1C}

.purge-bar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.75rem;margin-bottom:.75rem}
.purge-bar .sel-info{font-size:.85rem;color:#4B5563}
.purge-btns{display:flex;flex-wrap:wrap;gap:.5rem}

.pager{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.75rem;margin-top:1rem;font-size:.85rem;color:#6B7280}
.pg{display:flex;flex-wrap:wrap;gap:.35rem}
.pg a,.pg span{min-width:38px;height:38px;padding:0 .6rem;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;border:1px solid #D1D5DB;background:#fff;color:#374151;font-weight:600}
.pg a:hover{border-color:var(--gc)}
.pg .cur{background:var(--gc);border-color:var(--gc);color:#fff}
.pg .dis{opacity:.45}

.foot{background:#1F2937;color:#fff;padding:1.5rem 1rem calc(1.5rem + env(safe-area-inset-bottom,0px))}
@media(min-width:640px){.foot{padding-left:1.5rem;padding-right:1.5rem}}@media(min-width:1024px){.foot{padding-left:2rem;padding-right:2rem}}
.foot-in{display:flex;flex-direction:column;align-items:center;justify-content:space-between;gap:1rem;text-align:center}
@media(min-width:768px){.foot-in{flex-direction:row;text-align:left}}
.foot-l{display:flex;align-items:center;gap:1rem;text-align:left}
.foot-logo{width:40px;height:40px;flex:none;background:#2563EB;border-radius:8px;display:flex;align-items:center;justify-content:center}
.foot-r{display:flex;flex-wrap:wrap;justify-content:center;gap:.25rem 1.5rem;color:#9CA3AF;font-size:.875rem}
.foot-r span{display:inline-flex;align-items:center;gap:.5rem}

::-webkit-scrollbar{width:6px;height:6px}::-webkit-scrollbar-thumb{background:var(--gc);border-radius:10px}
a:focus-visible,button:focus-visible,input:focus-visible{outline:2px solid var(--gc);outline-offset:2px}
@media print{.eoc-side,.eoc-backdrop,.tools,.purge-bar,.navcards,.foot{display:none!important}.main-content{margin:0!important}}
</style>
</head>
<body>

<div class="eoc-backdrop" id="eocBackdrop" hidden></div>
<aside class="eoc-side" id="eocSide" aria-label="เมนูด้านข้าง">
    <div class="eoc-side-top">
        <span><i class="fas fa-bars" aria-hidden="true"></i>&nbsp; เมนู</span>
        <button type="button" id="eocNavClose" aria-label="ปิดเมนู"><i class="fas fa-xmark" aria-hidden="true"></i></button>
    </div>
    <?php
        $sidebar_drawer = true;
        $sidebar_skip_assets = true;
        include __DIR__ . '/sidebar_eoc.php';
    ?>
</aside>

<div class="main-content">

<header class="hdr">
    <div class="hdr-in">
        <div class="hdr-row">
            <div class="hdr-l">
                <button type="button" id="eocNavToggle" class="ibtn nav glass" aria-controls="eocSide" aria-expanded="false" aria-label="เปิดเมนูนำทาง"><i class="fas fa-bars" aria-hidden="true"></i></button>
                <div class="logo glass"><i class="fas fa-tags" style="color:#FCA5A5" aria-hidden="true"></i></div>
                <div style="min-width:0">
                    <h1>ตรวจ gstatus นอก master</h1>
                    <small>VOC – Vulnerable Operations Center · ตรวจข้ามทุกกลุ่ม</small>
                </div>
            </div>
            <div class="hdr-row" style="gap:.5rem">
                <div class="hdr-user glass">
                    <div class="avatar"><i class="fas fa-user-doctor" aria-hidden="true"></i></div>
                    <div style="min-width:0"><b><?php echo eoc_h($user_name); ?></b><span><?php echo eoc_h($status_user); ?> | <?php echo eoc_h($user_hospcode); ?></span></div>
                </div>
                <a href="../../logout.php" class="btn-ic" style="background:#DC2626" aria-label="ออกจากระบบ" title="ออกจากระบบ"><i class="fas fa-sign-out-alt" aria-hidden="true"></i></a>
            </div>
        </div>
        <div class="hdr-sub">
            <span class="hide-sm"><i class="fas fa-tag" aria-hidden="true"></i>กลุ่มปัจจุบัน (สำหรับเมนู): <?php echo eoc_h($group_name); ?> (<?php echo eoc_h($group_code); ?>)</span>
            <span><i class="fas fa-clock" aria-hidden="true"></i><?php echo date('H:i'); ?> &nbsp;<i class="fas fa-calendar" aria-hidden="true"></i><?php echo date('d/m/Y'); ?> &nbsp;<i class="fas fa-hourglass-half" aria-hidden="true"></i>หมดเขต: <b id="timeout-timer" class="mono"><?php echo $time_left_formatted; ?></b></span>
        </div>
    </div>
</header>

<main class="main">
<div class="wrap">

    <div class="bar">
        <nav class="crumb">
            <a href="eoc_data.php?g=<?php echo $gq; ?>">ทะเบียนผู้ป่วย</a> <i class="fas fa-chevron-right" aria-hidden="true"></i>
            <a href="eoc_pop_setup.php?g=<?php echo $gq; ?>">ตั้งค่าผู้ป่วย</a> <i class="fas fa-chevron-right" aria-hidden="true"></i>
            <b>ตรวจ gstatus นอก master</b>
        </nav>
        <a href="eoc_pop_setup.php?g=<?php echo $gq; ?>" class="btn-ic" style="background:#6B7280" title="ย้อนกลับ" aria-label="ย้อนกลับ"><i class="fas fa-arrow-left" aria-hidden="true"></i></a>
    </div>

    <div class="navcards">
        <a href="eoc_pop_setup.php?g=<?php echo $gq; ?>" class="nav-card">
            <div class="ic" style="color:var(--gc)"><i class="fas fa-users-cog"></i></div>
            <div class="info"><b>จัดการกลุ่มผู้ป่วย</b><small>ตรวจสอบและกำหนดกลุ่ม</small></div>
        </a>
        <a href="eoc_pop_new.php?g=<?php echo $gq; ?>" class="nav-card">
            <div class="ic" style="color:#3B82F6"><i class="fas fa-user-plus"></i></div>
            <div class="info"><b>เพิ่มผู้ป่วยใหม่</b><small>บันทึกข้อมูลผู้ป่วยใหม่</small></div>
        </a>
        <a href="eoc_pop_cancel.php?g=<?php echo $gq; ?>" class="nav-card">
            <div class="ic" style="color:#EF4444"><i class="fas fa-user-times"></i></div>
            <div class="info"><b>ยกเลิกผู้ป่วย</b><small>จัดการการยกเลิกข้อมูล</small></div>
        </a>
        <a href="eoc_pop_duplicate.php?g=<?php echo $gq; ?>" class="nav-card">
            <div class="ic" style="color:#F59E0B"><i class="fas fa-copy"></i></div>
            <div class="info"><b>ตรวจข้อมูลซ้ำ</b><small>ตรวจสอบข้อมูลซ้ำซ้อน</small></div>
        </a>
        <a href="eoc_pop_g99.php?g=<?php echo $gq; ?>" class="nav-card active" aria-current="page">
            <div class="ic" style="color:#EF4444"><i class="fas fa-tags"></i></div>
            <div class="info"><b>ตรวจ gstatus นอก master</b><small>ตรวจทุกกลุ่มพร้อมกัน</small></div>
        </a>
    </div>

    <?php if ($flash): ?>
    <div class="note <?php echo $flash[0] === 'ok' ? 'ok' : 'err'; ?>" role="status">
        <i class="fas <?php echo $flash[0] === 'ok' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>" style="margin-top:2px"></i>
        <div><?php echo eoc_h($flash[1]); ?></div>
    </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
    <div class="note err"><i class="fas fa-exclamation-circle"></i><div><?php echo eoc_h($error_message); ?></div></div>
    <?php endif; ?>

    <?php if (!$has_mas || !$has_pop): ?>
    <div class="note warn">
        <i class="fas fa-database" style="margin-top:2px"></i>
        <div>
            <b>ไม่พบตารางที่จำเป็น</b>
            <small><?php echo !$has_pop ? 'eoc_pop ' : ''; ?><?php echo !$has_mas ? 'eoc_mas_gstatus ' : ''; ?>กรุณาตรวจสอบฐานข้อมูลก่อนใช้งานหน้านี้</small>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$has_del): ?>
    <div class="note warn">
        <i class="fas fa-shield-halved" style="margin-top:2px"></i>
        <div>
            <b>ยังไม่มีตาราง eoc_pop_deleted</b>
            <small>ไม่สามารถสำรองข้อมูลก่อนลบได้ — ปุ่มลบจะถูกปิดไว้ กรุณาสร้างตารางนี้ก่อนใช้งาน</small>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$see_all): ?>
    <div class="note warn">
        <i class="fas fa-shield-halved" style="margin-top:2px"></i>
        <div>
            <b>ข้อมูลถูกจำกัดตามสถานบริการของคุณ</b>
            <small>คุณเห็นเฉพาะแถวที่ผูกกับผู้ป่วยของสถานบริการ <?php echo eoc_h($user_hospcode); ?> (admin และ รพ. 00860 เห็นทุกแห่ง)</small>
        </div>
    </div>
    <?php endif; ?>

    <div class="note info">
        <i class="fas fa-info-circle" style="margin-top:2px"></i>
        <div>
            <b>หลักการตรวจสอบ</b>
            <small>หน้านี้ตรวจ <strong>ทุกกลุ่มพร้อมกัน</strong> — เทียบ <code class="mono">eoc_pop.gstatus</code> กับ <code class="mono">eoc_mas_gstatus.gstatus_id</code> ทุกแถว ไม่กรองตามกลุ่มปัจจุบัน</small>
        </div>
    </div>

    <div class="stats">
        <div class="stat blue">
            <span>eoc_pop ทั้งหมด</span>
            <b><?php echo number_format($pop_total); ?></b>
            <small>แถว<?php echo $see_all ? ' (ทั้งระบบ)' : ' (ในสถานบริการของคุณ)'; ?></small>
        </div>
        <div class="stat red">
            <span>แถวนอก master</span>
            <b><?php echo number_format($bad_total_all); ?></b>
            <small><?php echo number_format(count($bad_groups_all)); ?> รหัส ที่ไม่อยู่ใน master</small>
        </div>
        <div class="stat amber">
            <span>คนที่ได้รับผลกระทบ</span>
            <b><?php echo number_format($bad_people_all); ?></b>
            <small>เลขบัตรไม่ซ้ำ</small>
        </div>
    </div>

    <section class="panel">
        <div class="panel-h">
            <div>
                <h2><i class="fas fa-list-check" style="color:#10B981" aria-hidden="true"></i>ความครอบคลุมของ master</h2>
                <p>ยืนยันว่าตรวจทุกกลุ่มใน <code class="mono">eoc_mas_gstatus</code> — ไม่ใช่เฉพาะกลุ่มปัจจุบัน</p>
            </div>
        </div>
        <div class="coverage-grid">
            <div class="coverage-cell">
                <b><?php echo number_format($coverage['total_groups']); ?></b>
                <span>กลุ่มใน master</span>
            </div>
            <div class="coverage-cell">
                <b style="color:#10B981"><?php echo number_format($coverage['groups_with_pop']); ?></b>
                <span>กลุ่มที่มีข้อมูล</span>
            </div>
            <div class="coverage-cell">
                <b style="color:#9CA3AF"><?php echo number_format(count($coverage['groups_without_pop'])); ?></b>
                <span>กลุ่มที่ไม่มีข้อมูล</span>
            </div>
        </div>
        <?php if (!empty($coverage['groups_without_pop'])): ?>
        <p style="font-size:.75rem;color:#6B7280;margin-top:.75rem">
            <i class="fas fa-circle-info" aria-hidden="true"></i>
            กลุ่มที่ไม่มีข้อมูล: <span class="mono"><?php echo eoc_h(implode(', ', $coverage['groups_without_pop'])); ?></span>
        </p>
        <?php endif; ?>
        <p style="font-size:.75rem;color:#6B7280;margin-top:.5rem">
            <i class="fas fa-check-circle" style="color:#10B981" aria-hidden="true"></i>
            ตรวจแล้ว: <strong><?php echo number_format($coverage['total_groups']); ?> กลุ่ม</strong> ·
            <?php echo number_format($coverage['total_pop_in_valid_groups']); ?> แถวอยู่ในกลุ่มที่ถูกต้อง ·
            <?php echo number_format($bad_total_all); ?> แถวนอก master
        </p>
    </section>

    <?php if (!empty($bad_groups_all)): ?>
    <section class="panel">
        <div class="panel-h">
            <div>
                <h2><i class="fas fa-tags" style="color:#EF4444" aria-hidden="true"></i>รหัส gstatus นอก master</h2>
                <p>พบ <?php echo number_format(count($bad_groups_all)); ?> รหัส ที่ไม่มีใน eoc_mas_gstatus</p>
            </div>
        </div>
        <div class="badge-row">
            <?php foreach ($bad_groups_all as $code => $info): ?>
                <span class="code-badge">
                    <span class="mono"><?php echo eoc_h($code === '' ? '(ค่าว่าง)' : $code); ?></span>
                    <span class="sub"><?php echo number_format($info['rows']); ?> แถว · <?php echo number_format($info['people']); ?> คน</span>
                </span>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($valid_groups)): ?>
        <p style="font-size:.75rem;color:#6B7280;margin-top:.75rem">
            <i class="fas fa-check-circle" style="color:#10B981" aria-hidden="true"></i>
            รหัสที่ถูกต้อง (master): <span class="mono"><?php echo eoc_h(implode(', ', array_keys($valid_groups))); ?></span>
        </p>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="panel">
        <div class="panel-h">
            <div>
                <h2><i class="fas fa-filter" style="color:#EF4444" aria-hidden="true"></i>ค้นหาแถวนอก master</h2>
                <p>กรองเฉพาะราย เพื่อตรวจ/ลบ</p>
            </div>
            <span class="pill"><i class="fas fa-shuffle" aria-hidden="true"></i> ข้ามทุกกลุ่ม</span>
        </div>

        <div class="tools">
            <form method="GET" action="eoc_pop_g99.php">
                <input type="hidden" name="g" value="<?php echo eoc_h($group_code); ?>">
                <label for="search"><i class="fas fa-search" aria-hidden="true"></i> ค้นหา</label>
                <div class="search-row">
                    <input id="search" name="search" type="search" class="fld" inputmode="numeric" maxlength="13"
                           value="<?php echo eoc_h($searchIdcard); ?>" placeholder="กรอกเลขบัตรประชาชน 13 หลัก เพื่อตรวจเฉพาะราย">
                    <label class="chk-wrap">
                        <input type="checkbox" name="only_with_service" value="1" <?php echo $onlyWithSvc ? 'checked' : ''; ?>>
                        <span>เฉพาะที่มีคำขอ</span>
                    </label>
                    <button type="submit" class="btn btn-blue"><i class="fas fa-search" aria-hidden="true"></i><span class="hide-sm-txt">ตรวจสอบ</span></button>
                    <?php if ($searchIdcard !== '' || $onlyWithSvc): ?>
                        <a href="eoc_pop_g99.php?g=<?php echo $gq; ?>" class="btn btn-gray" title="ล้างตัวกรอง"><i class="fas fa-times" aria-hidden="true"></i></a>
                    <?php endif; ?>
                </div>
                <p style="font-size:.75rem;color:#6B7280;margin-top:.5rem"><i class="fas fa-info-circle" aria-hidden="true"></i> เว้นว่างเพื่อดูทั้งหมด · ติ๊ก "เฉพาะที่มีคำขอ" เพื่อดูเฉพาะรายที่มีคำขอผูกอยู่</p>
            </form>
        </div>
    </section>

    <section class="panel">
        <div class="panel-h">
            <div>
                <h2><i class="fas fa-trash-alt" style="color:#EF4444" aria-hidden="true"></i>ดำเนินการลบ</h2>
                <p>สำรองแถวลง eoc_pop_deleted ก่อนลบทุกครั้ง — ย้อนกลับได้</p>
            </div>
        </div>

        <form method="POST" id="purgeForm" action="eoc_pop_g99.php">
            <input type="hidden" name="action" value="purge">
            <input type="hidden" name="csrf" value="<?php echo eoc_h($g99_csrf); ?>">
            <input type="hidden" name="mode" id="purgeMode" value="listed">
            <input type="hidden" name="q" value="<?php echo eoc_h($searchIdcard); ?>">
            <input type="hidden" name="flag_only_svc" value="<?php echo $onlyWithSvc ? '1' : ''; ?>">
            <input type="hidden" name="page" value="<?php echo (int)$page; ?>">

            <div class="purge-bar">
                <div class="sel-info">เลือกแล้ว <b id="selCount">0</b> แถว</div>
                <div class="purge-btns">
                    <button type="submit" data-mode="listed" id="btnListed"
                            class="purge-btn btn btn-red" disabled>
                        <i class="fas fa-trash-alt" aria-hidden="true"></i> ลบเฉพาะที่เลือก
                    </button>
                    <button type="submit" data-mode="all" id="btnAll"
                            class="purge-btn btn btn-amber" <?php echo ($bad_total_all > 0 && $has_del && $can_query) ? '' : 'disabled'; ?>>
                        <i class="fas fa-layer-group" aria-hidden="true"></i> ลบทั้งหมด (<?php echo number_format($bad_total_all); ?> แถว)
                    </button>
                </div>
            </div>

            <?php if (!$search_performed): ?>
                <div class="empty">
                    <i class="fas fa-tags" aria-hidden="true"></i>
                    ยังไม่ได้ค้นหา — กด "ตรวจสอบ" ด้านบนเพื่อดูรายการ<br>
                    <small style="font-size:.75rem;opacity:.75">หรือกด "ลบทั้งหมด" เพื่อลบแถวนอก master ทุกราย (สำรองก่อนลบทุกครั้ง)</small>
                </div>
            <?php elseif (empty($bad_rows)): ?>
                <div class="empty">
                    <i class="fas fa-check-circle" style="color:#10B981" aria-hidden="true"></i>
                    ไม่พบแถวนอก master ตามเงื่อนไข
                </div>
            <?php else: ?>
                <div class="tbl-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th style="width:44px"><input type="checkbox" class="chk" id="chkAll" aria-label="เลือกทั้งหน้า"></th>
                                <th>เลขบัตร</th>
                                <th>ชื่อผู้ป่วย</th>
                                <th style="text-align:center">gstatus (นอก master)</th>
                                <th style="text-align:center">คำขอ</th>
                                <th>อัปเดต</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($bad_rows as $b): ?>
                            <tr>
                                <td><input type="checkbox" class="chk row-chk" name="ids[]" value="<?php echo (int)$b['id']; ?>" aria-label="เลือกแถว <?php echo (int)$b['id']; ?>"></td>
                                <td class="mono" style="font-weight:600;color:#1F2937"><?php echo eoc_h($b['idcard']); ?></td>
                                <td style="color:#4B5563"><?php echo eoc_h(($b['patient_name'] !== null && $b['patient_name'] !== '') ? $b['patient_name'] : '-'); ?></td>
                                <td style="text-align:center">
                                    <span class="badge-g"><?php echo eoc_h($b['gstatus'] === '' ? '(ค่าว่าง)' : $b['gstatus']); ?></span>
                                </td>
                                <td style="text-align:center;font-size:.8rem">
                                    <?php if ((int)$b['service_count'] > 0): ?>
                                        <span class="badge-svc"><?php echo (int)$b['service_count']; ?> รายการ</span>
                                    <?php else: ?>
                                        <span style="color:#9CA3AF">ไม่มี</span>
                                    <?php endif; ?>
                                </td>
                                <td class="mono" style="font-size:.78rem;color:#6B7280"><?php echo eoc_h($b['d_update'] ? $b['d_update'] : '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </form>

        <?php if ($search_performed && $total_bad > 0): ?>
        <div class="pager">
            <div>
                พบ <b><?php echo number_format($total_bad); ?></b> แถว จาก <b><?php echo number_format($total_bad_groups); ?></b> รหัส
                (<?php echo number_format($search_people); ?> คน)
                <?php if ($total_pages > 1): ?>· หน้า <?php echo $page; ?> จาก <?php echo $total_pages; ?><?php endif; ?>
            </div>
            <?php if ($total_pages > 1):
                $from = max(1, $page - 2); $to = min($total_pages, $page + 2); ?>
            <nav class="pg" aria-label="แบ่งหน้า">
                <?php if ($page > 1): ?><a href="<?php echo eoc_h(g99_url($group_code, $searchIdcard, $onlyWithSvc, $page - 1)); ?>" aria-label="ก่อนหน้า"><i class="fas fa-chevron-left"></i></a><?php else: ?><span class="dis"><i class="fas fa-chevron-left"></i></span><?php endif; ?>
                <?php if ($from > 1): ?><a href="<?php echo eoc_h(g99_url($group_code, $searchIdcard, $onlyWithSvc, 1)); ?>">1</a><?php if ($from > 2): ?><span class="dis">…</span><?php endif; endif; ?>
                <?php for ($i = $from; $i <= $to; $i++): ?>
                    <?php if ($i === $page): ?><span class="cur" aria-current="page"><?php echo $i; ?></span><?php else: ?><a href="<?php echo eoc_h(g99_url($group_code, $searchIdcard, $onlyWithSvc, $i)); ?>"><?php echo $i; ?></a><?php endif; ?>
                <?php endfor; ?>
                <?php if ($to < $total_pages): if ($to < $total_pages - 1): ?><span class="dis">…</span><?php endif; ?><a href="<?php echo eoc_h(g99_url($group_code, $searchIdcard, $onlyWithSvc, $total_pages)); ?>"><?php echo $total_pages; ?></a><?php endif; ?>
                <?php if ($page < $total_pages): ?><a href="<?php echo eoc_h(g99_url($group_code, $searchIdcard, $onlyWithSvc, $page + 1)); ?>" aria-label="ถัดไป"><i class="fas fa-chevron-right"></i></a><?php else: ?><span class="dis"><i class="fas fa-chevron-right"></i></span><?php endif; ?>
            </nav>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </section>

</div>
</main>

<footer class="foot">
    <div class="foot-in">
        <div class="foot-l">
            <div class="foot-logo"><i class="fas fa-hand-holding-heart" aria-hidden="true"></i></div>
            <div>
                <div style="font-weight:600">VOC Thepha Care</div>
                <div style="color:#9CA3AF;font-size:.875rem">Vulnerable Operations Center</div>
                <div style="color:#6B7280;font-size:.75rem;margin-top:.25rem">ศูนย์ดูแลกลุ่มเปราะบาง ครอบคลุมทุกสถานการณ์</div>
            </div>
        </div>
        <div class="foot-r">
            <span><i class="fas fa-copyright" aria-hidden="true"></i><?php echo ((int)date('Y') + 543); ?> Thepha Care</span>
            <span><i class="fas fa-heart" style="color:#F87171" aria-hidden="true"></i>ดูแลครบวงจร ในจุดเดียว</span>
        </div>
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

var form = $('purgeForm');
if (!form) return;

// Loading overlay — แสดงตอนลบข้อมูล เพื่อกันหน้าจอค้างและป้องกัน double submit
var loading = document.createElement('div');
loading.id = 'purgeLoading';
loading.style.cssText = 'position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;flex-direction:column;color:#fff;font-family:Kanit,sans-serif';
loading.innerHTML = '<div style="width:40px;height:40px;border:4px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin 1s linear infinite;"></div><div style="margin-top:1rem;font-size:1rem">กำลังประมวลผล...</div>';
document.body.appendChild(loading);
var s = document.createElement('style');
s.textContent = '@keyframes spin{to{transform:rotate(360deg)}}';
document.head.appendChild(s);

var modeEl    = $('purgeMode');
var selCnt    = $('selCount');
var chkAll    = $('chkAll');
var btnListed = $('btnListed');
var btnAll    = $('btnAll');
var rows      = Array.prototype.slice.call(document.querySelectorAll('.row-chk'));
var TOTAL     = <?php echo (int)$bad_total_all; ?>;

[btnListed, btnAll].forEach(function (b) {
    if (!b) return;
    b.addEventListener('click', function () { modeEl.value = b.getAttribute('data-mode'); });
});

function countChecked() {
    var n = 0;
    for (var i = 0; i < rows.length; i++) if (rows[i].checked) n++;
    return n;
}
function refresh() {
    var n = countChecked();
    if (selCnt) selCnt.textContent = n.toLocaleString('th-TH');
    if (btnListed) btnListed.disabled = (n === 0);
    if (chkAll) {
        chkAll.checked = (n > 0 && n === rows.length);
        chkAll.indeterminate = (n > 0 && n < rows.length);
    }
}
if (chkAll) chkAll.addEventListener('change', function () {
    for (var i = 0; i < rows.length; i++) rows[i].checked = chkAll.checked;
    refresh();
});
for (var i = 0; i < rows.length; i++) rows[i].addEventListener('change', refresh);
refresh();

form.addEventListener('submit', function (e) {
    if (form.dataset.submitting === '1') { e.preventDefault(); return; } // block double submit
    var mode = modeEl.value;
    var msg;
    if (mode === 'listed') {
        var n = countChecked();
        if (n === 0) { e.preventDefault(); alert('กรุณาเลือกแถวอย่างน้อย 1 รายการ'); return; }
        msg = 'ยืนยันลบ ' + n + ' แถว?\n(ระบบจะสำรองไป eoc_pop_deleted ก่อนลบทุกครั้ง)';
    } else {
        msg = 'ยืนยันลบแถวนอก master ทั้งหมด ' + TOTAL.toLocaleString('th-TH') + ' แถว?\n(ระบบจะสำรองไป eoc_pop_deleted ก่อนลบทุกครั้ง)';
    }
    if (!confirm(msg)) { e.preventDefault(); return; }
    form.dataset.submitting = '1';
    var bAll = form.querySelectorAll('button[type=submit]');
    for (var j = 0; j < bAll.length; j++) bAll[j].disabled = true;
    loading.style.display = 'flex';
});
})();
</script>

</body>
</html>