<?php
// ecolet_functions.php
require_once __DIR__ . '/config.php';

/**
 * Generează AWB prin Ecolet respectând logica din Oclar/api/services/ecolet.js
 */
function generateEcoletAWB($orderId) {
    global $pdo;
    if (!isset($pdo)) {
        $pdo = getDbConnection();
    }

    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) throw new Exception("Comanda nu există.");

    // 1. Determinare Service ID (Logic din Oclar)
    // Dacă avem locker_id => Service 23 (Easybox), altfel Service 15 (Standard/GLS)
    $isEasybox = ($order['shipping_method'] === 'easybox' && !empty($order['easybox_locker_id']));
    $serviceId = $isEasybox ? 23 : 15;

    // 2. Construire Payload
    $payload = [
        'sender' => [
            'name' => defined('ECOLET_SENDER_NAME') ? ECOLET_SENDER_NAME : 'Secretul Pisicii',
            'phone' => '0700000000', // Poți pune telefonul tău în .env dacă vrei
            'country' => 'RO',
            'county' => defined('ECOLET_SENDER_COUNTY') ? ECOLET_SENDER_COUNTY : '',
            'city' => defined('ECOLET_SENDER_CITY') ? ECOLET_SENDER_CITY : '',
            'postal_code' => defined('ECOLET_SENDER_POSTAL') ? ECOLET_SENDER_POSTAL : '',
            'address' => defined('ECOLET_SENDER_STREET') ? ECOLET_SENDER_STREET : '',
            // ID-ul localității este crucial pentru sender în Ecolet
            'locality_id' => defined('ECOLET_SENDER_LOCALITY_ID') ? (int)ECOLET_SENDER_LOCALITY_ID : 0
        ],
        'receiver' => [
            'name' => $order['full_name'],
            'phone' => $order['phone'],
            'email' => $order['email'],
            'country' => 'RO',
            'county' => $order['county'],
            'city' => $order['city'],
            'address' => $order['address_line'],
            'postal_code' => $order['postal_code'] ?? '',
            // Observație: Oclar folosește cautaOras pentru locality_id receiver, 
            // dar API-ul Ecolet acceptă adesea string-uri la receiver dacă nu e strict.
        ],
        'service_id' => $serviceId,
        'parcels' => 1,
        'weight' => 1, // Oclar pune 1kg default
        'contents' => 'Perie Nano-Steam', // Sau 'Produse pentru animale'
        'envelope' => false
    ];

    // LOGICA SPECIFICĂ LOCKER (din Oclar)
    if ($isEasybox) {
        $payload['receiver']['locker_id'] = (int)$order['easybox_locker_id'];
    }

    // LOGICA RAMBURS (din Oclar)
    if ($order['payment_method'] === 'cash') {
        $payload['cod_value'] = (float)$order['total_price'];
        $payload['cod_currency'] = 'RON';
    }

    // 3. Autentificare Basic Auth (Username:Password base64)
    $auth = base64_encode(ECOLET_USERNAME . ":" . ECOLET_PASSWORD);

    // 4. Trimitere Request
    $ch = curl_init("https://app.ecolet.ro/api/v1/shipments");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Basic ' . $auth,
        'Accept: application/json'
    ]);
    
    // Setări SSL pentru servere de hosting standard
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($result === false) {
        throw new Exception("Eroare Conexiune Ecolet: " . $curlError);
    }

    $response = json_decode($result, true);

    // 5. Validare Răspuns
    if ($httpCode >= 400) {
        // Încercăm să extragem mesajul de eroare specific Ecolet
        $msg = $response['message'] ?? 'Unknown Error';
        if (isset($response['errors']) && is_array($response['errors'])) {
            $msg .= ' - ' . json_encode($response['errors']);
        }
        throw new Exception("Ecolet API Error ($httpCode): " . $msg);
    }

    if (!isset($response['awb_number'])) {
        throw new Exception("Ecolet nu a returnat numărul AWB. Răspuns: " . json_encode($response));
    }

    $awb = $response['awb_number'];

    // 6. Update Baza de Date
    $stmtUpdate = $pdo->prepare("UPDATE orders SET awb_number = ?, ecolet_status = 1 WHERE id = ?");
    $stmtUpdate->execute([$awb, $orderId]);

    return "AWB Generat cu succes: " . $awb;
}
?>