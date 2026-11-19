<?php
// เปิดการแสดงผล Error ทั้งหมดเพื่อใช้ในการตรวจสอบ
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ==========================================
// 1. ตั้งค่าข้อมูลการเชื่อมต่อฐานข้อมูล (แก้ไขตรงนี้)
// ==========================================
$host     = '27.254.134.24';     // หรือ IP เช่น 127.0.0.1 หรือ IP ของ Server
$port     = 3306;            // พอร์ตมาตรฐานของ MySQL คือ 3306
$dbname   = 'thephad_eds'; // ชื่อฐานข้อมูล
$username = 'thephad_thephad';          // ชื่อผู้ใช้
$password = '14sYR2xk1u';              // รหัสผ่าน
$charset  = 'utf8mb4';       // ชุดอักขระ
$timeout  = 5;               // ตั้งเวลารอสูงสุด (วินาที) เพื่อไม่ให้หน้ารอนานเกินไป

echo "<h2>🔍 ผลการทดสอบระบบฐานข้อมูล</h2>";
echo "<hr>";

// ==========================================
// 2. ขั้นตอนที่ 1: ทดสอบการเชื่อมต่อระดับเครือข่าย (Port Check)
// ==========================================
echo "<h3>1. ตรวจสอบการทะลุผ่าน Port ($host : $port)</h3>";
$socket = @fsockopen($host, $port, $errno, $errstr, $timeout);

if (!$socket) {
    echo "<p style='color: red;'>❌ <b>ไม่สามารถติดต่อ Port $port ได้!</b></p>";
    echo "<ul>";
    echo "<li><b>รหัสข้อผิดพลาด:</b> $errno</li>";
    echo "<li><b>รายละเอียด:</b> $errstr</li>";
    echo "<li><b>คำแนะนำ:</b> ปัญหานี้อยู่ที่ระดับเครือข่าย (ยังไม่ถึงขั้นตรวจรหัสผ่าน) ให้ตรวจสอบ Firewall, Security Group, IP/Host หรือดูว่าบริการ MySQL เปิดทำงานอยู่หรือไม่</li>";
    echo "</ul>";
    exit(); // หยุดการทำงานทันทีหาก Port ไม่เปิด
} else {
    echo "<p style='color: green;'>✅ <b>ติดต่อ Port $port สำเร็จ!</b> (เครือข่ายสามารถทะลุถึงเซิร์ฟเวอร์ฐานข้อมูลได้)</p>";
    fclose($socket);
}

echo "<hr>";

// ==========================================
// 3. ขั้นตอนที่ 2: ทดสอบล็อกอินเข้าฐานข้อมูลด้วย PDO
// ==========================================
echo "<h3>2. ตรวจสอบการเข้าสู่ระบบฐานข้อมูล (PDO)</h3>";

$dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // แจ้งเตือน Error แบบละเอียด
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // ดึงข้อมูลแบบ Associative Array
    PDO::ATTR_TIMEOUT            => $timeout,               // กำหนดเวลา Timeout ของ PDO
];

try {
    $pdo = new PDO($dsn, $username, $password, $options);
    
    // ดึงเวอร์ชันของฐานข้อมูลมาแสดงเพื่อยืนยันการทำงาน
    $version = $pdo->query('SELECT VERSION()')->fetchColumn();
    
    echo "<p style='color: green;'>✅ <b>เชื่อมต่อฐานข้อมูลสำเร็จสมบูรณ์!</b></p>";
    echo "<ul>";
    echo "<li><b>ฐานข้อมูล:</b> " . htmlspecialchars($dbname) . "</li>";
    echo "<li><b>เวอร์ชัน MySQL/MariaDB:</b> " . htmlspecialchars($version) . "</li>";
    echo "</ul>";

} catch (PDOException $e) {
    echo "<p style='color: red;'>❌ <b>เชื่อมต่อฐานข้อมูลล้มเหลว!</b></p>";
    echo "<ul>";
    echo "<li><b>สาเหตุ:</b> " . htmlspecialchars($e->getMessage()) . "</li>";
    echo "<li><b>คำแนะนำ:</b> หากผ่านขั้นตอนที่ 1 มาได้ แสดงว่าเครือข่ายปกติ แต่เกิดจากชื่อฐานข้อมูล, Username, Password ผิด หรือ User นี้ไม่ได้รับสิทธิ์ให้เข้าถึงจากภายนอก</li>";
    echo "</ul>";
}
?>
