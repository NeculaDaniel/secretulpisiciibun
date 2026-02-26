<?php
header('Content-Type: text/html; charset=utf-8');
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';

echo "<h2>perioada de Testare Deep Debug - Integrări API</h2>";

function run_detailed_curl($url, $headers = [], $postData = null, $auth = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_VERBOSE, true);
    curl_setopt($ch, CURLOPT_HEADER, true); // Vrem să vedem și header-ele de răspuns
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    if ($postData) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    }

    if ($auth) {
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $auth);
    }

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $response = curl_exec($ch);
    $info = curl_getinfo($ch);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    return [
        'raw' => $response,
        'info' => $info,
        'error' => $error,
        'errno' => $errno
    ];
}

// --- 1. TEST CONECTIVITATE DE BAZĂ ---
echo "<h3>1. Test DNS & Rețea (Poate serverul să sune afară?)</h3>";
$google_test = run_detailed_curl("https://www.google.com");
if ($google_test['errno'] === 0) {
    echo "✅ Serverul are acces la internet (Google OK).<br>";
} else {
    echo "❌ <b style='color:red'>SERVER BLOCAT:</b> Serverul tău nu poate accesa nici măcar Google. Eroare: " . $google_test['error'] . "<br>";
}

// --- 2. DEBUG OBLIO (Eroare 401) ---
echo "<h3>2. Debug OBLIO (Căutăm eroarea 401)</h3>";
$oblio_fields = [
    'client_id' => OBLIO_EMAIL,
    'client_secret' => OBLIO_API_SECRET,
    'grant_type' => 'client_credentials'
];
$oblio_res = run_detailed_curl(
    "https://www.oblio.eu/api/v1/authorize/token", 
    ['Content-Type: application/x-www-form-urlencoded'], 
    http_build_query($oblio_fields)
);

echo "HTTP Code: " . $oblio_res['info']['http_code'] . "<br>";
if ($oblio_res['errno'] !== 0) echo "CURL Error: " . $oblio_res['error'] . "<br>";
echo "<details><summary>Vezi Răspuns Brut Oblio</summary><pre>" . htmlspecialchars($oblio_res['raw']) . "</pre></details>";

// --- 3. DEBUG ECOLET (Eroare 0) ---
echo "<h3>3. Debug ECOLET (Căutăm cauza erorii 0)</h3>";
$ecolet_auth = ECOLET_USERNAME . ":" . ECOLET_PASSWORD;
$ecolet_res = run_detailed_curl(
    "https://app.ecolet.ro/api/v1/services", 
    ['Content-Type: application/json'],
    null,
    $ecolet_auth
);

echo "HTTP Code: " . $ecolet_res['info']['http_code'] . "<br>";
if ($ecolet_res['errno'] !== 0) {
    echo "❌ <b style='color:red'>CURL ERROR ".$ecolet_res['errno'].":</b> " . $ecolet_res['error'] . "<br>";
    if ($ecolet_res['errno'] == 7) echo "👉 Sfat: Porturile de ieșire sunt blocate de Firewall-ul hostingului.<br>";
    if ($ecolet_res['errno'] == 60 || $ecolet_res['errno'] == 77) echo "👉 Sfat: Probleme cu certificatele SSL de pe server.<br>";
}
echo "<details><summary>Vezi Răspuns Brut Ecolet</summary><pre>" . htmlspecialchars($ecolet_res['raw']) . "</pre></details>";

echo "<br><hr><h4>Sfat interpretare:</h4>";
echo "Dacă la 'Răspuns Brut' vezi text, dar HTTP Code e 401/403, parolele din .env nu sunt bine citite sau sunt greșite.";
echo "Dacă la Ecolet HTTP Code rămâne 0, trebuie să trimiți acest log firmei de hosting.";
?>