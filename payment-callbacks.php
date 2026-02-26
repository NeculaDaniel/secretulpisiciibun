<?php
/**
 * NETOPIA PAYMENT CALLBACKS
 * Pagini de redirecționare după plată
 */

require_once __DIR__ . '/config.php';

function getDbConnection() {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        return new PDO($dsn, DB_USER, DB_PASS);
    } catch (Exception $e) {
        return null;
    }
}

// ==========================================
// 1. RETURN PAGE (payment-return.php)
// Client revine după ce a terminat plata la Netopia
// ==========================================

if (basename($_SERVER['PHP_SELF']) == 'payment-return.php') {
    
    $orderId = isset($_GET['orderId']) ? intval($_GET['orderId']) : 0;
    
    if (!$orderId) {
        header('Location: /');
        exit;
    }
    
    // Verific statusul în baza de date (webhookul ar fi trebuit să-l marcheze)
    $pdo = getDbConnection();
    if ($pdo) {
        try {
            $stmt = $pdo->prepare("SELECT payment_status FROM orders WHERE id=?");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();
            
            if ($order && $order['payment_status'] === 'paid') {
                // Plata e confirmată
                $status = 'success';
                $message = 'Plata a fost confirmată! Comanda ta va fi procesată în curând.';
            } else {
                // Plata încă nu e confirmată (webhook delay)
                $status = 'pending';
                $message = 'Plata ta este în curs de procesare. Te vom contacta în scurt timp.';
            }
        } catch (Exception $e) {
            $status = 'error';
            $message = 'Eroare la verificarea plății.';
        }
    } else {
        $status = 'error';
        $message = 'Eroare de conexiune.';
    }
    
    // HTML response
    ?>
    <!DOCTYPE html>
    <html lang="ro">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?php echo $status === 'success' ? 'Plată Confirmată' : 'Plată în Curs'; ?></title>
        <style>
            body {
                font-family: 'Inter', Arial, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0;
            }
            .container {
                background: white;
                padding: 40px;
                border-radius: 12px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                max-width: 500px;
                text-align: center;
            }
            .icon {
                font-size: 60px;
                margin-bottom: 20px;
            }
            h1 {
                color: #1f2937;
                margin-bottom: 10px;
                font-size: 28px;
            }
            p {
                color: #6b7280;
                font-size: 16px;
                line-height: 1.6;
                margin-bottom: 30px;
            }
            .btn {
                display: inline-block;
                padding: 12px 30px;
                background: #667eea;
                color: white;
                text-decoration: none;
                border-radius: 6px;
                font-weight: 600;
                transition: background 0.3s;
            }
            .btn:hover {
                background: #764ba2;
            }
            .order-id {
                background: #f3f4f6;
                padding: 15px;
                border-radius: 6px;
                margin-bottom: 20px;
                font-weight: 600;
                color: #667eea;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <?php if ($status === 'success'): ?>
                <div class="icon">✅</div>
                <h1>Plată Confirmată!</h1>
                <div class="order-id">Comandă #<?php echo $orderId; ?></div>
                <p><?php echo $message; ?></p>
            <?php else: ?>
                <div class="icon">⏳</div>
                <h1>Plată în Curs</h1>
                <div class="order-id">Comandă #<?php echo $orderId; ?></div>
                <p><?php echo $message; ?></p>
            <?php endif; ?>
            <a href="/" class="btn">Înapoi la Site</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ==========================================
// 2. WEBHOOK (payment-webhook.php)
// Netopia trimite confirmare la serverul nostru
// ==========================================

if (basename($_SERVER['PHP_SELF']) == 'payment-webhook.php') {
    
    header('Content-Type: application/json');
    
    // Primesc JSON din Netopia
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['paymentRef'])) {
        http_response_code(400);
        echo json_encode(['success' => false]);
        exit;
    }
    
    // Extrag orderId din referință (format: ORD_123_timestamp)
    $refParts = explode('_', $input['paymentRef']);
    $orderId = isset($refParts[1]) ? intval($refParts[1]) : 0;
    
    if (!$orderId) {
        http_response_code(400);
        echo json_encode(['success' => false]);
        exit;
    }
    
    $pdo = getDbConnection();
    if (!$pdo) {
        http_response_code(500);
        echo json_encode(['success' => false]);
        exit;
    }
    
    try {
        if ($input['status'] === 'paid' || $input['status'] === 'completed') {
            
            // Marcez comanda ca plătită
            $stmt = $pdo->prepare("UPDATE orders SET payment_status='paid', payment_method='card' WHERE id=?");
            $stmt->execute([$orderId]);
            
            // Declanșez Oblio invoice
            require_once __DIR__ . '/oblio_functions.php';
            
            $stmt = $pdo->prepare("SELECT * FROM orders WHERE id=?");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();
            
            if ($order) {
                $orderDataForOblio = [
                    'full_name' => $order['full_name'],
                    'email' => $order['email'],
                    'phone' => $order['phone'],
                    'address_line' => $order['address_line'],
                    'city' => $order['city'],
                    'county' => $order['county'],
                    'total_price' => $order['total_price'],
                    'payment_method' => 'card',
                    'bundle' => $order['bundle']
                ];
                
                sendOrderToOblio($orderDataForOblio, $orderId, $pdo);
            }
            
            logEvent(LOG_PAYMENTS, "Webhook: Plată confirmată #$orderId");
            
            echo json_encode(['success' => true]);
            
        } elseif ($input['status'] === 'failed' || $input['status'] === 'cancelled') {
            
            // Marchez ca eșuat
            $stmt = $pdo->prepare("UPDATE orders SET payment_status='failed' WHERE id=?");
            $stmt->execute([$orderId]);
            
            logEvent(LOG_PAYMENTS, "Webhook: Plată eșuată #$orderId - {$input['status']}");
            
            echo json_encode(['success' => true]);
            
        } else {
            echo json_encode(['success' => true]); // Ignorez alte statusuri
        }
        
    } catch (Exception $e) {
        logEvent(LOG_ERRORS, "Webhook error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false]);
    }
    
    exit;
}

// ==========================================
// 3. CANCEL PAGE (payment-cancel.php)
// Client a anulat plata la Netopia
// ==========================================

if (basename($_SERVER['PHP_SELF']) == 'payment-cancel.php') {
    
    $orderId = isset($_GET['orderId']) ? intval($_GET['orderId']) : 0;
    
    if ($orderId) {
        $pdo = getDbConnection();
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("UPDATE orders SET payment_status='cancelled' WHERE id=?");
                $stmt->execute([$orderId]);
                logEvent(LOG_PAYMENTS, "Payment cancelled by user: #$orderId");
            } catch (Exception $e) {
                // Silent fail
            }
        }
    }
    
    ?>
    <!DOCTYPE html>
    <html lang="ro">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Plată Anulată</title>
        <style>
            body {
                font-family: 'Inter', Arial, sans-serif;
                background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0;
            }
            .container {
                background: white;
                padding: 40px;
                border-radius: 12px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                max-width: 500px;
                text-align: center;
            }
            .icon { font-size: 60px; margin-bottom: 20px; }
            h1 { color: #1f2937; margin-bottom: 10px; }
            p { color: #6b7280; margin-bottom: 30px; }
            .btn {
                display: inline-block;
                padding: 12px 30px;
                background: #f5576c;
                color: white;
                text-decoration: none;
                border-radius: 6px;
                font-weight: 600;
            }
            .btn:hover { background: #f093fb; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="icon">❌</div>
            <h1>Plată Anulată</h1>
            <p>Ai anulat procesul de plată. Comanda nu a fost finalizată.</p>
            <p>Dacă dorești, poți plasa comanda din nou cu <strong>plată la livrare</strong> sau să încerci o noua plată cu card.</p>
            <a href="/#order" class="btn">Încearcă Din Nou</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ==========================================
// 4. CONFIRM PAGE (payment-confirm.php)
// Page de confirmare temporara (inainte de webhook)
// ==========================================

if (basename($_SERVER['PHP_SELF']) == 'payment-confirm.php') {
    
    $orderId = isset($_GET['orderId']) ? intval($_GET['orderId']) : 0;
    
    ?>
    <!DOCTYPE html>
    <html lang="ro">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Procesare Plată...</title>
        <style>
            body {
                font-family: 'Inter', Arial, sans-serif;
                background: #f3f4f6;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0;
            }
            .container {
                text-align: center;
            }
            .spinner {
                width: 50px;
                height: 50px;
                border: 4px solid #e5e7eb;
                border-top-color: #667eea;
                border-radius: 50%;
                animation: spin 1s linear infinite;
                margin: 0 auto 20px;
            }
            @keyframes spin {
                to { transform: rotate(360deg); }
            }
            h1 { color: #1f2937; margin-bottom: 10px; }
            p { color: #6b7280; margin-bottom: 20px; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="spinner"></div>
            <h1>Se Procesează Plata...</h1>
            <p>Comandă #<?php echo $orderId; ?></p>
            <p style="font-size: 14px; color: #9ca3af;">Nu închide browserul. Vei fi redirecționat în scurt timp.</p>
            <script>
                // După 5 secunde, fă check la status
                setTimeout(function() {
                    fetch('/order-api.php?action=check_payment&orderId=<?php echo $orderId; ?>')
                        .then(r => r.json())
                        .then(data => {
                            if (data.status === 'paid') {
                                window.location.href = '/payment-return.php?orderId=<?php echo $orderId; ?>';
                            } else {
                                // Stay waiting for webhook
                                setTimeout(arguments.callee, 3000);
                            }
                        })
                        .catch(() => {
                            setTimeout(arguments.callee, 3000);
                        });
                }, 5000);
            </script>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Default - redirecționez la home
header('Location: /');
exit;
?>
