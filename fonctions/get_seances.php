<?php
session_start();
include './fonctions/config.php';

// Vérifier la connexion
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Non connecté']);
    exit();
}

$user_id = $_SESSION['user_id'];
$tontine_id = $_GET['tontine_id'] ?? 0;
$today = date('Y-m-d');

// Récupérer les séances disponibles pour cette tontine
$query = "
    SELECT s.id_seance, s.date_seance, t.montant_cotisation, t.nom_tontine,
           DATE_FORMAT(s.date_seance, '%d/%m/%Y') as date_format
    FROM seance s
    INNER JOIN tontine t ON s.id_tontine = t.id_tontine
    WHERE t.id_tontine = ?
    AND s.date_seance <= ?
    AND s.id_seance NOT IN (
        SELECT id_seance 
        FROM cotisation 
        WHERE id_membre = ? 
        AND statut = 'payé'
    )
    ORDER BY s.date_seance DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute([$tontine_id, $today, $user_id]);
$seances = $stmt->fetchAll();

header('Content-Type: application/json');
echo json_encode(['seances' => $seances]);
?>