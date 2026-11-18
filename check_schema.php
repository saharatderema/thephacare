<?php
require 'db.php';
$tables = ['smi_pop','smi_v_registry','smi_service','smi_mas_gstatus','smi_mas_level','smi_crisis_alert','smi_user_role','smi_medication_adherence','smi_clinical_assessment'];
foreach($tables as $t) {
    echo "=== $t ===\n";
    $cols = $pdo->query("DESCRIBE $t")->fetchAll(PDO::FETCH_ASSOC);
    foreach($cols as $c) {
        echo "  {$c['Field']} | {$c['Type']} | {$c['Null']} | {$c['Key']} | {$c['Default']} | {$c['Extra']}\n";
    }
    echo "\n";
}