<?php
/**
 * MIND-CARE THEPHA : Shared Functions Library
 */
if (!defined("SMI_FUNCTIONS_LOADED")) define("SMI_FUNCTIONS_LOADED", true);

/**
 * Initialize page: session, timeout, auth, CSRF, user info
 * @return array{user:array, csrf:string, pdo:PDO, time_left:int, timeout_duration:int}
 */
function smi_init_page(PDO $pdo, int $timeout_duration = 900, string $login_url = "../../login.php"): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start([
            "cookie_lifetime" => $timeout_duration,
            "cookie_httponly" => true,
            "cookie_secure"   => isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off",
            "cookie_samesite" => "Lax",
            "use_strict_mode" => true,
        ]);
    }
    $last_activity = $_SESSION["last_activity"] ?? 0;
    $now = time();
    if ($last_activity && ($now - $last_activity) > $timeout_duration) {
        session_unset();
        session_destroy();
        header("Location: $login_url?timeout=1", true, 302);
        exit;
    }
    $_SESSION["last_activity"] = $now;

    if (empty($_SESSION["user_id"])) {
        header("Location: $login_url", true, 302);
        exit;
    }

    if (empty($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }

    $stmt = $pdo->prepare("SELECT id, name, hospcode, status_user FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([":id" => $_SESSION["user_id"]]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        session_unset();
        session_destroy();
        header("Location: $login_url?error=user_not_found", true, 302);
        exit;
    }

    // Security headers
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    return [
        "user"             => $user,
        "csrf"             => $_SESSION["csrf_token"],
        "pdo"              => $pdo,
        "time_left"        => max(0, $timeout_duration - ($now - $_SESSION["last_activity"])),
        "timeout_duration" => $timeout_duration,
    ];
}

function smi_is_super_admin(string $hospcode): bool
{
    return $hospcode === "00860";
}

/**
 * Build hospcode filter for queries (join + where + params)
 * Super admin (00860) sees all, others filtered by person.hospcode
 */
function smi_build_hospcode_filter(PDO $pdo, string $user_hospcode, string $idcard_column = "s.idcard"): array
{
    if (smi_is_super_admin($user_hospcode) || $user_hospcode === "ไม่ระบุรหัส" || $user_hospcode === "") {
        return ["join" => "", "where" => "", "params" => []];
    }
    return [
        "join"   => " LEFT JOIN person p ON $idcard_column = p.idcard ",
        "where"  => " AND p.hospcode = ?",
        "params" => [$user_hospcode]
    ];
}

function smi_group_list(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $stmt = $pdo->query("
            SELECT gstatus_id, gstatus_name, gstatus_desc, gstatus_icon,
                   gstatus_color, gstatus_bg, display_order, is_active
            FROM smi_mas_gstatus
            WHERE is_active = 1
            ORDER BY display_order
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $out[$r["gstatus_id"]] = $r;
        }
        $cache = smi_group_merge_meta($out);
    } catch (PDOException $e) {
        error_log("[smi_group_list] " . $e->getMessage());
        $cache = smi_group_merge_meta([]);
    }
    return $cache;
}

function smi_group_merge_meta(array $groups): array
{
    $meta = [
        "psy"  => ["color_set" => "ชุดสี: เขียว / เหลือง / ส้ม / เทา", "has_level" => 0],
        "smiv" => ["color_set" => "ชุดสี: เขียว / เหลือง / ส้ม / แดง",  "has_level" => 1],
    ];
    foreach ($groups as $code => &$g) {
        $g["code"]        = $code;
        $g["gstatus_id"]  = $code;
        $m                = $meta[$code] ?? [];
        $g["color_set"]   = $m["color_set"] ?? "ชุดสีความเสี่ยง";
        $g["has_level"]   = $m["has_level"] ?? 0;
        $g["gstatus_desc"] = trim((string)($g["gstatus_desc"] ?? '')) ?: ($g["gstatus_name"] ?? strtoupper($code));
        $g["gstatus_name"] = trim((string)($g["gstatus_name"] ?? '')) ?: strtoupper($code);
    }
    unset($g);
    return $groups;
}

function smi_group_resolve(PDO $pdo, string $fallback = "smiv"): array
{
    $groups = smi_group_list($pdo);
    $req    = array_merge($_GET, $_POST);
    $wanted = $req["group"] ?? ($_SESSION["smi_group"] ?? null);
    if ($wanted && isset($groups[$wanted])) {
        $_SESSION["smi_group"] = $wanted;
        return $groups[$wanted];
    }
    $first = reset($groups);
    if ($first !== false) {
        $_SESSION["smi_group"] = $first["code"];
        return $first;
    }
    return ["code" => $fallback, "gstatus_id" => $fallback, "gstatus_name" => strtoupper($fallback)];
}

function smi_group_risk_colors(PDO $pdo, string $group_code): array
{
    static $cache = [];
    if (isset($cache[$group_code])) {
        return $cache[$group_code];
    }
    try {
        $stmt = $pdo->prepare("
            SELECT color_code, color_seq, color_name, color_name_en,
                   color_hex, color_bg, color_text, risk_desc
            FROM smi_mas_risk_color
            WHERE color_type = :group AND is_active = 1
            ORDER BY color_seq
        ");
        $stmt->execute([":group" => $group_code]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $cache[$group_code] = $rows;
        return $rows;
    } catch (PDOException $e) {
        error_log("[smi_group_risk_colors] " . $e->getMessage());
        return [];
    }
}r_hex,color_bg,color_text,risk_desc,action_hint FROM smi_mas_risk_color WHERE is_active=1 AND color_type=:ctype ORDER BY color_seq"); $stmt->execute([":ctype"=>$group_code]); $cache[$group_code]=$stmt->fetchAll(PDO::FETCH_ASSOC);}catch(PDOException $e){error_log("[smi_group_risk_colors] ".$e->getMessage());$cache[$group_code]=[];} return $cache[$group_code]; }
function smi_get_levels(PDO $pdo): array { static $cache=null; if($cache!==null) return $cache; try{$cache=$pdo->query("SELECT smi_level_id,smi_level_code,smi_level_no,level_name,level_desc,triage_code,triage_color,triage_label,action_hint,display_order FROM smi_mas_level WHERE is_active=1 ORDER BY display_order,smi_level_no")->fetchAll(PDO::FETCH_ASSOC);}catch(PDOException $e){error_log("[smi_get_levels] ".$e->getMessage());$cache=[];} return $cache; }
function smi_triage_meta(string $code): array { $map=["crisis"=>["label"=>"วิกฤต","color"=>"#C53030","icon"=>"fa-triangle-exclamation","bg"=>"#FEF2F2"],"warning"=>["label"=>"เตือนภัย","color"=>"#B7791F","icon"=>"fa-bell","bg"=>"#FFFBEB"],"stable"=>["label"=>"คงที่","color"=>"#2F855A","icon"=>"fa-circle-check","bg"=>"#F0FDF4"],"history"=>["label"=>"เฝ้าระวัง","color"=>"#4A5568","icon"=>"fa-clock-rotate-left","bg"=>"#F8FAFC"],"routine"=>["label"=>"ตามรอบปกติ","color"=>"#2B6CB0","icon"=>"fa-notes-medical","bg"=>"#EFF6FF"]]; $k=strtolower(trim((string)$code)); return $map[$k]??["label"=>$k!==""?$k:"-","color"=>"#64748B","icon"=>"fa-circle-question","bg"=>"#F1F5F9"]; }
function smi_get_tambons(PDO $pdo): array { static $cache=null; if($cache!==null) return $cache; try{$cache=$pdo->query("SELECT tambon_code,tambon_name,display_order FROM mas_tambon WHERE is_active=1 ORDER BY display_order,tambon_code")->fetchAll(PDO::FETCH_ASSOC);}catch(PDOException $e){$cache=[];} return $cache; }
test/ ================================================================================
// 3. Patient Data Access (smi_pop, smi_service, person)
// ===================================================================================

function smi_get_patient_list(PDO $pdo, array $filters = [], string $user_hospcode = ""): array {
    $group = $filters["group"] ?? "";
    $level = $filters["level"] ?? "";
    $risk_color = $filters["risk_color"] ?? "";
    $tambon = $filters["tambon"] ?? "";
    $hospcode = $filters["lore"] ?? "";
    $search = trim($filters["search"] ?? "");
    $status_patient = $filters["status_patient"] ?? "active";
    $page = max(1, (int)($filters[page] ?? 1));
    $limit = (int)($filters[limit] ??  25);
    $offset = ($page - 1) * $limit;

    $select_cols = "
        s.idcard, s.hn, s.full_name, s.birth_date, s.gender
        s/patient_type, s.smi_level_code, s.status_patient,
        s/risk_color_code, s.tambon_code, s.latitude, s.longitude,
        s.created_at, s/supdated_at,
        p.hospcode AS person_hospcode,
        rc.color_name, rc.color_hex, rc.color_bg, rc.color_text, rc.risk_desc,
        ml.level_name, ml.triage_code, ml.triage_color, ml.triage_label,
        t.tambon_name
    ";

    $from = "
        FROM smi_pop s
        LEF JOIN person p ON s.idcard = p.idcard

        LEF JOIN smi_mas_risk_color rc ON s.risk_color_code = rc.color_code AND s.patient_type = rc.color_type
        LEF JOIN smi_mas_level ml ON s/smi_level_code = ml.smi_level_code
        LEF JOIN mas_tambon t ON s.tambon_code = t.tambon_code
    ";

    $where = ["s.status_patient = :status_patient"];
    $params = [":status_patient" => $status_patient];

    if ($group !== "") { $where[] = "s.patient_type = :group"; $params[":group"] = $group; }
    if ($level !== "") { $where[] = "s.smi_level_code = :level"; $params[":level"] = $level; }
    if ($risk_color != "") { $where[] = "s.risk_color_code = :risk_color"; $params[":risk_color"] = $risk_color; }
    if ($tambon != "") { $where[] = "s.tambon_code = :tambon"; $params[":tambon"] = $tambon; }

    $hospcode_filter = smi_build_hopscode_filter($pdo, $user_hospcode, "s.idcard");
    $from .= $hospcode_filter["join"];
    if ($hospcode_filter\"where"] != "") { $where[] = substr($hospcode_filter["where"], 5); $params = array_merge($params, $hospcode_filter["parms"]); }
    if ($hospcode !== "" && !smi_is_super_admin($user_hospcode)) { $where[] = "p.hospcode = :hospcode"; $params[":hospcode"] = $hospcode; }

    if ($search != "") { $where[] = "(s.idcard LIKE :search OR s.hn LIKE :search OR s.full_name LIKE :search)"; $params[":search"] = "%$%search%%"; }

    $where_sql = "WHERE " . implode(" AND ', $where);
    $order_by = "ORDER BY s/supdated_at DES";

    $count_sql = "SELECT COUNT(S) $from $where_sql";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total = (int)$count_stmt->fetchColumn();
    $total_pages = max(1, (int)ceil($total / $limit));

    $data_sql = "SELECT $select_cols $from $where_sql $order_by LIMIT :limit OFFSET :offset";
    $data_stmt = $pdo->prepare($data_sql);
    foreach ($params as $k => $v) { $data_stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT -= PDO::PARAM_STR); }
    $data_stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
    $data_stmt->bindValue(":offset", $offset, PDO::PARAM_INT);
    $data_stmt->execute();
    $data = $data_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($data as &$row) {
        $row["age"] = $row["birth_date"] ? (int((strtotime("today") - strtotime($row["birth_date"]) / 31557600) : null;
        $row["triage_meta"] = smi_triage_meta($row["triage_code"] ?? "");
        $row["risk_color_meta"] = [
            "name" => $row["color_name"] ?? "-",
            "hxex" => $row["color_hex"] ?? "#64748B",
            "bg" => $row["color_bg"] ?? "#F1F1F9B",
            "text" => $row["color_text"] ?? "#4A5568",
            "desc" => $row["risk_desc"] ?? "",
        ];
    }
    unset($row);

    return ["data" => $data, "total" => $total, "page" => $page, "limit" => $limit, "total_pages" => $total_pages];
}