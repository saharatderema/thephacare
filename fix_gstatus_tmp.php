<?php
// สคริปต์ชั่วคราว : ปรับค่า gstatus_desc ให้เป็นชื่อกลุ่มผู้ป่วยแบบเต็มมาตรฐาน
$pdo = require __DIR__ . '/db.php';

// gstatus_name = รหัสย่อ (PSY, SMI-V)  ใช้ในป้ายสั้น / ตัวกรอง
// gstatus_desc = ชื่อกลุ่มผู้ป่วยแบบเต็ม   ใช้แสดงผลทุกที่
$VALUES = [
    'psy'  => 'ผู้ป่วยจิตเวช (PSY)',
    'smiv' => 'ผู้ป่วย SMI-V',
];

foreach ($VALUES as $gid => $desc) {
    $st = $pdo->prepare("UPDATE smi_mas_gstatus SET gstatus_desc = :d WHERE gstatus_id = :g");
    $st->execute([':d' => $desc, ':g' => $gid]);
    echo '  อัปเดต ' . $gid . ' : ' . $st->rowCount() . " แถว\n";
}

echo "\n=== ผลลัพธ์ ===\n";
foreach ($pdo->query("SELECT * FROM smi_mas_gstatus ORDER BY display_order") as $r) {
    printf("  %-5s | name=%-6s | desc=%s\n", $r['gstatus_id'], $r['gstatus_name'], $r['gstatus_desc']);
}
