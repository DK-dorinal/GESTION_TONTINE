<?php
// apply_penalty.php

/**
 * Fonction pour appliquer automatiquement les pénalités journalières
 * pour les crédits non remboursés et les cotisations en retard
 */
function verifierPenalitesJournalieres($pdo) {
    $result = [
        'success' => false,
        'message' => '',
        'total_penalites' => 0,
        'penalites' => []
    ];

    try {
        // Démarrer une transaction
        $pdo->beginTransaction();
        
        // 1. Vérifier les crédits en retard
        $penalites_credit = verifierCreditsEnRetard($pdo);
        
        // 2. Vérifier les cotisations en retard
        $penalites_cotisation = verifierCotisationsEnRetard($pdo);
        
        // Fusionner les résultats
        $penalites_appliquees = array_merge($penalites_credit, $penalites_cotisation);
        
        // Appliquer les pénalités à la base de données
        if (!empty($penalites_appliquees)) {
            foreach ($penalites_appliquees as $penalite) {
                $montant_penalite = calculerMontantPenalite($penalite);
                
                // Insérer la pénalité dans la base de données
                $stmt = $pdo->prepare("INSERT INTO penalite (id_membre, montant, raison, date_penalite) 
                                      VALUES (:id_membre, :montant, :raison, CURDATE())");
                $stmt->execute([
                    ':id_membre' => $penalite['id_membre'],
                    ':montant' => $montant_penalite,
                    ':raison' => $penalite['raison']
                ]);
                
                // Ajouter aux résultats
                $result['penalites'][] = [
                    'membre' => $penalite['nom_complet'],
                    'montant' => $montant_penalite,
                    'jours_retard' => $penalite['jours_retard'] ?? 0,
                    'raison' => $penalite['raison']
                ];
            }
            
            $result['total_penalites'] = count($penalites_appliquees);
        }
        
        // Valider la transaction
        $pdo->commit();
        
        if ($result['total_penalites'] > 0) {
            $result['success'] = true;
            $result['message'] = $result['total_penalites'] . " pénalité(s) appliquée(s) avec succès.";
        } else {
            $result['success'] = true;
            $result['message'] = "Aucune pénalité à appliquer aujourd'hui.";
        }
        
    } catch (Exception $e) {
        // Annuler la transaction en cas d'erreur
        $pdo->rollBack();
        $result['message'] = "Erreur lors de l'application des pénalités: " . $e->getMessage();
    }
    
    return $result;
}

/**
 * Vérifier les crédits en retard de paiement
 */
function verifierCreditsEnRetard($pdo) {
    $penalites = [];
    $date_actuelle = date('Y-m-d');
    
    // Récupérer les crédits en retard (statut 'en_retard' ou échéance dépassée)
    $query = "
        SELECT c.id_credit, c.id_membre, c.montant, c.date_emprunt, 
               c.taux_interet, c.duree_mois, c.montant_restant,
               CONCAT(m.nom, ' ', m.prenom) as nom_complet,
               m.telephone,
               DATEDIFF(:date_actuelle, DATE_ADD(c.date_emprunt, INTERVAL c.duree_mois MONTH)) as jours_retard
        FROM credit c
        JOIN membre m ON c.id_membre = m.id_membre
        WHERE c.statut IN ('en_retard', 'en_cours')
        AND DATE_ADD(c.date_emprunt, INTERVAL c.duree_mois MONTH) < :date_actuelle
        AND c.montant_restant > 0
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([':date_actuelle' => $date_actuelle]);
    $credits_en_retard = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($credits_en_retard as $credit) {
        // Calculer le nombre de mois de retard (arrondi à l'entier supérieur)
        $mois_retard = ceil($credit['jours_retard'] / 30);
        
        // Ajouter une pénalité par mois de retard
        if ($mois_retard > 0) {
            $penalites[] = [
                'id_membre' => $credit['id_membre'],
                'nom_complet' => $credit['nom_complet'],
                'type' => 'credit_retard',
                'montant_credit' => $credit['montant'],
                'montant_restant' => $credit['montant_restant'],
                'jours_retard' => $credit['jours_retard'],
                'mois_retard' => $mois_retard,
                'raison' => "Retard de remboursement crédit - " . $mois_retard . " mois de retard"
            ];
        }
    }
    
    return $penalites;
}

/**
 * Vérifier les cotisations en retard
 */
function verifierCotisationsEnRetard($pdo) {
    $penalites = [];
    $date_actuelle = date('Y-m-d');
    
    // Récupérer les cotisations impayées ou en retard
    $query = "
        SELECT co.id_cotisation, co.id_membre, co.id_seance, co.montant, 
               co.date_paiement, co.statut,
               s.date_seance,
               t.nom_tontine, t.montant_cotisation,
               CONCAT(m.nom, ' ', m.prenom) as nom_complet,
               m.telephone,
               DATEDIFF(:date_actuelle, s.date_seance) as jours_retard
        FROM cotisation co
        JOIN seance s ON co.id_seance = s.id_seance
        JOIN tontine t ON s.id_tontine = t.id_tontine
        JOIN membre m ON co.id_membre = m.id_membre
        WHERE co.statut IN ('impayé', 'retard')
        AND s.date_seance < :date_actuelle
        AND co.date_paiement IS NULL
        ORDER BY s.date_seance ASC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([':date_actuelle' => $date_actuelle]);
    $cotisations_en_retard = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($cotisations_en_retard as $cotisation) {
        // Ne pas pénaliser les cotisations de moins de 7 jours (tolérance)
        if ($cotisation['jours_retard'] >= 7) {
            // Calculer le nombre de périodes de 7 jours de retard
            $periodes_retard = floor($cotisation['jours_retard'] / 7);
            
            if ($periodes_retard > 0) {
                $penalites[] = [
                    'id_membre' => $cotisation['id_membre'],
                    'nom_complet' => $cotisation['nom_complet'],
                    'type' => 'cotisation_retard',
                    'montant_cotisation' => $cotisation['montant'],
                    'jours_retard' => $cotisation['jours_retard'],
                    'periodes_retard' => $periodes_retard,
                    'tontine' => $cotisation['nom_tontine'],
                    'raison' => "Retard de paiement cotisation - Tontine: " . 
                               $cotisation['nom_tontine'] . " (" . 
                               $cotisation['jours_retard'] . " jours de retard)"
                ];
            }
        }
    }
    
    return $penalites;
}

/**
 * Calculer le montant de la pénalité selon le type
 */
function calculerMontantPenalite($penalite) {
    switch ($penalite['type']) {
        case 'credit_retard':
            // Pénalité de 5% du montant restant par mois de retard (minimum 1000 FCFA)
            $montant = $penalite['montant_restant'] * 0.05 * $penalite['mois_retard'];
            return max($montant, 1000);
            
        case 'cotisation_retard':
            // Pénalité de 10% de la cotisation par période de 7 jours de retard
            $montant = $penalite['montant_cotisation'] * 0.10 * $penalite['periodes_retard'];
            return max($montant, 500);
            
        default:
            return 1000; // Pénalité par défaut
    }
}

/**
 * Fonction pour mettre à jour le statut des crédits en retard
 */
function mettreAJourStatutCredits($pdo) {
    $date_actuelle = date('Y-m-d');
    
    // Mettre à jour les crédits dont l'échéance est dépassée
    $query = "
        UPDATE credit 
        SET statut = 'en_retard'
        WHERE statut = 'en_cours'
        AND DATE_ADD(date_emprunt, INTERVAL duree_mois MONTH) < :date_actuelle
        AND montant_restant > 0
    ";
    
    $stmt = $pdo->prepare($query);
    return $stmt->execute([':date_actuelle' => $date_actuelle]);
}

/**
 * Fonction pour mettre à jour le statut des cotisations en retard
 */
function mettreAJourStatutCotisations($pdo) {
    $date_actuelle = date('Y-m-d');
    
    // Mettre à jour les cotisations impayées datant de plus de 7 jours
    $query = "
        UPDATE cotisation co
        JOIN seance s ON co.id_seance = s.id_seance
        SET co.statut = 'retard'
        WHERE co.statut = 'impayé'
        AND s.date_seance < DATE_SUB(:date_actuelle, INTERVAL 7 DAY)
        AND co.date_paiement IS NULL
    ";
    
    $stmt = $pdo->prepare($query);
    return $stmt->execute([':date_actuelle' => $date_actuelle]);
}

/**
 * Fonction pour vérifier si un membre a des pénalités non payées
 */
function getTotalPenalitesMembre($pdo, $id_membre) {
    $query = "
        SELECT SUM(montant) as total_penalites
        FROM penalite
        WHERE id_membre = :id_membre
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([':id_membre' => $id_membre]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result['total_penalites'] ?? 0;
}

/**
 * Fonction pour marquer une pénalité comme payée (à intégrer dans le système de paiement)
 */
function marquerPenalitePayee($pdo, $id_penalite, $date_paiement = null) {
    if ($date_paiement === null) {
        $date_paiement = date('Y-m-d');
    }
    
    // Note: Cette fonction nécessiterait d'ajouter un champ 'statut_paiement' à la table penalite
    // ou de créer une table séparée pour le suivi des paiements des pénalités
    
    return true;
}?>