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
$is_admin = false;

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

// Traitement du formulaire de paiement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payer_cotisation'])) {
    $id_seance = $_POST['id_seance'];
    $montant = $_POST['montant'];
    $penalite = $_POST['penalite'];
    
    try {
        $pdo->beginTransaction();
        
        // Insertion de la cotisation
        $stmt_insert = $pdo->prepare("
            INSERT INTO cotisation (id_membre, id_seance, montant, date_paiement, statut) 
            VALUES (?, ?, ?, CURDATE(), 'payé')
        ");
        $stmt_insert->execute([$user_id, $id_seance, $montant]);
        $id_cotisation = $pdo->lastInsertId();
        
        // Si pénalité, l'insérer
        if ($penalite > 0) {
            $stmt_penalite = $pdo->prepare("
                INSERT INTO penalite (id_membre, montant, raison, date_penalite, statut_paiement, id_cotisation_penalite) 
                VALUES (?, ?, 'Retard de paiement cotisation', CURDATE(), 'impayé', ?)
            ");
            $stmt_penalite->execute([$user_id, $penalite, $id_cotisation]);
        }
        
        $pdo->commit();
        $message_success = "Cotisation payée avec succès !";
    } catch(Exception $e) {
        $pdo->rollBack();
        $message_error = "Erreur lors du paiement : " . $e->getMessage();
    }
}

// Récupération des tontines du membre
$stmt_tontines = $pdo->prepare("
    SELECT DISTINCT t.* 
    FROM tontine t
    INNER JOIN beneficiaire b ON t.id_tontine = b.id_tontine
    WHERE b.id_membre = ? AND t.statut = 'active'
    ORDER BY t.nom_tontine
");
$stmt_tontines->execute([$user_id]);
$tontines = $stmt_tontines->fetchAll();

$id_tontine_selectionnee = $_GET['tontine'] ?? ($tontines[0]['id_tontine'] ?? null);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mes Cotisations | Gestion de Tontine</title>
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

        /* ==================== LAYOUT ==================== */
        .app-container {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }

        /* Sidebar (identique au dashboard) */
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

        .sidebar-header {
            display: flex;
            flex-direction: column;
            align-items: center;
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

        .nav-menu {
            display: flex;
            flex-direction: column;
            padding: 20px;
            flex: 1;
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
            font-weight: 500;
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

        .sidebar-footer {
            display: flex;
            flex-direction: column;
            padding: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            gap: 10px;
        }

        .logout-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            text-decoration: none;
            border-radius: 10px;
            background: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
            transition: var(--transition);
        }

        .logout-btn:hover {
            background: rgba(239, 68, 68, 0.3);
            color: white;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
            width: calc(100% - 280px);
            min-height: 100vh;
        }

        /* Header */
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

        .page-title {
            display: flex;
            flex-direction: column;
            gap: 10px;
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

        .user-badge {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            color: white;
        }

        .user-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent-gold), var(--light-blue));
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: white;
            font-size: 1.1rem;
        }

        .user-info h3 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 3px;
        }

        .user-info p {
            font-size: 0.85rem;
            opacity: 0.8;
        }

        /* Alert Messages */
        .alert-container {
            margin-bottom: 30px;
        }

        .alert {
            padding: 20px 25px;
            border-radius: var(--border-radius);
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

        /* Main Card */
        .main-card {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border-radius: var(--border-radius);
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 8px 32px var(--shadow-medium);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .card-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--navy-blue);
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .card-title i {
            color: var(--accent-gold);
        }

        /* Tontine Selection */
        .tontine-selection {
            margin-bottom: 30px;
        }

        .select-wrapper {
            position: relative;
            margin-bottom: 20px;
        }

        .select-wrapper i {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--accent-gold);
            font-size: 1.1rem;
            z-index: 1;
        }

        .select-wrapper select {
            width: 100%;
            padding: 16px 20px 16px 50px;
            border: 2px solid rgba(58, 95, 192, 0.2);
            border-radius: 12px;
            font-size: 1rem;
            font-family: 'Poppins', sans-serif;
            background: white;
            color: var(--text-dark);
            transition: var(--transition);
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%230f1a3a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 16px center;
            background-size: 20px;
        }

        .select-wrapper select:focus {
            outline: none;
            border-color: var(--light-blue);
            box-shadow: 0 0 0 3px rgba(58, 95, 192, 0.1);
        }

        /* Tontine Info Grid */
        .tontine-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
            padding: 25px;
            background: linear-gradient(135deg, rgba(58, 95, 192, 0.1), rgba(212, 175, 55, 0.1));
            border-radius: var(--border-radius);
            border: 1px solid rgba(58, 95, 192, 0.2);
        }

        .info-card {
            text-align: center;
            padding: 20px;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 12px;
            transition: var(--transition);
        }

        .info-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        .info-icon {
            width: 50px;
            height: 50px;
            margin: 0 auto 15px;
            background: linear-gradient(135deg, var(--medium-blue), var(--light-blue));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            color: white;
        }

        .info-label {
            font-size: 0.85rem;
            color: var(--text-light);
            margin-bottom: 8px;
            font-weight: 500;
        }

        .info-value {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--navy-blue);
        }

        /* Seances Grid */
        .seances-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
            margin-top: 30px;
        }

        .seance-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 25px;
            box-shadow: 0 4px 20px var(--shadow-light);
            transition: var(--transition);
            border: 2px solid transparent;
            position: relative;
            overflow: hidden;
        }

        .seance-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(90deg, var(--accent-gold), var(--accent-light));
        }

        .seance-card.en-retard::before {
            background: linear-gradient(90deg, var(--danger), #f97316);
        }

        .seance-card.payee::before {
            background: linear-gradient(90deg, var(--success), #22c55e);
        }

        .seance-card.a-venir::before {
            background: linear-gradient(90deg, var(--info), #60a5fa);
        }

        .seance-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 15px 35px var(--shadow-medium);
            border-color: var(--light-blue);
        }

        .seance-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
        }

        .seance-date {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--navy-blue);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .status-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: white;
        }

        .payee { background: var(--success); }
        .en-retard { background: var(--danger); }
        .a-venir { background: var(--info); }

        .seance-details {
            margin-top: 20px;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.95rem;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            color: var(--text-light);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .detail-value {
            color: var(--text-dark);
            font-weight: 600;
        }

        /* Penalty Alert */
        .penalty-alert {
            margin-top: 20px;
            padding: 20px;
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(249, 115, 22, 0.1));
            border-radius: 12px;
            border-left: 4px solid var(--danger);
        }

        .penalty-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }

        .penalty-header i {
            color: var(--danger);
            font-size: 1.2rem;
        }

        .penalty-header h4 {
            color: var(--danger);
            font-weight: 700;
            font-size: 1rem;
        }

        .penalty-amount {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--danger);
            margin-bottom: 5px;
        }

        .penalty-calc {
            font-size: 0.85rem;
            color: var(--text-light);
        }

        /* Payment Button */
        .payment-section {
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
        }

        .btn {
            width: 100%;
            padding: 16px 24px;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-family: 'Poppins', sans-serif;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--light-blue), var(--medium-blue));
            color: white;
            box-shadow: 0 4px 15px rgba(58, 95, 192, 0.3);
        }

        .btn-primary:hover:not(:disabled) {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(58, 95, 192, 0.4);
        }

        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success), #22c55e);
            color: white;
        }

        /* Summary Cards */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 40px;
        }

        .summary-card {
            padding: 25px;
            border-radius: var(--border-radius);
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            text-align: center;
            transition: var(--transition);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.1);
        }

        .summary-icon {
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

        .summary-label {
            font-size: 0.9rem;
            color: var(--text-light);
            margin-bottom: 8px;
            font-weight: 500;
        }

        .summary-value {
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 5px;
        }

        /* Empty State */
        .empty-state {
            grid-column: 1/-1;
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

        .empty-state p {
            font-size: 1rem;
            max-width: 500px;
            margin: 0 auto;
            line-height: 1.6;
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

        /* ==================== RESPONSIVE DESIGN ==================== */
        
        /* Tablets and below (1024px) */
        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .main-content {
                margin-left: 0;
                width: 100%;
                padding-bottom: 80px;
            }
            
            .mobile-nav {
                display: block;
            }
            
            .seances-grid {
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            }
        }
        
        /* Medium Mobile (768px) */
        @media (max-width: 768px) {
            .main-content {
                padding: 20px;
            }
            
            .header-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .user-badge {
                width: 100%;
                justify-content: flex-start;
            }
            
            .seances-grid {
                grid-template-columns: 1fr;
            }
            
            .tontine-info-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .summary-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        /* Small Mobile (480px) */
        @media (max-width: 480px) {
            .main-content {
                padding: 15px;
            }
            
            .page-title h1 {
                font-size: 1.5rem;
            }
            
            .main-card {
                padding: 20px;
            }
            
            .tontine-info-grid {
                grid-template-columns: 1fr;
                padding: 15px;
            }
            
            .summary-grid {
                grid-template-columns: 1fr;
            }
            
            .seance-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .status-badge {
                align-self: flex-start;
            }
        }
        
        /* Very Small Mobile (360px) */
        @media (max-width: 360px) {
            .page-title h1 {
                font-size: 1.3rem;
            }
            
            .info-card {
                padding: 15px;
            }
            
            .seance-card {
                padding: 20px;
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
                <a href="cotisation.php" class="nav-item active">
                    <i class="fas fa-money-bill-wave"></i>
                    <span>Cotisations</span>
                </a>
                <a href="credit.php" class="nav-item">
                    <i class="fas fa-credit-card"></i>
                    <span>Crédits</span>
                </a>
            </nav>

            <div class="sidebar-footer">
                <a href="logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Déconnexion</span>
                </a>
            </div>
        </aside>
        <?php endif; ?>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <header class="header">
                <div class="header-content">
                    <div class="page-title">
                        <h1>
                            <i class="fas fa-money-bill-wave"></i>
                            Gestion des Cotisations
                        </h1>
                        <p class="text-light">Sélectionnez une tontine et gérez vos paiements</p>
                    </div>
                    
                    <div class="user-badge">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($user['prenom'], 0, 1)); ?>
                        </div>
                        <div class="user-info">
                            <h3><?php echo htmlspecialchars($user['prenom'] . ' ' . $user['nom']); ?></h3>
                            <p><?php echo htmlspecialchars($user['telephone']); ?></p>
                        </div>
                    </div>
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

            <!-- Main Card -->
            <div class="main-card">
                <h2 class="card-title">
                    <i class="fas fa-hand-holding-usd"></i>
                    Mes Cotisations
                </h2>

                <!-- Tontine Selection -->
                <div class="tontine-selection">
                    <div class="select-wrapper">
                        <i class="fas fa-filter"></i>
                        <select name="tontine" id="tontineSelect" onchange="window.location.href = '?tontine=' + this.value">
                            <option value="">-- Choisir une tontine --</option>
                            <?php foreach ($tontines as $tontine): ?>
                                <option value="<?= $tontine['id_tontine'] ?>" 
                                    <?= $id_tontine_selectionnee == $tontine['id_tontine'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($tontine['nom_tontine']) ?> 
                                    (<?= number_format($tontine['montant_cotisation'], 0, ',', ' ') ?> FCFA - <?= $tontine['frequence'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($id_tontine_selectionnee): ?>
                        <?php
                        $stmt_tontine = $pdo->prepare("SELECT * FROM tontine WHERE id_tontine = ?");
                        $stmt_tontine->execute([$id_tontine_selectionnee]);
                        $tontine_active = $stmt_tontine->fetch();
                        ?>
                        
                        <div class="tontine-info-grid">
                            <div class="info-card">
                                <div class="info-icon">
                                    <i class="fas fa-cube"></i>
                                </div>
                                <div class="info-label">Type de Tontine</div>
                                <div class="info-value"><?= ucfirst($tontine_active['type_tontine']) ?></div>
                            </div>
                            
                            <div class="info-card">
                                <div class="info-icon">
                                    <i class="fas fa-coins"></i>
                                </div>
                                <div class="info-label">Montant Cotisation</div>
                                <div class="info-value"><?= number_format($tontine_active['montant_cotisation'], 0, ',', ' ') ?> FCFA</div>
                            </div>
                            
                            <div class="info-card">
                                <div class="info-icon">
                                    <i class="fas fa-calendar"></i>
                                </div>
                                <div class="info-label">Fréquence</div>
                                <div class="info-value"><?= ucfirst($tontine_active['frequence']) ?></div>
                            </div>
                            
                            <div class="info-card">
                                <div class="info-icon">
                                    <i class="fas fa-chart-line"></i>
                                </div>
                                <div class="info-label">Statut</div>
                                <div class="info-value" style="color: <?= $tontine_active['statut'] == 'active' ? 'var(--success)' : 'var(--warning)' ?>">
                                    <?= ucfirst($tontine_active['statut']) ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($id_tontine_selectionnee): ?>
                    <?php
                    // Récupération des paramètres de pénalité
                    $stmt_param = $pdo->query("SELECT * FROM parametres_penalites WHERE type_penalite = 'cotisation_retard_hebdo' AND actif = 1");
                    $param_penalite = $stmt_param->fetch();
                    $taux_penalite = $param_penalite['taux_penalite'] ?? 10;
                    $delai_jours = $param_penalite['delai_jours'] ?? 7;

                    // Récupération des séances
                    $stmt_seances = $pdo->prepare("
                        SELECT s.*, 
                            c.id_cotisation,
                            c.statut as statut_cotisation,
                            c.date_paiement,
                            DATEDIFF(CURDATE(), s.date_seance) as jours_retard
                        FROM seance s
                        LEFT JOIN cotisation c ON s.id_seance = c.id_seance AND c.id_membre = ?
                        WHERE s.id_tontine = ?
                        ORDER BY s.date_seance DESC
                    ");
                    $stmt_seances->execute([$user_id, $id_tontine_selectionnee]);
                    $seances = $stmt_seances->fetchAll();

                    $total_paye = 0;
                    $total_en_retard = 0;
                    $total_a_venir = 0;
                    $total_penalites = 0;
                    ?>

                    <div class="seances-grid">
                        <?php if (empty($seances)): ?>
                            <div class="empty-state">
                                <i class="fas fa-calendar-times"></i>
                                <h3>Aucune séance disponible</h3>
                                <p>Aucune séance n'a été programmée pour cette tontine</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($seances as $seance): ?>
                                <?php
                                $date_seance = new DateTime($seance['date_seance']);
                                $aujourd_hui = new DateTime();
                                $jours_retard = $seance['jours_retard'];
                                
                                // Déterminer le statut
                                if ($seance['statut_cotisation'] === 'payé') {
                                    $statut = 'payee';
                                    $statut_label = 'Payée';
                                    $penalite = 0;
                                    $total_paye++;
                                } elseif ($date_seance > $aujourd_hui) {
                                    $statut = 'a-venir';
                                    $statut_label = 'À venir';
                                    $penalite = 0;
                                    $total_a_venir++;
                                } else {
                                    $statut = 'en-retard';
                                    $statut_label = 'En retard';
                                    // Calcul de la pénalité
                                    $semaines_retard = floor($jours_retard / $delai_jours);
                                    $penalite = ($tontine_active['montant_cotisation'] * $taux_penalite / 100) * $semaines_retard;
                                    $total_en_retard++;
                                    $total_penalites += $penalite;
                                }
                                ?>
                                
                                <div class="seance-card <?= $statut ?>">
                                    <div class="seance-header">
                                        <div class="seance-date">
                                            <i class="fas fa-calendar-alt"></i>
                                            <?= $date_seance->format('d/m/Y') ?>
                                        </div>
                                        <span class="status-badge <?= $statut ?>">
                                            <?= $statut_label ?>
                                        </span>
                                    </div>

                                    <div class="seance-details">
                                        <div class="detail-row">
                                            <span class="detail-label">
                                                <i class="fas fa-clock"></i>
                                                Heure
                                            </span>
                                            <span class="detail-value"><?= substr($seance['heure_debut'], 0, 5) ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="detail-label">
                                                <i class="fas fa-map-marker-alt"></i>
                                                Lieu
                                            </span>
                                            <span class="detail-value"><?= htmlspecialchars($seance['lieu']) ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="detail-label">
                                                <i class="fas fa-coins"></i>
                                                Montant
                                            </span>
                                            <span class="detail-value" style="color: var(--success); font-weight: 700;">
                                                <?= number_format($tontine_active['montant_cotisation'], 0, ',', ' ') ?> FCFA
                                            </span>
                                        </div>

                                        <?php if ($statut === 'en-retard' && $penalite > 0): ?>
                                            <div class="penalty-alert">
                                                <div class="penalty-header">
                                                    <i class="fas fa-exclamation-triangle"></i>
                                                    <h4>Pénalité de retard</h4>
                                                </div>
                                                <div class="penalty-amount">
                                                    <?= number_format($penalite, 0, ',', ' ') ?> FCFA
                                                </div>
                                                <div class="penalty-calc">
                                                    <?= $jours_retard ?> jours de retard (<?= $semaines_retard ?> semaine<?= $semaines_retard > 1 ? 's' : '' ?>) 
                                                    × <?= $taux_penalite ?>%
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($seance['statut_cotisation'] === 'payé'): ?>
                                            <div class="payment-section">
                                                <button class="btn btn-success" disabled>
                                                    <i class="fas fa-check-circle"></i>
                                                    Payée le <?= date('d/m/Y', strtotime($seance['date_paiement'])) ?>
                                                </button>
                                            </div>
                                        <?php elseif ($statut === 'en-retard' && !$seance['id_cotisation']): ?>
                                            <div class="payment-section">
                                                <form method="POST">
                                                    <input type="hidden" name="id_seance" value="<?= $seance['id_seance'] ?>">
                                                    <input type="hidden" name="montant" value="<?= $tontine_active['montant_cotisation'] ?>">
                                                    <input type="hidden" name="penalite" value="<?= $penalite ?>">
                                                    <button type="submit" name="payer_cotisation" class="btn btn-primary">
                                                        <i class="fas fa-credit-card"></i>
                                                        Payer maintenant
                                                        <br>
                                                        <small style="opacity: 0.9; font-weight: 400;">
                                                            Total: <?= number_format($tontine_active['montant_cotisation'] + $penalite, 0, ',', ' ') ?> FCFA
                                                        </small>
                                                    </button>
                                                </form>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($seances)): ?>
                        <div class="summary-grid">
                            <div class="summary-card">
                                <div class="summary-icon" style="background: linear-gradient(135deg, var(--success), #22c55e);">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                                <div class="summary-label">Séances payées</div>
                                <div class="summary-value" style="color: var(--success);"><?= $total_paye ?></div>
                                <div class="summary-subtitle">À jour</div>
                            </div>
                            
                            <div class="summary-card">
                                <div class="summary-icon" style="background: linear-gradient(135deg, var(--danger), #f97316);">
                                    <i class="fas fa-exclamation-triangle"></i>
                                </div>
                                <div class="summary-label">Séances en retard</div>
                                <div class="summary-value" style="color: var(--danger);"><?= $total_en_retard ?></div>
                                <div class="summary-subtitle">À régulariser</div>
                            </div>
                            
                            <div class="summary-card">
                                <div class="summary-icon" style="background: linear-gradient(135deg, var(--info), #60a5fa);">
                                    <i class="fas fa-calendar-check"></i>
                                </div>
                                <div class="summary-label">Séances à venir</div>
                                <div class="summary-value" style="color: var(--info);"><?= $total_a_venir ?></div>
                                <div class="summary-subtitle">Programmées</div>
                            </div>
                            
                            <div class="summary-card">
                                <div class="summary-icon" style="background: linear-gradient(135deg, var(--warning), #fbbf24);">
                                    <i class="fas fa-money-bill-wave"></i>
                                </div>
                                <div class="summary-label">Total pénalités</div>
                                <div class="summary-value" style="color: var(--warning);">
                                    <?= number_format($total_penalites, 0, ',', ' ') ?> F
                                </div>
                                <div class="summary-subtitle">À payer</div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-hand-holding-usd"></i>
                        <h3>Aucune tontine sélectionnée</h3>
                        <p>Veuillez sélectionner une tontine dans la liste déroulante pour voir vos cotisations</p>
                    </div>
                <?php endif; ?>
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
            <?php if ($is_admin): ?>
                <a href="membre.php" class="mobile-nav-item">
                    <i class="fas fa-users mobile-nav-icon"></i>
                    <span class="mobile-nav-text">Membres</span>
                </a>
                <a href="tontine.php" class="mobile-nav-item">
                    <i class="fas fa-hand-holding-usd mobile-nav-icon"></i>
                    <span class="mobile-nav-text">Tontines</span>
                </a>
                <a href="cotisation.php" class="mobile-nav-item active">
                    <i class="fas fa-money-bill-wave mobile-nav-icon"></i>
                    <span class="mobile-nav-text">Cotisations</span>
                </a>
            <?php else: ?>
                <a href="cotisation.php" class="mobile-nav-item active">
                    <i class="fas fa-money-bill-wave mobile-nav-icon"></i>
                    <span class="mobile-nav-text">Cotisations</span>
                </a>
                <a href="seances.php" class="mobile-nav-item">
                    <i class="fas fa-hand-holding-usd mobile-nav-icon"></i>
                    <span class="mobile-nav-text">Tontines</span>
                </a>
                <a href="credit.php" class="mobile-nav-item">
                    <i class="fas fa-credit-card mobile-nav-icon"></i>
                    <span class="mobile-nav-text">Crédits</span>
                </a>
            <?php endif; ?>
            <a href="logout.php" class="mobile-nav-item">
                <i class="fas fa-sign-out-alt mobile-nav-icon"></i>
                <span class="mobile-nav-text">Sortir</span>
            </a>
        </div>
    </nav>

    <script>
        // Animation des cartes
        document.addEventListener('DOMContentLoaded', () => {
            const cards = document.querySelectorAll('.seance-card, .info-card, .summary-card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
                card.style.animation = 'slideIn 0.5s ease-out forwards';
                card.style.opacity = '0';
            });

            // Effets de survol améliorés
            const seanceCards = document.querySelectorAll('.seance-card');
            seanceCards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-10px) scale(1.02)';
                    this.style.boxShadow = '0 20px 40px rgba(0, 0, 0, 0.15)';
                });

                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                    this.style.boxShadow = '0 4px 20px rgba(15, 26, 58, 0.1)';
                });
            });

            // Confirmation de paiement
            const paymentForms = document.querySelectorAll('form[method="POST"]');
            paymentForms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const button = this.querySelector('button[type="submit"]');
                    const originalText = button.innerHTML;
                    
                    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Traitement en cours...';
                    button.disabled = true;
                    
                    setTimeout(() => {
                        button.innerHTML = originalText;
                        button.disabled = false;
                    }, 3000);
                });
            });

            // Auto-refresh pour les séances en retard
            setInterval(() => {
                const enRetardCards = document.querySelectorAll('.seance-card.en-retard');
                enRetardCards.forEach(card => {
                    const badge = card.querySelector('.status-badge');
                    if (badge) {
                        badge.style.animation = badge.style.animation ? '' : 'pulse 2s infinite';
                    }
                });
            }, 5000);
        });

        // Animation CSS pour le pulse
        const style = document.createElement('style');
        style.textContent = `
            @keyframes pulse {
                0% { opacity: 1; }
                50% { opacity: 0.7; }
                100% { opacity: 1; }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>