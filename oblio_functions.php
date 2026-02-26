<?php
// oblio_functions.php
require_once __DIR__ . '/config.php';

/**
 * Gestionează Token-ul Oblio cu Caching (Logica din Oclar/api/services/oblio.js)
 * Salvează token-ul într-un fișier temporar pentru a evita request-uri inutile.
 */
function getOblioToken() {
    $tokenFile = sys_get_temp_dir() . '/oblio_token_cache.txt';
    
    // 1. Verificăm dacă avem un token valid în cache
    if (file_exists($tokenFile)) {
        $cached = json_decode(file_get_contents($tokenFile), true);
        if ($cached && isset($cached['expires_at']) && time() < $cached['expires_at']) {
            return $cached['access_token'];
        }
    }

    // 2. Cerem token nou dacă nu avem sau a expirat
    $ch = curl_init("https://www.oblio.eu/api/v1/authorize/token");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'client_id' => OBLIO_EMAIL,
        'client_secret' => OBLIO_API_SECRET,
        'grant_type' => 'client_credentials'
    ]));
    // Logica Oclar folosește axios care trimite JSON, dar endpointul de token acceptă form-data.
    // Păstrăm compatibilitatea standard PHP curl.
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($result, true);

    if ($httpCode !== 200 || !isset($data['access_token'])) {
        throw new Exception("Eroare Autentificare Oblio: " . ($data['statusMessage'] ?? 'Unknown error'));
    }

    // 3. Salvăm în cache (expiră în 3600 secunde minus o marjă de siguranță)
    $cacheData = [
        'access_token' => $data['access_token'],
        'expires_at' => time() + ($data['expires_in'] ?? 3600) - 60
    ];
    file_put_contents($tokenFile, json_encode($cacheData));

    return $data['access_token'];
}

/**
 * Trimite comanda către Oblio respectând structura din Oclar
 */
function sendToOblio($orderId) {
    global $pdo;
    if (!isset($pdo)) {
        $pdo = getDbConnection(); // Asigură-te că funcția există în context
    }

    // 1. Preluăm datele comenzii
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) throw new Exception("Comanda #$orderId nu există.");

    // 2. Pregătim calculele (Logica Oclar separă produsele de transport)
    $shippingCost = ($order['shipping_method'] == 'easybox') ? SHIPPING_COST_EASYBOX : SHIPPING_COST_GLS;
    $totalOrder = floatval($order['total_price']);
    $productTotal = $totalOrder - $shippingCost;
    $qty = (int)$order['bundle'];
    
    // Calculăm prețul per bucată FĂRĂ TVA (Oblio preferă așa pentru a adăuga el TVA)
    // Preț cu TVA = Preț fără TVA * 1.19 => Preț fără TVA = Preț cu TVA / 1.19
    $unitPriceWithVat = $productTotal / $qty;
    $unitPriceNoVat = $unitPriceWithVat / 1.19; 
    
    $shippingNoVat = $shippingCost / 1.19;

    // 3. Construim Payload-ul EXACT ca în Oclar (api/services/oblio.js)
    $invoiceData = [
        'cif' => OBLIO_CUI_FIRMA,
        'client' => [
            'name' => $order['full_name'],
            'email' => $order['email'],
            'phone' => $order['phone'],
            'address' => $order['address_line'],
            'city' => $order['city'],
            'county' => $order['county'],
            'save' => true
        ],
        'seriesName' => OBLIO_SERIE,
        'issueDate' => date('Y-m-d'), // Oclar pune new Date()
        'dueDate' => date('Y-m-d', strtotime('+14 days')), // Oclar pune scadenta
        'deliveryDate' => date('Y-m-d'),
        'language' => 'RO',
        'precision' => 2,
        'currency' => 'RON',
        'useVAT' => true,
        'products' => [
            [
                'name' => 'Perie Nano-Steam (Pachet ' . $qty . ' buc)',
                'code' => 'SP-001',
                'description' => 'Dispozitiv îngrijire animale',
                'price' => round($unitPriceNoVat, 4), // Oblio vrea preț fără TVA aici dacă useVAT=true
                'measurementUnit' => 'buc',
                'vatPercentage' => 19,
                'quantity' => $qty
            ],
            [
                'name' => 'Servicii Transport',
                'code' => 'TRANSPORT',
                'price' => round($shippingNoVat, 4),
                'measurementUnit' => 'buc',
                'vatPercentage' => 19,
                'quantity' => 1
            ]
        ]
    ];

    // 4. Trimitem Request-ul
    $token = getOblioToken();
    
    $ch = curl_init("https://www.oblio.eu/api/v1/invoice");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($invoiceData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token
    ]);
    
    $result = curl_exec($ch);
    $response = json_decode($result, true);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 400) {
        $errMsg = $response['statusMessage'] ?? 'Eroare necunoscută';
        // Detalii suplimentare eroare (field validation)
        if (isset($response['data']) && is_array($response['data'])) {
            $errMsg .= ' - ' . json_encode($response['data']);
        }
        throw new Exception("Oblio Error: " . $errMsg);
    }

    // 5. Salvăm link-ul în baza de date
    $link = $response['data']['link'];
    $stmtUpdate = $pdo->prepare("UPDATE orders SET oblio_status = 1, oblio_link = ? WHERE id = ?");
    $stmtUpdate->execute([$link, $orderId]);

    return "Factură emisă cu succes!";
}
?>