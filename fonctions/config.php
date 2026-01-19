<?php
$host = '127.0.0.1:3306';
$dbname = 'gestion_tontine';
$username = 'root';
$password = '';
$charset = 'utf8mb4';

try {
    $dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erreur de connexion à la base de données: " . $e->getMessage());
}

// Configuration des pénalités
define('PENALITE_POURCENTAGE', 10); // 10% de pénalité
define('PENALITE_JOUR_MAX', 10); // Nombre maximum de jours pour calcul progressif
define('PENALITE_MAX_POURCENTAGE', 200); // Maximum 200% de pénalité

// Fonction pour calculer la pénalité
function calculerPenalite($montant_base, $jours_retard) {
    $pourcentage_base = PENALITE_POURCENTAGE; // 10%
    $jours_max = PENALITE_JOUR_MAX;
    $pourcentage_max = PENALITE_MAX_POURCENTAGE; // 200%
    
    // Calcul de la pénalité progressive
    $multiplicateur = min($jours_retard, $jours_max);
    $pourcentage_final = min($pourcentage_base * $multiplicateur, $pourcentage_max);
    
    return $montant_base * ($pourcentage_final / 100);
}

// ... reste de la configuration ...