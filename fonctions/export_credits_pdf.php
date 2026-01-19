<?php
session_start();
include './config.php';

// Vérifier la connexion
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

// Récupérer les informations de l'utilisateur connecté
$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT role FROM membre WHERE id_membre = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Vérifier si l'utilisateur est admin
if (!$user || $user['role'] !== 'admin') {
    header('Location: dashboard.php');
    exit();
}

// Inclure TCPDF
require_once('tcpdf/tcpdf.php');

// Récupérer les paramètres de filtrage
$search = $_GET['search'] ?? '';
$statut_filter = $_GET['statut'] ?? '';
$date_debut = $_GET['date_debut'] ?? '';
$date_fin = $_GET['date_fin'] ?? '';

// Construire la requête avec filtres
$whereClauses = [];
$params = [];

if (!empty($search)) {
    $whereClauses[] = "(m.nom LIKE ? OR m.prenom LIKE ? OR c.statut LIKE ?)";
    $searchParam = "%$search%";
    array_push($params, $searchParam, $searchParam, $searchParam);
}

if (!empty($statut_filter)) {
    $whereClauses[] = "c.statut = ?";
    $params[] = $statut_filter;
}

if (!empty($date_debut)) {
    $whereClauses[] = "c.date_emprunt >= ?";
    $params[] = $date_debut;
}

if (!empty($date_fin)) {
    $whereClauses[] = "c.date_emprunt <= ?";
    $params[] = $date_fin;
}

$whereSQL = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

try {
    // Requête pour les données des crédits
    $query = "
        SELECT 
            c.*,
            m.nom,
            m.prenom,
            m.telephone,
            CONCAT(m.nom, ' ', m.prenom) as nom_complet,
            ROUND(c.montant * (c.taux_interet/100) * (c.duree_mois/12), 2) as interet_total,
            ROUND(c.montant + (c.montant * (c.taux_interet/100) * (c.duree_mois/12)), 2) as montant_total_rembourser
        FROM credit c
        INNER JOIN membre m ON c.id_membre = m.id_membre
        $whereSQL
        ORDER BY c.date_emprunt DESC, c.id_credit DESC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $credits = $stmt->fetchAll();
    
    // Créer une nouvelle instance de TCPDF
    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    
    // Définir les métadonnées du document
    $pdf->SetCreator('Système de Gestion de Tontine');
    $pdf->SetAuthor('Administrateur');
    $pdf->SetTitle('Rapport des Crédits');
    $pdf->SetSubject('Export des crédits');
    
    // Définir les marges
    $pdf->SetMargins(10, 15, 10);
    $pdf->SetHeaderMargin(5);
    $pdf->SetFooterMargin(10);
    
    // Définir l'auto saut de page
    $pdf->SetAutoPageBreak(TRUE, 15);
    
    // Ajouter une page
    $pdf->AddPage();
    
    // Définir la police
    $pdf->SetFont('helvetica', '', 10);
    
    // Titre
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'RAPPORT DES CRÉDITS', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, 'Généré le: ' . date('d/m/Y à H:i:s'), 0, 1, 'C');
    
    // Informations de filtrage
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 10, 'Critères de sélection:', 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    
    $filters = [];
    if (!empty($search)) $filters[] = "Recherche: " . $search;
    if (!empty($statut_filter)) $filters[] = "Statut: " . $statut_filter;
    if (!empty($date_debut)) $filters[] = "Date début: " . $date_debut;
    if (!empty($date_fin)) $filters[] = "Date fin: " . $date_fin;
    
    if (!empty($filters)) {
        $pdf->MultiCell(0, 5, implode(' | ', $filters), 0, 'L');
    } else {
        $pdf->Cell(0, 5, 'Aucun filtre appliqué', 0, 1);
    }
    
    $pdf->Ln(5);
    
    // Statistiques sommaires
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 10, 'Statistiques:', 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    
    try {
        $statsQuery = "
            SELECT 
                COUNT(*) as total_credits,
                SUM(montant) as montant_total,
                SUM(montant_restant) as total_restant,
                COUNT(CASE WHEN statut = 'en_cours' THEN 1 END) as credits_en_cours,
                COUNT(CASE WHEN statut = 'en_retard' THEN 1 END) as credits_en_retard,
                COUNT(CASE WHEN statut = 'rembourse' THEN 1 END) as credits_rembourses
            FROM credit
        ";
        
        if ($whereSQL) {
            $statsQuery = str_replace("FROM credit", "FROM credit c INNER JOIN membre m ON c.id_membre = m.id_membre " . $whereSQL, $statsQuery);
            $statsStmt = $pdo->prepare($statsQuery);
            $statsStmt->execute($params);
        } else {
            $statsStmt = $pdo->query($statsQuery);
        }
        
        $stats = $statsStmt->fetch();
        
        $statsText = sprintf(
            "Total crédits: %d | En cours: %d | En retard: %d | Remboursés: %d | Montant total: %s FCFA | Reste à payer: %s FCFA",
            $stats['total_credits'] ?? 0,
            $stats['credits_en_cours'] ?? 0,
            $stats['credits_en_retard'] ?? 0,
            $stats['credits_rembourses'] ?? 0,
            number_format($stats['montant_total'] ?? 0, 0, ',', ' '),
            number_format($stats['total_restant'] ?? 0, 0, ',', ' ')
        );
        
        $pdf->MultiCell(0, 5, $statsText, 0, 'L');
    } catch (Exception $e) {
        $pdf->Cell(0, 5, 'Erreur dans les statistiques: ' . $e->getMessage(), 0, 1);
    }
    
    $pdf->Ln(5);
    
    // Créer le tableau
    $pdf->SetFont('helvetica', 'B', 9);
    
    // En-têtes du tableau
    $headers = array(
        'ID',
        'Membre',
        'Téléphone',
        'Montant (FCFA)',
        'Taux %',
        'Durée (mois)',
        'Date Emprunt',
        'Reste à Payer (FCFA)',
        'Intérêt (FCFA)',
        'Total à Remb. (FCFA)',
        'Statut'
    );
    
    // Largeurs des colonnes
    $widths = array(10, 35, 25, 25, 15, 15, 25, 25, 25, 25, 20);
    
    // Dessiner l'en-tête du tableau
    for ($i = 0; $i < count($headers); $i++) {
        $pdf->Cell($widths[$i], 7, $headers[$i], 1, 0, 'C');
    }
    $pdf->Ln();
    
    // Données du tableau
    $pdf->SetFont('helvetica', '', 8);
    
    $total_montant = 0;
    $total_restant = 0;
    $total_interet = 0;
    $total_a_rembourser = 0;
    $row_count = 0;
    
    foreach ($credits as $credit) {
        // Vérifier si on a besoin d'une nouvelle page
        if ($row_count > 0 && $row_count % 25 == 0) {
            $pdf->AddPage();
            // Redessiner l'en-tête
            $pdf->SetFont('helvetica', 'B', 9);
            for ($i = 0; $i < count($headers); $i++) {
                $pdf->Cell($widths[$i], 7, $headers[$i], 1, 0, 'C');
            }
            $pdf->Ln();
            $pdf->SetFont('helvetica', '', 8);
        }
        
        // Convertir le statut
        $statut_text = '';
        switch($credit['statut']) {
            case 'en_cours':
                $statut_text = 'En cours';
                break;
            case 'en_retard':
                $statut_text = 'En retard';
                break;
            case 'rembourse':
                $statut_text = 'Remboursé';
                break;
        }
        
        // Calculer les totaux
        $total_montant += $credit['montant'];
        $total_restant += $credit['montant_restant'];
        $total_interet += $credit['interet_total'];
        $total_a_rembourser += $credit['montant_total_rembourser'];
        
        // Ligne de données
        $pdf->Cell($widths[0], 6, $credit['id_credit'], 1, 0, 'C');
        $pdf->Cell($widths[1], 6, substr($credit['nom_complet'], 0, 20), 1, 0, 'L');
        $pdf->Cell($widths[2], 6, $credit['telephone'], 1, 0, 'C');
        $pdf->Cell($widths[3], 6, number_format($credit['montant'], 0, ',', ' '), 1, 0, 'R');
        $pdf->Cell($widths[4], 6, $credit['taux_interet'] . '%', 1, 0, 'C');
        $pdf->Cell($widths[5], 6, $credit['duree_mois'], 1, 0, 'C');
        $pdf->Cell($widths[6], 6, date('d/m/Y', strtotime($credit['date_emprunt'])), 1, 0, 'C');
        $pdf->Cell($widths[7], 6, number_format($credit['montant_restant'], 0, ',', ' '), 1, 0, 'R');
        $pdf->Cell($widths[8], 6, number_format($credit['interet_total'], 0, ',', ' '), 1, 0, 'R');
        $pdf->Cell($widths[9], 6, number_format($credit['montant_total_rembourser'], 0, ',', ' '), 1, 0, 'R');
        $pdf->Cell($widths[10], 6, $statut_text, 1, 0, 'C');
        $pdf->Ln();
        
        $row_count++;
    }
    
    // Ligne des totaux
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->Cell($widths[0] + $widths[1] + $widths[2], 6, 'TOTAUX:', 1, 0, 'C');
    $pdf->Cell($widths[3], 6, number_format($total_montant, 0, ',', ' '), 1, 0, 'R');
    $pdf->Cell($widths[4] + $widths[5] + $widths[6], 6, '', 1, 0, 'C');
    $pdf->Cell($widths[7], 6, number_format($total_restant, 0, ',', ' '), 1, 0, 'R');
    $pdf->Cell($widths[8], 6, number_format($total_interet, 0, ',', ' '), 1, 0, 'R');
    $pdf->Cell($widths[9], 6, number_format($total_a_rembourser, 0, ',', ' '), 1, 0, 'R');
    $pdf->Cell($widths[10], 6, '', 1, 0, 'C');
    $pdf->Ln();
    
    // Ajouter un espace
    $pdf->Ln(10);
    
    // Ajouter des notes
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->MultiCell(0, 5, "Notes:\n- Ce rapport a été généré automatiquement par le système de gestion de tontine.\n- Les montants sont exprimés en Francs CFA.\n- Les intérêts sont calculés sur la base du taux annuel.", 0, 'L');
    
    // Ajouter le pied de page
    $pdf->SetY(-15);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->Cell(0, 10, 'Page ' . $pdf->getAliasNumPage() . '/' . $pdf->getAliasNbPages(), 0, 0, 'C');
    
    // Nom du fichier
    $filename = 'credits_export_' . date('Y-m-d_H-i') . '.pdf';
    
    // Générer le PDF
    $pdf->Output($filename, 'D');
    
} catch (PDOException $e) {
    echo "Erreur lors de la génération du PDF: " . $e->getMessage();
    exit();
}
?>