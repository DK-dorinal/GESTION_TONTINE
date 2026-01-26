<?php
session_start();
include './fonctions/config.php';

// Vérifier la connexion
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Vérifier si l'utilisateur est admin
$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT * FROM membre WHERE id_membre = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user || $user['role'] !== 'admin') {
    header('Location: dashboard.php');
    exit();
}

// Gérer les actions CRUD
$action = $_GET['action'] ?? '';
$message = '';
$error = '';

// Ajouter un projet
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_project'])) {
    try {
        $stmt = $pdo->prepare("INSERT INTO projet_fiac (nom_projet, description, montant_budget, date_debut, date_fin, statut) 
                              VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['nom_projet'],
            $_POST['description'],
            $_POST['montant_budget'],
            $_POST['date_debut'],
            $_POST['date_fin'],
            $_POST['statut']
        ]);
        $message = "Projet ajouté avec succès !";
    } catch (PDOException $e) {
        $error = "Erreur lors de l'ajout du projet: " . $e->getMessage();
    }
}

// Modifier un projet
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_project'])) {
    try {
        $stmt = $pdo->prepare("UPDATE projet_fiac SET nom_projet = ?, description = ?, montant_budget = ?, 
                              date_debut = ?, date_fin = ?, statut = ? WHERE id_projet = ?");
        $stmt->execute([
            $_POST['nom_projet'],
            $_POST['description'],
            $_POST['montant_budget'],
            $_POST['date_debut'],
            $_POST['date_fin'],
            $_POST['statut'],
            $_POST['id_projet']
        ]);
        $message = "Projet modifié avec succès !";
    } catch (PDOException $e) {
        $error = "Erreur lors de la modification: " . $e->getMessage();
    }
}

// Supprimer un projet
if ($action === 'delete' && isset($_GET['id'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM projet_fiac WHERE id_projet = ?");
        $stmt->execute([$_GET['id']]);
        $message = "Projet supprimé avec succès !";
    } catch (PDOException $e) {
        $error = "Erreur lors de la suppression: " . $e->getMessage();
    }
}

// Récupérer les données
try {
    // Récupérer tous les projets
    $projets = $pdo->query("SELECT * FROM projet_fiac ORDER BY date_debut DESC")->fetchAll();
    
    // Statistiques
    $stats = [
        'total_projets' => $pdo->query("SELECT COUNT(*) FROM projet_fiac")->fetchColumn(),
        'projets_actifs' => $pdo->query("SELECT COUNT(*) FROM projet_fiac WHERE statut = 'actif'")->fetchColumn(),
        'projets_planifies' => $pdo->query("SELECT COUNT(*) FROM projet_fiac WHERE statut = 'planifié'")->fetchColumn(),
        'budget_total' => $pdo->query("SELECT COALESCE(SUM(montant_budget), 0) FROM projet_fiac")->fetchColumn(),
        'budget_actifs' => $pdo->query("SELECT COALESCE(SUM(montant_budget), 0) FROM projet_fiac WHERE statut = 'actif'")->fetchColumn(),
    ];
    
    // Récupérer un projet spécifique pour modification
    $projet_edit = null;
    if ($action === 'edit' && isset($_GET['id'])) {
        $stmt = $pdo->prepare("SELECT * FROM projet_fiac WHERE id_projet = ?");
        $stmt->execute([$_GET['id']]);
        $projet_edit = $stmt->fetch();
    }
} catch (PDOException $e) {
    $error = "Erreur lors de la récupération des données: " . $e->getMessage();
}

// Fonction pour obtenir la classe de badge selon le statut
function getStatusBadgeClass($statut) {
    switch ($statut) {
        case 'actif': return 'bg-success';
        case 'planifié': return 'bg-warning';
        case 'en_étude': return 'bg-info';
        case 'terminé': return 'bg-secondary';
        default: return 'bg-light text-dark';
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Projets FIAC | Gestion de Tontine</title>
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
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #0f1a3a 0%, #1a2b55 100%);
            min-height: 100vh;
            color: var(--text-dark);
            width: 100%;
            overflow-x: hidden;
        }

        /* ==================== LAYOUT FLEXBOX ==================== */
        .app-container {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }

        /* Sidebar */
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

        .logo-text {
            display: flex;
            flex-direction: column;
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

        .nav-item i {
            width: 24px;
            text-align: center;
            font-size: 1.1rem;
        }

        /* Sidebar footer */
        .sidebar-footer {
            display: flex;
            flex-direction: column;
            padding: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            gap: 10px;
        }

        .logout-btn, .share-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            text-decoration: none;
            border-radius: 10px;
            transition: var(--transition);
            font-weight: 500;
        }

        .logout-btn {
            background: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
        }

        .logout-btn:hover {
            background: rgba(239, 68, 68, 0.3);
            color: white;
        }

        .share-btn {
            background: rgba(59, 130, 246, 0.2);
            color: #93c5fd;
        }

        .share-btn:hover {
            background: rgba(59, 130, 246, 0.3);
            color: white;
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
            display: flex;
            flex-direction: column;
            gap: 20px;
            margin-bottom: 30px;
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 20px;
        }

        .header-title {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .header-title h1 {
            font-size: 2rem;
            font-weight: 700;
            color: white;
        }

        .header-title p {
            color: rgba(255, 255, 255, 0.8);
            font-size: 1rem;
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

        /* Alert Messages */
        .alert {
            padding: 16px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease-out;
        }

        .alert-success {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border-left: 4px solid #047857;
        }

        .alert-error {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            border-left: 4px solid #b91c1c;
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

        /* Statistics Grid */
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

        .stat-footer {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(0, 0, 0, 0.05);
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 14px 28px;
            border-radius: 10px;
            border: none;
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent-gold), var(--accent-light));
            color: var(--navy-blue);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(212, 175, 55, 0.4);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-3px);
        }

        /* Table Container */
        .table-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 25px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        thead {
            background: linear-gradient(135deg, var(--medium-blue), var(--light-blue));
            color: white;
        }

        th {
            padding: 16px 20px;
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
            padding: 16px 20px;
            font-size: 0.9rem;
        }

        /* Badges */
        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            display: inline-block;
        }

        .bg-success { background: #d1fae5; color: #065f46; }
        .bg-warning { background: #fef3c7; color: #92400e; }
        .bg-info { background: #dbeafe; color: #1e40af; }
        .bg-secondary { background: #f3f4f6; color: #6b7280; }

        /* Action Buttons in Table */
        .action-cell {
            display: flex;
            gap: 8px;
        }

        .btn-sm {
            padding: 8px 12px;
            border-radius: 8px;
            border: none;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .btn-edit {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .btn-edit:hover {
            background: rgba(59, 130, 246, 0.2);
            transform: translateY(-2px);
        }

        .btn-delete {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .btn-delete:hover {
            background: rgba(239, 68, 68, 0.2);
            transform: translateY(-2px);
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(5px);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-content {
            background: linear-gradient(135deg, var(--navy-blue), var(--dark-blue));
            border-radius: 16px;
            padding: 30px;
            max-width: 600px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.5);
            animation: modalSlideIn 0.3s ease-out;
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .modal-title {
            color: white;
            font-size: 1.4rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .close-modal {
            background: rgba(255, 255, 255, 0.1);
            border: none;
            color: white;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
        }

        .close-modal:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: rotate(90deg);
        }

        /* Form */
        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            color: white;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid rgba(255, 255, 255, 0.2);
            border-radius: 10px;
            color: white;
            font-family: 'Poppins', sans-serif;
            font-size: 0.95rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--accent-gold);
            background: rgba(255, 255, 255, 0.15);
        }

        textarea.form-control {
            min-height: 120px;
            resize: vertical;
        }

        .form-row {
            display: flex;
            gap: 20px;
        }

        .form-row .form-group {
            flex: 1;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
        }

        /* Delete Confirmation */
        .delete-modal .modal-content {
            max-width: 400px;
            text-align: center;
        }

        .delete-icon {
            width: 80px;
            height: 80px;
            background: rgba(239, 68, 68, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            color: #ef4444;
            font-size: 36px;
        }

        .delete-modal h3 {
            color: white;
            margin-bottom: 10px;
        }

        .delete-modal p {
            color: rgba(255, 255, 255, 0.8);
            margin-bottom: 25px;
            line-height: 1.5;
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
        }

        .empty-state h3 {
            font-size: 1.3rem;
            margin-bottom: 10px;
            color: var(--text-dark);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .main-content {
                margin-left: 0;
                width: 100%;
                padding-bottom: 80px;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 20px;
            }
            
            .header-top {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .stats-grid {
                gap: 15px;
            }
            
            .stat-card {
                min-width: calc(50% - 15px);
            }
            
            .form-row {
                flex-direction: column;
                gap: 0;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 15px;
            }
            
            .header-title h1 {
                font-size: 1.5rem;
            }
            
            .stat-card {
                min-width: 100%;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
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
                    <span>Cotisations</span>
                </a>
                <a href="credit.php" class="nav-item">
                    <i class="fas fa-credit-card"></i>
                    <span>Crédits</span>
                </a>
                <a href="projets_fiac.php" class="nav-item active">
                    <i class="fas fa-project-diagram"></i>
                    <span>Projets FIAC</span>
                </a>
            </nav>

            <div class="sidebar-footer">
                <a href="#" class="share-btn" onclick="openShareModal()">
                    <i class="fas fa-share-alt"></i>
                    <span>Partager</span>
                </a>
                <a href="logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Déconnexion</span>
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <header class="header">
                <div class="header-top">
                    <div class="header-title">
                        <h1>📋 Projets FIAC</h1>
                        <p>Gestion des projets d'investissement collectif</p>
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

            <!-- Messages -->
            <?php if ($message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #667eea, #764ba2);">
                            <i class="fas fa-project-diagram"></i>
                        </div>
                        <span class="stat-badge" style="background: #d1fae5; color: #065f46;">
                            <?php echo $stats['projets_actifs']; ?> actifs
                        </span>
                    </div>
                    <div class="stat-value"><?php echo $stats['total_projets']; ?></div>
                    <div class="stat-label">Total Projets</div>
                    <div class="stat-footer" style="color: #10b981;">
                        <i class="fas fa-chart-line"></i>
                        <strong><?php echo $stats['total_projets'] > 0 ? round(($stats['projets_actifs'] / $stats['total_projets']) * 100) : 0; ?>%</strong>
                        <span>de projets actifs</span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #f093fb, #f5576c);">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <span class="stat-badge" style="background: #dbeafe; color: #1e40af;">
                            Budget total
                        </span>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['budget_total'], 0, ',', ' '); ?> FCFA</div>
                    <div class="stat-label">Budget Total</div>
                    <div class="stat-footer" style="color: #3b82f6;">
                        <i class="fas fa-coins"></i>
                        <strong><?php echo number_format($stats['budget_actifs'], 0, ',', ' '); ?> FCFA</strong>
                        <span>pour projets actifs</span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #4facfe, #00f2fe);">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <span class="stat-badge" style="background: #fef3c7; color: #92400e;">
                            En cours
                        </span>
                    </div>
                    <div class="stat-value"><?php echo $stats['projets_planifies']; ?></div>
                    <div class="stat-label">Projets Planifiés</div>
                    <div class="stat-footer" style="color: #f59e0b;">
                        <i class="fas fa-clock"></i>
                        <span>En attente de lancement</span>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="action-buttons">
                <button class="btn btn-primary" onclick="openAddModal()">
                    <i class="fas fa-plus"></i>
                    Nouveau Projet
                </button>
                <button class="btn btn-secondary" onclick="exportProjects()">
                    <i class="fas fa-file-export"></i>
                    Exporter
                </button>
            </div>

            <!-- Projects Table -->
            <div class="table-container">
                <?php if (empty($projets)): ?>
                    <div class="empty-state">
                        <i class="fas fa-project-diagram"></i>
                        <h3>Aucun projet FIAC</h3>
                        <p>Commencez par ajouter votre premier projet d'investissement collectif.</p>
                        <button class="btn btn-primary" onclick="openAddModal()" style="margin-top: 20px;">
                            <i class="fas fa-plus"></i>
                            Ajouter un projet
                        </button>
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Nom du Projet</th>
                                <th>Description</th>
                                <th>Budget</th>
                                <th>Dates</th>
                                <th>Statut</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($projets as $projet): ?>
                                <tr>
                                    <td style="font-weight: 700; color: var(--text-dark);">
                                        <?php echo htmlspecialchars($projet['nom_projet']); ?>
                                    </td>
                                    <td style="color: var(--text-light); max-width: 300px;">
                                        <?php echo nl2br(htmlspecialchars(substr($projet['description'], 0, 100) . (strlen($projet['description']) > 100 ? '...' : ''))); ?>
                                    </td>
                                    <td style="font-weight: 700; color: #10b981;">
                                        <?php echo number_format($projet['montant_budget'], 0, ',', ' '); ?> FCFA
                                    </td>
                                    <td>
                                        <div style="display: flex; flex-direction: column; gap: 4px; font-size: 0.85rem;">
                                            <span style="color: var(--text-dark);">
                                                <i class="fas fa-play" style="color: var(--accent-gold); margin-right: 5px;"></i>
                                                Début: <?php echo date('d/m/Y', strtotime($projet['date_debut'])); ?>
                                            </span>
                                            <span style="color: var(--text-light);">
                                                <i class="fas fa-flag-checkered" style="margin-right: 5px;"></i>
                                                Fin: <?php echo $projet['date_fin'] ? date('d/m/Y', strtotime($projet['date_fin'])) : 'Indéfinie'; ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo getStatusBadgeClass($projet['statut']); ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $projet['statut'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-cell">
                                            <button class="btn-sm btn-edit" onclick="editProject(<?php echo $projet['id_projet']; ?>)">
                                                <i class="fas fa-edit"></i>
                                                Modifier
                                            </button>
                                            <button class="btn-sm btn-delete" onclick="confirmDelete(<?php echo $projet['id_projet']; ?>, '<?php echo htmlspecialchars($projet['nom_projet']); ?>')">
                                                <i class="fas fa-trash"></i>
                                                Supprimer
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Add/Edit Modal -->
    <div id="projectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">
                    <i class="fas fa-project-diagram"></i>
                    <span id="modalTitle">Nouveau Projet FIAC</span>
                </h2>
                <button class="close-modal" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="projectForm" method="POST">
                <input type="hidden" name="id_projet" id="id_projet">
                
                <div class="form-group">
                    <label class="form-label" for="nom_projet">Nom du Projet *</label>
                    <input type="text" class="form-control" id="nom_projet" name="nom_projet" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="description">Description</label>
                    <textarea class="form-control" id="description" name="description" rows="4"></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="montant_budget">Budget (FCFA) *</label>
                        <input type="number" class="form-control" id="montant_budget" name="montant_budget" min="0" step="1000" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="statut">Statut *</label>
                        <select class="form-control" id="statut" name="statut" required>
                            <option value="planifié">Planifié</option>
                            <option value="en_étude">En étude</option>
                            <option value="actif">Actif</option>
                            <option value="terminé">Terminé</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="date_debut">Date de début *</label>
                        <input type="date" class="form-control" id="date_debut" name="date_debut" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="date_fin">Date de fin (optionnel)</label>
                        <input type="date" class="form-control" id="date_fin" name="date_fin">
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">
                        Annuler
                    </button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <i class="fas fa-save"></i>
                        Enregistrer
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal delete-modal">
        <div class="modal-content">
            <div class="delete-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h3>Confirmer la suppression</h3>
            <p id="deleteMessage">Êtes-vous sûr de vouloir supprimer ce projet ? Cette action est irréversible.</p>
            <div style="display: flex; gap: 15px; justify-content: center;">
                <button class="btn btn-secondary" onclick="closeDeleteModal()">
                    Annuler
                </button>
                <a href="#" id="deleteLink" class="btn" style="background: linear-gradient(135deg, #ef4444, #dc2626); color: white;">
                    <i class="fas fa-trash"></i>
                    Supprimer
                </a>
            </div>
        </div>
    </div>

    <script>
        // Mettre à jour la date
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

        // Gestion des modals
        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Nouveau Projet FIAC';
            document.getElementById('projectForm').reset();
            document.getElementById('id_projet').value = '';
            document.getElementById('submitBtn').name = 'add_project';
            document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save"></i> Enregistrer';
            
            // Définir la date par défaut à aujourd'hui
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('date_debut').value = today;
            
            document.getElementById('projectModal').style.display = 'flex';
        }

        function editProject(id) {
            // Charger les données du projet via AJAX
            fetch(`get_project.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('modalTitle').textContent = 'Modifier le Projet';
                    document.getElementById('id_projet').value = data.id_projet;
                    document.getElementById('nom_projet').value = data.nom_projet;
                    document.getElementById('description').value = data.description;
                    document.getElementById('montant_budget').value = data.montant_budget;
                    document.getElementById('statut').value = data.statut;
                    document.getElementById('date_debut').value = data.date_debut;
                    document.getElementById('date_fin').value = data.date_fin || '';
                    document.getElementById('submitBtn').name = 'edit_project';
                    document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save"></i> Mettre à jour';
                    
                    document.getElementById('projectModal').style.display = 'flex';
                })
                .catch(error => {
                    console.error('Erreur:', error);
                    alert('Erreur lors du chargement des données du projet');
                });
        }

        function closeModal() {
            document.getElementById('projectModal').style.display = 'none';
        }

        function confirmDelete(id, nom) {
            document.getElementById('deleteMessage').textContent = 
                `Êtes-vous sûr de vouloir supprimer le projet "${nom}" ? Cette action est irréversible.`;
            document.getElementById('deleteLink').href = `projets_fiac.php?action=delete&id=${id}`;
            document.getElementById('deleteModal').style.display = 'flex';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Fermer les modals en cliquant à l'extérieur
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    if (this.id === 'projectModal') closeModal();
                    if (this.id === 'deleteModal') closeDeleteModal();
                }
            });
        });

        // Exporter les projets
        function exportProjects() {
            // Ici, vous pouvez ajouter la logique d'export (Excel, PDF, etc.)
            alert('Fonctionnalité d\'export à implémenter');
        }

        // Validation du formulaire
        document.getElementById('projectForm').addEventListener('submit', function(e) {
            const budget = document.getElementById('montant_budget').value;
            if (budget <= 0) {
                e.preventDefault();
                alert('Le budget doit être supérieur à 0');
                return;
            }

            const dateDebut = document.getElementById('date_debut').value;
            const dateFin = document.getElementById('date_fin').value;
            
            if (dateFin && new Date(dateFin) < new Date(dateDebut)) {
                e.preventDefault();
                alert('La date de fin doit être postérieure à la date de début');
                return;
            }

            // Afficher un indicateur de chargement
            const submitBtn = document.getElementById('submitBtn');
            const originalHtml = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> En cours...';
            submitBtn.disabled = true;

            // Revenir à l'état normal après 3 secondes (au cas où le formulaire ne serait pas soumis)
            setTimeout(() => {
                submitBtn.innerHTML = originalHtml;
                submitBtn.disabled = false;
            }, 3000);
        });

        // Animation des cartes
        document.addEventListener('DOMContentLoaded', () => {
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
                
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-8px) scale(1.02)';
                    this.style.boxShadow = '0 20px 50px rgba(0, 0, 0, 0.2)';
                });

                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                    this.style.boxShadow = 'none';
                });
            });

            // Fermer les messages après 5 secondes
            setTimeout(() => {
                document.querySelectorAll('.alert').forEach(alert => {
                    alert.style.opacity = '0';
                    alert.style.transform = 'translateY(-20px)';
                    setTimeout(() => alert.remove(), 300);
                });
            }, 5000);
        });
    </script>
</body>
</html>