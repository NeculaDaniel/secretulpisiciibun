<?php
// oblio_functions.php
require_once __DIR__ . '/config.php';

function getOblioToken() {
    $ch = curl_init("https://www.oblio.eu/api/v1/authorize/token");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    
    // Trimitem datele ca form-urlencoded pentru compatibilitate maximă
    $postFields = [
        'client_id' => OBLIO_EMAIL,
        'client_secret' => OBLIO_API_SECRET,
        'grant_type' => 'client_credentials'
    ];
    
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $result = curl_exec($ch);
    $data = json_decode($result, true);
    curl_close($ch);

    if (!isset($data['access_token'])) {
        $errorMsg = isset($data['statusMessage']) ? $data['statusMessage'] : 'Eroare necunoscuta la autentificare';
        throw new Exception("Eroare Oblio: " . $errorMsg);
    }
    return $data['access_token'];
}

function sendToOblio($orderId) {
    global $pdo;
    if (!isset($pdo)) {
        $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    }

    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) throw new Exception("Comanda nu exista.");

    $token = getOblioToken();
    
    // Calculăm prețurile (ajustează dacă ai alte pachete)
    $qty = (int)$order['bundle'];
    $total = floatval($order['total_price']);
    $shipping = ($order['shipping_method'] == 'easybox') ? 10 : 14;
    $unitPrice = ($total - $shipping) / $qty;

    $invoiceData = [
        'cif' => OBLIO_CUI_FIRMA,
        'seriesName' => OBLIO_SERIE,
        'client' => [
            'name' => $order['full_name'],
            'email' => $order['email'],
            'phone' => $order['phone'],
            'address' => $order['address_line'],
            'city' => $order['city'],
            'county' => $order['county'],
            'save' => true
        ],
        'products' => [
            [
                'name' => 'Perie Nano-Steam',
                'quantity' => $qty,
                'price' => round($unitPrice, 2),
                'vatPercentage' => 19,
                'measurementUnit' => 'buc'
            ],
            [
                'name' => 'Transport',
                'quantity' => 1,
                'price' => $shipping,
                'vatPercentage' => 19,
                'measurementUnit' => 'serv'
            ]
        ],
        'sendEmail' => true
    ];

    $ch = curl_init("https://www.oblio.eu/api/v1/invoice");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($invoiceData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $result = curl_exec($ch);
    $response = json_decode($result, true);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 400) {
        throw new Exception("Eroare Emitere: " . ($response['statusMessage'] ?? 'Server error'));
    }

    $link = $response['data']['link'];
    $pdo->prepare("UPDATE orders SET oblio_status = 1, oblio_link = ? WHERE id = ?")->execute([$link, $orderId]);

    return "Factura emisa cu succes!";
}