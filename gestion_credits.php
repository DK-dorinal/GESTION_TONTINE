<?php
session_start();
include './fonctions/config.php';

// Vérifier la connexion et les droits admin
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT role FROM membre WHERE id_membre = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user || $user['role'] !== 'admin') {
    header('Location: dashboard.php');
    exit();
}

// Fonction pour calculer le montant maximum empruntable
function calculerMontantMaxEmpruntable($pdo, $id_membre) {
    try {
        // 1. Récupérer le total des cotisations du membre
        $query_cotisations = "
            SELECT COALESCE(SUM(c.montant), 0) as total_cotisations
            FROM cotisation c
            WHERE c.id_membre = ? 
            AND c.statut = 'payé'
        ";
        $stmt = $pdo->prepare($query_cotisations);
        $stmt->execute([$id_membre]);
        $result = $stmt->fetch();
        $total_cotisations = $result['total_cotisations'] ?? 0;

        // 2. Compter le nombre total de membres actifs
        $query_membres = "SELECT COUNT(*) as nb_membres FROM membre WHERE statut = 'actif'";
        $stmt = $pdo->query($query_membres);
        $result = $stmt->fetch();
        $nb_membres = $result['nb_membres'] ?? 1; // Minimum 1 pour éviter la division par 0

        // 3. Calculer le montant maximum selon la formule
        $montant_max = $total_cotisations * $nb_membres * 100;
        
        return [
            'total_cotisations' => $total_cotisations,
            'nb_membres' => $nb_membres,
            'montant_max' => $montant_max
        ];
    } catch (PDOException $e) {
        error_log("Erreur calcul montant max: " . $e->getMessage());
        return [
            'total_cotisations' => 0,
            'nb_membres' => 1,
            'montant_max' => 0
        ];
    }
}

// Traitement des actions (validation/refus)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && isset($_POST['credit_id'])) {
        $credit_id = intval($_POST['credit_id']);
        $action = $_POST['action'];
        
        try {
            if ($action === 'valider') {
                $stmt = $pdo->prepare("UPDATE credit SET statut = 'en_cours' WHERE id_credit = ? AND statut = 'demande'");
                $stmt->execute([$credit_id]);
                
                // Récupérer les infos du crédit pour notification
                $query_info = "
                    SELECT c.montant, m.nom, m.prenom 
                    FROM credit c 
                    JOIN membre m ON c.id_membre = m.id_membre 
                    WHERE c.id_credit = ?
                ";
                $stmt_info = $pdo->prepare($query_info);
                $stmt_info->execute([$credit_id]);
                $credit_info = $stmt_info->fetch();
                
                if ($credit_info) {
                    $_SESSION['success_message'] = "Crédit #$credit_id de " . $credit_info['prenom'] . " " . $credit_info['nom'] . " validé pour " . number_format($credit_info['montant'], 0, ',', ' ') . " FCFA";
                } else {
                    $_SESSION['success_message'] = "Crédit validé avec succès!";
                }
                
            } elseif ($action === 'refuser') {
                $raison_refus = $_POST['raison_refus'] ?? 'Non spécifiée';
                $stmt = $pdo->prepare("UPDATE credit SET statut = 'refuse', montant_restant = 0 WHERE id_credit = ? AND statut = 'demande'");
                $stmt->execute([$credit_id]);
                
                // Ajouter un commentaire ou historique
                $_SESSION['success_message'] = "Crédit #$credit_id refusé. Raison: $raison_refus";
            }
            
            header('Location: gestion_credits.php');
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Erreur lors du traitement: " . $e->getMessage();
            header('Location: gestion_credits.php');
            exit();
        }
    }
}

// Récupérer les demandes de crédit en attente
try {
    $query_demandes = "
        SELECT 
            c.*,
            m.nom,
            m.prenom,
            m.telephone,
            m.date_inscription,
            CONCAT(m.nom, ' ', m.prenom) as nom_complet,
            DATEDIFF(CURDATE(), m.date_inscription) as anciennete_jours,
            (SELECT COALESCE(SUM(montant), 0) FROM cotisation WHERE id_membre = m.id_membre AND statut = 'payé') as total_cotisations_payees
        FROM credit c
        INNER JOIN membre m ON c.id_membre = m.id_membre
        WHERE c.statut = 'demande'
        ORDER BY c.date_emprunt DESC
    ";
    
    $demandes = $pdo->query($query_demandes)->fetchAll();
    
    // Récupérer les crédits actifs pour statistiques
    $query_credits_actifs = "
        SELECT COUNT(*) as total_actifs, SUM(montant) as montant_total_actifs
        FROM credit 
        WHERE statut IN ('en_cours', 'en_retard')
    ";
    $credits_actifs = $pdo->query($query_credits_actifs)->fetch();
    
    // Récupérer l'historique des décisions
    $query_historique = "
        SELECT 
            c.*,
            m.nom,
            m.prenom,
            CONCAT(m.nom, ' ', m.prenom) as nom_complet
        FROM credit c
        INNER JOIN membre m ON c.id_membre = m.id_membre
        WHERE c.statut IN ('en_cours', 'refuse')
        ORDER BY c.date_emprunt DESC
        LIMIT 10
    ";
    $historique = $pdo->query($query_historique)->fetchAll();
    
} catch (PDOException $e) {
    $error = "Erreur lors de la récupération des données: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Demandes de Crédit | Gestion de Tontine</title>
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
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --info-color: #3b82f6;
            --border-radius: 12px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: linear-gradient(135deg, #0f1a3a 0%, #1a2b55 100%);
            min-height: 100vh;
            color: var(--text-dark);
        }

        /* Layout */
        .app-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: 280px;
            background: linear-gradient(135deg, rgba(15, 26, 58, 0.95), rgba(26, 43, 85, 0.95));
            backdrop-filter: blur(10px);
            padding: 20px 0;
            position: fixed;
            height: 100vh;
            z-index: 1000;
            box-shadow: 4px 0 20px rgba(0, 0, 0, 0.3);
        }

        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }

        .logo-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--accent-gold) 0%, var(--light-blue) 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
        }

        .logo-text h2 {
            color: white;
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 2px;
        }

        .logo-text p {
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.75rem;
        }

        /* Navigation */
        .nav-menu {
            padding: 20px;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            border-radius: 10px;
            margin-bottom: 8px;
            transition: var(--transition);
            font-size: 0.95rem;
        }

        .nav-item:hover,
        .nav-item.active {
            background: rgba(212, 175, 55, 0.2);
            color: white;
            transform: translateX(5px);
        }

        .nav-item.active {
            background: rgba(212, 175, 55, 0.3);
            border-left: 3px solid var(--accent-gold);
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
            width: calc(100% - 280px);
        }

        /* Header */
        .header {
            margin-bottom: 30px;
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 20px;
        }

        .header-title h1 {
            font-size: 2rem;
            font-weight: 700;
            color: white;
            margin-bottom: 5px;
        }

        .header-title p {
            color: rgba(255, 255, 255, 0.8);
        }

        .header-actions {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }

        .date-badge {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            padding: 10px 16px;
            border-radius: 10px;
            color: white;
            font-size: 0.9rem;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .role-badge {
            background: linear-gradient(135deg, var(--accent-gold), var(--accent-light));
            padding: 10px 20px;
            border-radius: 10px;
            color: var(--navy-blue);
            font-weight: 700;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 4px 15px rgba(212, 175, 55, 0.4);
        }

        /* Statistics Cards */
        .stats-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            flex: 1;
            min-width: 250px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 25px;
            transition: var(--transition);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            color: white;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 800;
            color: var(--text-dark);
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--text-light);
            font-weight: 600;
        }

        /* Messages */
        .message {
            padding: 15px 20px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
            border-left: 4px solid;
        }

        .message-success {
            background: rgba(16, 185, 129, 0.1);
            border-left-color: var(--success-color);
            color: #065f46;
        }

        .message-error {
            background: rgba(239, 68, 68, 0.1);
            border-left-color: var(--danger-color);
            color: #991b1b;
        }

        /* Demande Cards */
        .demandes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }

        .demande-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.3);
            transition: var(--transition);
        }

        .demande-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
        }

        .demande-header {
            background: linear-gradient(135deg, var(--medium-blue), var(--light-blue));
            color: white;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .demande-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.1rem;
            font-weight: 700;
        }

        .demande-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            background: rgba(255, 255, 255, 0.2);
        }

        .demande-body {
            padding: 25px;
        }

        .demande-info {
            margin-bottom: 20px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .info-label {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.9rem;
        }

        .info-value {
            font-weight: 700;
            color: var(--dark-blue);
            font-size: 0.95rem;
        }

        .montant-value {
            font-size: 1.3rem;
            color: var(--success-color);
        }

        .limite-info {
            background: rgba(245, 158, 11, 0.1);
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
            border-left: 4px solid var(--warning-color);
        }

        .limite-title {
            font-weight: 700;
            color: var(--warning-color);
            margin-bottom: 5px;
            font-size: 0.9rem;
        }

        .limite-details {
            font-size: 0.85rem;
            color: var(--text-light);
        }

        .demande-actions {
            display: flex;
            gap: 15px;
            margin-top: 25px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.9rem;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: var(--transition);
            flex: 1;
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success-color), #059669);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger-color), #dc2626);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(239, 68, 68, 0.3);
        }

        .btn-secondary {
            background: #f1f5f9;
            color: var(--text-dark);
        }

        .btn-secondary:hover {
            background: #e2e8f0;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            border-radius: 16px;
            width: 90%;
            max-width: 500px;
            padding: 30px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.25);
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            margin-bottom: 20px;
        }

        .modal-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-body {
            margin-bottom: 25px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 0.95rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--accent-gold);
            box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.1);
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .modal-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
        }

        /* Historique */
        .historique-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 25px;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .section-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .historique-table {
            width: 100%;
            border-collapse: collapse;
        }

        .historique-table th {
            text-align: left;
            padding: 12px 16px;
            background: #f8fafc;
            color: var(--text-light);
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            border-bottom: 2px solid #e2e8f0;
        }

        .historique-table td {
            padding: 16px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.9rem;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            display: inline-block;
        }

        .status-en_cours {
            background: #d1fae5;
            color: #065f46;
        }

        .status-refuse {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .demandes-grid {
                grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 20px;
            }
            
            .demandes-grid {
                grid-template-columns: 1fr;
            }
            
            .stat-card {
                min-width: 100%;
            }
            
            .header-top {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar -->
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
                    <span>Cotisations</span>
                </a>
                <a href="credit.php" class="nav-item">
                    <i class="fas fa-credit-card"></i>
                    <span>Crédits</span>
                </a>
                <a href="gestion_credits.php" class="nav-item active">
                    <i class="fas fa-clipboard-check"></i>
                    <span>Validation Crédits</span>
                </a>
                <a href="logout.php" class="nav-item">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Déconnexion</span>
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <header class="header">
                <div class="header-top">
                    <div class="header-title">
                        <h1>📋 Validation des Crédits</h1>
                        <p>Gestion des demandes de crédit selon la limite calculée</p>
                    </div>
                    <div class="header-actions">
                        <div class="date-badge">
                            <i class="fas fa-calendar-alt"></i>
                            <span id="currentDate"></span>
                        </div>
                        <div class="role-badge">
                            Administrateur
                        </div>
                    </div>
                </div>
            </header>

            <!-- Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="message message-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="message message-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #667eea, #764ba2);">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo count($demandes ?? []); ?></div>
                    <div class="stat-label">Demandes en attente</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #fa709a, #fee140);">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                    </div>
                    <div class="stat-value">
                        <?php echo number_format($credits_actifs['montant_total_actifs'] ?? 0, 0, ',', ' '); ?> F
                    </div>
                    <div class="stat-label">Montant total actif</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #4facfe, #00f2fe);">
                            <i class="fas fa-chart-line"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo $credits_actifs['total_actifs'] ?? 0; ?></div>
                    <div class="stat-label">Crédits actifs</div>
                </div>
            </div>

            <!-- Demandes de Crédit -->
            <h2 style="color: white; margin-bottom: 20px; font-size: 1.5rem;">
                <i class="fas fa-clipboard-list"></i> Demandes en attente de validation
            </h2>

            <?php if (isset($error)): ?>
                <div class="message message-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <?php if (empty($demandes)): ?>
                <div style="background: rgba(255, 255, 255, 0.95); border-radius: 16px; padding: 50px; text-align: center; margin-bottom: 30px;">
                    <i class="fas fa-check-circle" style="font-size: 4rem; color: var(--success-color); margin-bottom: 20px; opacity: 0.5;"></i>
                    <h3 style="color: var(--text-dark); margin-bottom: 10px; font-size: 1.3rem;">Aucune demande en attente</h3>
                    <p style="color: var(--text-light);">Toutes les demandes de crédit ont été traitées.</p>
                </div>
            <?php else: ?>
                <div class="demandes-grid">
                    <?php foreach ($demandes as $demande): ?>
                        <?php
                        // Calculer le montant maximum empruntable
                        $limite_info = calculerMontantMaxEmpruntable($pdo, $demande['id_membre']);
                        $montant_max = $limite_info['montant_max'];
                        $montant_demande = $demande['montant'];
                        $pourcentage_limite = $montant_max > 0 ? ($montant_demande / $montant_max) * 100 : 0;
                        $est_dans_limite = $montant_demande <= $montant_max;
                        ?>
                        
                        <div class="demande-card">
                            <div class="demande-header">
                                <div class="demande-title">
                                    <i class="fas fa-user-circle"></i>
                                    <?php echo htmlspecialchars($demande['nom_complet']); ?>
                                </div>
                                <div class="demande-badge">
                                    #<?php echo $demande['id_credit']; ?>
                                </div>
                            </div>
                            
                            <div class="demande-body">
                                <div class="demande-info">
                                    <div class="info-row">
                                        <span class="info-label">Montant demandé</span>
                                        <span class="info-value montant-value">
                                            <?php echo number_format($montant_demande, 0, ',', ' '); ?> FCFA
                                        </span>
                                    </div>
                                    
                                    <div class="info-row">
                                        <span class="info-label">Taux d'intérêt</span>
                                        <span class="info-value"><?php echo $demande['taux_interet']; ?>%</span>
                                    </div>
                                    
                                    <div class="info-row">
                                        <span class="info-label">Durée</span>
                                        <span class="info-value"><?php echo $demande['duree_mois']; ?> mois</span>
                                    </div>
                                    
                                    <div class="info-row">
                                        <span class="info-label">Date demande</span>
                                        <span class="info-value"><?php echo date('d/m/Y', strtotime($demande['date_emprunt'])); ?></span>
                                    </div>
                                    
                                    <div class="info-row">
                                        <span class="info-label">Tél. membre</span>
                                        <span class="info-value"><?php echo htmlspecialchars($demande['telephone']); ?></span>
                                    </div>
                                    
                                    <div class="info-row">
                                        <span class="info-label">Ancienneté</span>
                                        <span class="info-value"><?php echo $demande['anciennete_jours']; ?> jours</span>
                                    </div>
                                </div>
                                
                                <!-- Information sur la limite -->
                                <div class="limite-info">
                                    <div class="limite-title">
                                        <i class="fas fa-calculator"></i>
                                        Calcul de la limite d'emprunt
                                    </div>
                                    <div class="limite-details">
                                        <div style="margin-bottom: 5px;">
                                            Total cotisé: <strong><?php echo number_format($limite_info['total_cotisations'], 0, ',', ' '); ?> FCFA</strong>
                                        </div>
                                        <div style="margin-bottom: 5px;">
                                            Nombre de membres: <strong><?php echo $limite_info['nb_membres']; ?></strong>
                                        </div>
                                        <div>
                                            Limite max: <strong><?php echo number_format($montant_max, 0, ',', ' '); ?> FCFA</strong>
                                            (cotisé × membres × 100)
                                        </div>
                                        <div style="margin-top: 8px; padding: 8px; background: <?php echo $est_dans_limite ? 'rgba(16, 185, 129, 0.2)' : 'rgba(239, 68, 68, 0.2)'; ?>; border-radius: 6px;">
                                            <strong><?php echo number_format($pourcentage_limite, 1); ?>%</strong> de la limite utilisée
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Actions -->
                                <div class="demande-actions">
                                    <?php if ($est_dans_limite): ?>
                                        <form method="POST" style="flex: 1;" onsubmit="return confirm('Valider ce crédit de <?php echo number_format($montant_demande, 0, ',', ' '); ?> FCFA ?')">
                                            <input type="hidden" name="credit_id" value="<?php echo $demande['id_credit']; ?>">
                                            <input type="hidden" name="action" value="valider">
                                            <button type="submit" class="btn btn-success">
                                                <i class="fas fa-check"></i>
                                                Valider
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <div style="flex: 1;">
                                            <button class="btn btn-secondary" disabled>
                                                <i class="fas fa-ban"></i>
                                                Montant trop élevé
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <button type="button" class="btn btn-danger" onclick="openRefusModal(<?php echo $demande['id_credit']; ?>, '<?php echo addslashes($demande['nom_complet']); ?>')">
                                        <i class="fas fa-times"></i>
                                        Refuser
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Historique des Décisions -->
            <div class="historique-section">
                <h3 class="section-title">
                    <i class="fas fa-history"></i>
                    Historique récent des décisions
                </h3>
                
                <?php if (!empty($historique)): ?>
                    <table class="historique-table">
                        <thead>
                            <tr>
                                <th>Membre</th>
                                <th>Montant</th>
                                <th>Date</th>
                                <th>Statut</th>
                                <th>Durée</th>
                                <th>Taux</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($historique as $credit): ?>
                                <tr>
                                    <td style="font-weight: 600;"><?php echo htmlspecialchars($credit['nom_complet']); ?></td>
                                    <td style="font-weight: 700; color: var(--dark-blue);">
                                        <?php echo number_format($credit['montant'], 0, ',', ' '); ?> F
                                    </td>
                                    <td><?php echo date('d/m/Y', strtotime($credit['date_emprunt'])); ?></td>
                                    <td>
                                        <?php if ($credit['statut'] === 'en_cours'): ?>
                                            <span class="status-badge status-en_cours">
                                                <i class="fas fa-check-circle"></i> Validé
                                            </span>
                                        <?php else: ?>
                                            <span class="status-badge status-refuse">
                                                <i class="fas fa-times-circle"></i> Refusé
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $credit['duree_mois']; ?> mois</td>
                                    <td><?php echo $credit['taux_interet']; ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: var(--text-light);">
                        <i class="fas fa-history" style="font-size: 3rem; opacity: 0.3; margin-bottom: 15px;"></i>
                        <p>Aucune décision récente</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Modal de refus -->
    <div id="refusModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-times-circle" style="color: var(--danger-color);"></i>
                    Refuser une demande de crédit
                </h3>
            </div>
            <div class="modal-body">
                <p style="margin-bottom: 20px; color: var(--text-dark);">
                    Vous êtes sur le point de refuser la demande de crédit de <strong id="modalMembreNom"></strong>.
                    Veuillez indiquer la raison du refus :
                </p>
                
                <form id="refusForm" method="POST">
                    <input type="hidden" name="credit_id" id="modalCreditId">
                    <input type="hidden" name="action" value="refuser">
                    
                    <div class="form-group">
                        <label for="raison_refus">
                            <i class="fas fa-comment-alt"></i>
                            Raison du refus *
                        </label>
                        <textarea 
                            id="raison_refus" 
                            name="raison_refus" 
                            class="form-control" 
                            placeholder="Expliquez la raison du refus (dépassement de limite, historique de paiement, etc.)"
                            required
                        ></textarea>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeModal()">
                            <i class="fas fa-times"></i>
                            Annuler
                        </button>
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-ban"></i>
                            Confirmer le refus
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Mettre à jour la date
        function updateDateTime() {
            const now = new Date();
            const options = {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            };
            const dateStr = now.toLocaleDateString('fr-FR', options);
            document.getElementById('currentDate').textContent = dateStr;
        }
        
        updateDateTime();
        setInterval(updateDateTime, 60000);

        // Gestion des modals
        function openRefusModal(creditId, membreNom) {
            document.getElementById('modalCreditId').value = creditId;
            document.getElementById('modalMembreNom').textContent = membreNom;
            document.getElementById('raison_refus').value = '';
            document.getElementById('refusModal').style.display = 'flex';
        }
        
        function closeModal() {
            document.getElementById('refusModal').style.display = 'none';
        }

        // Fermer modal en cliquant à l'extérieur
        document.getElementById('refusModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Validation du formulaire de refus
        document.getElementById('refusForm').addEventListener('submit', function(e) {
            const raison = document.getElementById('raison_refus').value.trim();
            if (!raison) {
                e.preventDefault();
                alert('Veuillez indiquer la raison du refus.');
                document.getElementById('raison_refus').focus();
            } else {
                if (!confirm('Êtes-vous sûr de vouloir refuser cette demande de crédit ? Cette action est irréversible.')) {
                    e.preventDefault();
                }
            }
        });

        // Animation des cartes
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.demande-card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
            });
        });
    </script>
</body>
</html>