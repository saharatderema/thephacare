<?php
/**
 * ============================================================================
 * AI RISK ENGINE - Vulnerable Patient Risk Stratification
 * ============================================================================
 * ไฟล์กลางสำหรับคำนวณ "คะแนนความเสี่ยง" ของผู้ป่วยกลุ่มเปราะบาง
 *
 * แนวคิด (Explainable AI Layer 1 - Rule-based Scoring):
 *   - เป็นชั้นแรกของ AI ที่ "อธิบายได้" (Explainable) ไม่ใช่กล่องดำ
 *   - ให้คะแนนจากปัจจัยที่บุคลากรสาธารณสุขตรวจสอบย้อนกลับได้ 100%
 *   - ทำหน้าที่เป็น (ก) ตัวช่วยจัดลำดับความสำคัญทันที
 *                    (ข) Feature Engineering ให้โมเดล ML ในขั้นถัดไป
 *
 * หมายเหตุสำคัญ: คะแนนนี้เป็น "ข้อเสนอแนะเพื่อจัดลำดับการติดตาม"
 * ไม่ใช่การวินิจฉัยทางการแพทย์ — ต้องมี human-in-the-loop เสมอ
 *
 * โครงสร้างข้อมูลจริงที่ใช้ (ตรวจสอบกับ production 2026-09-23):
 *   person.age      ยืนยันได้ (NOT NULL 100%) | person.birth ใช้ไม่ได้ (NULL 99.99%, เป็น พ.ศ.)
 *   person.mobile   ว่าง 57% ของประชากรทั้งหมด / 20% ของกลุ่มเปราะบาง
 *   person.xgis,ygis มีพิกัดครบ 99.99%
 *   eoc_pop.gstatus g1..g6 + g99 (g99 ไม่มีใน eoc_mas_gstatus = orphan)
 *   eoc_pop.emergency_code ว่าง 98% (489/498) => ปัจจัยนี้ยัง "เชื่อถือได้ต่ำ"
 *   eoc_services    มีเพียง 9 แถว => ประวัติการรับบริการยังไม่พอทำนาย
 * ============================================================================
 */

if (!defined('RISK_ENGINE_VERSION')) {
    define('RISK_ENGINE_VERSION', '1.0.0-rule');
}

/**
 * น้ำหนักคะแนนทั้งระบบ (ปรับได้ที่เดียว) - ตั้งใจให้โปร่งใสตรวจสอบได้
 * คะแนนดิบรวมสูงสุด = 122 แต่จะถูก clamp ที่ 100
 */
function risk_config(): array
{
    return [
        // 1) ความเปราะบางทางคลินิก (มาจากกลุ่มผู้ป่วยที่ขึ้นทะเบียนไว้แล้ว)
        'gstatus' => [
            'g1'  => 34, // ติดเตียง
            'g3'  => 28, // ใช้ออกซิเจน / เครื่องช่วยหายใจ / เจาะคอ
            'g4'  => 26, // ล้างไต HD (ฟอกเลือด)
            'g5'  => 22, // ล้างไต CAPD (ทางช่องท้อง)
            'g2'  => 16, // ติดบ้าน
            'g6'  => 15, // หญิงตั้งครรภ์
            'g99' => 8,  // ไม่ระบุกลุ่ม (ข้อมูลไม่ครบ)
        ],
        // 2) กลุ่มช่วงอายุที่เปราะบาง
        'age_bands' => [
            ['min' => 80, 'max' => 200, 'score' => 22, 'label' => 'ผู้สูงอายุมาก (80 ปีขึ้นไป)'],
            ['min' => 70, 'max' => 79,  'score' => 16, 'label' => 'ผู้สูงอายุ (70-79 ปี)'],
            ['min' => 60, 'max' => 69,  'score' => 11, 'label' => 'วัยสูงอายุตอนต้น (60-69 ปี)'],
            ['min' => 0,  'max' => 4,   'score' => 14, 'label' => 'เด็กเล็ก (0-4 ปี)'],
        ],
        // 3) ระดับความเร่งด่วนทางคลินิก
        'emergency' => [
            'EMG_01' => 26, // วิกฤตฉุกเฉิน
            'EMG_02' => 20, // เร่งด่วน
            'EMG_03' => 11, // กึ่งเร่งด่วน
            'EMG_04' => 5,  // ไม่เร่งด่วน
        ],
        // 4) ความสามารถในการเข้าถึง / ติดต่อผู้ป่วย
        'no_mobile'  => 9,  // ไม่มีเบอร์โทร = ตามตัวยาก
        'no_geo'     => 4,  // ไม่มีพิกัด = จัดเส้นทาง/เยี่ยมบ้านยาก
        // 5) ความสดใหม่ของข้อมูล (ไม่ถูกติดตามทันตามกำหนด)
        'overdue_90'  => 8,  // ไม่มีการอัปเดตเกิน 90 วัน
        'overdue_180' => 14, // เกิน 180 วัน
        // 6) ช่องว่างการรับบริการ (Unmet need)
        'never_served' => 4, // ไม่เคยมีประวัติรับบริการเลย
        'open_service' => 6, // มีงานค้างสถานะ pending / in_progress
        // 7) คุณภาพรหัสกลุ่มและการเชื่อมโยงทะเบียน
        'orphan_group'   => 3, // gstatus ไม่อยู่ใน master data
        'no_person_link' => 6, // ขึ้นทะเบียนเปราะบางแล้ว แต่ไม่พบข้อมูลในทะเบียน person
    ];
}

/** ระดับความเสี่ยง + เกณฑ์ช่วงคะแนน */
function risk_tiers(): array
{
    return [
        'critical' => ['label' => 'วิกฤต',    'min' => 75, 'max' => 100, 'color' => '#DC2626', 'icon' => 'fa-triangle-exclamation', 'advice' => 'ติดตามภายใน 24-48 ชั่วโมง'],
        'high'     => ['label' => 'สูง',      'min' => 60, 'max' => 74,  'color' => '#EA580C', 'icon' => 'fa-circle-exclamation',   'advice' => 'ติดตามภายใน 7 วัน'],
        'medium'   => ['label' => 'ปานกลาง',  'min' => 40, 'max' => 59,  'color' => '#CA8A04', 'icon' => 'fa-circle-info',           'advice' => 'ติดตามตามรอบปกติ (30 วัน)'],
        'low'      => ['label' => 'ต่ำ',      'min' => 0,  'max' => 39,  'color' => '#16A34A', 'icon' => 'fa-circle-check',          'advice' => 'ติดตามตามรอบปกติ (90 วัน)'],
    ];
}

/** แปลงคะแนนดิบเป็นระดับความเสี่ยง */
function risk_tier_of(float $score): string
{
    if ($score >= 75) return 'critical';
    if ($score >= 60) return 'high';
    if ($score >= 40) return 'medium';
    return 'low';
}

/** ปิดบังเลขบัตรประชาชน (PDPA data minimization) */
function risk_mask_idcard(?string $idcard): string
{
    $idcard = (string) $idcard;
    if ($idcard === '') return '-';
    $len = strlen($idcard);
    if ($len <= 4) return str_repeat('x', $len);

    return str_repeat('x', $len - 4) . substr($idcard, -4);
}

/** ปิดบังชื่อ-นามสกุล (เหลือคำนำหน้า + อักษรแรก) */
function risk_mask_name(?string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', (string) $name));
    if ($name === '') return '-';

    $parts = explode(' ', $name);
    if (count($parts) === 1) {
        return mb_substr($parts[0], 0, 1, 'UTF-8') . '.';
    }
    $prefix = $parts[0];
    $first  = mb_substr($parts[1], 0, 1, 'UTF-8');

    return $prefix . ' ' . $first . '.';
}


/**
 * ดึงชุดข้อมูล Feature ของผู้ป่วยกลุ่มเปราะบาง (คิวรี่เดียวจบ)
 *
 * จุดสำคัญด้านประสิทธิภาพ:
 *  - ใช้ derived table เลือก eoc_pop แถวล่าสุดต่อ 1 idcard => กันข้อมูลซ้ำ (ในของจริงมี idcard ซ้ำ 15 ราย)
 *  - LEFT JOIN person ด้วย CAST(... AS UNSIGNED) เพื่อให้ใช้ index idx_idcard ได้จริง
 *    (ถ้าเทียบ varchar = bigint ตรง ๆ MariaDB จะไม่ใช้ index — ตรวจสอบด้วย EXPLAIN แล้ว)
 *  - ใช้ subquery เลือก person แค่ 1 แถวต่อ idcard (person มี idcard ซ้ำ 1,196 แถว)
 *    => เลี่ยง GROUP BY และได้ผลลัพธ์ที่แน่นอน
 *  - eoc_services ถูก aggregate ก่อน join เพื่อไม่ให้แถวทวีคูณ
 *
 * @param PDO    $pdo
 * @param string $hospcode_cond   เช่น 'AND p.hospcode = :hospcode' (มาจาก bootstrap.php)
 * @param array  $hospcode_params ['hospcode' => '...']
 * @param int    $limit           จำนวนแถวสูงสุด (0 = ไม่จำกัด)
 */
function risk_fetch_features(PDO $pdo, string $hospcode_cond = '', array $hospcode_params = [], int $limit = 0): array
{
    $sql = "
        SELECT
            e.idcard,
            e.gstatus,
            e.emergency_code,
            e.d_update,
            DATEDIFF(CURDATE(), DATE(e.d_update)) AS days_since_update,
            p.name,
            p.age,
            p.gender,
            p.mobile,
            p.hospcode,
            p.tambon,
            p.ampher,
            p.moo,
            p.hno,
            p.xgis,
            p.ygis,
            COALESCE(s.service_count, 0) AS service_count,
            COALESCE(s.open_count, 0)    AS open_count,
            s.last_service_date,
            CASE WHEN p.id IS NULL THEN 1 ELSE 0 END AS no_person,
            CASE WHEN g.gstatus_id IS NULL THEN 1 ELSE 0 END AS orphan_group
        FROM (
            SELECT e1.idcard, e1.gstatus, e1.emergency_code, e1.d_update
            FROM eoc_pop e1
            INNER JOIN (
                SELECT idcard, MAX(id) AS max_id
                FROM eoc_pop
                GROUP BY idcard
            ) latest ON latest.idcard = e1.idcard AND latest.max_id = e1.id
        ) e
        LEFT JOIN person p ON p.id = (
            SELECT MIN(p2.id) FROM person p2 WHERE p2.idcard = CAST(e.idcard AS UNSIGNED)
        )
        LEFT JOIN eoc_mas_gstatus g ON g.gstatus_id = e.gstatus
        LEFT JOIN (
            SELECT
                idcard,
                SUM(CASE WHEN status <> 'cancelled' THEN 1 ELSE 0 END) AS service_count,
                SUM(CASE WHEN status IN ('pending','in_progress') THEN 1 ELSE 0 END) AS open_count,
                MAX(COALESCE(service_date, DATE(created_at))) AS last_service_date
            FROM eoc_services
            GROUP BY idcard
        ) s ON s.idcard = e.idcard
        WHERE 1=1 $hospcode_cond
    ";

    if ($limit > 0) {
        $sql .= " ORDER BY e.d_update DESC LIMIT " . (int) $limit;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($hospcode_params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
/**
 * ให้คะแนนความเสี่ยงผู้ป่วย 1 ราย
 * คืน "ที่มาของคะแนน" ทุกจุด (Explainable AI) เพื่อให้บุคลากรตรวจสอบและค้านได้
 *
 * @param array $row แถวจาก risk_fetch_features()
 * @return array{score:float,raw:int,tier:string,factors:array<int,array{key:string,label:string,points:int}>}
 */
function risk_score_patient(array $row): array
{
    $cfg     = risk_config();
    $score   = 0;
    $factors = [];

    $add = function (string $type, string $key, string $label, int $points) use (&$score, &$factors) {
        if ($points <= 0) return;
        $score += $points;
        $factors[] = ['type' => $type, 'key' => $key, 'label' => $label, 'points' => $points];
    };

    $groupNames = [
        'g1' => 'ติดเตียง', 'g2' => 'ติดบ้าน', 'g3' => 'ใช้ออกซิเจน/ช่วยหายใจ/เจาะคอ',
        'g4' => 'ล้างไต HD', 'g5' => 'ล้างไต CAPD', 'g6' => 'หญิงตั้งครรภ์', 'g99' => 'ไม่ระบุกลุ่ม',
    ];

    // 1) กลุ่มเปราะบางทางคลินิก
    $gstatus = trim((string) ($row['gstatus'] ?? ''));
    if (isset($cfg['gstatus'][$gstatus])) {
        $gLabel = 'กลุ่ม' . ($groupNames[$gstatus] ?? $gstatus);
        $add('gstatus', 'gstatus_' . $gstatus, $gLabel, $cfg['gstatus'][$gstatus]);
    }

    // 2) ช่วงอายุ
    if (isset($row['age']) && $row['age'] !== null && $row['age'] !== '') {
        $age = (int) $row['age'];
        foreach ($cfg['age_bands'] as $band) {
            if ($age >= $band['min'] && $age <= $band['max']) {
                $add('age', 'age_' . $band['min'] . '_' . $band['max'], $band['label'], $band['score']);
                break;
            }
        }
    } elseif (empty($row['no_person'])) {
        // ไม่มีข้อมูลอายุ แต่ยังเทียบกับทะเบียน person ได้ => ปัญหาคุณภาพข้อมูลรายบุคคล
        $add('data_quality', 'age_missing', 'ไม่ทราบอายุ (ข้อมูลไม่ครบ)', 5);
    }

    // 3) ระดับความเร่งด่วนทางคลินิก
    $emg       = trim((string) ($row['emergency_code'] ?? ''));
    $emgLabels = ['EMG_01' => 'วิกฤตฉุกเฉิน', 'EMG_02' => 'เร่งด่วน', 'EMG_03' => 'กึ่งเร่งด่วน', 'EMG_04' => 'ไม่เร่งด่วน'];
    if ($emg !== '' && isset($cfg['emergency'][$emg])) {
        $add('emergency', 'emergency_' . $emg, 'ระดับ' . ($emgLabels[$emg] ?? $emg), $cfg['emergency'][$emg]);
    }

    // 4) ความสามารถในการเข้าถึง / ติดต่อ
    if (trim((string) ($row['mobile'] ?? '')) === '') {
        $add('access', 'no_mobile', 'ไม่มีเบอร์โทรติดต่อ', $cfg['no_mobile']);
    }
    if (empty($row['xgis']) || empty($row['ygis'])) {
        $add('access', 'no_geo', 'ไม่มีพิกัดในแผนที่', $cfg['no_geo']);
    }

    // 5) ความล้าสมัยของข้อมูล (เลยกำหนดติดตาม)
    $days = (isset($row['days_since_update']) && $row['days_since_update'] !== null)
        ? (int) $row['days_since_update'] : null;
    if ($days !== null) {
        if ($days > 180) {
            $add('followup', 'overdue_180', "ไม่มีการติดตามเกิน 180 วัน (ล่าสุด {$days} วันก่อน)", $cfg['overdue_180']);
        } elseif ($days > 90) {
            $add('followup', 'overdue_90', "ไม่มีการติดตามเกิน 90 วัน (ล่าสุด {$days} วันก่อน)", $cfg['overdue_90']);
        }
    }

    // 6) ช่องว่างการรับบริการ (Unmet need)
    if ((int) ($row['service_count'] ?? 0) === 0) {
        $add('service', 'never_served', 'ไม่เคยมีประวัติรับบริการในระบบ', $cfg['never_served']);
    }
    if ((int) ($row['open_count'] ?? 0) > 0) {
        $add('service', 'open_service', 'มีงานค้างรอดำเนินการ ' . (int) $row['open_count'] . ' รายการ', $cfg['open_service']);
    }

    // 7) รหัสกลุ่มไม่ตรงกับ master data
    if ((int) ($row['orphan_group'] ?? 0) === 1) {
        $add('data_quality', 'orphan_group', 'รหัสกลุ่ม "' . $gstatus . '" ไม่มีใน master data', $cfg['orphan_group']);
    }

    // 8) ขึ้นทะเบียนเปราะบางแล้ว แต่ไม่พบข้อมูลในทะเบียน person (ต้องนำเข้าข้อมูลให้ครบ)
    if ((int) ($row['no_person'] ?? 0) === 1) {
        $add('data_quality', 'no_person_link', 'ไม่พบข้อมูลประชากรในทะเบียน person', $cfg['no_person_link']);
    }

    usort($factors, function ($a, $b) { return $b['points'] <=> $a['points']; });

    $raw   = (int) $score;
    $final = min(100, $raw);

    return [
        'score'   => (float) $final,
        'raw'     => $raw,
        'tier'    => risk_tier_of((float) $final),
        'factors' => $factors,
    ];
}

/**
 * ให้คะแนนผู้ป่วยทั้งชุด + เรียงจากเสี่ยงมากไปน้อย
 *
 * @param array $rows แถวทั้งหมดจาก risk_fetch_features()
 * @return array<int,array>  แถวเดิม + คีย์ risk_score/risk_tier/risk_factors
 */
function risk_score_all(array $rows): array
{
    $scored = [];
    foreach ($rows as $row) {
        $r = risk_score_patient($row);
        $row['risk_score']   = $r['score'];
        $row['risk_raw']     = $r['raw'];
        $row['risk_tier']    = $r['tier'];
        $row['risk_factors'] = $r['factors'];
        $scored[] = $row;
    }

    usort($scored, function ($a, $b) {
        if ($a['risk_score'] == $b['risk_score']) {
            return ($b['age'] ?? 0) <=> ($a['age'] ?? 0);
        }
        return $a['risk_score'] < $b['risk_score'] ? 1 : -1;
    });

    return $scored;
}


/** ช่วงอายุสำหรับการจัดกลุ่มสถิติ */
function risk_age_band_of($age): string
{
    if ($age === null || $age === '') return 'ไม่ทราบอายุ';
    $age = (int) $age;
    if ($age <= 4)  return '0-4 ปี';
    if ($age <= 14) return '5-14 ปี';
    if ($age <= 34) return '15-34 ปี';
    if ($age <= 59) return '35-59 ปี';
    if ($age <= 69) return '60-69 ปี';
    if ($age <= 79) return '70-79 ปี';
    return '80 ปีขึ้นไป';
}

/** สะสมค่าลง bucket สถิติ (ใช้ภายใน) */
function risk_bucket_add(array &$bucket, string $key, float $score, bool $isCritical): void
{
    if (!isset($bucket[$key])) {
        $bucket[$key] = ['name' => $key, 'count' => 0, 'critical' => 0, 'sum_score' => 0.0];
    }
    $bucket[$key]['count']++;
    $bucket[$key]['sum_score'] += $score;
    if ($isCritical) $bucket[$key]['critical']++;
}

/** แปลง sum_score เป็น avg_score แล้วเรียงจากคะแนนเฉลี่ยมากไปน้อย (ใช้ภายใน) */
function risk_bucket_finalize(array $bucket): array
{
    foreach ($bucket as $k => $v) {
        $v['avg_score'] = $v['count'] > 0 ? round($v['sum_score'] / $v['count'], 1) : 0;
        unset($v['sum_score']);
        $bucket[$k] = $v;
    }
    uasort($bucket, function ($a, $b) { return $b['avg_score'] <=> $a['avg_score']; });
    return $bucket;
}


/**
 * สรุปสถิติจากผลการให้คะแนน
 * คำนวณในหน่วยความจำทั้งหมด (ข้อมูลจริงเพียง ~500 แถว) => ไม่คิวรี่ฐานข้อมูลซ้ำ
 */
function risk_summarize(array $scored, array $groupNames = []): array
{
    $tiers    = array_fill_keys(array_keys(risk_tiers()), 0);
    $byGroup  = [];
    $byAmpher = [];
    $byTam    = [];
    $byAge    = [];
    $byHosp   = [];
    $byGender = ['ชาย' => 0, 'หญิง' => 0, 'ไม่ระบุ' => 0];
    $sumScore = 0.0;

    foreach ($scored as $r) {
        $tier   = $r['risk_tier'];
        $score  = (float) $r['risk_score'];
        $isCrit = ($tier === 'critical');

        $tiers[$tier] = ($tiers[$tier] ?? 0) + 1;
        $sumScore += $score;

        $g = trim((string) ($r['gstatus'] ?? '')) !== '' ? $r['gstatus'] : 'ไม่ระบุ';
        risk_bucket_add($byGroup, $g, $score, $isCrit);
        $byGroup[$g]['name'] = $groupNames[$g] ?? ('กลุ่ม ' . $g);

        risk_bucket_add($byAmpher, trim((string) ($r['ampher'] ?? '')) ?: 'ไม่ระบุ', $score, $isCrit);
        risk_bucket_add($byTam, trim((string) ($r['tambon'] ?? '')) ?: 'ไม่ระบุ', $score, $isCrit);
        risk_bucket_add($byHosp, trim((string) ($r['hospcode'] ?? '')) ?: 'ไม่ระบุ', $score, $isCrit);

        $band = risk_age_band_of($r['age'] ?? null);
        $byAge[$band] = ($byAge[$band] ?? 0) + 1;

        $gender = trim((string) ($r['gender'] ?? ''));
        if ($gender === '') $gender = 'ไม่ระบุ';
        $byGender[$gender] = ($byGender[$gender] ?? 0) + 1;
    }

    $ageOrder  = ['0-4 ปี', '5-14 ปี', '15-34 ปี', '35-59 ปี', '60-69 ปี', '70-79 ปี', '80 ปีขึ้นไป', 'ไม่ทราบอายุ'];
    $byAgeSort = [];
    foreach ($ageOrder as $band) {
        if (isset($byAge[$band])) $byAgeSort[$band] = $byAge[$band];
    }

    $total = count($scored);

    return [
        'total'       => $total,
        'avg_score'   => $total > 0 ? round($sumScore / $total, 1) : 0,
        'tiers'       => $tiers,
        'critical_n'  => $tiers['critical'] ?? 0,
        'by_group'    => risk_bucket_finalize($byGroup),
        'by_ampher'   => risk_bucket_finalize($byAmpher),
        'by_tambon'   => risk_bucket_finalize($byTam),
        'by_hospital' => risk_bucket_finalize($byHosp),
        'by_age'      => $byAgeSort,
        'by_gender'   => $byGender,
    ];
}


/**
 * ประเมิน "ความพร้อมของข้อมูล" (Data Readiness Assessment)
 * นี่คือหัวใจของการวางแผนทำ AI: บอกว่าปัจจัยใดเชื่อถือได้ และปัจจัยใดต้องเก็บเพิ่มก่อน
 */
function risk_data_readiness(array $scored): array
{
    $total = count($scored);
    if ($total === 0) {
        return ['total' => 0, 'checks' => [], 'readiness_score' => 0, 'verdict' => 'ยังไม่มีข้อมูลให้ประเมิน'];
    }

    $count = function ($fn) use ($scored) {
        $n = 0;
        foreach ($scored as $r) { if ($fn($r)) $n++; }
        return $n;
    };

    $checks = [
        [
            'key' => 'has_idcard', 'label' => 'มีเลขบัตรประชาชน', 'critical' => true,
            'note' => 'คีย์เชื่อมข้อมูลทุกตาราง (Master Key)',
            'present' => $count(function ($r) { return trim((string) ($r['idcard'] ?? '')) !== ''; }),
        ],
        [
            'key' => 'has_person_link', 'label' => 'เชื่อมโยงกับทะเบียน person ได้', 'critical' => true,
            'note' => 'พบผู้ป่วยที่ขึ้นทะเบียนเปราะบางแล้วแต่ไม่มีข้อมูลประชากร (ตรวจพบ 24 ราย)',
            'present' => $count(function ($r) { return empty($r['no_person']); }),
        ],
        [
            'key' => 'has_age', 'label' => 'มีอายุ (age)', 'critical' => true,
            'note' => 'field birth ใช้งานไม่ได้ (NULL 99.99% และเป็นปี พ.ศ.) จึงต้องพึ่ง age',
            'present' => $count(function ($r) { return isset($r['age']) && $r['age'] !== null && $r['age'] !== ''; }),
        ],
        [
            'key' => 'has_gender', 'label' => 'มีเพศ', 'critical' => true,
            'note' => '',
            'present' => $count(function ($r) { return trim((string) ($r['gender'] ?? '')) !== ''; }),
        ],
        [
            'key' => 'has_geo', 'label' => 'มีพิกัดแผนที่ (xgis/ygis)', 'critical' => false,
            'note' => 'จำเป็นสำหรับการวิเคราะห์เชิงพื้นที่และจัดเส้นทางเยี่ยมบ้าน',
            'present' => $count(function ($r) {
                // ใช้ flag has_geo ถ้ามี (ทนต่อการ mask ข้อมูล) มิฉะนั้นอ่านจากคอลัมน์ตรง
                if (array_key_exists('has_geo', $r)) return (bool) $r['has_geo'];
                return !empty($r['xgis']) && !empty($r['ygis']);
            }),
        ],
        [
            'key' => 'has_mobile', 'label' => 'มีเบอร์โทรติดต่อ', 'critical' => false,
            'note' => 'จำเป็นสำหรับการติดตามเชิงรุกทางโทรศัพท์',
            'present' => $count(function ($r) {
                if (array_key_exists('has_mobile', $r)) return (bool) $r['has_mobile'];
                return trim((string) ($r['mobile'] ?? '')) !== '';
            }),
        ],
        [
            'key' => 'has_emergency', 'label' => 'มีระดับความเร่งด่วน (emergency_code)', 'critical' => false,
            'note' => 'ปัจจัยที่ควรเก็บเพิ่มมากที่สุด — ถ้าครบจะยกระดับความแม่นยำของโมเดลทันที',
            'present' => $count(function ($r) { return trim((string) ($r['emergency_code'] ?? '')) !== ''; }),
        ],
        [
            'key' => 'has_group_master', 'label' => 'รหัสกลุ่มตรงกับ master data', 'critical' => false,
            'note' => 'รหัส g99 ไม่มีอยู่ในตาราง eoc_mas_gstatus',
            'present' => $count(function ($r) { return empty($r['orphan_group']); }),
        ],
        [
            'key' => 'has_service_history', 'label' => 'มีประวัติรับบริการ (eoc_services)', 'critical' => false,
            'note' => 'ต้องมีข้อมูลย้อนหลังอย่างน้อย 6 เดือนต่อราย จึงจะทำนายแนวโน้มได้',
            'present' => $count(function ($r) { return (int) ($r['service_count'] ?? 0) > 0; }),
        ],
        [
            'key' => 'fresh_data', 'label' => 'ข้อมูลอัปเดตภายใน 90 วัน', 'critical' => false,
            'note' => '',
            'present' => $count(function ($r) { return (int) ($r['days_since_update'] ?? 9999) <= 90; }),
        ],
    ];

    $weightedSum = 0.0;
    $weightTotal = 0.0;

    foreach ($checks as $k => $c) {
        $percent = round(($c['present'] / $total) * 100, 1);
        $checks[$k]['percent'] = $percent;
        $checks[$k]['missing'] = $total - $c['present'];
        $checks[$k]['status']  = $percent >= 90 ? 'good' : ($percent >= 60 ? 'warn' : 'bad');

        $weight = $c['critical'] ? 2 : 1;
        $weightedSum += $percent * $weight;
        $weightTotal += 100 * $weight;
    }

    $readiness = round($weightedSum / max($weightTotal, 1) * 100, 1);

    if ($readiness >= 85) {
        $verdict = 'พร้อมต่อยอดไป Predictive Model (ชั้นที่ 3) ได้';
    } elseif ($readiness >= 65) {
        $verdict = 'เหมาะกับ Rule-based Scoring + Analytics (ชั้นที่ 1-2) ซึ่งเป็นสิ่งที่ระบบนี้ทำให้แล้ว';
    } else {
        $verdict = 'ควรปรับปรุงคุณภาพข้อมูลก่อน มิฉะนั้นโมเดลจะให้ผลผิดพลาด';
    }

    return [
        'total'           => $total,
        'checks'          => $checks,
        'readiness_score' => $readiness,
        'verdict'         => $verdict,
    ];
}


/**
 * เติมข้อมูลประกอบให้ผลการให้คะแนน + ปิดบังข้อมูลส่วนบุคคล (PDPA)
 *
 * @param array $scored        ผลจาก risk_score_all()
 * @param array $groupNames    ['g1' => 'กลุ่มติดเตียง', ...]
 * @param array $hospitalMap   ['09495' => 'ท่าไทร', ...]
 * @param bool  $showPii       true = ส่งเลขบัตร/ชื่อ/เบอร์เต็ม (เฉพาะ session ที่มีสิทธิ์)
 */
function risk_enrich(array $scored, array $groupNames = [], array $hospitalMap = [], bool $showPii = false): array
{
    $tiers = risk_tiers();

    foreach ($scored as $k => $row) {
        $gid  = trim((string) ($row['gstatus'] ?? ''));
        $hosp = trim((string) ($row['hospcode'] ?? ''));
        $tel  = trim((string) ($row['mobile'] ?? ''));

        $idcard_masked = risk_mask_idcard($row['idcard'] ?? '');
        $name_masked   = risk_mask_name($row['name'] ?? '');

        $scored[$k]['group_name']  = $groupNames[$gid] ?? ('กลุ่ม ' . $gid);
        $scored[$k]['hosp_name']   = $hospitalMap[$hosp] ?? ($hosp !== '' ? $hosp : '-');
        $scored[$k]['has_geo']     = (!empty($row['xgis']) && !empty($row['ygis']));
        $scored[$k]['has_mobile']  = ($tel !== '');
        $scored[$k]['lat']         = $row['xgis'] !== null ? (float) $row['xgis'] : null;
        $scored[$k]['lng']         = $row['ygis'] !== null ? (float) $row['ygis'] : null;
        $scored[$k]['advice']      = $tiers[$row['risk_tier']]['advice'] ?? '';
        $scored[$k]['age']         = ($row['age'] === null || $row['age'] === '') ? null : (int) $row['age'];

        unset($scored[$k]['risk_raw']);

        if ($showPii) {
            $scored[$k]['mobile'] = $tel;
        } else {
            // ไม่ส่งข้อมูลอ่อนไหวออกจากเซิร์ฟเวอร์เลย — ส่งเฉพาะสถานะว่ามี/ไม่มี
            $scored[$k]['idcard'] = $idcard_masked;
            $scored[$k]['name']   = $name_masked;
            $scored[$k]['mobile'] = $tel !== '' ? 'มี' : 'ไม่มี';

            // PDPA: ลดความละเอียดพิกัดเหลือระดับชุมชน (~110 ม.)
            // ยังใช้วางแผนเส้นทางเยี่ยมบ้านได้ แต่ระบุบ้านหลังเฉพาะเจาะจงได้ยากขึ้น
            if ($scored[$k]['lat'] !== null) $scored[$k]['lat'] = round($scored[$k]['lat'], 3);
            if ($scored[$k]['lng'] !== null) $scored[$k]['lng'] = round($scored[$k]['lng'], 3);
        }

        unset($scored[$k]['xgis'], $scored[$k]['ygis']);
    }

    return $scored;
}

/**
 * จัดอันดับ "พื้นที่ที่ควรลงเชิงรุก" (Spatial Hotspot Ranking)
 * ให้ค่าน้ำหนักกับพื้นที่ที่มีผู้ป่วยระดับวิกฤตมากเป็นพิเศษ
 */
function risk_hotspots(array $scored, int $limit = 10): array
{
    $spots = [];
    foreach ($scored as $r) {
        $key = (trim((string) ($r['tambon'] ?? '')) ?: 'ไม่ระบุ')
             . ' / ' . (trim((string) ($r['ampher'] ?? '')) ?: 'ไม่ระบุ');

        if (!isset($spots[$key])) {
            $spots[$key] = ['name' => $key, 'total' => 0, 'critical' => 0, 'high' => 0, 'sum' => 0.0];
        }
        $spots[$key]['total']++;
        $spots[$key]['sum'] += (float) $r['risk_score'];
        if ($r['risk_tier'] === 'critical') $spots[$key]['critical']++;
        if ($r['risk_tier'] === 'high')     $spots[$key]['high']++;
    }

    foreach ($spots as $k => $v) {
        $spots[$k]['avg_score'] = round($v['sum'] / max(1, $v['total']), 1);
        // คะแนนจัดอันดับ: ผู้ป่วยวิกฤตมีน้ำหนัก 3 เท่าของกลุ่มสูง
        $spots[$k]['priority']  = $v['critical'] * 3 + $v['high'];
        unset($spots[$k]['sum']);
    }

    uasort($spots, function ($a, $b) {
        if ($a['priority'] !== $b['priority']) return $b['priority'] <=> $a['priority'];
        return $b['avg_score'] <=> $a['avg_score'];
    });

    return array_slice(array_values($spots), 0, $limit);
}

/**
 * นับความถี่ของปัจจัยเสี่ยงที่ถูกใช้เป็นเหตุผล (Risk Driver Frequency)
 * ใช้ตอบคำถามเชิงนโยบายว่า "ปัญหาที่แท้จริงของพื้นที่คืออะไร"
 */
function risk_factor_frequency(array $scored, int $limit = 10): array
{
    $freq = [];
    foreach ($scored as $r) {
        foreach ($r['risk_factors'] as $f) {
            if (!isset($freq[$f['key']])) {
                $freq[$f['key']] = ['key' => $f['key'], 'label' => $f['label'], 'points' => $f['points'], 'count' => 0];
            }
            $freq[$f['key']]['count']++;
        }
    }
    uasort($freq, function ($a, $b) { return $b['count'] <=> $a['count']; });

    return array_slice(array_values($freq), 0, $limit);
}

