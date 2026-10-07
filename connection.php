<?php
require_once __DIR__ . '/includes/env.php';
// =====================================================
//  🔒 connection.php - SECURE DB CONNECTION
// =====================================================

// منع ظهور الأخطاء للمستخدم العادي (فعلها فقط أثناء التطوير)
ini_set('display_errors', 0);
error_reporting(E_ALL);

// 1️⃣ تحميل ملف .env
$envPath = __DIR__ . '/../.env'; // المسار: خطوة واحدة للخلف

$env = oldora_env_all();
if (!$env && oldora_env('DB_NAME') === '') {
    // احتياطي: إذا لم يتم العثور على ملف env، يمكنك وضع البيانات هنا مباشرة أو إيقاف الكود
    throw new RuntimeException('Database configuration is missing.');
}

// 2️⃣ إنشاء الاتصال
$db_host = oldora_env('DB_HOST', 'localhost');
$db_user = oldora_env('DB_USER', 'root');
$db_pass = oldora_env('DB_PASS');
$db_name = oldora_env('DB_NAME', 'test');

$con = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($con->connect_error) {
    throw new RuntimeException('Database connection failed.');
}

// ضبط الترميز
$con->set_charset("utf8mb4");
$con->query("SET time_zone = '+00:00'");

// 3️⃣ دوال مساعدة (Helper Functions)
if (!function_exists('getUserIP')) {
    function getUserIP() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return $_SERVER['HTTP_X_FORWARDED_FOR'];
        return $_SERVER['REMOTE_ADDR'];
    }
}

if (!function_exists('getIPInfo')) {
    function getIPInfo($ip) {
        if ($ip == '127.0.0.1' || $ip == '::1') return ['country' => 'Localhost'];
        
        // استخدام cURL مع مهلة زمنية قصيرة لتجنب تعليق الموقع
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "http://ip-api.com/json/{$ip}?fields=country");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            $response = curl_exec($ch);
            curl_close($ch);
            if ($response) return json_decode($response, true);
        }
        return ['country' => 'Unknown'];
    }
}
// دالة لإرسال إشعارات تيليجرام
function sendTelegramAlert($email, $ip, $status, $country, $device) {
    // 1. جلب التوكن من ملف .env (الموجود خارج المجلد الحالي بمستوى واحد)
    $envPath = __DIR__ . '/../.env'; // عدّل المسار حسب مكان الملف
    $token = '';
    
    if (file_exists($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue; // تجاهل التعليقات
            list($name, $value) = explode('=', $line, 2);
            if (trim($name) == 'TELEGRAM_BOT_TOKEN') {
                $token = trim($value);
                break;
            }
        }
    }

    if (empty($token)) return; // إذا لم يتم العثور على التوكن، توقف

    // 2. إعداد البيانات
    $chat_id = "5077182872"; // الآيدي الخاص بك
    
    // تنسيق الرسالة
    $message = "🛡 <b>New Login Attempt</b>\n";
    $message .= "━━━━━━━━━━━━━━\n";
    $message .= "📧 <b>Email:</b> " . $email . "\n";
    $message .= "📡 <b>IP:</b> " . $ip . "\n";
    $message .= "🏳️ <b>Country:</b> " . $country . "\n";
    $message .= "📊 <b>Status:</b> " . ($status == 'Success' ? '✅ Success' : '❌ Failed') . "\n";
    $message .= "💻 <b>Device:</b> " . substr($device, 0, 50) . "...";

    // 3. الإرسال عبر API تيليجرام
    $url = "https://api.telegram.org/bot$token/sendMessage";
    $data = [
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => 'HTML'
    ];

    // استخدام CURL للإرسال
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    $result = curl_exec($ch);
    curl_close($ch);
}

require_once __DIR__ . '/includes/app_menu.php';
oldora_register_shared_menu($con);
?>
