<?php
/**
 * Script à exécuter via cron job quotidien
 * Exemple: 0 0 * * * php /chemin/vers/cron_penalites.php
 */

require_once __DIR__ . '/fonctions/config.php';
require_once __DIR__ . '/fonctions/apply_penalty.php';

// Démarrer la session (si nécessaire)
session_start();

// Journalisation
$log_file = __DIR__ . '/logs/penalites_cron.log';
$timestamp = date('Y-m-d H:i:s');
$log_message = "=== Exécution du cron des pénalités - $timestamp ===\n";

try {
    // Vérifier et appliquer les pénalités journalières
    $result = verifierPenalitesJournalieres($pdo);
    
    if ($result['success']) {
        $log_message .= "✅ " . $result['total_penalites'] . " pénalité(s) appliquée(s)\n";
        
        foreach ($result['penalites'] as $penalite) {
            $log_message .= "   - {$penalite['membre']}: {$penalite['montant']} FCFA ({$penalite['jours_retard']} jours de retard)\n";
        }
    } else {
        $log_message .= "❌ Erreur: " . $result['message'] . "\n";
    }
    
} catch (Exception $e) {
    $log_message .= "❌ Exception: " . $e->getMessage() . "\n";
}

$log_message .= "=== Fin d'exécution ===\n\n";

// Écrire dans le fichier log
file_put_contents($log_file, $log_message, FILE_APPEND);

// Optionnel: Envoyer un email de rapport
if (isset($result['total_penalites']) && $result['total_penalites'] > 0) {
    $to = "admin@votredomaine.com";
    $subject = "Rapport des pénalités journalières - " . date('d/m/Y');
    $message = "Bonjour,\n\n";
    $message .= "Voici le rapport des pénalités appliquées automatiquement aujourd'hui:\n\n";
    $message .= $log_message;
    $message .= "\nCordialement,\nSystème de Gestion de Tontine";
    
    mail($to, $subject, $message);
}

echo $log_message;