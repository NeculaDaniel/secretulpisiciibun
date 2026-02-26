<?php
require_once 'config.php';

echo "<h3>Diagnosticare Conexiune:</h3>";
echo "Host: " . DB_HOST . "<br>";
echo "Baza de date: " . DB_NAME . "<br>";
echo "Utilizator: " . DB_USER . "<br><br>";

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    echo "<b style='color:green'>Succes! Site-ul s-a conectat la baza de date.</b>";
} catch (PDOException $e) {
    echo "<b style='color:red'>Eroare detaliată:</b> " . $e->getMessage();
}
?>