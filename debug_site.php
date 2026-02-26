<?php
// debug_site.php - Verifică de ce nu merge comanda
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>🕵️ Secretul Pisicii - Diagnosticare Sistem</h1>";
echo "<style>body{font-family:sans-serif; padding:20px;} .ok{color:green; font-weight:bold;} .err{color:red; font-weight:bold; background:#ffecec; padding:5px;} .info{color:blue;}</style>";

// 1. VERIFICARE FIȘIERE CRITICE
echo "<h3>1. Verificare Fișiere</h3>";
$files = [
    'config.php' => 'Configurarea principală',
    '.env' => 'Fișierul cu parole (ascuns)',
    'order-api.php' => 'Scriptul de comandă',
    'PHPMailer-master/src/PHPMailer.php' => 'Librăria de Email'
];

$allFilesExist = true;
foreach ($files as $file => $desc) {
    if (file_exists(__DIR__ . '/' . $file)) {
        echo "✅ $file ($desc) ...... <span class='ok'>GĂSIT</span><br>";
    } else {
        echo "❌ $file ($desc) ...... <span class='err'>LIPSĂ!</span><br>";
        $allFilesExist = false;
    }
}

if (!$allFilesExist) {
    echo "<h2 class='err'>STOP! Nu are rost să continuăm. Urcă fișierele lipsă.</h2>";
    exit;
}

// 2. VERIFICARE CONFIGURARE (.env)
echo "<h3>2. Verificare Configurare (.env)</h3>";
require_once __DIR__ . '/config.php';

// Verificăm dacă s-au încărcat variabilele
$vars = [
    'DB_HOST' => defined('DB_HOST') ? DB_HOST : null,
    'DB_USER' => defined('DB_USER') ? DB_USER : null,
    'DB_PASS' => defined('DB_PASS') ? '******' : null, // Ascundem parola
    'DB_NAME' => defined('DB_NAME') ? DB_NAME : null,
    'SMTP_HOST' => defined('SMTP_HOST') ? SMTP_HOST : null,
];

foreach ($vars as $name => $val) {
    if (!empty($val)) {
        echo "✅ $name ...... <span class='ok'>OK</span><br>";
    } else {
        echo "❌ $name ...... <span class='err'>GOL sau NE-DEFINIT! Verifică .env și config.php</span><br>";
    }
}

// 3. VERIFICARE BAZĂ DE DATE
echo "<h3>3. Test Conexiune Bază de Date</h3>";
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✅ Conexiune MySQL ...... <span class='ok'>REUȘITĂ!</span><br>";
    
    // Verificăm tabelul orders
    echo "Checking table 'orders' structure...<br>";
    $stmt = $pdo->query("DESCRIBE orders");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $required_cols = ['id', 'full_name', 'phone', 'address_line', 'county', 'city', 'shipping_method', 'payment_method', 'total_price', 'bundle'];
    $missing_cols = [];
    
    foreach ($required_cols as $req) {
        if (!in_array($req, $columns)) {
            $missing_cols[] = $req;
        }
    }
    
    if (empty($missing_cols)) {
        echo "✅ Tabelul 'orders' ...... <span class='ok'>STRUCTURĂ CORECTĂ</span><br>";
    } else {
        echo "❌ Tabelul 'orders' ...... <span class='err'>LIPSESC COLOANE: " . implode(', ', $missing_cols) . "</span><br>";
        echo "<small>Soluție: Șterge tabelul din phpMyAdmin și creează-l din nou cu codul SQL corect.</small><br>";
    }

} catch (PDOException $e) {
    echo "❌ Conexiune MySQL ...... <span class='err'>EȘUATĂ!</span><br>";
    echo "Mesaj eroare: " . $e->getMessage() . "<br>";
    echo "Verifică DB_USER și DB_PASS în fișierul .env";
}

// 4. VERIFICARE PERMISIUNI LOGS
echo "<h3>4. Verificare Folder Logs</h3>";
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    // Încercăm să-l creăm
    if (@mkdir($logDir, 0755, true)) {
        echo "✅ Folder logs ...... <span class='ok'>CREAT ACUM</span><br>";
    } else {
        echo "❌ Folder logs ...... <span class='err'>LIPSĂ și nu pot crea. Creează folderul 'logs' manual.</span><br>";
    }
} else {
    if (is_writable($logDir)) {
        echo "✅ Folder logs ...... <span class='ok'>SCRIERE PERMISĂ</span><br>";
    } else {
        echo "❌ Folder logs ...... <span class='err'>NU AM DREPT DE SCRIERE (Permission Denied). Dă permisiuni 755 sau 777.</span><br>";
    }
}

// 5. TEST PHPMAILER
echo "<h3>5. Test Încărcare PHPMailer</h3>";
try {
    require_once __DIR__ . '/PHPMailer-master/src/Exception.php';
    require_once __DIR__ . '/PHPMailer-master/src/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer-master/src/SMTP.php';
    
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    echo "✅ Clasa PHPMailer ...... <span class='ok'>INIȚIALIZATĂ CORECT</span><br>";
} catch (Exception $e) {
    echo "❌ Clasa PHPMailer ...... <span class='err'>CRASH: " . $e->getMessage() . "</span><br>";
}

echo "<hr><h2>CONCLUZIE:</h2>";
echo "<p>Dacă vezi vreun ❌ ROȘU mai sus, aia e problema. Dacă totul e ✅ VERDE, înseamnă că serverul e perfect și problema e strict în codul JS (frontend) sau datele trimise.</p>";
?>