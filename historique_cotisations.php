<?php
// Inclusion du fichier de configuration
require_once 'fonctions/config.php';

// Démarrage de la session
session_start();

// Vérifier la connexion
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Récupérer les données de l'utilisateur connecté
$stmt = $pdo->prepare("SELECT * FROM membre WHERE id_membre = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Si l'utilisateur n'existe pas
if (!$user) {
    session_destroy();
    header('Location: index.php');
    exit();
}

// Déterminer le rôle
$is_admin = ($user['role'] === 'admin');

// Récupérer les messages depuis la session
if (isset($_SESSION['message_success'])) {
    $message_success = $_SESSION['message_success'];
    unset($_SESSION['message_success']);
}

if (isset($_SESSION['message_error'])) {
    $message_error = $_SESSION['message_error'];
    unset($_SESSION['message_error']);
}

// Récupérer l'historique complet des cotisations
if ($is_admin) {
    // Pour l'admin : voir toutes les cotisations
    $stmt_cotisations = $pdo->prepare("
        SELECT 
            c.*,
            m.nom,
            m.prenom,
            t.nom_tontine,
            t.montant_cotisation as montant_prev,
            s.date_seance,
            s.lieu,
            s.heure_debut,
            DATEDIFF(c.date_paiement, s.date_seance) as jours_retard,
            CASE 
                WHEN DATEDIFF(c.date_paiement, s.date_seance) > 0 THEN 'En retard'
                WHEN DATEDIFF(c.date_paiement, s.date_seance) <= 0 THEN 'À temps'
                ELSE 'Date inconnue'
            END as statut_paiement
        FROM cotisation c
        INNER JOIN membre m ON c.id_membre = m.id_membre
        INNER JOIN seance s ON c.id_seance = s.id_seance
        INNER JOIN tontine t ON s.id_tontine = t.id_tontine
        ORDER BY c.date_paiement DESC, c.id_cotisation DESC
    ");
    $stmt_cotisations->execute();
} else {
    // Pour le membre : voir seulement ses cotisations
    $stmt_cotisations = $pdo->prepare("
        SELECT 
            c.*,
            m.nom,
            m.prenom,
            t.nom_tontine,
            t.montant_cotisation as montant_prev,
            s.date_seance,
            s.lieu,
            s.heure_debut,
            DATEDIFF(c.date_paiement, s.date_seance) as jours_retard,
            CASE 
                WHEN DATEDIFF(c.date_paiement, s.date_seance) > 0 THEN 'En retard'
                WHEN DATEDIFF(c.date_paiement, s.date_seance) <= 0 THEN 'À temps'
                ELSE 'Date inconnue'
            END as statut_paiement,
            (SELECT COALESCE(SUM(p.montant), 0) FROM penalite p WHERE p.id_cotisation_penalite = c.id_cotisation) as montant_penalite
        FROM cotisation c
        INNER JOIN membre m ON c.id_membre = m.id_membre
        INNER JOIN seance s ON c.id_seance = s.id_seance
        INNER JOIN tontine t ON s.id_tontine = t.id_tontine
        WHERE c.id_membre = ?
        ORDER BY c.date_paiement DESC, c.id_cotisation DESC
    ");
    $stmt_cotisations->execute([$user_id]);
}

$cotisations = $stmt_cotisations->fetchAll();

// Statistiques - CORRECTION ICI
if ($is_admin) {
    // Pour admin
    $stmt_total = $pdo->query("SELECT COUNT(*) FROM cotisation");
    $stats['total'] = $stmt_total->fetchColumn();
    
    $stmt_montant = $pdo->query("SELECT COALESCE(SUM(montant), 0) FROM cotisation");
    $stats['total_montant'] = $stmt_montant->fetchColumn();
    
    $stmt_retard = $pdo->query("
        SELECT COUNT(*) 
        FROM cotisation c
        INNER JOIN seance s ON c.id_seance = s.id_seance
        WHERE DATEDIFF(c.date_paiement, s.date_seance) > 0
    ");
    $stats['en_retard'] = $stmt_retard->fetchColumn();
    
    $stmt_temps = $pdo->query("
        SELECT COUNT(*) 
        FROM cotisation c
        INNER JOIN seance s ON c.id_seance = s.id_seance
        WHERE DATEDIFF(c.date_paiement, s.date_seance) <= 0
    ");
    $stats['a_temps'] = $stmt_temps->fetchColumn();
} else {
    // Pour membre - CORRECTION ICI
    $stmt_total = $pdo->prepare("SELECT COUNT(*) FROM cotisation WHERE id_membre = ?");
    $stmt_total->execute([$user_id]);
    $stats['total'] = $stmt_total->fetchColumn();
    
    $stmt_montant = $pdo->prepare("SELECT COALESCE(SUM(montant), 0) FROM cotisation WHERE id_membre = ?");
    $stmt_montant->execute([$user_id]);
    $stats['total_montant'] = $stmt_montant->fetchColumn();
    
    $stmt_retard = $pdo->prepare("
        SELECT COUNT(*) 
        FROM cotisation c
        INNER JOIN seance s ON c.id_seance = s.id_seance
        WHERE c.id_membre = ? AND DATEDIFF(c.date_paiement, s.date_seance) > 0
    ");
    $stmt_retard->execute([$user_id]);
    $stats['en_retard'] = $stmt_retard->fetchColumn();
    
    $stmt_temps = $pdo->prepare("
        SELECT COUNT(*) 
        FROM cotisation c
        INNER JOIN seance s ON c.id_seance = s.id_seance
        WHERE c.id_membre = ? AND DATEDIFF(c.date_paiement, s.date_seance) <= 0
    ");
    $stmt_temps->execute([$user_id]);
    $stats['a_temps'] = $stmt_temps->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historique des Cotisations | Gestion de Tontine</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy-blue: #0f1a3a;
            --dark-blue: #1a2b55;
            --medium-blue: #2d4a8a;
            --light-blue: #3a5fc0;
            --accent-gold: #d4af37;
            --accent-light: #e6c34d;
            --pure-white: #ffffff;
            --text-dark: #1e293b;
            --text-light: #64748b;
            --bg-light: #f8fafc;
            --glass-bg: rgba(255, 255, 255, 0.95);
            --shadow-light: rgba(15, 26, 58, 0.1);
            --shadow-medium: rgba(15, 26, 58, 0.2);
            --border-radius: 16px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
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
            color: var(--text-dark);
        }

        .app-container {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }

        .sidebar {
            display: flex;
            flex-direction: column;
            width: 280px;
            background: linear-gradient(135deg, rgba(15, 26, 58, 0.95), rgba(26, 43, 85, 0.95));
            backdrop-filter: blur(10px);
            padding: 20px 0;
            position: fixed;
            height: 100vh;
            z-index: 1000;
            box-shadow: 4px 0 20px rgba(0, 0, 0, 0.3);
        }

        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
            width: calc(100% - 280px);
            min-height: 100vh;
        }

        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 20px;
            }
        }

        .header {
            margin-bottom: 30px;
            padding: 25px;
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border-radius: var(--border-radius);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .page-title h1 {
            font-size: 2rem;
            font-weight: 700;
            color: white;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .page-title h1 i {
            color: var(--accent-gold);
        }
        
        /* Alert Messages */
        .alert-container {
            margin-bottom: 20px;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
            animation: slideIn 0.3s ease-out;
            backdrop-filter: blur(10px);
            border: 1px solid transparent;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .alert-success {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
            border-color: rgba(16, 185, 129, 0.3);
        }
        
        .alert-error {
            background: rgba(239, 68, 68, 0.15);
            color: #f87171;
            border-color: rgba(239, 68, 68, 0.3);
        }
        
        .alert i {
            font-size: 1.3rem;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--glass-bg);
            border-radius: var(--border-radius);
            padding: 25px;
            text-align: center;
            transition: var(--transition);
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 8px 32px var(--shadow-light);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px var(--shadow-medium);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            margin: 0 auto 15px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--text-light);
            margin-bottom: 8px;
            font-weight: 500;
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 5px;
        }

        /* Table */
        .table-container {
            background: var(--glass-bg);
            border-radius: var(--border-radius);
            padding: 30px;
            box-shadow: 0 8px 32px var(--shadow-medium);
            margin-bottom: 30px;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        thead {
            background: linear-gradient(135deg, var(--medium-blue), var(--light-blue));
        }

        th {
            padding: 16px 20px;
            text-align: left;
            color: white;
            font-weight: 700;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        tbody tr {
            border-bottom: 1px solid #e2e8f0;
            transition: var(--transition);
        }

        tbody tr:hover {
            background: rgba(212, 175, 55, 0.05);
        }

        td {
            padding: 16px 20px;
            font-size: 0.95rem;
        }

        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 700;
            display: inline-block;
        }

        .badge-success {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .badge-warning {
            background: rgba(245, 158, 11, 0.15);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        .badge-danger {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .montant {
            font-weight: 700;
            color: var(--text-dark);
        }

        .retard-days {
            font-weight: 700;
        }

        .retard-days.positive {
            color: var(--danger);
        }

        .retard-days.negative {
            color: var(--success);
        }

        /* Filters */
        .filters {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-label {
            color: var(--text-light);
            font-size: 0.9rem;
            font-weight: 500;
        }

        .filter-select {
            padding: 10px 15px;
            border: 2px solid rgba(58, 95, 192, 0.2);
            border-radius: 10px;
            background: white;
            font-family: 'Poppins', sans-serif;
            color: var(--text-dark);
            font-size: 0.9rem;
            cursor: pointer;
            transition: var(--transition);
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--light-blue);
            box-shadow: 0 0 0 3px rgba(58, 95, 192, 0.1);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
        }

        .empty-state i {
            font-size: 4rem;
            opacity: 0.3;
            margin-bottom: 20px;
            color: var(--accent-gold);
        }

        .empty-state h3 {
            font-size: 1.5rem;
            color: var(--text-dark);
            margin-bottom: 10px;
        }

        .export-btn {
            padding: 10px 20px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            border: none;
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .export-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(239, 68, 68, 0.3);
        }
        
        .back-btn {
            padding: 8px 15px;
            background: linear-gradient(135deg, var(--light-blue), var(--medium-blue));
            color: white;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-top: 10px;
            transition: var(--transition);
        }
        
        .back-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(58, 95, 192, 0.3);
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filters {
                flex-direction: column;
            }
            
            .page-title h1 {
                font-size: 1.5rem;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .table-container {
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php if ($is_admin): ?>
        <!-- Desktop Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="logo">
                    <div class="logo-icon">
                        <i class="fas fa-hand-holding-usd"></i>
                    </div>
                    <div class="logo-text">
                        <h2>Gestion Tontine</h2>
                        <p>Version 1.0</p>
                    </div>
                </div>
            </div>

            <nav class="nav-menu">
                <a href="dashboard.php" class="nav-item">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Tableau de Bord</span>
                </a>
                <a href="membre.php" class="nav-item">
                    <i class="fas fa-users"></i>
                    <span>Membres</span>
                </a>
                <a href="tontine.php" class="nav-item">
                    <i class="fas fa-hand-holding-usd"></i>
                    <span>Tontines</span>
                </a>
                <a href="seance.php" class="nav-item">
                    <i class="fas fa-calendar-alt"></i>
                    <span>Séances</span>
                </a>
                <a href="cotisation.php" class="nav-item">
                    <i class="fas fa-money-bill-wave"></i>
                    <span>Cotiser</span>
                </a>
                <a href="historique_cotisations.php" class="nav-item active">
                    <i class="fas fa-history"></i>
                    <span>Historique</span>
                </a>
                <a href="credit.php" class="nav-item">
                    <i class="fas fa-credit-card"></i>
                    <span>Crédits</span>
                </a>
            </nav>
        </aside>
        <?php endif; ?>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <header class="header">
                <div class="header-content">
                    <div class="page-title">
                        <h1>
                            <i class="fas fa-history"></i>
                            <?php echo $is_admin ? 'Historique des Cotisations' : 'Mes Cotisations'; ?>
                        </h1>
                        <div style="display: flex; gap: 10px; margin-top: 10px;">
                            <a href="cotisation.php" class="back-btn">
                                <i class="fas fa-arrow-left"></i>
                                Retour aux cotisations
                            </a>
                            <p style="color: rgba(255, 255, 255, 0.8); margin: 0;">
                                Consultez toutes les cotisations<?php echo $is_admin ? '' : ' que vous avez effectuées'; ?>
                            </p>
                        </div>
                    </div>
                    
                    <button class="export-btn" onclick="exportToPDF()">
                        <i class="fas fa-file-pdf"></i>
                        Exporter PDF
                    </button>
                </div>
            </header>
            
            <!-- Alert Messages -->
            <div class="alert-container">
                <?php if (isset($message_success)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <div><?php echo htmlspecialchars($message_success); ?></div>
                    </div>
                <?php endif; ?>

                <?php if (isset($message_error)): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <div><?php echo htmlspecialchars($message_error); ?></div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, var(--medium-blue), var(--light-blue));">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-label">Total Cotisations</div>
                    <div class="stat-value"><?= $stats['total'] ?></div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, var(--success), #22c55e);">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-label">Paiements à temps</div>
                    <div class="stat-value" style="color: var(--success);"><?= $stats['a_temps'] ?></div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, var(--danger), #f97316);">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-label">Paiements en retard</div>
                    <div class="stat-value" style="color: var(--danger);"><?= $stats['en_retard'] ?></div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, var(--warning), #fbbf24);">
                        <i class="fas fa-coins"></i>
                    </div>
                    <div class="stat-label">Montant total</div>
                    <div class="stat-value" style="color: var(--warning);">
                        <?= number_format($stats['total_montant'], 0, ',', ' ') ?> F
                    </div>
                </div>
            </div>

            <!-- Table -->
            <div class="table-container">
                <?php if (!empty($cotisations)): ?>
                    <div class="filters">
                        <div class="filter-group">
                            <span class="filter-label">Statut :</span>
                            <select class="filter-select" id="filterStatus" onchange="filterTable()">
                                <option value="all">Tous</option>
                                <option value="retard">En retard</option>
                                <option value="temps">À temps</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <span class="filter-label">Tontine :</span>
                            <select class="filter-select" id="filterTontine" onchange="filterTable()">
                                <option value="all">Toutes</option>
                                <?php 
                                $tontines_unique = [];
                                foreach ($cotisations as $cotisation) {
                                    $nom_tontine = htmlspecialchars($cotisation['nom_tontine']);
                                    if (!in_array($nom_tontine, $tontines_unique)) {
                                        $tontines_unique[] = $nom_tontine;
                                        echo '<option value="' . $nom_tontine . '">' . $nom_tontine . '</option>';
                                    }
                                }
                                ?>
                            </select>
                        </div>
                    </div>

                    <table id="cotisationsTable">
                        <thead>
                            <tr>
                                <?php if ($is_admin): ?>
                                    <th>Membre</th>
                                <?php endif; ?>
                                <th>Tontine</th>
                                <th>Séance</th>
                                <th>Date Séance</th>
                                <th>Date Paiement</th>
                                <th>Retard (jours)</th>
                                <th>Statut</th>
                                <th>Montant Prévu</th>
                                <th>Montant Payé</th>
                                <th>Pénalité</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cotisations as $cotisation): 
                                $jours_retard = $cotisation['jours_retard'] ?? 0;
                                $montant_penalite = $cotisation['montant_penalite'] ?? 0;
                                $montant_total = $cotisation['montant'] + $montant_penalite;
                                $statut = ($jours_retard > 0) ? 'En retard' : 'À temps';
                                $statut_class = ($jours_retard > 0) ? 'badge-danger' : 'badge-success';
                            ?>
                                <tr class="cotisation-row" 
                                    data-statut="<?= ($jours_retard > 0) ? 'en_retard' : 'a_temps' ?>"
                                    data-tontine="<?= htmlspecialchars($cotisation['nom_tontine']) ?>">
                                    <?php if ($is_admin): ?>
                                        <td>
                                            <strong><?= htmlspecialchars($cotisation['prenom'] . ' ' . $cotisation['nom']) ?></strong>
                                        </td>
                                    <?php endif; ?>
                                    <td><?= htmlspecialchars($cotisation['nom_tontine']) ?></td>
                                    <td><?= htmlspecialchars($cotisation['lieu']) ?> (<?= isset($cotisation['heure_debut']) ? substr($cotisation['heure_debut'], 0, 5) : '--:--' ?>)</td>
                                    <td><?= date('d/m/Y', strtotime($cotisation['date_seance'])) ?></td>
                                    <td>
                                        <strong><?= date('d/m/Y', strtotime($cotisation['date_paiement'])) ?></strong>
                                    </td>
                                    <td>
                                        <span class="retard-days <?= $jours_retard > 0 ? 'positive' : 'negative' ?>">
                                            <?= $jours_retard > 0 ? '+' . $jours_retard : $jours_retard ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?= $statut_class ?>">
                                            <?= $statut ?>
                                        </span>
                                    </td>
                                    <td class="montant">
                                        <?= number_format($cotisation['montant_prev'] ?? 0, 0, ',', ' ') ?> F
                                    </td>
                                    <td class="montant">
                                        <?= number_format($cotisation['montant'], 0, ',', ' ') ?> F
                                    </td>
                                    <td class="montant">
                                        <?php if ($montant_penalite > 0): ?>
                                            <span style="color: var(--danger); font-weight: 700;">
                                                <?= number_format($montant_penalite, 0, ',', ' ') ?> F
                                            </span>
                                        <?php else: ?>
                                            <span style="color: var(--text-light);">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="montant" style="color: var(--medium-blue); font-weight: 800;">
                                        <?= number_format($montant_total, 0, ',', ' ') ?> F
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <h3>Aucune cotisation trouvée</h3>
                        <p>Vous n'avez pas encore effectué de cotisation.</p>
                        <a href="cotisation.php" style="display: inline-block; margin-top: 20px; padding: 12px 24px; 
                           background: linear-gradient(135deg, var(--light-blue), var(--medium-blue)); 
                           color: white; border-radius: 10px; text-decoration: none; font-weight: 600;">
                            <i class="fas fa-money-bill-wave"></i> Faire une cotisation
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        function filterTable() {
            const statusFilter = document.getElementById('filterStatus').value;
            const tontineFilter = document.getElementById('filterTontine').value;
            const rows = document.querySelectorAll('.cotisation-row');
            
            rows.forEach(row => {
                const statut = row.getAttribute('data-statut');
                const tontine = row.getAttribute('data-tontine');
                
                let show = true;
                
                // Filtrer par statut
                if (statusFilter !== 'all') {
                    if (statusFilter === 'retard' && statut !== 'en_retard') show = false;
                    if (statusFilter === 'temps' && statut !== 'a_temps') show = false;
                }
                
                // Filtrer par tontine
                if (tontineFilter !== 'all' && tontine !== tontineFilter) {
                    show = false;
                }
                
                row.style.display = show ? '' : 'none';
            });
        }
        
        // Initialiser les filtres
        document.addEventListener('DOMContentLoaded', () => {
            // Ajouter des effets aux lignes
            const rows = document.querySelectorAll('.cotisation-row');
            rows.forEach(row => {
                row.addEventListener('mouseenter', function() {
                    this.style.backgroundColor = 'rgba(212, 175, 55, 0.05)';
                    this.style.transform = 'translateX(5px)';
                    this.style.transition = 'all 0.3s ease';
                });
                
                row.addEventListener('mouseleave', function() {
                    this.style.backgroundColor = '';
                    this.style.transform = '';
                });
            });
            
            // Trier automatiquement par date
            sortByDate();
        });
        
        // Exporter en PDF
        function exportToPDF() {
            const table = document.getElementById('cotisationsTable');
            if (table) {
                const title = 'Historique des Cotisations - ' + new Date().toLocaleDateString('fr-FR');
                const content = table.outerHTML;
                
                // Ouvrir une nouvelle fenêtre pour impression
                const win = window.open('', '_blank');
                win.document.write(`
                    <html>
                        <head>
                            <title>${title}</title>
                            <style>
                                body { 
                                    font-family: Arial, sans-serif; 
                                    padding: 20px; 
                                    margin: 0;
                                }
                                h1 { 
                                    color: #0f1a3a; 
                                    margin-bottom: 20px;
                                    border-bottom: 2px solid #d4af37;
                                    padding-bottom: 10px;
                                }
                                .info {
                                    background: #f8fafc;
                                    padding: 15px;
                                    border-radius: 8px;
                                    margin-bottom: 20px;
                                    border-left: 4px solid #3a5fc0;
                                }
                                table { 
                                    width: 100%; 
                                    border-collapse: collapse; 
                                    margin-top: 20px;
                                    font-size: 12px;
                                }
                                th { 
                                    background: #2d4a8a; 
                                    color: white; 
                                    padding: 10px; 
                                    text-align: left; 
                                    border: 1px solid #1a2b55;
                                }
                                td { 
                                    padding: 8px 10px; 
                                    border-bottom: 1px solid #ddd;
                                    border-right: 1px solid #eee;
                                }
                                tr:nth-child(even) {
                                    background: #f9fafb;
                                }
                                .badge { 
                                    padding: 3px 8px; 
                                    border-radius: 10px; 
                                    font-size: 11px; 
                                    font-weight: bold;
                                }
                                .badge-success { 
                                    background: #d1fae5; 
                                    color: #065f46; 
                                }
                                .badge-danger { 
                                    background: #fee2e2; 
                                    color: #991b1b; 
                                }
                                .montant {
                                    font-weight: bold;
                                    color: #1e293b;
                                }
                                .footer {
                                    margin-top: 30px;
                                    padding-top: 20px;
                                    border-top: 1px solid #ddd;
                                    text-align: center;
                                    color: #64748b;
                                    font-size: 11px;
                                }
                                @media print {
                                    body { padding: 10px; }
                                    table { font-size: 10px; }
                                }
                            </style>
                        </head>
                        <body>
                            <h1>${title}</h1>
                            <div class="info">
                                Généré le: ${new Date().toLocaleDateString('fr-FR', { 
                                    weekday: 'long', 
                                    year: 'numeric', 
                                    month: 'long', 
                                    day: 'numeric',
                                    hour: '2-digit',
                                    minute: '2-digit'
                                })}
                                <br>
                                Nombre total: ${<?= $stats['total'] ?>} cotisations
                            </div>
                            ${content}
                            <div class="footer">
                                Système de Gestion de Tontine - Généré automatiquement
                            </div>
                        </body>
                    </html>
                `);
                win.document.close();
                
                // Attendre que le contenu soit chargé avant d'imprimer
                setTimeout(() => {
                    win.print();
                }, 500);
            }
        }
        
        // Trier les cotisations par date de paiement
        function sortByDate() {
            const table = document.getElementById('cotisationsTable');
            if (!table) return;
            
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            // Déterminer la colonne de date selon si on est admin ou membre
            const dateColumnIndex = <?= $is_admin ? 4 : 3 ?>;
            
            rows.sort((a, b) => {
                const dateA = a.cells[dateColumnIndex].textContent;
                const dateB = b.cells[dateColumnIndex].textContent;
                
                // Convertir "dd/mm/yyyy" en "yyyy-mm-dd" pour la comparaison
                const convertDate = (dateStr) => {
                    const parts = dateStr.split('/');
                    return parts[2] + '-' + parts[1] + '-' + parts[0];
                };
                
                return new Date(convertDate(dateB)) - new Date(convertDate(dateA));
            });
            
            // Réinsérer les lignes triées
            rows.forEach(row => tbody.appendChild(row));
        }
    </script>
</body>
</html>