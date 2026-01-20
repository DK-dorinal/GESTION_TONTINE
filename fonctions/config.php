<?php

$host = 'localhost';
$dbname = 'gestion_tontine';
$username = 'root';
$password = '';
$port = 3306;
$charset = 'utf8mb4';

try {
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=$charset";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die('Erreur connexion BD : ' . $e->getMessage());
}
?>