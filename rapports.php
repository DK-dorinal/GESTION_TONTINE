<?php
// stats_global.php
session_start();
include './fonctions/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

// Récupérer les statistiques globales
function getGlobalStats($pdo)
{
    $stats = [];

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
                         SUM(montant) as montant_total,
                         SUM(CASE WHEN statut = 'payé' THEN montant ELSE 0 END) as paye,
                         SUM(CASE WHEN statut = 'impayé' THEN montant ELSE 0 END) as impaye,
                         SUM(CASE WHEN statut = 'retard' THEN montant ELSE 0 END) as retard
                         FROM cotisation");
    $stats['cotisations'] = $stmt->fetch();

    // 4. Statistiques séances
    $stmt = $pdo->query("SELECT COUNT(*) as total,
                         AVG(montant_total) as montant_moyen,
                         SUM(montant_total) as montant_total
                         FROM seance");
    $stats['seances'] = $stmt->fetch();

    // 5. Statistiques crédits
    $stmt = $pdo->query("SELECT COUNT(*) as total,
                         SUM(montant) as montant_total,
                         SUM(montant_restant) as reste_total,
                         SUM(CASE WHEN statut = 'en_cours' THEN 1 ELSE 0 END) as en_cours,
                         SUM(CASE WHEN statut = 'en_retard' THEN 1 ELSE 0 END) as en_retard,
                         AVG(taux_interet) as taux_moyen
                         FROM credit");
    $stats['credits'] = $stmt->fetch();

    // 6. Statistiques bénéficiaires
    $stmt = $pdo->query("SELECT COUNT(*) as total,
                         SUM(montant_gagne) as montant_total,
                         AVG(montant_gagne) as montant_moyen
                         FROM beneficiaire");
    $stats['beneficiaires'] = $stmt->fetch();

    // 7. Statistiques pénalités
    $stmt = $pdo->query("SELECT COUNT(*) as total,
                         SUM(montant) as montant_total,
                         AVG(montant) as montant_moyen
                         FROM penalite");
    $stats['penalites'] = $stmt->fetch();

    // 8. Statistiques projets FIAC
    $stmt = $pdo->query("SELECT COUNT(*) as total,
                         SUM(montant_budget) as budget_total,
                         SUM(CASE WHEN statut = 'actif' THEN 1 ELSE 0 END) as actifs,
                         SUM(CASE WHEN statut = 'planifié' THEN 1 ELSE 0 END) as planifies,
                         SUM(CASE WHEN statut = 'en_étude' THEN 1 ELSE 0 END) as en_etude
                         FROM projet_fiac");
    $stats['projets'] = $stmt->fetch();

    // 9. Top 5 membres avec plus de gains
    $stmt = $pdo->query("SELECT m.prenom, m.nom, SUM(b.montant_gagne) as total_gains
                         FROM beneficiaire b
                         JOIN membre m ON b.id_membre = m.id_membre
                         GROUP BY b.id_membre
                         ORDER BY total_gains DESC
                         LIMIT 5");
    $stats['top_gagnants'] = $stmt->fetchAll();

    // 10. Top 5 membres avec crédits en retard
    $stmt = $pdo->query("SELECT m.prenom, m.nom, 
                         COUNT(c.id_credit) as nb_credits_retard,
                         SUM(c.montant_restant) as total_reste
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
                         SUM(montant) as montant_total
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
                         ROUND((COUNT(DISTINCT p.id_membre) * 100.0 / t.participants_max), 1) as taux_participation
                         FROM tontine t
                         LEFT JOIN participation_tontine p ON t.id_tontine = p.id_tontine
                         WHERE p.statut = 'active'
                         GROUP BY t.id_tontine");
    $stats['participation_tontines'] = $stmt->fetchAll();

    return $stats;
}

$stats = getGlobalStats($pdo);

// Fonction pour générer le PDF
if (isset($_GET['export']) && $_GET['export'] == 'pdf') {
    require_once __DIR__ . '/vendor/autoload.php'; // Si vous utilisez TCPDF ou Dompdf

    // Pour cet exemple, nous allons utiliser une solution simple avec HTML
    // En production, utilisez TCPDF, Dompdf ou mPDF

    $html_content = generatePDFContent($stats);

    // Pour TCPDF (décommentez et adaptez si vous avez installé TCPDF)
    /*
    require_once('tcpdf/tcpdf.php');
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator('Gestion Tontine');
    $pdf->SetAuthor('Administrateur');
    $pdf->SetTitle('Statistiques Globales');
    $pdf->AddPage();
    $pdf->writeHTML($html_content, true, false, true, false, '');
    $pdf->Output('statistiques_globales_' . date('Y-m-d') . '.pdf', 'D');
    exit();
    */

    // Solution temporaire - téléchargement HTML pour conversion manuelle
    header('Content-Type: text/html');
    header('Content-Disposition: attachment; filename="statistiques_globales_' . date('Y-m-d') . '.html"');
    echo $html_content;
    exit();
}

function generatePDFContent($stats)
{
    ob_start();
?>
    <!DOCTYPE html>
    <html>

    <head>
        <meta charset="UTF-8">
        <title>Statistiques Globales - <?php echo date('d/m/Y'); ?></title>
        <style>
            body {
                font-family: Arial, sans-serif;
                margin: 20px;
                overflow-x: scroll;
                width: 100;
            }

            h1 {
                color: #0f1a3a;
                border-bottom: 2px solid #d4af37;
                padding-bottom: 10px;
            }

            h2 {
                color: #1a2b55;
                margin-top: 25px;
                padding-bottom: 5px;
                border-bottom: 1px solid #ddd;
            }

            .section {
                margin-bottom: 20px;
            }

            .stat-grid {
                display: flex;
                flex-wrap: wrap;
                gap: 15px;
                margin-top: 10px;
            }

            .stat-item {
                flex: 1;
                min-width: 200px;
                padding: 10px;
                 
                border-radius: 5px;
            }

            .stat-value {
                font-size: 1.2em;
                font-weight: bold;
                color: #0f1a3a;
            }

            .stat-label {
                color: #666;
                font-size: 0.9em;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 10px;
            }

            th {
                background-color: #0f1a3a;
                color: white;
                padding: 8px;
                text-align: left;
            }

            td {
                padding: 8px;
                border-bottom: 1px solid #ddd;
            }

            .text-success {
                color: #28a745;
            }

            .text-danger {
                color: #dc3545;
            }

            .text-warning {
                color: #ffc107;
            }

            .text-info {
                color: #17a2b8;
            }

            .header {
                text-align: center;
                margin-bottom: 30px;
            }

            .footer {
                margin-top: 50px;
                text-align: center;
                color: #666;
                font-size: 0.8em;
            }
        </style>
    </head>

    <body>
        <div class="header">
            <h1>Statistiques Globales - Gestion Tontine</h1>
            <p>Rapport généré le <?php echo date('d/m/Y à H:i'); ?></p>
        </div>

        <div class="section">
            <h2>Vue d'ensemble</h2>
            <div class="stat-grid">
                <div class="stat-item">
                    <div class="stat-value"><?php echo $stats['membres']['total']; ?></div>
                    <div class="stat-label">Membres Totaux</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo number_format($stats['cotisations']['montant_total'], 0, ',', ' '); ?> F</div>
                    <div class="stat-label">Cotisations Collectées</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo $stats['credits']['total']; ?></div>
                    <div class="stat-label">Crédits Actifs</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo $stats['seances']['total']; ?></div>
                    <div class="stat-label">Séances Effectuées</div>
                </div>
            </div>
        </div>

        <div class="section">
            <h2>Statistiques des Membres</h2>
            <table>
                <tr>
                    <th>Catégorie</th>
                    <th>Valeur</th>
                </tr>
                <tr>
                    <td>Membres Actifs</td>
                    <td class="text-success"><?php echo $stats['membres']['actifs']; ?></td>
                </tr>
                <tr>
                    <td>Membres Inactifs</td>
                    <td class="text-danger"><?php echo $stats['membres']['inactifs']; ?></td>
                </tr>
                <tr>
                    <td>Administrateurs</td>
                    <td class="text-info"><?php echo $stats['membres']['admins']; ?></td>
                </tr>
            </table>
        </div>

        <div class="section">
            <h2>Statistiques Financières</h2>
            <table>
                <tr>
                    <th>Indicateur</th>
                    <th>Valeur</th>
                </tr>
                <tr>
                    <td>Total Cotisations</td>
                    <td class="text-success"><?php echo number_format($stats['cotisations']['montant_total'], 0, ',', ' '); ?> F</td>
                </tr>
                <tr>
                    <td>Cotisations Payées</td>
                    <td><?php echo number_format($stats['cotisations']['paye'], 0, ',', ' '); ?> F</td>
                </tr>
                <tr>
                    <td>Cotisations Impayées</td>
                    <td class="text-danger"><?php echo number_format($stats['cotisations']['impaye'], 0, ',', ' '); ?> F</td>
                </tr>
                <tr>
                    <td>Total Crédits</td>
                    <td><?php echo number_format($stats['credits']['montant_total'], 0, ',', ' '); ?> F</td>
                </tr>
                <tr>
                    <td>Reste à Payer</td>
                    <td class="text-danger"><?php echo number_format($stats['credits']['reste_total'], 0, ',', ' '); ?> F</td>
                </tr>
            </table>
        </div>

        <div class="footer">
            <p>Document généré automatiquement par le système de gestion de tontine</p>
            <p>© <?php echo date('Y'); ?> - Tous droits réservés</p>
        </div>
    </body>

    </html>
<?php
    return ob_get_clean();
}
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statistiques Globales | Gestion Tontine</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-color: #0f1a3a;
            --secondary-color: #d4af37;
            --success-color: #10b981;
            --danger-color: #ef4444;
            --warning-color: #f59e0b;
            --info-color: #3b82f6;
            --light-color: #f8fafc;
            --dark-color: #1e293b;
            --border-color: #e2e8f0;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #0f1a3a 0%, #1a2b55 100%);
            min-height: 100vh;
            color: var(--dark-color);
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        /* Header */
        .header {
            background: white;
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .header-title h1 {
            font-size: 2rem;
            color: var(--primary-color);
            margin-bottom: 5px;
        }

        .header-title p {
            color: #64748b;
        }

        .header-actions {
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .date-badge {
            background: #f1f5f9;
            padding: 10px 15px;
            border-radius: 10px;
            font-size: 0.9rem;
            color: #475569;
        }

        .role-badge {
            background: linear-gradient(135deg, var(--secondary-color), #e6c34d);
            color: var(--primary-color);
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 700;
        }

        /* Stats Overview */
        .stats-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            border-top: 4px solid var(--info-color);
        }

        .stat-card.success {
            border-top-color: var(--success-color);
        }

        .stat-card.danger {
            border-top-color: var(--danger-color);
        }

        .stat-card.warning {
            border-top-color: var(--warning-color);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            background: linear-gradient(135deg, var(--info-color), #2563eb);
        }

        .stat-card.success .stat-icon {
            background: linear-gradient(135deg, var(--success-color), #059669);
        }

        .stat-card.danger .stat-icon {
            background: linear-gradient(135deg, var(--danger-color), #dc2626);
        }

        .stat-card.warning .stat-icon {
            background: linear-gradient(135deg, var(--warning-color), #d97706);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 800;
            color: var(--dark-color);
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.9rem;
            color: #64748b;
            font-weight: 600;
        }

        /* Sections */
        .section {
            background: white;
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--border-color);
        }

        .section-title h2 {
            font-size: 1.5rem;
            color: var(--primary-color);
        }

        .section-title i {
            color: var(--secondary-color);
        }

        /* Charts */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 25px;
            margin-bottom: 25px;
        }

        .chart-container {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
        }

        /* Tables */
        .table-container {
            overflow-x: auto;
            border-radius: 12px;
            border: 2px solid var(--border-color);
            margin-top: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }

        thead {
            background: var(--primary-color);
            color: white;
        }

        th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            font-size: 0.9rem;
        }

        tbody tr {
            border-bottom: 1px solid var(--border-color);
            transition: background 0.3s ease;
        }

        tbody tr:hover {
            background: #f8fafc;
        }

        td {
            padding: 15px;
            font-size: 0.95rem;
        }

        /* MODIFICATION: Suppression des backgrounds sur les badges */
        .badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 700;
            display: inline-block;
        }

        .badge-success {
            color: #065f46;
        }

        .badge-danger {
            color: #991b1b;
            border: 1px solid #991b1b;
        }

        .badge-warning {
            color: #92400e;
            border: 1px solid #92400e;
        }

        .badge-info {
            color: #1e40af;
            border: 1px solid #1e40af;
        }

        /* Detailed Stats */
        .detailed-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }

        .stat-box {
            background: #f8fafc;
            border-radius: 10px;
            padding: 20px;
        }

        .stat-box h4 {
            color: var(--primary-color);
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border-color);
        }

        .stat-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px dashed var(--border-color);
        }

        .stat-item:last-child {
            border-bottom: none;
        }

        .stat-label {
            color: #64748b;
        }

        .stat-value {
            font-weight: 600;
            color: var(--dark-color);
        }

        /* Boutons */
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid var(--border-color);
        }

        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), #2d4a8a);
            color: white;
        }

        .btn-secondary {
            background: #e2e8f0;
            color: var(--dark-color);
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        /* Modal pour prévisualisation PDF */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 1000;
            overflow-y: auto;
        }

        .modal-content {
            background: white;
            margin: 20px auto;
            padding: 30px;
            border-radius: 16px;
            max-width: 900px;
            width: 90%;
            position: relative;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--border-color);
        }

        .modal-header h3 {
            color: var(--primary-color);
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #64748b;
        }

        .pdf-preview {
            background: #f8fafc;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }

            .charts-grid {
                grid-template-columns: 1fr;
            }

            .stats-overview {
                grid-template-columns: 1fr;
            }

            .detailed-stats {
                grid-template-columns: 1fr;
            }

            .header-top {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            table {
                min-width: 500px;
            }

            .action-buttons {
                flex-wrap: wrap;
                justify-content: center;
            }

            .btn {
                padding: 10px 15px;
                font-size: 0.9rem;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-top">
                <div class="header-title">
                    <h1><i class="fas fa-chart-pie"></i> Tableau de Bord Global</h1>
                    <p>Statistiques complètes de la tontine - Vision globale</p>
                </div>
                <div class="header-actions">
                    <div class="date-badge">
                        <i class="fas fa-calendar-alt"></i>
                        <?php echo date('d/m/Y'); ?>
                    </div>
                    <div class="role-badge">
                        <i class="fas fa-user-shield"></i> Administrateur
                    </div>
                </div>
            </div>
        </div>

        <!-- Overview Statistics -->
        <div class="stats-overview">
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $stats['membres']['total']; ?></div>
                <div class="stat-label">Membres Totaux</div>
            </div>

            <div class="stat-card success">
                <div class="stat-header">
                    <div class="stat-icon">
                        <i class="fas fa-hand-holding-usd"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo number_format($stats['cotisations']['montant_total'], 0, ',', ' '); ?> F</div>
                <div class="stat-label">Cotisations Collectées</div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon">
                        <i class="fas fa-credit-card"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $stats['credits']['total']; ?></div>
                <div class="stat-label">Crédits Actifs</div>
            </div>

            <div class="stat-card warning">
                <div class="stat-header">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $stats['seances']['total']; ?></div>
                <div class="stat-label">Séances Effectuées</div>
            </div>
        </div>

        <!-- Section 1: Membres -->
        <div class="section">
            <div class="section-title">
                <i class="fas fa-users"></i>
                <h2>Statistiques des Membres</h2>
            </div>

            <div class="detailed-stats">
                <div class="stat-box">
                    <h4>Répartition par Statut</h4>
                    <div class="stat-item">
                        <span class="stat-label">Membres Actifs:</span>
                        <span class="stat-value badge-success"><?php echo $stats['membres']['actifs']; ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Membres Inactifs:</span>
                        <span class="stat-value badge-danger"><?php echo $stats['membres']['inactifs']; ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Administrateurs:</span>
                        <span class="stat-value badge-info"><?php echo $stats['membres']['admins']; ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Utilisateurs Normaux:</span>
                        <span class="stat-value"><?php echo $stats['membres']['total'] - $stats['membres']['admins']; ?></span>
                    </div>
                </div>

                <div class="stat-box">
                    <h4>Top 5 Gagnants</h4>
                    <?php foreach ($stats['top_gagnants'] as $gagnant): ?>
                        <div class="stat-item">
                            <span class="stat-label"><?php echo htmlspecialchars($gagnant['prenom'] . ' ' . $gagnant['nom']); ?>:</span>
                            <span class="stat-value badge-success"><?php echo number_format($gagnant['total_gains'], 0, ',', ' '); ?> F</span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="stat-box">
                    <h4>Retardataires Crédits</h4>
                    <?php if (count($stats['retardataires']) > 0): ?>
                        <?php foreach ($stats['retardataires'] as $retardataire): ?>
                            <div class="stat-item">
                                <span class="stat-label"><?php echo htmlspecialchars($retardataire['prenom'] . ' ' . $retardataire['nom']); ?>:</span>
                                <span class="stat-value badge-danger"><?php echo number_format($retardataire['total_reste'], 0, ',', ' '); ?> F</span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="stat-item">
                            <span class="stat-label">Aucun retardataire</span>
                            <span class="stat-value badge-success">✓</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Section 2: Tontines & Participation -->
        <div class="section">
            <div class="section-title">
                <i class="fas fa-handshake"></i>
                <h2>Tontines & Participation</h2>
            </div>

            <div class="detailed-stats">
                <div class="stat-box">
                    <h4>Statistiques Générales</h4>
                    <div class="stat-item">
                        <span class="stat-label">Tontines Totales:</span>
                        <span class="stat-value"><?php echo $stats['tontines']['total']; ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Tontines Actives:</span>
                        <span class="stat-value badge-success"><?php echo $stats['tontines']['actives']; ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Tontines Inactives:</span>
                        <span class="stat-value"><?php echo $stats['tontines']['inactives']; ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">En Attente:</span>
                        <span class="stat-value badge-warning"><?php echo $stats['tontines']['en_attente']; ?></span>
                    </div>
                </div>

                <div class="stat-box">
                    <h4>Répartition par Type</h4>
                    <div class="stat-item">
                        <span class="stat-label">Tontines Obligatoires:</span>
                        <span class="stat-value"><?php echo $stats['tontines']['obligatoires']; ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Tontines Optionnelles:</span>
                        <span class="stat-value"><?php echo $stats['tontines']['optionnelles']; ?></span>
                    </div>
                </div>

                <div class="stat-box">
                    <h4>Taux de Participation</h4>
                    <?php foreach ($stats['participation_tontines'] as $tontine): ?>
                        <div class="stat-item">
                            <span class="stat-label"><?php echo htmlspecialchars($tontine['nom_tontine']); ?>:</span>
                            <span class="stat-value <?php echo $tontine['taux_participation'] >= 80 ? 'badge-success' : ($tontine['taux_participation'] >= 50 ? 'badge-warning' : 'badge-danger'); ?>">
                                <?php echo $tontine['taux_participation']; ?>%
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Section 3: Finances -->
        <div class="section">
            <div class="section-title">
                <i class="fas fa-chart-line"></i>
                <h2>Statistiques Financières</h2>
            </div>

            <div class="charts-grid">
                <div class="chart-container">
                    <h4>Cotisations par Statut</h4>
                    <canvas id="cotisationsChart"></canvas>
                </div>

                <div class="chart-container">
                    <h4>Évolution des Cotisations (6 derniers mois)</h4>
                    <canvas id="evolutionChart"></canvas>
                </div>
            </div>

            <div class="detailed-stats" style="margin-top: 25px;">
                <div class="stat-box">
                    <h4>Cotisations</h4>
                    <div class="stat-item">
                        <span class="stat-label">Total Collecté:</span>
                        <span class="stat-value badge-success"><?php echo number_format($stats['cotisations']['montant_total'], 0, ',', ' '); ?> F</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Payées:</span>
                        <span class="stat-value badge-success"><?php echo number_format($stats['cotisations']['paye'], 0, ',', ' '); ?> F</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Impayées:</span>
                        <span class="stat-value badge-danger"><?php echo number_format($stats['cotisations']['impaye'], 0, ',', ' '); ?> F</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">En Retard:</span>
                        <span class="stat-value badge-warning"><?php echo number_format($stats['cotisations']['retard'], 0, ',', ' '); ?> F</span>
                    </div>
                </div>

                <div class="stat-box">
                    <h4>Crédits</h4>
                    <div class="stat-item">
                        <span class="stat-label">Total Emprunté:</span>
                        <span class="stat-value"><?php echo number_format($stats['credits']['montant_total'], 0, ',', ' '); ?> F</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Reste à Payer:</span>
                        <span class="stat-value badge-danger"><?php echo number_format($stats['credits']['reste_total'], 0, ',', ' '); ?> F</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Crédits en Cours:</span>
                        <span class="stat-value badge-info"><?php echo $stats['credits']['en_cours']; ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Crédits en Retard:</span>
                        <span class="stat-value badge-danger"><?php echo $stats['credits']['en_retard']; ?></span>
                    </div>
                </div>

                <div class="stat-box">
                    <h4>Gains & Pénalités</h4>
                    <div class="stat-item">
                        <span class="stat-label">Total Gains:</span>
                        <span class="stat-value badge-success"><?php echo number_format($stats['beneficiaires']['montant_total'], 0, ',', ' '); ?> F</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Moyenne des Gains:</span>
                        <span class="stat-value"><?php echo number_format($stats['beneficiaires']['montant_moyen'], 0, ',', ' '); ?> F</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Total Pénalités:</span>
                        <span class="stat-value badge-danger"><?php echo number_format($stats['penalites']['montant_total'], 0, ',', ' '); ?> F</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Moyenne Pénalité:</span>
                        <span class="stat-value"><?php echo number_format($stats['penalites']['montant_moyen'], 0, ',', ' '); ?> F</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 4: Séances & Projets -->
        <div class="section">
            <div class="section-title">
                <i class="fas fa-calendar-alt"></i>
                <h2>Séances & Projets FIAC</h2>
            </div>

            <div class="detailed-stats">
                <div class="stat-box">
                    <h4>Statistiques Séances</h4>
                    <div class="stat-item">
                        <span class="stat-label">Séances Total:</span>
                        <span class="stat-value"><?php echo $stats['seances']['total']; ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Montant Total:</span>
                        <span class="stat-value badge-success"><?php echo number_format($stats['seances']['montant_total'], 0, ',', ' '); ?> F</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Montant Moyen/Séance:</span>
                        <span class="stat-value"><?php echo number_format($stats['seances']['montant_moyen'], 0, ',', ' '); ?> F</span>
                    </div>
                </div>

                <div class="stat-box">
                    <h4>Projets FIAC</h4>
                    <div class="stat-item">
                        <span class="stat-label">Total Projets:</span>
                        <span class="stat-value"><?php echo $stats['projets']['total']; ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Budget Total:</span>
                        <span class="stat-value badge-success"><?php echo number_format($stats['projets']['budget_total'], 0, ',', ' '); ?> F</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Projets Actifs:</span>
                        <span class="stat-value badge-success"><?php echo $stats['projets']['actifs']; ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Projets Planifiés:</span>
                        <span class="stat-value badge-warning"><?php echo $stats['projets']['planifies']; ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">En Étude:</span>
                        <span class="stat-value badge-info"><?php echo $stats['projets']['en_etude']; ?></span>
                    </div>
                </div>

                <div class="stat-box">
                    <h4>Performances Globales</h4>
                    <div class="stat-item">
                        <span class="stat-label">Taux d'Activité:</span>
                        <span class="stat-value badge-success">
                            <?php
                            $taux_activite = $stats['membres']['total'] > 0 ?
                                round(($stats['membres']['actifs'] / $stats['membres']['total']) * 100, 1) : 0;
                            echo $taux_activite; ?>%
                        </span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Taux de Recouvrement:</span>
                        <span class="stat-value 
                            <?php
                            $taux_recouvrement = $stats['cotisations']['montant_total'] > 0 ?
                                round(($stats['cotisations']['paye'] / $stats['cotisations']['montant_total']) * 100, 1) : 0;
                            echo $taux_recouvrement >= 90 ? 'badge-success' : ($taux_recouvrement >= 70 ? 'badge-warning' : 'badge-danger');
                            ?>">
                            <?php echo $taux_recouvrement; ?>%
                        </span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Taux Intérêt Moyen:</span>
                        <span class="stat-value"><?php echo number_format($stats['credits']['taux_moyen'], 2, ',', ' '); ?>%</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="action-buttons">
            <button class="btn btn-secondary" onclick="window.print()">
                <i class="fas fa-print"></i> Imprimer le Rapport
            </button>
            <button class="btn btn-primary" onclick="previewPDF()">
                <i class="fas fa-file-pdf"></i> Prévisualiser PDF
            </button>
            <button class="btn btn-success" onclick="exportToExcel()">
                <i class="fas fa-file-excel"></i> Exporter en Excel
            </button>
            <button class="btn btn-secondary" onclick="window.location.href='dashboard.php'">
                <i class="fas fa-arrow-left"></i> Retour au Tableau de Bord
            </button>
        </div>
    </div>

    <!-- Modal pour prévisualisation PDF -->
    <div id="pdfPreviewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-file-pdf"></i> Prévisualisation du Rapport PDF</h3>
                <button class="close-modal" onclick="closePDFPreview()">&times;</button>
            </div>
            <div class="pdf-preview" id="pdfPreviewContent">
                <!-- Contenu de la prévisualisation sera inséré ici -->
            </div>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="closePDFPreview()">
                    <i class="fas fa-times"></i> Annuler
                </button>
                <button class="btn btn-primary" onclick="downloadPDF()">
                    <i class="fas fa-download"></i> Télécharger PDF
                </button>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Chart 1: Cotisations par Statut
            const cotisationsCtx = document.getElementById('cotisationsChart').getContext('2d');
            new Chart(cotisationsCtx, {
                type: 'pie',
                data: {
                    labels: ['Payées', 'Impayées', 'En Retard'],
                    datasets: [{
                        data: [
                            <?php echo $stats['cotisations']['paye']; ?>,
                            <?php echo $stats['cotisations']['impaye']; ?>,
                            <?php echo $stats['cotisations']['retard']; ?>
                        ],
                        backgroundColor: [
                            '#10b981',
                            '#ef4444',
                            '#f59e0b'
                        ],
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });

            // Chart 2: Évolution des cotisations
            const evolutionCtx = document.getElementById('evolutionChart').getContext('2d');
            const months = <?php echo json_encode(array_column($stats['evolution_cotisations'], 'mois')); ?>;
            const amounts = <?php echo json_encode(array_column($stats['evolution_cotisations'], 'montant_total')); ?>;

            new Chart(evolutionCtx, {
                type: 'line',
                data: {
                    labels: months,
                    datasets: [{
                        label: 'Montant des Cotisations',
                        data: amounts,
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return value.toLocaleString('fr-FR') + ' F';
                                }
                            }
                        }
                    }
                }
            });
        });

        function previewPDF() {
            // Récupérer les données principales pour la prévisualisation
            const previewContent = `
                <h4 style="color: #0f1a3a; border-bottom: 2px solid #d4af37; padding-bottom: 10px;">
                    Statistiques Globales - <?php echo date('d/m/Y'); ?>
                </h4>
                
                <div style="display: flex; flex-wrap: wrap; gap: 20px; margin: 20px 0;">
                    <div style="flex: 1; min-width: 200px; padding: 15px;   border-radius: 8px;">
                        <div style="font-size: 1.5em; font-weight: bold; color: #0f1a3a;">
                            <?php echo $stats['membres']['total']; ?>
                        </div>
                        <div style="color: #666;">Membres Totaux</div>
                    </div>
                    
                    <div style="flex: 1; min-width: 200px; padding: 15px;   border-radius: 8px;">
                        <div style="font-size: 1.5em; font-weight: bold; color: #0f1a3a;">
                            <?php echo number_format($stats['cotisations']['montant_total'], 0, ',', ' '); ?> F
                        </div>
                        <div style="color: #666;">Cotisations Collectées</div>
                    </div>
                    
                    <div style="flex: 1; min-width: 200px; padding: 15px;   border-radius: 8px;">
                        <div style="font-size: 1.5em; font-weight: bold; color: #0f1a3a;">
                            <?php echo $stats['credits']['total']; ?>
                        </div>
                        <div style="color: #666;">Crédits Actifs</div>
                    </div>
                </div>
                
                <h5 style="color: #1a2b55; margin-top: 25px;">Détails Financiers</h5>
                <table style="width: 100%; border-collapse: collapse; margin-top: 10px;">
                    <tr style="background-color: #f1f5f9;">
                        <th style="padding: 10px; text-align: left;">Catégorie</th>
                        <th style="padding: 10px; text-align: left;">Valeur</th>
                    </tr>
                    <tr>
                        <td style="padding: 10px; border-bottom: 1px solid #ddd;">Membres Actifs</td>
                        <td style="padding: 10px; border-bottom: 1px solid #ddd; color: #10b981;">
                            <?php echo $stats['membres']['actifs']; ?>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 10px; border-bottom: 1px solid #ddd;">Cotisations Payées</td>
                        <td style="padding: 10px; border-bottom: 1px solid #ddd; color: #10b981;">
                            <?php echo number_format($stats['cotisations']['paye'], 0, ',', ' '); ?> F
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 10px; border-bottom: 1px solid #ddd;">Crédits en Retard</td>
                        <td style="padding: 10px; border-bottom: 1px solid #ddd; color: #ef4444;">
                            <?php echo $stats['credits']['en_retard']; ?>
                        </td>
                    </tr>
                </table>
                
                <div style="margin-top: 20px; padding: 15px; background-color: #f8fafc; border-radius: 5px;">
                    <strong>Note:</strong> Ce document contient un résumé des statistiques globales.
                    Le PDF complet contiendra tous les détails et tableaux.
                </div>
            `;

            document.getElementById('pdfPreviewContent').innerHTML = previewContent;
            document.getElementById('pdfPreviewModal').style.display = 'block';
        }

        function closePDFPreview() {
            document.getElementById('pdfPreviewModal').style.display = 'none';
        }

        function downloadPDF() {
            // Fermer la modal
            closePDFPreview();

            // Télécharger le PDF
            window.location.href = '?export=pdf';
        }

        function exportToExcel() {
            // Créer un tableau Excel simple
            const table = document.createElement('table');
            const titleRow = table.insertRow();
            titleRow.insertCell().textContent = 'Statistiques Globales - ' + new Date().toLocaleDateString('fr-FR');

            const stats = [
                ['Membres Totaux', <?php echo $stats['membres']['total']; ?>],
                ['Membres Actifs', <?php echo $stats['membres']['actifs']; ?>],
                ['Membres Inactifs', <?php echo $stats['membres']['inactifs']; ?>],
                ['Cotisations Totales', <?php echo $stats['cotisations']['montant_total']; ?>],
                ['Cotisations Payées', <?php echo $stats['cotisations']['paye']; ?>],
                ['Cotisations Impayées', <?php echo $stats['cotisations']['impaye']; ?>],
                ['Crédits Actifs', <?php echo $stats['credits']['total']; ?>],
                ['Crédits en Retard', <?php echo $stats['credits']['en_retard']; ?>],
                ['Séances Effectuées', <?php echo $stats['seances']['total']; ?>],
                ['Projets FIAC', <?php echo $stats['projets']['total']; ?>],
                ['Budget Total Projets', <?php echo $stats['projets']['budget_total']; ?>]
            ];

            stats.forEach(stat => {
                const row = table.insertRow();
                row.insertCell().textContent = stat[0];
                row.insertCell().textContent = stat[1];
            });

            const html = `
                <html>
                <head>
                    <meta charset="UTF-8">
                    <style>
                        table { border-collapse: collapse; width: 100%; }
                        th { background-color: #0f1a3a; color: white; padding: 10px; }
                        td {   padding: 8px; }
                    </style>
                </head>
                <body>
                    ${table.outerHTML}
                </body>
                </html>
            `;

            const blob = new Blob([html], {
                type: 'application/vnd.ms-excel'
            });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'statistiques_globales_' + new Date().toISOString().split('T')[0] + '.xls';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }

        // Fermer la modal en cliquant en dehors
        window.onclick = function(event) {
            const modal = document.getElementById('pdfPreviewModal');
            if (event.target == modal) {
                closePDFPreview();
            }
        }
    </script>
</body>

</html>