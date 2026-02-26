<?php
// ecolet_functions.php
require_once __DIR__ . '/config.php';

function generateEcoletAWB($orderId) {
    global $pdo;
    if (!isset($pdo)) {
        $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    }

    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) throw new Exception("Comanda nu exista.");

    $isEasybox = ($order['shipping_method'] === 'easybox');
    $serviceId = $isEasybox ? 23 : 15; // Ajustează ID-urile conform contului tău

    $payload = [
        'sender' => ['name' => 'Secretul Pisicii', 'phone' => '0700000000'],
        'receiver' => [
            'name' => $order['full_name'],
            'phone' => $order['phone'],
            'email' => $order['email'],
            'address' => $order['address_line'],
            'city' => $order['city'],
            'county' => $order['county'],
            'postal_code' => $order['postal_code']
        ],
        'service_id' => $serviceId,
        'parcels' => 1,
        'weight' => 0.5,
        'contents' => 'Perie Nano-Steam',
        'cod_value' => ($order['payment_method'] == 'cash') ? $order['total_price'] : 0
    ];

    if ($isEasybox) {
        $payload['receiver']['locker_id'] = $order['easybox_locker_id'];
    }

    $ch = curl_init("https://app.ecolet.ro/api/v1/shipments");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    
    // FIX PENTRU COD 0
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $auth = base64_encode(ECOLET_USERNAME . ":" . ECOLET_PASSWORD);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Basic ' . $auth
    ]);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($result === false) {
        throw new Exception("Eroare Retea Ecolet: " . $curlError);
    }

    $response = json_decode($result, true);

    if ($httpCode >= 400) {
        throw new Exception("Eroare Ecolet: " . ($response['message'] ?? 'Service error'));
    }

    $awb = $response['awb_number'] ?? 'OK';
    $pdo->prepare("UPDATE orders SET awb_number = ?, ecolet_status = 1 WHERE id = ?")->execute([$awb, $orderId]);

    return "AWB generat: " . $awb;
}