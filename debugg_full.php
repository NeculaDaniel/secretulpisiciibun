<?php
// debug_full.php - DIAGNOSTICARE COMPLETĂ
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Stiluri CSS pentru claritate
echo "
<style>
    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f4f9; padding: 20px; color: #333; }
    .card { background: #fff; padding: 20px; margin-bottom: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
    h2 { border-bottom: 2px solid #eee; padding-bottom: 10px; margin-top: 0; }
    .pass { color: #27ae60; font-weight: bold; }
    .fail { color: #c0392b; font-weight: bold; background: #fadbd8; padding: 2px 5px; border-radius: 3px; }
    .warn { color: #d35400; font-weight: bold; }
    pre { background: #333; color: #eee; padding: 10px; overflow-x: auto; border-radius: 5px; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th, td { text-align: left; padding: 8px; border-bottom: 1px solid #ddd; }
</style>
<h1>🕵️ Diagnosticare Sistem - Secretul Pisicii</h1>
";

// FUNCȚIE PENTRU ÎNCĂRCARE .ENV MANUAL (Independent de config.php)
function getEnvValue($key) {
    static $envData = null;
    if ($envData === null) {
        $envData = [];
        if (file_exists(__DIR__ . '/.env')) {
            $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0) continue;
                if (strpos($line, '=') !== false) {
                    list($k, $v) = explode('=', $line, 2);
                    $envData[trim($k)] = trim($v);
                }
            }
        }
    }
    return isset($envData[$key]) ? $envData[$key] : null;
}

// ---------------------------------------------------------
// 1. VERIFICARE MEDIU PHP
// ---------------------------------------------------------
echo "<div class='card'><h2>1. Mediu Server & PHP</h2><table>";
echo "<tr><td>Versiune PHP</td><td>" . phpversion() . " (Recomandat 7.4+)</td></tr>";

$extensions = ['pdo', 'pdo_mysql', 'mbstring', 'json', 'openssl'];
foreach ($extensions as $ext) {
    $status = extension_loaded($ext) ? "<span class='pass'>ACTIVAT</span>" : "<span class='fail'>LIPSĂ</span>";
    echo "<tr><td>Extensie '$ext'</td><td>$status</td></tr>";
}

// Verificare permisiuni scriere
$logDir = __DIR__ . '/logs';
$canWrite = false;
if (!is_dir($logDir)) {
    if (@mkdir($logDir, 0755, true)) {
        $msgLogs = "<span class='pass'>Creat acum</span>";
        $canWrite = true;
    } else {
        $msgLogs = "<span class='fail'>Nu pot crea folderul 'logs' (Check Permissions)</span>";
    }
} else {
    if (is_writable($logDir)) {
        $msgLogs = "<span class='pass'>OK (Se poate scrie)</span>";
        $canWrite = true;
    } else {
        $msgLogs = "<span class='fail'>NU AM DREPT DE SCRIERE (Permission Denied)</span>";
    }
}
echo "<tr><td>Folder Logs</td><td>$msgLogs</td></tr>";
echo "</table></div>";

// ---------------------------------------------------------
// 2. VERIFICARE FIȘIERE CRITICE
// ---------------------------------------------------------
echo "<div class='card'><h2>2. Fișiere Necesare</h2><table>";
$files = [
    '.env' => 'Fișier configurare secrete',
    'config.php' => 'Configurare PHP',
    'order-api.php' => 'Script procesare comandă',
    'PHPMailer-master/src/PHPMailer.php' => 'Clasă PHPMailer',
    'PHPMailer-master/src/SMTP.php' => 'Clasă SMTP',
    'PHPMailer-master/src/Exception.php' => 'Clasă Exception',
];

$filesOk = true;
foreach ($files as $path => $desc) {
    if (file_exists(__DIR__ . '/' . $path)) {
        echo "<tr><td>$path</td><td><span class='pass'>GĂSIT</span></td></tr>";
    } else {
        echo "<tr><td>$path</td><td><span class='fail'>LIPSĂ!</span> ($desc)</td></tr>";
        $filesOk = false;
    }
}
echo "</table></div>";

if (!$filesOk) {
    die("<h1 class='fail'>STOP: Fișiere lipsă. Urcă fișierele și dă refresh.</h1>");
}

// ---------------------------------------------------------
// 3. VERIFICARE BAZA DE DATE
// ---------------------------------------------------------
echo "<div class='card'><h2>3. Baza de Date</h2>";

$dbHost = getEnvValue('DB_HOST');
$dbName = getEnvValue('DB_NAME');
$dbUser = getEnvValue('DB_USER');
$dbPass = getEnvValue('DB_PASS');

if (!$dbHost || !$dbName || !$dbUser) {
    echo "<p class='fail'>Datele de conectare lipsesc din fișierul .env!</p>";
} else {
    try {
        $dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
        $pdo = new PDO($dsn, $dbUser, $dbPass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        echo "<p class='pass'>✅ Conexiune MySQL reușită!</p>";

        // Verificăm structura tabelului
        echo "<h3>Verificare Tabel 'orders'</h3>";
        $stmt = $pdo->query("DESCRIBE orders");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $requiredCols = [
            'id', 'full_name', 'phone', 'email', 'address_line', 
            'county', 'city', 'postal_code', 'shipping_method', 
            'easybox_locker_id', 'payment_method', 'total_price', 
            'bundle', 'status', 'created_at'
        ];

        $missingCols = [];
        echo "<ul>";
        foreach ($requiredCols as $req) {
            if (in_array($req, $columns)) {
                echo "<li>Coloana <code>$req</code>: <span class='pass'>OK</span></li>";
            } else {
                echo "<li>Coloana <code>$req</code>: <span class='fail'>LIPSĂ!</span></li>";
                $missingCols[] = $req;
            }
        }
        echo "</ul>";

        if (!empty($missingCols)) {
            echo "<div class='fail'>ERROARE FATALĂ: Tabelul din baza de date este VECHI.</div>";
            echo "<p>Codul PHP încearcă să scrie în coloane care nu există (ex: easybox_locker_id).";
            echo "<br><strong>SOLUȚIE:</strong> Șterge tabelul 'orders' din phpMyAdmin și recreează-l cu codul SQL corect.</p>";
        }

    } catch (PDOException $e) {
        echo "<p class='fail'>❌ Eroare conectare DB: " . $e->getMessage() . "</p>";
        echo "<p>Verifică parola în fișierul .env</p>";
    }
}
echo "</div>";

// ---------------------------------------------------------
// 4. VERIFICARE SMTP (EMAIL)
// ---------------------------------------------------------
echo "<div class='card'><h2>4. Test Trimitere Email (SMTP)</h2>";

require_once __DIR__ . '/PHPMailer-master/src/Exception.php';
require_once __DIR__ . '/PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer-master/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$smtpHost = getEnvValue('SMTP_HOST');
$smtpUser = getEnvValue('SMTP_USER');
$smtpPass = getEnvValue('SMTP_PASS');
$smtpPort = getEnvValue('SMTP_PORT');
$adminEmail = getEnvValue('ADMIN_EMAIL');

echo "<ul>";
echo "<li>Host: $smtpHost</li>";
echo "<li>Port: $smtpPort</li>";
echo "<li>User: $smtpUser</li>";
echo "<li>Destinatar Test: $adminEmail</li>";
echo "</ul>";

$mail = new PHPMailer(true);
try {
    // Server settings
    $mail->isSMTP();
    $mail->Host       = $smtpHost;
    $mail->SMTPAuth   = true;
    $mail->Username   = $smtpUser;
    $mail->Password   = $smtpPass;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // Încearcă TLS
    if ($smtpPort == 465) {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // SSL pentru port 465
    }
    $mail->Port       = $smtpPort;

    // Recipients
    $mail->setFrom($smtpUser, 'DEBUG TEST');
    $mail->addAddress($adminEmail); 

    // Content
    $mail->isHTML(true);
    $mail->Subject = 'Test Debugging Secretul Pisicii';
    $mail->Body    = 'Daca vezi asta, <b>SMTP-ul functioneaza perfect!</b>';

    $mail->send();
    echo "<h3 class='pass'>✅ Email trimis cu SUCCES!</h3>";
} catch (Exception $e) {
    echo "<h3 class='fail'>❌ Eroare trimitere Email:</h3>";
    echo "<pre>" . $mail->ErrorInfo . "</pre>";
    echo "<p>Sfaturi: Verifică parola (dacă e Gmail, trebuie App Password), verifică portul (465 vs 587).</p>";
}
echo "</div>";

?>