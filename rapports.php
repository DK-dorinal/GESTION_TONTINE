<?php
// Connexion à la base de données
require_once 'fonctions/config.php';

function getGlobalStats($pdo) {
    $stats = [];
    try {
        // 1. Statistiques membres
        $stmt = $pdo->query("SELECT COUNT(*) as total, 
            SUM(CASE WHEN statut = 'actif' THEN 1 ELSE 0 END) as actifs, 
            SUM(CASE WHEN statut = 'inactif' THEN 1 ELSE 0 END) as inactifs, 
            SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as admins 
            FROM membre");
        $stats['membres'] = $stmt->fetch();

        // 2. Statistiques tontines
        $stmt = $pdo->query("SELECT COUNT(*) as total, 
            SUM(CASE WHEN type_tontine = 'obligatoire' THEN 1 ELSE 0 END) as obligatoires, 
            SUM(CASE WHEN type_tontine = 'optionnel' THEN 1 ELSE 0 END) as optionnelles, 
            SUM(CASE WHEN statut = 'active' THEN 1 ELSE 0 END) as actives, 
            SUM(CASE WHEN statut = 'inactive' THEN 1 ELSE 0 END) as inactives, 
            SUM(CASE WHEN statut = 'pending' THEN 1 ELSE 0 END) as en_attente 
            FROM tontine");
        $stats['tontines'] = $stmt->fetch();

        // 3. Statistiques cotisations
        $stmt = $pdo->query("SELECT COUNT(*) as total, 
            COALESCE(SUM(montant), 0) as montant_total, 
            COALESCE(SUM(CASE WHEN statut = 'payé' THEN montant ELSE 0 END), 0) as paye, 
            COALESCE(SUM(CASE WHEN statut = 'impayé' THEN montant ELSE 0 END), 0) as impaye, 
            COALESCE(SUM(CASE WHEN statut = 'retard' THEN montant ELSE 0 END), 0) as retard 
            FROM cotisation");
        $stats['cotisations'] = $stmt->fetch();

        // 4. Statistiques séances
        $stmt = $pdo->query("SELECT COUNT(*) as total, 
            COALESCE(AVG(montant_total), 0) as montant_moyen, 
            COALESCE(SUM(montant_total), 0) as montant_total 
            FROM seance");
        $stats['seances'] = $stmt->fetch();

        // 5. Statistiques crédits
        $stmt = $pdo->query("SELECT COUNT(*) as total, 
            COALESCE(SUM(montant), 0) as montant_total, 
            COALESCE(SUM(montant_restant), 0) as reste_total, 
            COALESCE(SUM(CASE WHEN statut = 'en_cours' THEN 1 ELSE 0 END), 0) as en_cours, 
            COALESCE(SUM(CASE WHEN statut = 'en_retard' THEN 1 ELSE 0 END), 0) as en_retard, 
            COALESCE(AVG(taux_interet), 0) as taux_moyen 
            FROM credit");
        $stats['credits'] = $stmt->fetch();

        // 6. Statistiques bénéficiaires
        $stmt = $pdo->query("SELECT COUNT(*) as total, 
            COALESCE(SUM(montant_gagne), 0) as montant_total, 
            COALESCE(AVG(montant_gagne), 0) as montant_moyen 
            FROM beneficiaire");
        $stats['beneficiaires'] = $stmt->fetch();

        // 7. Statistiques pénalités
        $stmt = $pdo->query("SELECT COUNT(*) as total, 
            COALESCE(SUM(montant), 0) as montant_total, 
            COALESCE(AVG(montant), 0) as montant_moyen 
            FROM penalite");
        $stats['penalites'] = $stmt->fetch();

        // 8. Statistiques projets FIAC
        $stmt = $pdo->query("SELECT COUNT(*) as total, 
            COALESCE(SUM(montant_budget), 0) as budget_total, 
            COALESCE(SUM(CASE WHEN statut = 'actif' THEN 1 ELSE 0 END), 0) as actifs, 
            COALESCE(SUM(CASE WHEN statut = 'planifié' THEN 1 ELSE 0 END), 0) as planifies, 
            COALESCE(SUM(CASE WHEN statut = 'en_étude' THEN 1 ELSE 0 END), 0) as en_etude 
            FROM projet_fiac");
        $stats['projets'] = $stmt->fetch();

        // 9. Top 5 membres avec plus de gains
        $stmt = $pdo->query("SELECT m.prenom, m.nom, 
            COALESCE(SUM(b.montant_gagne), 0) as total_gains 
            FROM beneficiaire b 
            JOIN membre m ON b.id_membre = m.id_membre 
            GROUP BY b.id_membre 
            ORDER BY total_gains DESC 
            LIMIT 5");
        $stats['top_gagnants'] = $stmt->fetchAll();

        // 10. Top 5 membres avec crédits en retard
        $stmt = $pdo->query("SELECT m.prenom, m.nom, 
            COUNT(c.id_credit) as nb_credits_retard, 
            COALESCE(SUM(c.montant_restant), 0) as total_reste 
            FROM credit c 
            JOIN membre m ON c.id_membre = m.id_membre 
            WHERE c.statut = 'en_retard' 
            GROUP BY c.id_membre 
            ORDER BY total_reste DESC 
            LIMIT 5");
        $stats['retardataires'] = $stmt->fetchAll();

        // 11. Évolution mensuelle des cotisations (derniers 6 mois)
        $stmt = $pdo->query("SELECT DATE_FORMAT(date_paiement, '%Y-%m') as mois, 
            COUNT(*) as nb_cotisations, 
            COALESCE(SUM(montant), 0) as montant_total 
            FROM cotisation 
            WHERE date_paiement IS NOT NULL 
            AND date_paiement >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) 
            GROUP BY DATE_FORMAT(date_paiement, '%Y-%m') 
            ORDER BY mois");
        $stats['evolution_cotisations'] = $stmt->fetchAll();

        // 12. Taux de participation par tontine
        $stmt = $pdo->query("SELECT t.nom_tontine, 
            COUNT(DISTINCT p.id_membre) as participants, 
            t.participants_max, 
            ROUND((COUNT(DISTINCT p.id_membre) * 100.0 / NULLIF(t.participants_max, 0)), 1) as taux_participation 
            FROM tontine t 
            LEFT JOIN participation_tontine p ON t.id_tontine = p.id_tontine AND p.statut = 'active' 
            GROUP BY t.id_tontine");
        $stats['participation_tontines'] = $stmt->fetchAll();

    } catch (PDOException $e) {
        die("Erreur SQL : " . $e->getMessage());
    }
    return $stats;
}

// Fonction pour générer le PDF avec TCPDF
function generatePDF($stats) {
    // Inclusion de TCPDF
    require_once('tcpdf/tcpdf.php');
    
    // Calcul des taux
    $taux_activite = $stats['membres']['total'] > 0 ? 
        round(($stats['membres']['actifs'] / $stats['membres']['total']) * 100, 1) : 0;
    $taux_recouvrement = $stats['cotisations']['montant_total'] > 0 ? 
        round(($stats['cotisations']['paye'] / $stats['cotisations']['montant_total']) * 100, 1) : 0;
    
    // Création d'une nouvelle instance de TCPDF
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    
    // Définir les informations du document
    $pdf->SetCreator('Système de Gestion de Tontine');
    $pdf->SetAuthor('Administrateur Tontine');
    $pdf->SetTitle('Rapport Statistique Tontine');
    $pdf->SetSubject('Statistiques Globales');
    
    // Supprimer l'en-tête et le pied de page par défaut
    $pdf->setPrintHeader(true);
    $pdf->setPrintFooter(true);
    
    // Définir les marges
    $pdf->SetMargins(15, 25, 15);
    $pdf->SetHeaderMargin(10);
    $pdf->SetFooterMargin(15);
    
    // Définir un saut de page automatique
    $pdf->SetAutoPageBreak(TRUE, 25);
    
    // Ajouter une page
    $pdf->AddPage();
    
    // Définir l'en-tête personnalisé
    $pdf->SetHeaderData('', 0, 'Rapport Statistique Tontine', 'Généré le ' . date('d/m/Y à H:i'));
    
    // Définir la police
    $pdf->SetFont('helvetica', '', 10);
    
    // Titre principal
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'Rapport Statistique Complet', 0, 1, 'C');
    $pdf->Ln(5);
    
    // Résumé exécutif
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'Résumé Exécutif', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    
    // Tableau de résumé
    $summary = '<table border="1" cellpadding="5" cellspacing="0" style="background-color:#f8f9fa;">
        <tr>
            <td width="25%" style="text-align:center;"><b>' . number_format($stats['membres']['total']) . '</b><br/>Membres Totaux</td>
            <td width="25%" style="text-align:center;"><b>' . number_format($stats['cotisations']['montant_total']) . ' FCFA</b><br/>Cotisations Total</td>
            <td width="25%" style="text-align:center;"><b>' . $taux_activite . '%</b><br/>Taux d\'Activité</td>
            <td width="25%" style="text-align:center;"><b>' . $taux_recouvrement . '%</b><br/>Taux de Recouvrement</td>
        </tr>
    </table>';
    
    $pdf->writeHTML($summary, true, false, true, false, '');
    $pdf->Ln(10);
    
    // 1. Statistiques Générales
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, '1. Statistiques Générales', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    
    $general_stats = '<table border="1" cellpadding="5" cellspacing="0" width="100%">
        <tr style="background-color:#e8f4fc;">
            <td width="50%"><b>Membres Actifs:</b></td>
            <td width="50%">' . $stats['membres']['actifs'] . ' / ' . $stats['membres']['total'] . '</td>
        </tr>
        <tr>
            <td><b>Administrateurs:</b></td>
            <td>' . $stats['membres']['admins'] . '</td>
        </tr>
        <tr style="background-color:#e8f4fc;">
            <td><b>Tontines Actives:</b></td>
            <td>' . $stats['tontines']['actives'] . ' / ' . $stats['tontines']['total'] . '</td>
        </tr>
        <tr>
            <td><b>Séances Réalisées:</b></td>
            <td>' . $stats['seances']['total'] . '</td>
        </tr>
        <tr style="background-color:#e8f4fc;">
            <td><b>Projets FIAC:</b></td>
            <td>' . $stats['projets']['total'] . '</td>
        </tr>
        <tr>
            <td><b>Budget Total Projets:</b></td>
            <td>' . number_format($stats['projets']['budget_total']) . ' FCFA</td>
        </tr>
    </table>';
    
    $pdf->writeHTML($general_stats, true, false, true, false, '');
    $pdf->Ln(10);
    
    // 2. Données Financières
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, '2. Données Financières', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    
    // Cotisations
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 8, 'Cotisations', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    
    $cotisations = '<table border="1" cellpadding="5" cellspacing="0" width="100%">
        <tr style="background-color:#3498db;color:white;">
            <th width="40%">Type</th>
            <th width="30%">Montant (FCFA)</th>
            <th width="30%">Pourcentage</th>
        </tr>
        <tr>
            <td>Payées</td>
            <td>' . number_format($stats['cotisations']['paye']) . '</td>
            <td style="color:#27ae60;"><b>' . $taux_recouvrement . '%</b></td>
        </tr>
        <tr style="background-color:#f9f9f9;">
            <td>Impayées</td>
            <td>' . number_format($stats['cotisations']['impaye']) . '</td>
            <td style="color:#e74c3c;"><b>' . round(($stats['cotisations']['impaye'] / max($stats['cotisations']['montant_total'], 1)) * 100, 1) . '%</b></td>
        </tr>
        <tr>
            <td>En retard</td>
            <td>' . number_format($stats['cotisations']['retard']) . '</td>
            <td style="color:#f39c12;"><b>' . round(($stats['cotisations']['retard'] / max($stats['cotisations']['montant_total'], 1)) * 100, 1) . '%</b></td>
        </tr>
        <tr style="background-color:#2c3e50;color:white;">
            <td><b>TOTAL</b></td>
            <td><b>' . number_format($stats['cotisations']['montant_total']) . '</b></td>
            <td><b>100%</b></td>
        </tr>
    </table>';
    
    $pdf->writeHTML($cotisations, true, false, true, false, '');
    $pdf->Ln(8);
    
    // Crédits
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 8, 'Crédits', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    
    $credits_table = '<table border="1" cellpadding="5" cellspacing="0" width="100%">
        <tr style="background-color:#f8f9fa;">
            <td width="50%"><b>Crédits Actifs:</b></td>
            <td width="50%">' . $stats['credits']['en_cours'] . '</td>
        </tr>
        <tr>
            <td><b>Montant Total:</b></td>
            <td>' . number_format($stats['credits']['montant_total']) . ' FCFA</td>
        </tr>
        <tr style="background-color:#f8f9fa;">
            <td><b>Crédits en Retard:</b></td>
            <td>' . $stats['credits']['en_retard'] . '</td>
        </tr>
        <tr>
            <td><b>Reste à Payer:</b></td>
            <td>' . number_format($stats['credits']['reste_total']) . ' FCFA</td>
        </tr>
        <tr style="background-color:#f8f9fa;">
            <td><b>Taux Intérêt Moyen:</b></td>
            <td>' . number_format($stats['credits']['taux_moyen'], 1) . '%</td>
        </tr>
    </table>';
    
    $pdf->writeHTML($credits_table, true, false, true, false, '');
    $pdf->Ln(8);
    
    // Gains et Pénalités
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 8, 'Gains et Pénalités', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    
    $gains_table = '<table border="1" cellpadding="5" cellspacing="0" width="100%">
        <tr style="background-color:#f8f9fa;">
            <td width="50%"><b>Total Gains Distribués:</b></td>
            <td width="50%">' . number_format($stats['beneficiaires']['montant_total']) . ' FCFA</td>
        </tr>
        <tr>
            <td><b>Nombre Bénéficiaires:</b></td>
            <td>' . $stats['beneficiaires']['total'] . '</td>
        </tr>
        <tr style="background-color:#f8f9fa;">
            <td><b>Pénalités Collectées:</b></td>
            <td>' . number_format($stats['penalites']['montant_total']) . ' FCFA</td>
        </tr>
        <tr>
            <td><b>Moyenne Pénalité:</b></td>
            <td>' . number_format($stats['penalites']['montant_moyen']) . ' FCFA</td>
        </tr>
    </table>';
    
    $pdf->writeHTML($gains_table, true, false, true, false, '');
    
    // Nouvelle page si nécessaire
    $pdf->AddPage();
    
    // 3. Classements
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, '3. Classements', 0, 1, 'L');
    
    // Top 5 Gagnants
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 8, 'Top 5 Gagnants', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    
    $top_gagnants = '<table border="1" cellpadding="5" cellspacing="0" width="100%">
        <tr style="background-color:#2ecc71;color:white;">
            <th width="15%">#</th>
            <th width="55%">Membre</th>
            <th width="30%">Total Gains (FCFA)</th>
        </tr>';
    
    if (count($stats['top_gagnants']) > 0) {
        foreach ($stats['top_gagnants'] as $index => $gagnant) {
            $top_gagnants .= '<tr>';
            $top_gagnants .= '<td>' . ($index + 1) . '</td>';
            $top_gagnants .= '<td>' . htmlspecialchars($gagnant['prenom'] . ' ' . $gagnant['nom']) . '</td>';
            $top_gagnants .= '<td><b>' . number_format($gagnant['total_gains']) . '</b></td>';
            $top_gagnants .= '</tr>';
        }
    } else {
        $top_gagnants .= '<tr><td colspan="3" style="text-align:center;">Aucun gagnant pour le moment</td></tr>';
    }
    
    $top_gagnants .= '</table>';
    $pdf->writeHTML($top_gagnants, true, false, true, false, '');
    $pdf->Ln(8);
    
    // Membres avec Crédits en Retard
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 8, 'Membres avec Crédits en Retard', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    
    if (count($stats['retardataires']) > 0) {
        $retard_table = '<table border="1" cellpadding="5" cellspacing="0" width="100%">
            <tr style="background-color:#e74c3c;color:white;">
                <th width="40%">Membre</th>
                <th width="20%">Nb Crédits</th>
                <th width="40%">Montant Restant (FCFA)</th>
            </tr>';
        
        foreach ($stats['retardataires'] as $retardataire) {
            $retard_table .= '<tr>';
            $retard_table .= '<td>' . htmlspecialchars($retardataire['prenom'] . ' ' . $retardataire['nom']) . '</td>';
            $retard_table .= '<td>' . $retardataire['nb_credits_retard'] . '</td>';
            $retard_table .= '<td><b>' . number_format($retardataire['total_reste']) . '</b></td>';
            $retard_table .= '</tr>';
        }
        
        $retard_table .= '</table>';
        $pdf->writeHTML($retard_table, true, false, true, false, '');
    } else {
        $pdf->Cell(0, 8, 'Aucun crédit en retard.', 0, 1, 'L');
    }
    
    $pdf->Ln(8);
    
    // 4. Évolution Mensuelle
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, '4. Évolution Mensuelle (6 derniers mois)', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    
    $evolution_table = '<table border="1" cellpadding="5" cellspacing="0" width="100%">
        <tr style="background-color:#3498db;color:white;">
            <th width="40%">Mois</th>
            <th width="30%">Nombre Cotisations</th>
            <th width="30%">Montant Total (FCFA)</th>
        </tr>';
    
    foreach ($stats['evolution_cotisations'] as $ev) {
        $evolution_table .= '<tr>';
        $evolution_table .= '<td>' . date('M Y', strtotime($ev['mois'] . '-01')) . '</td>';
        $evolution_table .= '<td>' . $ev['nb_cotisations'] . '</td>';
        $evolution_table .= '<td>' . number_format($ev['montant_total']) . '</td>';
        $evolution_table .= '</tr>';
    }
    
    $evolution_table .= '</table>';
    $pdf->writeHTML($evolution_table, true, false, true, false, '');
    
    // Nouvelle page si nécessaire
    $pdf->AddPage();
    
    // 5. Participation par Tontine
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, '5. Participation par Tontine', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    
    $participation_table = '<table border="1" cellpadding="5" cellspacing="0" width="100%">
        <tr style="background-color:#9b59b6;color:white;">
            <th width="50%">Nom Tontine</th>
            <th width="15%">Participants</th>
            <th width="15%">Maximum</th>
            <th width="20%">Taux</th>
        </tr>';
    
    foreach ($stats['participation_tontines'] as $tontine) {
        $taux = $tontine['taux_participation'];
        $color = $taux >= 80 ? '#27ae60' : ($taux >= 60 ? '#f39c12' : '#e74c3c');
        
        $participation_table .= '<tr>';
        $participation_table .= '<td>' . htmlspecialchars($tontine['nom_tontine']) . '</td>';
        $participation_table .= '<td>' . $tontine['participants'] . '</td>';
        $participation_table .= '<td>' . $tontine['participants_max'] . '</td>';
        $participation_table .= '<td style="color:' . $color . ';"><b>' . $taux . '%</b></td>';
        $participation_table .= '</tr>';
    }
    
    $participation_table .= '</table>';
    $pdf->writeHTML($participation_table, true, false, true, false, '');
    $pdf->Ln(10);
    
    // 6. Analyse des Performances
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, '6. Analyse des Performances', 0, 1, 'L');
    
    $performance_table = '<table border="0" cellpadding="10" cellspacing="0" width="100%" style="border:1px solid #ddd;">
        <tr>
            <td width="33%" style="text-align:center;border-right:1px solid #ddd;">
                <span style="font-size:24px;font-weight:bold;color:#27ae60;">' . $taux_activite . '%</span><br/>
                <span style="font-size:10px;">Taux d\'Activité</span>
            </td>
            <td width="33%" style="text-align:center;border-right:1px solid #ddd;">
                <span style="font-size:24px;font-weight:bold;color:' . ($taux_recouvrement >= 90 ? '#27ae60' : ($taux_recouvrement >= 70 ? '#f39c12' : '#e74c3c')) . ';">' . $taux_recouvrement . '%</span><br/>
                <span style="font-size:10px;">Taux de Recouvrement</span>
            </td>
            <td width="33%" style="text-align:center;">
                <span style="font-size:24px;font-weight:bold;color:#3498db;">' . number_format($stats['credits']['taux_moyen'], 1) . '%</span><br/>
                <span style="font-size:10px;">Taux Intérêt Moyen</span>
            </td>
        </tr>
    </table>';
    
    $pdf->writeHTML($performance_table, true, false, true, false, '');
    $pdf->Ln(10);
    
    // Conclusion
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 10, 'Conclusion', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    
    $conclusion = '<div style="background-color:#f8f9fa;padding:15px;border:1px solid #ddd;border-radius:5px;">
        <p><b>Conclusion:</b> Ce rapport présente l\'état complet du système de tontine au ' . date('d/m/Y') . '.</p>
        <p><b>Points Forts:</b> ' . ($taux_activite > 70 ? 'Fort taux d\'engagement des membres' : 'Besoin d\'améliorer l\'engagement') . '</p>
        <p><b>Points à Surveiller:</b> ' . ($taux_recouvrement < 90 ? 'Améliorer le recouvrement des cotisations' : 'Bon taux de recouvrement') . '</p>
        <p><b>Recommandations:</b> Surveiller les crédits en retard et optimiser la participation aux tontines.</p>
    </div>';
    
    $pdf->writeHTML($conclusion, true, false, true, false, '');
    $pdf->Ln(10);
    
    // Signature et date
    $pdf->SetFont('helvetica', 'I', 9);
    $pdf->Cell(0, 5, '--- Fin du Rapport ---', 0, 1, 'C');
    $pdf->Cell(0, 5, 'Document généré automatiquement par le système de gestion de tontine', 0, 1, 'C');
    $pdf->Cell(0, 5, '© ' . date('Y') . ' - Tous droits réservés', 0, 1, 'C');
    
    // Générer et télécharger le PDF
    $filename = 'rapport_statistiques_' . date('Y-m-d') . '.pdf';
    $pdf->Output($filename, 'D');
    exit();
}

// Récupérer les statistiques
$stats = getGlobalStats($pdo);

// Calculs des taux
$taux_activite = $stats['membres']['total'] > 0 ? 
    round(($stats['membres']['actifs'] / $stats['membres']['total']) * 100, 1) : 0;
$taux_recouvrement = $stats['cotisations']['montant_total'] > 0 ? 
    round(($stats['cotisations']['paye'] / $stats['cotisations']['montant_total']) * 100, 1) : 0;

// Gestion de l'export PDF
if (isset($_GET['export']) && $_GET['export'] == 'pdf') {
    generatePDF($stats);
    exit();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de Bord - Statistiques Tontine</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
       * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #0f1a3a 0%, #1a2b55 100%);
            min-height: 100vh;
            padding: 10px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .header {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 15px;
        }

        .header-title h1 {
            color: #1e293b;
            font-size: 1.5rem;
            margin-bottom: 5px;
        }

        .header-title p {
            color: #64748b;
            font-size: 0.85rem;
        }

        .header-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            width: 100%;
            max-width: 400px;
        }

        .btn {
            padding: 10px 15px;
            border: none;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            flex: 1;
            min-width: 120px;
            justify-content: center;
            text-align: center;
            white-space: nowrap;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: #f1f5f9;
            color: #475569;
        }

        .btn-secondary:hover {
            background: #e2e8f0;
        }

        .dashboard-grid {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        @media (min-width: 768px) {
            .dashboard-grid {
                display: grid;
                grid-template-columns: 1fr;
            }
        }

        @media (min-width: 992px) {
            .dashboard-grid {
                grid-template-columns: 3fr 1fr;
            }
        }

        .main-content {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .sidebar {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        /* Ordre d'affichage pour mobile */
        @media (max-width: 991px) {
            .sidebar {
                order: -1;
            }
        }

        .stats-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 15px;
        }

        @media (min-width: 640px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            min-width: 0;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--card-color-1), var(--card-color-2));
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .stat-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .stat-icon {
            width: 45px;
            height: 45px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            background: linear-gradient(135deg, var(--card-color-1), var(--card-color-2));
            color: white;
            flex-shrink: 0;
        }

        .stat-trend {
            font-size: 0.8rem;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 15px;
            white-space: nowrap;
        }

        .trend-up {
            background: #dcfce7;
            color: #16a34a;
        }

        .trend-down {
            background: #fee2e2;
            color: #dc2626;
        }

        .stat-label {
            color: #64748b;
            font-size: 0.85rem;
            font-weight: 500;
            margin-bottom: 6px;
            word-wrap: break-word;
        }

        .stat-value {
            color: #1e293b;
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 4px;
            line-height: 1.2;
            overflow-wrap: break-word;
        }

        @media (min-width: 768px) {
            .stat-value {
                font-size: 1.8rem;
            }
        }

        .stat-subtitle {
            color: #94a3b8;
            font-size: 0.75rem;
            line-height: 1.3;
        }

        .charts-section,
        .tables-section,
        .performance-section,
        .quick-stats,
        .summary-section {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            width: 100%;
            overflow: hidden;
        }

        .charts-section h2,
        .tables-section h2,
        .performance-section h2,
        .quick-stats h2,
        .summary-section h2 {
            color: #1e293b;
            font-size: 1.1rem;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .charts-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }

        @media (min-width: 768px) {
            .charts-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        .chart-card {
            min-height: 300px;
            display: flex;
            flex-direction: column;
            width: 100%;
        }

        @media (min-width: 768px) {
            .chart-card {
                height: 350px;
            }
        }
        @media (max-width: 768px) {
            .canvas-container{
                width: 90%;
            }
        }

        .chart-card h3 {
            color: #1e293b;
            font-size: 1rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }

        .chart-card h3 i {
            color: #667eea;
        }

        .canvas-container {
            flex: 1;
            position: relative;
            min-height: 250px;
            width: 100%;
        }

        @media (max-width: 767px) {
            .canvas-container {
                min-height: 200px;
            }
        }

        .tables-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
            width: 100%;
        }

        .table-responsive {
            overflow-x: auto;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            -webkit-overflow-scrolling: touch;
            width: 100%;
            margin: 0 auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 500px;
            font-size: 0.9rem;
        }

        @media (max-width: 767px) {
            table {
                font-size: 0.85rem;
                min-width: 350px;
            }
        }

        th, td {
            padding: 12px 10px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
            word-break: break-word;
        }

        @media (max-width: 576px) {
            th, td {
                padding: 10px 8px;
            }
        }

        th {
            background: #f8fafc;
            color: #475569;
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }

        td {
            color: #64748b;
            line-height: 1.4;
        }

        tr:hover {
            background: #f8fafc;
        }

        .badge {
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
            white-space: nowrap;
        }

        .performance-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }

        @media (max-width: 480px) {
            .performance-grid {
                grid-template-columns: 1fr;
            }
        }

        .performance-card {
            padding: 15px;
            text-align: center;
            border-radius: 12px;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            min-width: 0;
        }

        .performance-value {
            font-size: 1.8rem;
            font-weight: 700;
            margin: 8px 0;
            line-height: 1.2;
            word-break: break-word;
        }

        @media (min-width: 768px) {
            .performance-value {
                font-size: 2.5rem;
            }
        }

        .performance-label {
            color: #64748b;
            font-size: 0.8rem;
            font-weight: 500;
            line-height: 1.3;
        }

        .quick-stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }

        @media (max-width: 480px) {
            .quick-stats-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (min-width: 768px) {
            .quick-stats-grid {
                grid-template-columns: 1fr;
            }
        }

        .quick-stat {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            background: #f8fafc;
            border-radius: 8px;
            flex-wrap: wrap;
            gap: 8px;
        }

        .quick-stat-label {
            color: #64748b;
            font-size: 0.85rem;
            flex: 1;
            min-width: 120px;
        }

        .quick-stat-value {
            color: #1e293b;
            font-weight: 600;
            font-size: 1rem;
            white-space: nowrap;
        }

        @media (min-width: 768px) {
            .quick-stat {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }
            
            .quick-stat-label {
                min-width: auto;
            }
        }

        .summary-content {
            color: #64748b;
            line-height: 1.5;
            font-size: 0.9rem;
        }

        .summary-item {
            margin-bottom: 12px;
            padding-left: 15px;
            border-left: 3px solid #667eea;
            word-break: break-word;
        }

        /* Modal Responsive */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            padding: 15px;
            overflow-y: auto;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 15px;
            padding: 25px;
            width: 100%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .modal-header h2 {
            color: #1e293b;
            font-size: 1.3rem;
            flex: 1;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #94a3b8;
            transition: color 0.3s;
            flex-shrink: 0;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Optimisations pour très petits écrans */
        @media (max-width: 360px) {
            .btn {
                padding: 8px 12px;
                font-size: 0.8rem;
                min-width: 100px;
            }
            
            .stat-value {
                font-size: 1.3rem;
            }
            
            .header-title h1 {
                font-size: 1.3rem;
            }
            
            .charts-section,
            .tables-section,
            .performance-section,
            .quick-stats,
            .summary-section {
                padding: 15px;
            }
        }

        /* Gestion du paysage mobile */
        @media (max-height: 600px) and (orientation: landscape) {
            body {
                padding: 5px;
            }
            
            .header {
                padding: 15px;
            }
            
            .canvas-container {
                min-height: 180px;
            }
        }

        /* Amélioration de l'accessibilité tactile */
        @media (hover: none) and (pointer: coarse) {
            .btn {
                min-height: 44px;
            }
            
            th, td {
                padding: 14px 10px;
            }
            
            .stat-card {
                padding: 18px;
            }
        }

        /* Colors for cards */
        .stat-card:nth-child(1) { --card-color-1: #3b82f6; --card-color-2: #2563eb; }
        .stat-card:nth-child(2) { --card-color-1: #10b981; --card-color-2: #059669; }
        .stat-card:nth-child(3) { --card-color-1: #f59e0b; --card-color-2: #d97706; }
        .stat-card:nth-child(4) { --card-color-1: #8b5cf6; --card-color-2: #7c3aed; }
        .stat-card:nth-child(5) { --card-color-1: #ec4899; --card-color-2: #db2777; }
        .stat-card:nth-child(6) { --card-color-1: #06b6d4; --card-color-2: #0891b2; }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1><i class="fas fa-chart-line"></i> Tableau de Bord Global</h1>
                    <p>Statistiques complètes de la tontine • <?php echo date('d/m/Y à H:i'); ?></p>
                </div>
                <div class="header-actions">
                    <button class="btn btn-primary" onclick="openPDFModal()">
                        <i class="fas fa-file-pdf"></i> Télécharger PDF
                    </button>
                    <button class="btn btn-secondary" onclick="window.print()">
                        <i class="fas fa-print"></i> Imprimer
                    </button>
                    <a href="dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Retour
                    </a>
                </div>
            </div>
        </div>

        <!-- Main Dashboard Grid -->
        <div class="dashboard-grid">
            <!-- Main Content -->
            <div class="main-content">
                <!-- Stats Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-icon">
                                <i class="fas fa-users"></i>
                            </div>
                            <span class="stat-trend trend-up">
                                <i class="fas fa-arrow-up"></i> <?php echo $taux_activite; ?>%
                            </span>
                        </div>
                        <div class="stat-label">Membres Totaux</div>
                        <div class="stat-value"><?php echo number_format($stats['membres']['total']); ?></div>
                        <div class="stat-subtitle"><?php echo $stats['membres']['actifs']; ?> actifs • <?php echo $stats['membres']['admins']; ?> admins</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-icon">
                                <i class="fas fa-wallet"></i>
                            </div>
                            <span class="stat-trend trend-up">
                                <i class="fas fa-arrow-up"></i> <?php echo $taux_recouvrement; ?>%
                            </span>
                        </div>
                        <div class="stat-label">Cotisations Collectées</div>
                        <div class="stat-value"><?php echo number_format($stats['cotisations']['paye']); ?> FCFA</div>
                        <div class="stat-subtitle"><?php echo $stats['cotisations']['total']; ?> transactions</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-icon">
                                <i class="fas fa-hand-holding-usd"></i>
                            </div>
                            <span class="stat-trend <?php echo $stats['credits']['en_retard'] > 0 ? 'trend-down' : ''; ?>">
                                <i class="fas <?php echo $stats['credits']['en_retard'] > 0 ? 'fa-arrow-down' : 'fa-check'; ?>"></i> 
                                <?php echo $stats['credits']['en_retard']; ?>
                            </span>
                        </div>
                        <div class="stat-label">Crédits Actifs</div>
                        <div class="stat-value"><?php echo $stats['credits']['en_cours']; ?></div>
                        <div class="stat-subtitle"><?php echo number_format($stats['credits']['montant_total']); ?> FCFA empruntés</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-icon">
                                <i class="fas fa-trophy"></i>
                            </div>
                        </div>
                        <div class="stat-label">Total Gains Distribués</div>
                        <div class="stat-value"><?php echo number_format($stats['beneficiaires']['montant_total']); ?> FCFA</div>
                        <div class="stat-subtitle"><?php echo $stats['beneficiaires']['total']; ?> bénéficiaires</div>
                    </div>
                </div>

                <!-- Charts Section -->
                <div class="charts-section">
                    <h2><i class="fas fa-chart-pie"></i> Visualisations</h2>
                    <div class="charts-grid">
                        <div class="chart-card">
                            <h3><i class="fas fa-chart-pie"></i> Répartition des Cotisations</h3>
                            <div class="canvas-container">
                                <canvas id="cotisationsChart"></canvas>
                            </div>
                        </div>

                        <div class="chart-card">
                            <h3><i class="fas fa-chart-area"></i> Évolution Mensuelle</h3>
                            <div class="canvas-container">
                                <canvas id="evolutionChart"></canvas>
                            </div>
                        </div>

                        <div class="chart-card">
                            <h3><i class="fas fa-users-cog"></i> Répartition des Membres</h3>
                            <div class="canvas-container">
                                <canvas id="membresChart"></canvas>
                            </div>
                        </div>

                        <div class="chart-card">
                            <h3><i class="fas fa-chart-bar"></i> Taux de Participation</h3>
                            <div class="canvas-container">
                                <canvas id="participationChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Performance Indicators -->
                <div class="performance-section">
                    <h2><i class="fas fa-tachometer-alt"></i> Indicateurs de Performance</h2>
                    <div class="performance-grid">
                        <div class="performance-card">
                            <div class="performance-value" style="color: #10b981;"><?php echo $taux_activite; ?>%</div>
                            <div class="performance-label">Taux d'Activité</div>
                        </div>
                        <div class="performance-card">
                            <div class="performance-value" style="color: <?php echo $taux_recouvrement >= 90 ? '#10b981' : ($taux_recouvrement >= 70 ? '#f59e0b' : '#ef4444'); ?>">
                                <?php echo $taux_recouvrement; ?>%
                            </div>
                            <div class="performance-label">Taux de Recouvrement</div>
                        </div>
                        <div class="performance-card">
                            <div class="performance-value" style="color: #3b82f6;"><?php echo number_format($stats['credits']['taux_moyen'], 1); ?>%</div>
                            <div class="performance-label">Taux Intérêt Moyen</div>
                        </div>
                        <div class="performance-card">
                            <div class="performance-value" style="color: #8b5cf6;">
                                <?php echo $stats['seances']['total'] > 0 ? number_format($stats['seances']['montant_moyen']) : 0; ?> FCFA
                            </div>
                            <div class="performance-label">Moyenne par Séance</div>
                        </div>
                    </div>
                </div>

                <!-- Tables Section -->
                <div class="tables-section">
                    <h2><i class="fas fa-table"></i> Données Détailées</h2>
                    <div class="tables-grid">
                        <!-- Top Gagnants -->
                        <div>
                            <h3 style="color: #1e293b; margin-bottom: 15px; font-size: 1.1rem;">
                                <i class="fas fa-trophy"></i> Top 5 Gagnants
                            </h3>
                            <div class="table-responsive">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Rang</th>
                                            <th>Membre</th>
                                            <th>Total Gains</th>
                                            <th>Statut</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($stats['top_gagnants']) > 0): ?>
                                            <?php foreach ($stats['top_gagnants'] as $index => $gagnant): ?>
                                                <tr>
                                                    <td><strong><?php echo $index + 1; ?></strong></td>
                                                    <td><?php echo htmlspecialchars($gagnant['prenom'] . ' ' . $gagnant['nom']); ?></td>
                                                    <td><strong><?php echo number_format($gagnant['total_gains']); ?> FCFA</strong></td>
                                                    <td>
                                                        <?php if ($index === 0): ?>
                                                            <span class="badge badge-warning"><i class="fas fa-crown"></i> 1er</span>
                                                        <?php else: ?>
                                                            <span class="badge badge-success"><i class="fas fa-medal"></i> Top <?php echo $index + 1; ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="4" style="text-align: center; color: #94a3b8;">Aucun gagnant pour le moment</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Retardataires Crédits -->
                        <?php if (count($stats['retardataires']) > 0): ?>
                        <div>
                            <h3 style="color: #1e293b; margin-bottom: 15px; font-size: 1.1rem;">
                                <i class="fas fa-exclamation-triangle"></i> Crédits en Retard
                            </h3>
                            <div class="table-responsive">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Membre</th>
                                            <th>Nb Crédits</th>
                                            <th>Montant Restant</th>
                                            <th>Alerte</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($stats['retardataires'] as $retardataire): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($retardataire['prenom'] . ' ' . $retardataire['nom']); ?></td>
                                                <td><?php echo $retardataire['nb_credits_retard']; ?></td>
                                                <td><strong><?php echo number_format($retardataire['total_reste']); ?> FCFA</strong></td>
                                                <td><span class="badge badge-danger"><i class="fas fa-clock"></i> En retard</span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Participation par Tontine -->
                        <div>
                            <h3 style="color: #1e293b; margin-bottom: 15px; font-size: 1.1rem;">
                                <i class="fas fa-users"></i> Participation par Tontine
                            </h3>
                            <div class="table-responsive">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Nom Tontine</th>
                                            <th>Participants</th>
                                            <th>Maximum</th>
                                            <th>Taux</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($stats['participation_tontines'] as $tontine): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($tontine['nom_tontine']); ?></td>
                                                <td><?php echo $tontine['participants']; ?></td>
                                                <td><?php echo $tontine['participants_max']; ?></td>
                                                <td>
                                                    <?php 
                                                    $taux = $tontine['taux_participation'];
                                                    $badge_class = $taux >= 80 ? 'badge-success' : ($taux >= 60 ? 'badge-warning' : 'badge-danger');
                                                    ?>
                                                    <span class="badge <?php echo $badge_class; ?>"><?php echo $taux; ?>%</span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="sidebar">
                <!-- Quick Stats -->
                <div class="quick-stats">
                    <h2><i class="fas fa-bolt"></i> Vue Rapide</h2>
                    <div class="quick-stats-grid">
                        <div class="quick-stat">
                            <span class="quick-stat-label">Tontines Actives</span>
                            <span class="quick-stat-value"><?php echo $stats['tontines']['actives']; ?></span>
                        </div>
                        <div class="quick-stat">
                            <span class="quick-stat-label">Séances</span>
                            <span class="quick-stat-value"><?php echo $stats['seances']['total']; ?></span>
                        </div>
                        <div class="quick-stat">
                            <span class="quick-stat-label">Projets FIAC</span>
                            <span class="quick-stat-value"><?php echo $stats['projets']['total']; ?></span>
                        </div>
                        <div class="quick-stat">
                            <span class="quick-stat-label">Pénalités</span>
                            <span class="quick-stat-value"><?php echo $stats['penalites']['total']; ?></span>
                        </div>
                        <div class="quick-stat">
                            <span class="quick-stat-label">Cotisations Impayées</span>
                            <span class="quick-stat-value"><?php echo number_format($stats['cotisations']['impaye']); ?> FCFA</span>
                        </div>
                        <div class="quick-stat">
                            <span class="quick-stat-label">Gain Moyen</span>
                            <span class="quick-stat-value"><?php echo number_format($stats['beneficiaires']['montant_moyen']); ?> FCFA</span>
                        </div>
                    </div>
                </div>

                <!-- Summary -->
                <div class="summary-section">
                    <h2><i class="fas fa-clipboard-list"></i> Résumé</h2>
                    <div class="summary-content">
                        <div class="summary-item">
                            <strong>Total Membres:</strong> <?php echo number_format($stats['membres']['total']); ?><br>
                            <small><?php echo $stats['membres']['actifs']; ?> actifs (<?php echo $taux_activite; ?>%)</small>
                        </div>
                        <div class="summary-item">
                            <strong>Cotisations Total:</strong> <?php echo number_format($stats['cotisations']['montant_total']); ?> FCFA<br>
                            <small><?php echo $taux_recouvrement; ?>% recouvrés</small>
                        </div>
                        <div class="summary-item">
                            <strong>Gains Distribués:</strong> <?php echo number_format($stats['beneficiaires']['montant_total']); ?> FCFA<br>
                            <small><?php echo $stats['beneficiaires']['total']; ?> bénéficiaires</small>
                        </div>
                        <div class="summary-item">
                            <strong>Activité du Mois:</strong><br>
                            <small><?php echo count($stats['evolution_cotisations']); ?> mois d'activité</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal PDF -->
    <div id="pdfModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-file-pdf"></i> Générer le Rapport PDF</h2>
                <button class="close-modal" onclick="closePDFModal()">×</button>
            </div>
            <p style="color: #64748b; margin-bottom: 25px;">
                Vous êtes sur le point de générer un rapport PDF complet contenant toutes les statistiques actuelles.
            </p>
            <div style="background: #f8fafc; padding: 20px; border-radius: 10px; margin-bottom: 25px;">
                <h4 style="color: #1e293b; margin-bottom: 15px;">Le rapport inclura :</h4>
                <ul style="color: #64748b; line-height: 2;">
                    <li><i class="fas fa-check" style="color: #10b981;"></i> Statistiques générales</li>
                    <li><i class="fas fa-check" style="color: #10b981;"></i> Données financières détaillées</li>
                    <li><i class="fas fa-check" style="color: #10b981;"></i> Top gagnants et retardataires</li>
                    <li><i class="fas fa-check" style="color: #10b981;"></i> Graphiques et tableaux</li>
                    <li><i class="fas fa-check" style="color: #10b981;"></i> Analyse des performances</li>
                </ul>
            </div>
            <div style="display: flex; gap: 10px;">
                <button class="btn btn-primary" onclick="downloadPDF()" style="flex: 1;">
                    <i class="fas fa-download"></i> Télécharger PDF
                </button>
                <button class="btn btn-secondary" onclick="closePDFModal()">
                    Annuler
                </button>
            </div>
        </div>
    </div>

    <script>
        // Données pour les graphiques
        const cotisationsData = {
            labels: ['Payées', 'Impayées', 'En Retard'],
            datasets: [{
                data: [
                    <?php echo $stats['cotisations']['paye']; ?>,
                    <?php echo $stats['cotisations']['impaye']; ?>,
                    <?php echo $stats['cotisations']['retard']; ?>
                ],
                backgroundColor: [
                    'rgba(16, 185, 129, 0.8)',
                    'rgba(245, 158, 11, 0.8)',
                    'rgba(239, 68, 68, 0.8)'
                ],
                borderWidth: 0
            }]
        };

        const evolutionData = {
            labels: [
                <?php foreach ($stats['evolution_cotisations'] as $ev): ?>
                    '<?php echo date('M Y', strtotime($ev['mois'] . '-01')); ?>',
                <?php endforeach; ?>
            ],
            datasets: [{
                label: 'Montant (FCFA)',
                data: [
                    <?php foreach ($stats['evolution_cotisations'] as $ev): ?>
                        <?php echo $ev['montant_total']; ?>,
                    <?php endforeach; ?>
                ],
                fill: true,
                backgroundColor: 'rgba(102, 126, 234, 0.1)',
                borderColor: 'rgba(102, 126, 234, 1)',
                tension: 0.4,
                pointBackgroundColor: 'rgba(102, 126, 234, 1)',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 5,
                pointHoverRadius: 7
            }]
        };

        const membresData = {
            labels: ['Actifs', 'Inactifs', 'Admins'],
            datasets: [{
                data: [
                    <?php echo $stats['membres']['actifs']; ?>,
                    <?php echo $stats['membres']['inactifs']; ?>,
                    <?php echo $stats['membres']['admins']; ?>
                ],
                backgroundColor: [
                    'rgba(59, 130, 246, 0.8)',
                    'rgba(148, 163, 184, 0.8)',
                    'rgba(139, 92, 246, 0.8)'
                ],
                borderWidth: 0
            }]
        };

        const participationData = {
            labels: [
                <?php foreach ($stats['participation_tontines'] as $pt): ?>
                    '<?php echo addslashes($pt['nom_tontine']); ?>',
                <?php endforeach; ?>
            ],
            datasets: [{
                label: 'Taux de Participation (%)',
                data: [
                    <?php foreach ($stats['participation_tontines'] as $pt): ?>
                        <?php echo $pt['taux_participation']; ?>,
                    <?php endforeach; ?>
                ],
                backgroundColor: 'rgba(139, 92, 246, 0.8)',
                borderRadius: 8,
                borderSkipped: false
            }]
        };

        // Configuration des graphiques
        const chartOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'bottom',
                    labels: {
                        padding: 15,
                        font: { size: 12 },
                        usePointStyle: true
                    }
                }
            }
        };

        // Initialisation des graphiques
        document.addEventListener('DOMContentLoaded', function() {
            new Chart(document.getElementById('cotisationsChart'), {
                type: 'doughnut',
                data: cotisationsData,
                options: {
                    ...chartOptions,
                    cutout: '70%',
                    plugins: {
                        ...chartOptions.plugins,
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.label + ': ' + context.parsed.toLocaleString() + ' FCFA';
                                }
                            }
                        }
                    }
                }
            });

            new Chart(document.getElementById('evolutionChart'), {
                type: 'line',
                data: evolutionData,
                options: {
                    ...chartOptions,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return value.toLocaleString() + ' FCFA';
                                }
                            }
                        }
                    },
                    plugins: {
                        ...chartOptions.plugins,
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return 'Montant: ' + context.parsed.y.toLocaleString() + ' FCFA';
                                }
                            }
                        }
                    }
                }
            });

            new Chart(document.getElementById('membresChart'), {
                type: 'pie',
                data: membresData,
                options: chartOptions
            });

            new Chart(document.getElementById('participationChart'), {
                type: 'bar',
                data: participationData,
                options: {
                    ...chartOptions,
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            ticks: {
                                callback: function(value) {
                                    return value + '%';
                                }
                            }
                        }
                    },
                    plugins: {
                        ...chartOptions.plugins,
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return 'Taux: ' + context.parsed.y + '%';
                                }
                            }
                        }
                    }
                }
            });
        });

        // Fonctions Modal
        function openPDFModal() {
            document.getElementById('pdfModal').classList.add('active');
        }

        function closePDFModal() {
            document.getElementById('pdfModal').classList.remove('active');
        }

        function downloadPDF() {
            window.location.href = '?export=pdf';
            closePDFModal();
        }

        // Fermer modal en cliquant à l'extérieur
        window.onclick = function(event) {
            const modal = document.getElementById('pdfModal');
            if (event.target === modal) {
                closePDFModal();
            }
        }
    </script>
</body>
</html>