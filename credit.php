<?php
session_start();
include './fonctions/config.php';

// Vérifier la connexion
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Récupérer les informations de l'utilisateur connecté
$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT role, nom, prenom FROM membre WHERE id_membre = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Vérifier si l'utilisateur est admin (seuls les admins peuvent voir tous les crédits)
if (!$user || $user['role'] !== 'admin') {
    header('Location: dashboard.php');
    exit();
}

// Variables pour le filtrage et la pagination
$search = $_GET['search'] ?? '';
$statut_filter = $_GET['statut'] ?? '';
$date_debut = $_GET['date_debut'] ?? '';
$date_fin = $_GET['date_fin'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

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
    // Requête pour les données des crédits avec informations des membres
    $query = "
        SELECT 
            c.*,
            m.nom,
            m.prenom,
            m.telephone,
            m.date_inscription,
            m.statut as statut_membre,
            CONCAT(m.nom, ' ', m.prenom) as nom_complet,
            ROUND(c.montant * (c.taux_interet/100) * (c.duree_mois/12), 2) as interet_total,
            ROUND(c.montant + (c.montant * (c.taux_interet/100) * (c.duree_mois/12)), 2) as montant_total_rembourser
        FROM credit c
        INNER JOIN membre m ON c.id_membre = m.id_membre
        $whereSQL
        ORDER BY c.date_emprunt DESC, c.id_credit DESC
        LIMIT $limit OFFSET $offset
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $credits = $stmt->fetchAll();

    // Requête pour le total
    $countQuery = "
        SELECT COUNT(*) as total
        FROM credit c
        INNER JOIN membre m ON c.id_membre = m.id_membre
        $whereSQL
    ";

    $countStmt = $pdo->prepare($countQuery);
    $countStmt->execute($params);
    $totalResult = $countStmt->fetch();
    $totalCredits = $totalResult['total'];
    $totalPages = ceil($totalCredits / $limit);

    // Calculer les statistiques
    $statsQuery = "
        SELECT 
            COUNT(*) as total_credits,
            SUM(montant) as montant_total,
            AVG(montant) as moyenne_montant,
            SUM(montant_restant) as total_restant,
            MIN(date_emprunt) as plus_ancien,
            MAX(date_emprunt) as plus_recent,
            COUNT(CASE WHEN statut = 'en_cours' THEN 1 END) as credits_en_cours,
            COUNT(CASE WHEN statut = 'en_retard' THEN 1 END) as credits_en_retard,
            COUNT(CASE WHEN statut = 'rembourse' THEN 1 END) as credits_rembourses
        FROM credit
    ";
    $stats = $pdo->query($statsQuery)->fetch();

    // Récupérer les membres ayant le plus de crédits
    $topMembresQuery = "
        SELECT 
            m.id_membre,
            m.nom,
            m.prenom,
            COUNT(c.id_credit) as nb_credits,
            SUM(c.montant) as total_emprunte
        FROM credit c
        INNER JOIN membre m ON c.id_membre = m.id_membre
        GROUP BY c.id_membre
        ORDER BY nb_credits DESC, total_emprunte DESC
        LIMIT 5
    ";
    $topMembres = $pdo->query($topMembresQuery)->fetchAll();

    // Récupérer la répartition par statut
    $repartitionQuery = "
        SELECT 
            statut,
            COUNT(*) as nombre,
            SUM(montant) as montant_total
        FROM credit
        GROUP BY statut
        ORDER BY nombre DESC
    ";
    $repartition = $pdo->query($repartitionQuery)->fetchAll();
} catch (PDOException $e) {
    $error = "Erreur lors de la récupération des données: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Crédits | Gestion de Tontine</title>
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
            --glass-bg: rgba(255, 255, 255, 0.9);
            --shadow-light: rgba(15, 26, 58, 0.1);
            --shadow-medium: rgba(15, 26, 58, 0.2);
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
            width: 100%;
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

        .stat-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
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

        /* Filters Card */
        .filter-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 30px;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        .filter-group label {
            display: block;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .filter-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 0.95rem;
            transition: var(--transition);
            background: white;
        }

        .filter-input:focus {
            outline: none;
            border-color: var(--accent-gold);
            box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.1);
        }

        .filter-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
        }

        /* Table Container */
        .table-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            overflow: hidden;
            margin-bottom: 30px;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .card-header {
            padding: 25px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .table-container {
            overflow-x: auto;
            padding: 0 25px 25px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        thead {
            background: linear-gradient(135deg, var(--medium-blue), var(--light-blue));
            color: white;
        }

        th {
            padding: 16px;
            text-align: left;
            font-weight: 700;
            font-size: 0.85rem;
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
            padding: 16px;
            font-size: 0.9rem;
        }

        /* Status Badges */
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

        .status-en_retard {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-rembourse {
            background: #dbeafe;
            color: #1e40af;
        }

        /* Progress Bar */
        .progress-bar {
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 8px;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--accent-gold), var(--light-blue));
            border-radius: 4px;
            transition: width 0.5s ease;
        }

        /* Info Cards */
        .info-cards {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }

        .info-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 25px;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .info-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.9rem;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: var(--transition);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--medium-blue), var(--light-blue));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(58, 95, 192, 0.3);
        }

        .btn-secondary {
            background: #f1f5f9;
            color: var(--text-dark);
        }

        .btn-secondary:hover {
            background: #e2e8f0;
        }

        .btn-success {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            padding: 25px;
            border-top: 1px solid #e2e8f0;
        }

        .pagination-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: white;
            border: 1px solid #e2e8f0;
            color: var(--text-dark);
            text-decoration: none;
            transition: var(--transition);
        }

        .pagination-btn:hover:not(.disabled) {
            background: linear-gradient(135deg, var(--accent-gold), var(--accent-light));
            color: var(--navy-blue);
            border-color: transparent;
        }

        .pagination-btn.active {
            background: linear-gradient(135deg, var(--medium-blue), var(--light-blue));
            color: white;
            border-color: transparent;
        }

        .pagination-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Mobile Navigation */
        .mobile-nav {
            display: none;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(180deg, #0f1a3a 0%, #1a2b55 100%);
            backdrop-filter: blur(10px);
            border-top: 2px solid var(--accent-gold);
            z-index: 1000;
            height: 70px;
            padding: 0 10px;
        }

        .mobile-nav-container {
            display: flex;
            justify-content: space-around;
            align-items: center;
            height: 100%;
        }

        .mobile-nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 8px 4px;
            color: rgba(255, 255, 255, 0.6);
            text-decoration: none;
            transition: var(--transition);
            border-radius: 8px;
            min-width: 60px;
        }

        .mobile-nav-item:hover,
        .mobile-nav-item.active {
            color: var(--accent-gold);
            background: rgba(212, 175, 55, 0.15);
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .filter-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .info-cards {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .btn{
                padding: 15px 9px;
            }
            .sidebar {
                transform: translateX(-100%);
            }
            
            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 20px;
                padding-bottom: 80px;
            }
            
            .mobile-nav {
                display: block;
            }
            
            .stats-grid {
                flex-direction: column;
            }
            
            .stat-card {
                min-width: 100%;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .info-cards {
                grid-template-columns: 1fr;
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
                <a href="credit.php" class="nav-item active">
                    <i class="fas fa-credit-card"></i>
                    <span>Crédits</span>
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
                        <h1>📊 Gestion des Crédits</h1>
                        <p>Suivi des emprunts et crédits des membres</p>
                    </div>
                    <div class="header-actions">
                        <div class="date-badge">
                            <i class="fas fa-calendar-alt"></i>
                            <span id="currentDate"></span>
                        </div>
                        <div class="role-badge">
                            Admin
                        </div>
                    </div>
                </div>
            </header>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #667eea, #764ba2);">
                            <i class="fas fa-wallet"></i>
                        </div>
                        <span class="stat-badge" style="background: #d1fae5; color: #065f46;">
                            Total
                        </span>
                    </div>
                    <div class="stat-value"><?php echo $stats['total_credits'] ?? 0; ?></div>
                    <div class="stat-label">Crédits Actifs</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #fa709a, #fee140);">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <span class="stat-badge" style="background: #dbeafe; color: #1e40af;">
                            Montant
                        </span>
                    </div>
                    <div class="stat-value">
                        <?php echo number_format($stats['montant_total'] ?? 0, 0, ',', ' '); ?> F
                    </div>
                    <div class="stat-label">Total Emprunté</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #4facfe, #00f2fe);">
                            <i class="fas fa-hourglass-half"></i>
                        </div>
                        <span class="stat-badge" style="background: #fef3c7; color: #92400e;">
                            Restant
                        </span>
                    </div>
                    <div class="stat-value">
                        <?php echo number_format($stats['total_restant'] ?? 0, 0, ',', ' '); ?> F
                    </div>
                    <div class="stat-label">Reste à Payer</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #f093fb, #f5576c);">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <span class="stat-badge" style="background: #fee2e2; color: #991b1b;">
                            Attention
                        </span>
                    </div>
                    <div class="stat-value"><?php echo $stats['credits_en_retard'] ?? 0; ?></div>
                    <div class="stat-label">Crédits en Retard</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filter-card">
                <form method="GET" action="">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label>
                                <i class="fas fa-search"></i> Recherche
                            </label>
                            <input type="text" name="search" placeholder="Nom, prénom, statut..."
                                value="<?php echo htmlspecialchars($search); ?>" class="filter-input">
                        </div>

                        <div class="filter-group">
                            <label>
                                <i class="fas fa-filter"></i> Statut
                            </label>
                            <select name="statut" class="filter-input">
                                <option value="">Tous les statuts</option>
                                <option value="en_cours" <?php echo $statut_filter == 'en_cours' ? 'selected' : ''; ?>>En cours</option>
                                <option value="en_retard" <?php echo $statut_filter == 'en_retard' ? 'selected' : ''; ?>>En retard</option>
                                <option value="rembourse" <?php echo $statut_filter == 'rembourse' ? 'selected' : ''; ?>>Remboursé</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label>
                                <i class="fas fa-calendar-alt"></i> Date début
                            </label>
                            <input type="date" name="date_debut" value="<?php echo htmlspecialchars($date_debut); ?>" class="filter-input">
                        </div>

                        <div class="filter-group">
                            <label>
                                <i class="fas fa-calendar-alt"></i> Date fin
                            </label>
                            <input type="date" name="date_fin" value="<?php echo htmlspecialchars($date_fin); ?>" class="filter-input">
                        </div>
                    </div>

                    <div class="filter-actions">
                        <div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i>
                                Appliquer les filtres
                            </button>
                            <a href="credit.php" class="btn btn-secondary">
                                <i class="fas fa-redo"></i>
                                Réinitialiser
                            </a>
                        </div>
                        <div>
                            <button type="button" onclick="exportToExcel()" class="btn btn-success">
                                <i class="fas fa-file-excel"></i>
                                Exporter Excel
                            </button>
                            <button type="button" onclick="printPage()" class="btn btn-primary">
                                <i class="fas fa-print"></i>
                                Imprimer
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Credits Table -->
            <div class="table-card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-list"></i>
                        Liste des Crédits
                        <span style="font-size: 0.9rem; color: var(--text-light); margin-left: 10px;">
                            (<?php echo $totalCredits; ?> crédit<?php echo $totalCredits > 1 ? 's' : ''; ?>)
                        </span>
                    </h3>
                </div>

                <?php if (isset($error)): ?>
                    <div style="margin: 0 25px 25px; padding: 16px; background: #fee2e2; border-radius: 8px; color: #991b1b; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-exclamation-circle"></i>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>

                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Membre</th>
                                <th>Montant</th>
                                <th>Taux</th>
                                <th>Durée</th>
                                <th>Date Emprunt</th>
                                <th>Reste à Payer</th>
                                <th>Statut</th>
                                <th>Progression</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($credits)): ?>
                                <?php foreach ($credits as $credit): ?>
                                    <?php
                                    $pourcentage_paye = $credit['montant'] > 0 ?
                                        (($credit['montant'] - $credit['montant_restant']) / $credit['montant']) * 100 : 0;
                                    ?>
                                    <tr>
                                        <td style="font-weight: 700; color: var(--medium-blue);">
                                            #<?php echo $credit['id_credit']; ?>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600; color: var(--text-dark);">
                                                <?php echo htmlspecialchars($credit['nom_complet']); ?>
                                            </div>
                                            <div style="font-size: 0.8rem; color: var(--text-light);">
                                                <?php echo $credit['telephone']; ?>
                                            </div>
                                        </td>
                                        <td style="font-weight: 700; color: var(--dark-blue);">
                                            <?php echo number_format($credit['montant'], 0, ',', ' '); ?> FCFA
                                        </td>
                                        <td>
                                            <div style="font-weight: 600;"><?php echo $credit['taux_interet']; ?>%</div>
                                            <div style="font-size: 0.8rem; color: var(--text-light);">
                                                Intérêt: <?php echo number_format($credit['interet_total'], 0, ',', ' '); ?> F
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600;"><?php echo $credit['duree_mois']; ?> mois</div>
                                            <div style="font-size: 0.8rem; color: var(--text-light);">
                                                Total: <?php echo number_format($credit['montant_total_rembourser'], 0, ',', ' '); ?> F
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600;">
                                                <?php echo date('d/m/Y', strtotime($credit['date_emprunt'])); ?>
                                            </div>
                                        </td>
                                        <td style="font-weight: 700; <?php echo $credit['montant_restant'] > 0 ? 'color: #dc2626;' : 'color: #059669;'; ?>">
                                            <?php echo number_format($credit['montant_restant'], 0, ',', ' '); ?> FCFA
                                        </td>
                                        <td>
                                            <?php
                                            $status_class = '';
                                            $status_text = '';
                                            switch ($credit['statut']) {
                                                case 'en_cours':
                                                    $status_class = 'status-en_cours';
                                                    $status_text = 'En cours';
                                                    break;
                                                case 'en_retard':
                                                    $status_class = 'status-en_retard';
                                                    $status_text = 'En retard';
                                                    break;
                                                case 'rembourse':
                                                    $status_class = 'status-rembourse';
                                                    $status_text = 'Remboursé';
                                                    break;
                                            }
                                            ?>
                                            <span class="status-badge <?php echo $status_class; ?>">
                                                <?php echo $status_text; ?>
                                            </span>
                                        </td>
                                        <td style="width: 200px;">
                                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                                                <span style="font-size: 0.8rem; color: var(--text-light);">Progression</span>
                                                <span style="font-weight: 600; font-size: 0.9rem;">
                                                    <?php echo round($pourcentage_paye, 1); ?>%
                                                </span>
                                            </div>
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?php echo $pourcentage_paye; ?>%"></div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" style="text-align: center; padding: 40px;">
                                        <div style="color: var(--text-light);">
                                            <i class="fas fa-credit-card" style="font-size: 3rem; opacity: 0.3; margin-bottom: 15px;"></i>
                                            <p style="font-weight: 600; margin-bottom: 5px;">Aucun crédit trouvé</p>
                                            <p style="font-size: 0.9rem;">Utilisez les filtres pour affiner votre recherche</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>"
                           class="pagination-btn <?php echo $page == 1 ? 'disabled' : ''; ?>">
                            <i class="fas fa-angle-double-left"></i>
                        </a>
                        
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => max(1, $page - 1)])); ?>"
                           class="pagination-btn <?php echo $page == 1 ? 'disabled' : ''; ?>">
                            <i class="fas fa-angle-left"></i>
                        </a>
                        
                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        
                        for ($i = $startPage; $i <= $endPage; $i++):
                        ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"
                               class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => min($totalPages, $page + 1)])); ?>"
                           class="pagination-btn <?php echo $page == $totalPages ? 'disabled' : ''; ?>">
                            <i class="fas fa-angle-right"></i>
                        </a>
                        
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $totalPages])); ?>"
                           class="pagination-btn <?php echo $page == $totalPages ? 'disabled' : ''; ?>">
                            <i class="fas fa-angle-double-right"></i>
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Information Cards -->
            <div class="info-cards">
                <!-- Distribution by Status -->
                <div class="info-card">
                    <h3 class="info-title">
                        <i class="fas fa-chart-pie"></i>
                        Répartition par Statut
                    </h3>
                    <div style="display: flex; flex-direction: column; gap: 15px;">
                        <?php if (!empty($repartition)): ?>
                            <?php foreach ($repartition as $rep): ?>
                                <div>
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <?php
                                            $dot_color = '';
                                            $statut_text = '';
                                            switch ($rep['statut']) {
                                                case 'en_cours':
                                                    $dot_color = '#10b981';
                                                    $statut_text = 'En cours';
                                                    break;
                                                case 'en_retard':
                                                    $dot_color = '#ef4444';
                                                    $statut_text = 'En retard';
                                                    break;
                                                case 'rembourse':
                                                    $dot_color = '#3b82f6';
                                                    $statut_text = 'Remboursé';
                                                    break;
                                                default:
                                                    $dot_color = '#6b7280';
                                                    $statut_text = $rep['statut'];
                                            }
                                            ?>
                                            <div style="width: 10px; height: 10px; background: <?php echo $dot_color; ?>; border-radius: 50%;"></div>
                                            <span style="font-weight: 600; font-size: 0.9rem;"><?php echo $statut_text; ?></span>
                                        </div>
                                        <span style="font-weight: 700;"><?php echo $rep['nombre']; ?></span>
                                    </div>
                                    <div style="font-size: 0.8rem; color: var(--text-light);">
                                        <?php echo number_format($rep['montant_total'], 0, ',', ' '); ?> FCFA
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="text-align: center; padding: 20px; color: var(--text-light);">
                                <i class="fas fa-chart-pie" style="font-size: 2rem; opacity: 0.3; margin-bottom: 10px;"></i>
                                <p>Aucune donnée disponible</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Top Borrowers -->
                <div class="info-card">
                    <h3 class="info-title">
                        <i class="fas fa-chart-bar"></i>
                        Top 5 des Emprunteurs
                    </h3>
                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        <?php if (!empty($topMembres)): ?>
                            <?php foreach ($topMembres as $membre): ?>
                                <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px; background: linear-gradient(135deg, rgba(58, 95, 192, 0.05), rgba(212, 175, 55, 0.05)); border-radius: 10px;">
                                    <div style="display: flex; align-items: center; gap: 12px;">
                                        <div style="width: 40px; height: 40px; background: linear-gradient(135deg, var(--accent-gold), var(--light-blue)); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 1rem;">
                                            <?php echo strtoupper(substr($membre['prenom'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <div style="font-weight: 600; font-size: 0.9rem;">
                                                <?php echo htmlspecialchars($membre['prenom'] . ' ' . $membre['nom']); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div style="text-align: right;">
                                        <div style="font-weight: 700; color: var(--medium-blue); font-size: 0.9rem;">
                                            <?php echo number_format($membre['total_emprunte'], 0, ',', ' '); ?> F
                                        </div>
                                        <div style="font-size: 0.75rem; color: var(--text-light);">
                                            <?php echo $membre['nb_credits']; ?> crédit<?php echo $membre['nb_credits'] > 1 ? 's' : ''; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="text-align: center; padding: 20px; color: var(--text-light);">
                                <i class="fas fa-chart-bar" style="font-size: 2rem; opacity: 0.3; margin-bottom: 10px;"></i>
                                <p>Aucune donnée disponible</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Credit Information -->
                <div class="info-card">
                    <h3 class="info-title">
                        <i class="fas fa-info-circle"></i>
                        Informations sur les Crédits
                    </h3>
                    <div style="display: flex; flex-direction: column; gap: 15px;">
                        <div style="background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(212, 175, 55, 0.1)); padding: 15px; border-radius: 10px;">
                            <h4 style="font-weight: 700; color: var(--dark-blue); margin-bottom: 10px; font-size: 0.9rem;">📊 Statistiques Temporelles</h4>
                            <div style="font-size: 0.8rem; display: flex; flex-direction: column; gap: 5px;">
                                <div style="display: flex; justify-content: space-between;">
                                    <span style="color: var(--text-light);">Plus ancien :</span>
                                    <span style="font-weight: 600;">
                                        <?php echo $stats['plus_ancien'] ? date('d/m/Y', strtotime($stats['plus_ancien'])) : 'N/A'; ?>
                                    </span>
                                </div>
                                <div style="display: flex; justify-content: space-between;">
                                    <span style="color: var(--text-light);">Plus récent :</span>
                                    <span style="font-weight: 600;">
                                        <?php echo $stats['plus_recent'] ? date('d/m/Y', strtotime($stats['plus_recent'])) : 'N/A'; ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div style="background: linear-gradient(135deg, rgba(34, 197, 94, 0.1), rgba(21, 128, 61, 0.1)); padding: 15px; border-radius: 10px;">
                            <h4 style="font-weight: 700; color: #065f46; margin-bottom: 10px; font-size: 0.9rem;">💰 Taux d'Intérêt</h4>
                            <p style="font-size: 0.8rem; color: #065f46;">
                                <i class="fas fa-percentage"></i>
                                Les taux varient de 4.5% à 7% selon la durée et le profil du membre.
                            </p>
                        </div>

                        <div style="background: linear-gradient(135deg, rgba(168, 85, 247, 0.1), rgba(147, 51, 234, 0.1)); padding: 15px; border-radius: 10px;">
                            <h4 style="font-weight: 700; color: #6b21a8; margin-bottom: 10px; font-size: 0.9rem;">⚡ Remboursement</h4>
                            <ul style="font-size: 0.8rem; color: #6b21a8; list-style: none; padding: 0; margin: 0;">
                                <li><i class="fas fa-check-circle" style="margin-right: 5px;"></i> Mensualités fixes</li>
                                <li><i class="fas fa-check-circle" style="margin-right: 5px;"></i> Remboursement anticipé possible</li>
                                <li><i class="fas fa-check-circle" style="margin-right: 5px;"></i> Pénalités de retard appliquées</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Mobile Navigation -->
    <nav class="mobile-nav">
        <div class="mobile-nav-container">
            <a href="dashboard.php" class="mobile-nav-item">
                <i class="fas fa-tachometer-alt mobile-nav-icon"></i>
                <span class="mobile-nav-text">Dashboard</span>
            </a>
            <a href="membre.php" class="mobile-nav-item">
                <i class="fas fa-users mobile-nav-icon"></i>
                <span class="mobile-nav-text">Membres</span>
            </a>
            <a href="tontine.php" class="mobile-nav-item">
                <i class="fas fa-hand-holding-usd mobile-nav-icon"></i>
                <span class="mobile-nav-text">Tontines</span>
            </a>
            <a href="seance.php" class="mobile-nav-item">
                <i class="fas fa-calendar-alt mobile-nav-icon"></i>
                <span class="mobile-nav-text">Séances</span>
            </a>
            <a href="cotisation.php" class="mobile-nav-item">
                <i class="fas fa-money-bill-wave mobile-nav-icon"></i>
                <span class="mobile-nav-text">Cotisations</span>
            </a>
            <a href="credit.php" class="mobile-nav-item active">
                <i class="fas fa-credit-card mobile-nav-icon"></i>
                <span class="mobile-nav-text">Crédits</span>
            </a>
            <a href="logout.php" class="mobile-nav-item">
                <i class="fas fa-sign-out-alt mobile-nav-icon"></i>
                <span class="mobile-nav-text">Sortir</span>
            </a>
        </div>
    </nav>

    <script>
        // Mettre à jour la date et l'heure
        function updateDateTime() {
            const now = new Date();
            const options = {
                weekday: 'short',
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            };
            const dateStr = now.toLocaleDateString('fr-FR', options);
            document.getElementById('currentDate').textContent = dateStr;
        }

        // Initialiser la date
        updateDateTime();
        setInterval(updateDateTime, 60000);

        // Fonctions pour les actions
        function exportToExcel() {
            const params = new URLSearchParams(window.location.search);
            window.open(`./fonctions/export_credits.php?${params.toString()}`, '_blank');
        }

        function printPage() {
            window.print();
        }

        // Initialiser les dates par défaut
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date().toISOString().split('T')[0];
            const dateFinInput = document.querySelector('input[name="date_fin"]');
            if (dateFinInput && !dateFinInput.value) {
                dateFinInput.value = today;
            }

            // Calculer automatiquement la date de début (3 mois avant)
            const dateDebutInput = document.querySelector('input[name="date_debut"]');
            if (dateDebutInput && !dateDebutInput.value) {
                const threeMonthsAgo = new Date();
                threeMonthsAgo.setMonth(threeMonthsAgo.getMonth() - 3);
                dateDebutInput.value = threeMonthsAgo.toISOString().split('T')[0];
            }

            // Animer les barres de progression
            setTimeout(() => {
                const progressBars = document.querySelectorAll('.progress-fill');
                progressBars.forEach(bar => {
                    const currentWidth = bar.style.width;
                    bar.style.transition = 'none';
                    bar.style.width = '0%';
                    setTimeout(() => {
                        bar.style.transition = 'width 1.5s ease';
                        bar.style.width = currentWidth;
                    }, 50);
                });
            }, 500);
        });

        // Détecter l'inactivité
        let inactivityTimer;
        const TIMEOUT = 600000;

        function resetInactivityTimer() {
            clearTimeout(inactivityTimer);
            inactivityTimer = setTimeout(logoutDueToInactivity, TIMEOUT);
        }

        function logoutDueToInactivity() {
            window.location.href = 'index.php?expired=1';
        }

        document.addEventListener('DOMContentLoaded', () => {
            resetInactivityTimer();
            ['mousedown', 'mousemove', 'keydown', 'scroll', 'click', 'touchstart'].forEach(event => {
                document.addEventListener(event, resetInactivityTimer);
            });
        });
    </script>
</body>

</html>