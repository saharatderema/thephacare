<?php
/**
 * totp_helper.php — Pure-PHP TOTP (RFC 6238 / RFC 4648) สำหรับ 2FA ด้วยแอป Authenticator
 * ใช้เฉพาะรหัส 6 หลักปัจจุบันจากแอป (เปลี่ยนทุก 30 วินาที) — ไม่มี backup code / ไม่มีรหัสคงที่
 * ไม่ต้องติดตั้ง composer library เพิ่ม เติม ใช้แค่ hash_hmac() ที่มีใน PHP อยู่แล้ว
 */

if (!function_exists('twofa_random_secret')) {

    /**
     * สุ่ม Secret ใหม่ (Base32, default 160-bit ตามคำแนะนำ RFC 4226)
     */
    function twofa_random_secret($length = 20) {
        $random = random_bytes($length);
        return twofa_base32_encode($random);
    }

    /**
     * เข้ารหัส Base32 (RFC 4648, ไม่มี padding)
     */
    function twofa_base32_encode($data) {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $out .= $alphabet[bindec($chunk)];
        }
        return $out;
    }

    /**
     * ถอดรหัส Base32 กลับเป็น binary (รองรับ padding / ตัวพิมพ์เล็ก / ช่องว่าง)
     */
    function twofa_base32_decode($secret) {
        $secret = strtoupper(preg_replace('/[^A-Z2-7=]/i', '', $secret));
        $secret = rtrim($secret, '=');
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        $len = strlen($secret);
        for ($i = 0; $i < $len; $i++) {
            $pos = strpos($alphabet, $secret[$i]);
            if ($pos === false) {
                return false;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }

    /**
     * สร้าง otpauth:// URI สำหรับทำเป็น QR Code ให้แอป Authenticator สแกน
     */
    function twofa_provisioning_uri($account, $issuer, $secret) {
        $label = rawurlencode($issuer . ':' . $account);
        return 'otpauth://totp/' . $label
            . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=6&period=30';
    }

    /**
     * คำนวณรหัส TOTP 6 หลัก ณ time-slice ที่กำหนด
     */
    function twofa_code($secret, $timeSlice = null, $period = 30) {
        $key = twofa_base32_decode($secret);
        if ($key === false || $key === '') {
            return false;
        }
        if ($timeSlice === null) {
            $timeSlice = (int)floor(time() / $period);
        }
        // counter 8-byte big-endian
        $counter = pack('N*', 0, $timeSlice);
        $hash = hash_hmac('sha1', $counter, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $code = ((ord($hash[$offset]) & 0x7F) << 24)
              | ((ord($hash[$offset + 1]) & 0xFF) << 16)
              | ((ord($hash[$offset + 2]) & 0xFF) << 8)
              | (ord($hash[$offset + 3]) & 0xFF);
        return str_pad((string)($code % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * ตรวจสอบรหัส 6 หลัก (เผื่อ clock drift ±$window time-step)
     * ใช้ hash_equals() ป้องกัน timing attack
     */
    function twofa_verify($secret, $code, $window = 1, $period = 30) {
        $code = preg_replace('/[^0-9]/', '', (string)$code);
        if (strlen($code) !== 6) {
            return false;
        }
        $slice = (int)floor(time() / $period);
        for ($i = -$window; $i <= $window; $i++) {
            $expected = twofa_code($secret, $slice + $i, $period);
            if ($expected !== false && hash_equals($expected, $code)) {
                return true;
            }
        }
        return false;
    }

    /**
     * [เลิกใช้] เดิมสำหรับสร้าง Backup Code — ปัจจุบันระบบใช้เฉพาะรหัสจากแอป
     * คงฟังก์ชันไว้เพื่อไม่ให้โค้ดเก่าพัง แต่ไม่มีการเรียกใช้แล้ว
     */
    function twofa_new_backup_codes($count = 8) {
        return [[], []];
    }

} // end if (!function_exists)
