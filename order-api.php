<?php
/**
 * ==========================================
 * ORDER API - Secretul Pisicii (FULL VERSION)
 * Include: PHPMailer, HTML Templates, Logging, Background Processing
 * ==========================================
 */

// === PASUL 1: PREGĂTIRE SERVER PENTRU RĂSPUNS RAPID ===
// Oprim compresia GZIP temporar pentru a putea calcula lungimea corectă
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', 1);
}
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);

// Curățăm orice output anterior
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Setări pentru procesare în background
ignore_user_abort(true);
set_time_limit(0);

// Configurare Erori
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error_log'); 

// Headers
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// === PASUL 2: CONFIG & LIBRĂRII ===
require_once __DIR__ . '/config.php';

// Verificăm și includem PHPMailer
if (file_exists(__DIR__ . '/PHPMailer-master/src/PHPMailer.php')) {
    require __DIR__ . '/PHPMailer-master/src/Exception.php';
    require __DIR__ . '/PHPMailer-master/src/PHPMailer.php';
    require __DIR__ . '/PHPMailer-master/src/SMTP.php';
} else {
    error_log("CRITICAL: Folderul PHPMailer-master nu a fost gasit!");
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ==========================================
// FUNCȚII AJUTĂTOARE (HELPER FUNCTIONS)
// ==========================================

// FIX: Verificăm dacă funcția există deja pentru a evita Fatal Error
if (!function_exists('logEvent')) {
    function logEvent($type, $message) {
        $date = date('Y-m-d H:i:s');
        $logEntry = "[$date] [$type] $message" . PHP_EOL;
        // Scriem într-un fișier orders.log pentru debugging ușor
        $logFile = defined('LOG_ORDERS') ? LOG_ORDERS : __DIR__ . '/logs/orders.log';
        // Asigurăm crearea folderului
        $dir = dirname($logFile);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
}

function getDbConnection() {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        return new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        logEvent("DB_ERROR", $e->getMessage());
        return null;
    }
}

function saveOrderToDatabase($data) {
    $pdo = getDbConnection();
    if (!$pdo) return false;
    
    // Calculăm costurile din nou pe server pentru siguranță
    $shipping = ($data['shippingMethod'] === 'easybox') ? 10.00 : 14.00; 
    $productPrice = floatval($data['price']);
    $finalTotal = $productPrice + $shipping;
    
    // SQL Insert - INCLUDE POSTAL CODE
    $sql = "INSERT INTO orders (
        full_name, phone, email, county, city, address_line, postal_code, bundle,
        total_price, payment_method, shipping_method, easybox_locker_id, status, created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";
    
    try {
        $stmt = $pdo->prepare($sql);
        
        $lockerId = isset($data['lockerId']) ? $data['lockerId'] : null;
        // Preluăm codul poștal din structura JSON trimisă de frontend
        $postalCode = isset($data['address']['postal_code']) ? $data['address']['postal_code'] : '';

        $stmt->execute([
            $data['fullName'],
            $data['phone'],
            $data['email'] ?: '',
            $data['address']['county'],
            $data['address']['city'],
            $data['address']['line'],
            $postalCode,
            $data['bundle'],
            $finalTotal,
            $data['paymentMethod'],
            $data['shippingMethod'] ?? 'gls',
            $lockerId
        ]);
        
        return $pdo->lastInsertId();
        
    } catch (PDOException $e) {
        logEvent("INSERT_ERROR", $e->getMessage());
        return false;
    }
}

function getMailer() {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 10;
        return $mail;
    } catch (Exception $e) {
        logEvent("MAILER_SETUP_ERROR", $e->getMessage());
        return null;
    }
}

// ==========================================
// FUNCȚII TRIMITERE EMAIL (DESIGN HTML COMPLET - NESCHIMBAT)
// ==========================================

function sendAdminEmail($data, $orderId, $shipping) {
    if (empty(SMTP_PASS)) return false;
    
    $mail = getMailer();
    if (!$mail) return false;
    
    $prodPrice = floatval($data['price']);
    $total = $prodPrice + $shipping;
    $shippingMethodText = ($data['shippingMethod'] === 'easybox') ? 'EasyBox Locker' : 'GLS Curier';
    
    try {
        $mail->setFrom(FROM_EMAIL, 'Comenzi Secretul Pisicii'); 
        if (!empty($data['email'])) {
            $mail->addReplyTo($data['email'], $data['fullName']);
        }
        $mail->addAddress(ADMIN_EMAIL);
        
        $mail->Subject = "💰 Comanda Noua #{$orderId} - {$total} RON";
        $mail->isHTML(true);
        
        $mail->Body = "
        <div style='font-family: Arial, sans-serif; padding: 20px; background: #f3f4f6;'>
            <div style='background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e5e7eb;'>
                <h2 style='color: #eb2571; margin-top:0;'>Comandă Nouă #{$orderId}</h2>
                <p><strong>Client:</strong> {$data['fullName']}</p>
                <p><strong>Telefon:</strong> <a href='tel:{$data['phone']}'>{$data['phone']}</a></p>
                <p><strong>Email:</strong> {$data['email']}</p>
                <hr>
                <h3>Detalii Livrare</h3>
                <p><strong>Metoda:</strong> {$shippingMethodText}</p>
                " . ($data['lockerId'] ? "<p><strong>Locker ID:</strong> {$data['lockerId']}</p>" : "") . "
                <p><strong>Adresa:</strong> {$data['address']['county']}, {$data['address']['city']}, {$data['address']['line']}</p>
                <p><strong>Cod Poștal:</strong> " . ($data['address']['postal_code'] ?? '-') . "</p>
                <hr>
                <h3>Sumar Financiar</h3>
                <p>Pachet: {$data['bundle']} buc ({$prodPrice} Lei)</p>
                <p>Transport: {$shipping} Lei</p>
                <h3 style='color: #eb2571;'>TOTAL: {$total} Lei</h3>
                <p><strong>Plată:</strong> " . strtoupper($data['paymentMethod']) . "</p>
            </div>
        </div>";
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        logEvent("EMAIL_ADMIN_FAIL", $mail->ErrorInfo);
        return false;
    }
}

function sendClientEmail($data, $orderId, $shipping) {
    if (empty($data['email'])) return false;
    if (empty(SMTP_PASS)) return false;
    
    $mail = getMailer();
    if (!$mail) return false;
    
    $prodPrice = floatval($data['price']);
    $total = $prodPrice + $shipping;
    $shippingMethodText = ($data['shippingMethod'] === 'easybox') ? 'EasyBox Locker' : 'GLS Curier';
    
    try {
        $mail->setFrom(FROM_EMAIL, 'Secretul Pisicii');
        $mail->addAddress($data['email'], $data['fullName']);
        
        $mail->Subject = "Confirmare Comanda #{$orderId} - Secretul Pisicii";
        $mail->isHTML(true);
        
        $mail->Body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background-color: #ffffff;'>
            <div style='background-color: #eb2571; padding: 20px; text-align: center;'>
                <h1 style='color: #ffffff; margin: 0;'>Mulțumim! 😻</h1>
            </div>
            
            <div style='padding: 20px; border: 1px solid #eee;'>
                <p>Salut <strong>{$data['fullName']}</strong>,</p>
                <p>Comanda ta a fost înregistrată cu succes și urmează să fie pregătită pentru livrare.</p>
                
                <div style='background-color: #f9fafb; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                    <h3 style='margin-top: 0; color: #333;'>Rezumat Comandă #{$orderId}</h3>
                    <table style='width: 100%;'>
                        <tr>
                            <td style='padding: 5px 0;'>Pachet selectat:</td>
                            <td style='text-align: right; font-weight: bold;'>{$data['bundle']} buc</td>
                        </tr>
                        <tr>
                            <td style='padding: 5px 0;'>Preț produse:</td>
                            <td style='text-align: right;'>{$prodPrice} Lei</td>
                        </tr>
                        <tr>
                            <td style='padding: 5px 0;'>Livrare ({$shippingMethodText}):</td>
                            <td style='text-align: right;'>{$shipping} Lei</td>
                        </tr>
                        <tr style='border-top: 1px solid #ddd;'>
                            <td style='padding: 10px 0; font-weight: bold;'>TOTAL DE PLATĂ:</td>
                            <td style='text-align: right; font-weight: bold; color: #eb2571; font-size: 18px;'>{$total} Lei</td>
                        </tr>
                    </table>
                </div>

                <p><strong>Adresa de livrare:</strong><br>
                {$data['address']['line']}<br>
                {$data['address']['city']}, {$data['address']['county']}<br>
                " . ($data['address']['postal_code'] ? "Cod: " . $data['address']['postal_code'] : "") . "</p>

                <p style='margin-top: 30px; font-size: 14px; color: #666;'>
                    Vei primi un SMS de la curier în ziua livrării.<br>
                    Dacă ai întrebări, răspunde la acest email.
                </p>
            </div>
            <div style='text-align: center; padding: 10px; font-size: 12px; color: #999;'>
                &copy; 2026 Secretul Pisicii. Toate drepturile rezervate.
            </div>
        </div>";
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        logEvent("EMAIL_CLIENT_FAIL", $mail->ErrorInfo);
        return false;
    }
}

// ==========================================
// LOGICA PRINCIPALĂ (MAIN LOGIC)
// ==========================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Metodă HTTP invalidă.']);
    exit;
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Date invalide JSON.']);
    exit;
}

if (empty($data['fullName']) || empty($data['phone'])) {
    echo json_encode(['success' => false, 'message' => 'Date incomplete (nume sau telefon lipsă).']);
    exit;
}

$orderId = saveOrderToDatabase($data);

if (!$orderId) {
    echo json_encode(['success' => false, 'message' => 'Eroare internă. Te rugăm să încerci din nou.']);
    exit;
}

$shipping = ($data['shippingMethod'] === 'easybox') ? 10.00 : 14.00;
$totalAmount = floatval($data['price']) + $shipping;

// ==========================================
// TRIMITERE RĂSPUNS RAPID
// ==========================================

$response = [
    'success' => true,
    'message' => 'Comanda a fost înregistrată!',
    'data' => [
        'orderId' => $orderId,
        'total' => $totalAmount,
        'paymentMethod' => $data['paymentMethod']
    ]
];

ob_start();
echo json_encode($response);
$size = ob_get_length();

header("Content-Encoding: none");
header("Content-Length: {$size}");
header("Connection: close");

ob_end_flush();
flush();

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// ==========================================
// PROCESARE ÎN BACKGROUND
// ==========================================

logEvent("INFO", "Procesare background pornită pentru comanda #$orderId");

try {
    sendAdminEmail($data, $orderId, $shipping);
    logEvent("INFO", "Email Admin trimis.");
} catch (Exception $e) {
    logEvent("ERROR", "Eșec email admin: " . $e->getMessage());
}

try {
    sendClientEmail($data, $orderId, $shipping);
    logEvent("INFO", "Email Client trimis.");
} catch (Exception $e) {
    logEvent("ERROR", "Eșec email client: " . $e->getMessage());
}

// Integrări Opționale
if (file_exists(__DIR__ . '/oblio_functions.php') && $data['paymentMethod'] !== 'card') {
    try {
        require_once __DIR__ . '/oblio_functions.php';
        logEvent("INFO", "Oblio procesat (simulat).");
    } catch (Exception $e) {
        logEvent("ERROR", "Oblio error: " . $e->getMessage());
    }
}

if (file_exists(__DIR__ . '/ecolet_functions.php') && $data['shippingMethod'] === 'gls') {
    try {
        require_once __DIR__ . '/ecolet_functions.php';
        logEvent("INFO", "Ecolet procesat (simulat).");
    } catch (Exception $e) {
        logEvent("ERROR", "Ecolet error: " . $e->getMessage());
    }
}

logEvent("INFO", "Procesare completă pentru #$orderId");
exit;
?>