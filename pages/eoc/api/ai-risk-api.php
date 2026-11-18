<?php
/**
 * ============================================================================
 * AI RISK API - Vulnerable Patient Risk Stratification Endpoint
 * ============================================================================
 * ปลายทาง JSON สำหรับให้หน้าจอ ai_insight.php เรียกใช้แบบ Async (fetch)
 *
 * Endpoints (?action=...):
 *   overview    สรุปภาพรวม: จำนวนตามระดับความเสี่ยง, กลุ่ม, พื้นที่, ช่วงอายุ + Data Readiness
 *   list        รายชื่อผู้ป่วยพร้อมคะแนนความเสี่ยง (กรอง/ค้นหา/แบ่งหน้าได้)
 *   methodology อธิบายเกณฑ์การให้คะแนนทั้งหมด (โปร่งใส ตรวจสอบได้)
 *
 * หลักการที่ยึด:
 *   - PDPA Data Minimization: ปิดบังเลขบัตรประชาชน/ชื่อ เป็นค่าเริ่มต้น
 *     ต้องส่ง show_pii=1 (ต้องเป็น session ที่ล็อกอินแล้ว) จึงจะเห็นเลขเต็ม
 *   - ไม่ cache ข้อมูลสุขภาพไว้ที่ตัวกลาง (no-store)
 *   - ใช้ PDO Prepared Statements + Prepared Params จาก bootstrap.php (กรองตาม hospcode ของผู้ใช้)
 * ============================================================================
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// API ต้องตอบ JSON เสมอ ไม่ redirect ไปหน้า HTML
$AI_GUARD_JSON      = true;
$AI_GUARD_LOGIN_URL = '../../../login.php';

require_once __DIR__ . '/../includes/ai_guard.php';
require_once __DIR__ . '/../includes/risk_engine.php';

/** ส่งออก JSON แล้วจบการทำงาน */
function risk_json($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

$action = isset($_GET['action']) ? trim((string) $_GET['action']) : 'overview';

// ---------- ตัวกรองที่รับจากผู้ใช้ (ทำ sanitize แบบ whitelist) ----------
$filter_tier   = isset($_GET['tier'])    ? trim((string) $_GET['tier'])    : '';
$filter_group  = isset($_GET['gstatus']) ? trim((string) $_GET['gstatus']) : '';
$filter_ampher = isset($_GET['ampher'])  ? trim((string) $_GET['ampher'])  : '';
$filter_search = isset($_GET['q'])       ? trim((string) $_GET['q'])       : '';
$show_pii      = (isset($_GET['show_pii']) && $_GET['show_pii'] === '1');
$limit         = isset($_GET['limit'])   ? max(1, min(500, (int) $_GET['limit'])) : 50;
$offset        = isset($_GET['offset'])  ? max(0, (int) $_GET['offset']) : 0;

if ($filter_tier !== '' && !array_key_exists($filter_tier, risk_tiers())) {
    $filter_tier = '';
}

// ชื่อกลุ่มจาก master data (bootstrap.php เตรียมไว้ให้แล้ว)
$group_names = [];
foreach ($all_groups as $g) {
    $group_names[$g['gstatus_id']] = $g['gstatus_name'];
}

$tiers_cfg = risk_tiers();

try {
    // ---------- โหลด + ให้คะแนน (คิวรี่เดียวทั้งชุด ~500 แถว ใช้เวลาไม่ถึง 0.3 วินาที) ----------
    $features = risk_fetch_features($pdo, $hospcode_cond, $hospcode_params);
    $scored   = risk_score_all($features);

    // แผนที่ชื่อโรงพยาบาล
    $hospital_map = [];
    try {
        $hospital_map = $pdo->query("SELECT hospcode, hosp_name FROM hospital")
                            ->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) { /* ไม่มีตาราง hospital ก็ทำงานต่อได้ */ }

    // เติมข้อมูลประกอบ + ปิดบังข้อมูลส่วนบุคคล (ตรรกะอยู่ใน risk_engine.php)
    $scored = risk_enrich($scored, $group_names, $hospital_map, $show_pii);

    switch ($action) {
        // ==================================================================
        case 'overview':
            $summary   = risk_summarize($scored, $group_names);
            $readiness = risk_data_readiness($scored);

            // จำนวนผู้ป่วยที่ต้องติดตามใน 2 ระดับแรก (ใช้เป็น KPI หลัก)
            $actionable = ($summary['tiers']['critical'] ?? 0) + ($summary['tiers']['high'] ?? 0);

            // อันดับพื้นที่ที่ควรลงพื้นที่เชิงรุก + ปัจจัยเสี่ยงที่พบบ่อย
            $hotspots    = risk_hotspots($scored, 10);
            $factor_freq = risk_factor_frequency($scored, 10);

            risk_json([
                'success' => true,
                'model'   => ['version' => RISK_ENGINE_VERSION, 'computed_at' => date('c')],
                'scope'   => ['hospcode' => $user_hospcode, 'is_super_admin' => $is_super_admin],
                'summary' => $summary,
                'kpi'     => [
                    'total'      => $summary['total'],
                    'critical'   => $summary['tiers']['critical'] ?? 0,
                    'high'       => $summary['tiers']['high'] ?? 0,
                    'actionable' => $actionable,
                    'avg_score'  => $summary['avg_score'],
                ],
                'hotspots'     => $hotspots,
                'factor_freq'  => $factor_freq,
                'readiness'    => $readiness,
                'tiers_config' => risk_tiers(),
            ]);
            break;

        // ==================================================================
        case 'list':
            $filtered = [];
            foreach ($scored as $r) {
                if ($filter_tier !== '' && $r['risk_tier'] !== $filter_tier) continue;
                if ($filter_group !== '' && trim((string) $r['gstatus']) !== $filter_group) continue;
                if ($filter_ampher !== '' && trim((string) $r['ampher']) !== $filter_ampher) continue;
                if ($filter_search !== '') {
                    $hay = mb_strtolower(
                        (string) $r['idcard'] . ' ' . (string) $r['name'] . ' ' . (string) $r['tambon'] . ' ' .
                        (string) $r['ampher'] . ' ' . (string) $r['hosp_name'], 'UTF-8'
                    );
                    if (mb_strpos($hay, mb_strtolower($filter_search, 'UTF-8'), 0, 'UTF-8') === false) continue;
                }
                $filtered[] = $r;
            }

            risk_json([
                'success'  => true,
                'total'    => count($filtered),
                'limit'    => $limit,
                'offset'   => $offset,
                'patients' => array_slice($filtered, $offset, $limit),
            ]);
            break;

        // ==================================================================
        case 'methodology':
            risk_json([
                'success'    => true,
                'version'    => RISK_ENGINE_VERSION,
                'principles' => [
                    'เป็นระบบช่วยจัดลำดับความสำคัญ ไม่ใช่การวินิจฉัยทางการแพทย์',
                    'คะแนนทุกจุดอธิบายที่มาได้ บุคลากรตรวจสอบและค้านได้ (Explainable)',
                    'ต้องมีมนุษย์ยืนยันก่อนดำเนินการทุกครั้ง (Human-in-the-loop)',
                ],
                'weights'    => risk_config(),
                'tiers'      => risk_tiers(),
                'disclaimer' => 'คะแนนคำนวณจากข้อมูลที่บันทึกในระบบ ณ เวลาที่เรียกใช้ หากข้อมูลไม่ครบ คะแนนอาจสูงกว่าความเป็นจริง เพราะมีปัจจัย "ข้อมูลไม่ครบ" รวมอยู่ด้วย',
            ]);
            break;

        default:
            risk_json(['error' => 'Invalid action', 'allowed' => ['overview', 'list', 'methodology']], 400);
    }
} catch (PDOException $e) {
    error_log('[ai-risk-api] DB Error: ' . $e->getMessage());
    risk_json(['error' => 'Database error'], 500);
} catch (Throwable $e) {
    error_log('[ai-risk-api] Error: ' . $e->getMessage());
    risk_json(['error' => 'Internal error'], 500);
}

