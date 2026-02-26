<?php
// config.php - Citeste configuratia din .env

if (basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    header("HTTP/1.0 403 Forbidden");
    exit;
}

// Functie simpla de incarcare .env (Protejata la redeclarare)
if (!function_exists('loadEnv')) {
    function loadEnv($path) {
        if (!file_exists($path)) return;
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            if (strpos($line, '=') === false) continue;
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name); 
            $value = trim($value);
            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv("$name=$value"); 
                $_ENV[$name] = $value; 
                $_SERVER[$name] = $value;
            }
        }
    }
}

// Incarcam .env
loadEnv(__DIR__ . '/.env');

// === DATABASE ===
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME'));
define('DB_USER', getenv('DB_USER'));
define('DB_PASS', getenv('DB_PASS'));

// === SMTP (EMAIL) ===
define('SMTP_HOST', getenv('SMTP_HOST'));
define('SMTP_PORT', getenv('SMTP_PORT'));
define('SMTP_USER', getenv('SMTP_USER'));
define('SMTP_PASS', getenv('SMTP_PASS'));
define('ADMIN_EMAIL', getenv('ADMIN_EMAIL'));
define('FROM_EMAIL', getenv('SMTP_USER'));
define('FROM_NAME', 'Secretul Pisicii');

// === OBLIO (Facturare Automata) ===
define('OBLIO_EMAIL', getenv('OBLIO_EMAIL'));
define('OBLIO_API_SECRET', getenv('OBLIO_API_SECRET'));
define('OBLIO_CUI_FIRMA', getenv('OBLIO_CUI'));
define('OBLIO_SERIE', getenv('OBLIO_SERIE'));

// === E-COLET (Transport GLS) ===
define('ECOLET_CLIENT_ID', getenv('ECOLET_CLIENT_ID'));
define('ECOLET_CLIENT_SECRET', getenv('ECOLET_CLIENT_SECRET'));
define('ECOLET_USERNAME', getenv('ECOLET_USERNAME'));
define('ECOLET_PASSWORD', getenv('ECOLET_PASSWORD'));

// SENDER INFO (Datele tale de expediere - CRITICE PENTRU AWB)
define('ECOLET_SENDER_NAME', getenv('ECOLET_SENDER_NAME') ?: 'Secretul Pisicii');
define('ECOLET_SENDER_COUNTY', getenv('ECOLET_SENDER_COUNTY'));
define('ECOLET_SENDER_CITY', getenv('ECOLET_SENDER_CITY'));
define('ECOLET_SENDER_STREET', getenv('ECOLET_SENDER_STREET'));
define('ECOLET_SENDER_POSTAL', getenv('ECOLET_SENDER_POSTAL'));
define('ECOLET_SENDER_LOCALITY_ID', getenv('ECOLET_SENDER_LOCALITY_ID')); 

// === NETOPIA (Plati Card) ===
define('NETOPIA_MERCHANT_ID', getenv('NETOPIA_MERCHANT_ID'));
define('NETOPIA_API_KEY', getenv('NETOPIA_API_KEY'));
define('NETOPIA_SIGNATURE_KEY', getenv('NETOPIA_SIGNATURE_KEY'));
define('NETOPIA_SANDBOX', getenv('NETOPIA_SANDBOX') === 'true');
define('NETOPIA_URL', NETOPIA_SANDBOX 
    ? 'https://sandbox.netopia.ro/api'  
    : 'https://api.netopia.ro/api'
);

// === GOOGLE PLACES API ===
define('GOOGLE_PLACES_API_KEY', getenv('GOOGLE_PLACES_API_KEY'));

// === EASY BOX (Transport) ===
define('EASYBOX_API_KEY', getenv('EASYBOX_API_KEY'));
define('EASYBOX_SANDBOX', getenv('EASYBOX_SANDBOX') === 'true');
define('EASYBOX_API_URL', EASYBOX_SANDBOX
    ? 'https://sandbox.easybox.bg/api'
    : 'https://api.easybox.bg/api'
);

// === SHIPPING COSTS ===
define('SHIPPING_COST_GLS', floatval(getenv('SHIPPING_COST_GLS') ?: 14.00));
define('SHIPPING_COST_EASYBOX', floatval(getenv('SHIPPING_COST_EASYBOX') ?: 10.00));
define('SHIPPING_COST', SHIPPING_COST_GLS); // Default pentru compatibilitate

// === ADMIN ===
define('ADMIN_USER', getenv('ADMIN_USER') ?: 'admin');
define('ADMIN_PASS', getenv('ADMIN_PASS') ?: 'pisica2024');
define('ADMIN_URL', getenv('ADMIN_URL'));
define('SITE_URL', getenv('SITE_URL') ?: 'https://secretulpisicii.alvoro.ro');

// === LOGGING ===
define('LOG_ORDERS', __DIR__ . '/logs/orders.log');
define('LOG_PAYMENTS', __DIR__ . '/logs/payments.log');
define('LOG_ERRORS', __DIR__ . '/logs/errors.log');

// Helper function pentru logging
if (!function_exists('systemLog')) {
    function systemLog($file, $message) {
        $dir = dirname($file);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $timestamp = date('Y-m-d H:i:s');
        $line = "[$timestamp] $message" . PHP_EOL;
        @file_put_contents($file, $line, FILE_APPEND);
    }
}
?>