<?php
/**
 * Database connection using PDO
 * Values loaded from .env file
 * รองรับทั้งโหมดพัฒนา (development) และใช้งานจริง (production)
 * อ่านค่าตามตัวแปร APP_ENV ที่กำหนดใน .env
 */

// โหลดค่าจากไฟล์ .env
if (!function_exists('loadEnv')) {
    function loadEnv($filePath) {
        if (!file_exists($filePath)) {
            return;
        }
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            // ข้ามคอมเมนต์และบรรทัดว่าง
            if (strpos(trim($line), '#') === 0 || trim($line) === '') {
                continue;
            }
            // แยกคีย์และค่า (แบ่งที่ = ลำดับแรกเท่านั้น)
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $key = trim($parts[0]);
                $value = trim($parts[1]);
                // ลบเครื่องหมายอัญประกาศรอบค่าออก (ถ้ามี)
                $value = trim($value, '"\'');
                $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }
    }
}

// โหลด .env จาก directory เดียวกับไฟล์นี้
loadEnv(__DIR__ . '/.env');

// ฟังก์ชันช่วยดึงค่าจาก env โดยรองรับตัวแปรแยกตามสภาพแวดล้อม
function getEnvValue(string $key, mixed $default = null): mixed
{
    // 1. ตรวจสอบใน $_ENV ก่อน
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return $_ENV[$key];
    }
    // 2. ตรวจสอบผ่าน getenv()
    $envVal = getenv($key);
    if ($envVal !== false && $envVal !== '') {
        return $envVal;
    }
    return $default;
}

// ----- กำหนดสภาพแวดล้อมที่ใช้งาน -----
$appEnv = getEnvValue('APP_ENV', 'development'); // development หรือ production

// ----- อ่านค่าฐานข้อมูลตามสภาพแวดล้อม -----
if ($appEnv === 'production') {
    // โหมดใช้งานจริง: อ่านตัวแปรที่ลงท้ายด้วย _PROD
    $host = getEnvValue('DB_HOST_PROD', getEnvValue('DB_HOST', 'localhost'));
    $port = getEnvValue('DB_PORT_PROD', getEnvValue('DB_PORT', '3306'));
    $dbname = getEnvValue('DB_DATABASE_PROD', getEnvValue('DB_DATABASE', ''));
    $username = getEnvValue('DB_USERNAME_PROD', getEnvValue('DB_USERNAME', ''));
    $password = getEnvValue('DB_PASSWORD_PROD', getEnvValue('DB_PASSWORD', ''));
} else {
    // โหมดพัฒนา: อ่านตัวแปรที่ลงท้ายด้วย _DEV ก่อน ถ้าไม่มีให้ใช้ค่าแบน (DB_HOST ฯลฯ)
    $host = getEnvValue('DB_HOST_DEV', getEnvValue('DB_HOST', 'localhost'));
    $port = getEnvValue('DB_PORT_DEV', getEnvValue('DB_PORT', '3306'));
    $dbname = getEnvValue('DB_DATABASE_DEV', getEnvValue('DB_DATABASE', ''));
    $username = getEnvValue('DB_USERNAME_DEV', getEnvValue('DB_USERNAME', ''));
    $password = getEnvValue('DB_PASSWORD_DEV', getEnvValue('DB_PASSWORD', ''));
}

// ----- ตั้งค่า error reporting ตามสภาพแวดล้อม -----
$appDebug = getEnvValue('APP_DEBUG', $appEnv === 'development' ? 'true' : 'false');

try {
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    if ($appDebug === 'true') {
        die("Database connection failed: " . $e->getMessage());
    } else {
        error_log("Database connection failed: " . $e->getMessage());
        die("ไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาลองใหม่อีกครั้ง");
    }
}

// ส่งตัวแปร PDO ออกไปใช้ในไฟล์อื่นได้
return $pdo;
