<?php
session_start();
include './fonctions/config.php';

// Vérifier la connexion
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Récupérer les données de l'utilisateur connecté
$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT * FROM membre WHERE id_membre = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Si l'utilisateur n'existe pas
if (!$user) {
    session_destroy();
    header('Location: index.php');
    exit();
}

// Vérifier l'inactivité (10 minutes)
$timeout = 12000;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
    session_unset();
    session_destroy();
    header('Location: index.php?expired=1');
    exit();
}

// Mettre à jour le timestamp de la dernière activité
$_SESSION['last_activity'] = time();

// Déterminer le rôle
$is_admin = ($user['role'] === 'admin');

// Initialiser les variables
$stats = [];
$activites = [];
$membres_recents = [];
$mes_tontines = [];
$erreur = null;

// Récupérer les statistiques selon le rôle
try {
    if ($is_admin) {
        $stats = [
            'total_membres' => $pdo->query("SELECT COUNT(*) FROM membre")->fetchColumn(),
            'membres_actifs' => $pdo->query("SELECT COUNT(*) FROM membre WHERE statut = 'actif'")->fetchColumn(),
            'total_tontines' => $pdo->query("SELECT COUNT(*) FROM tontine")->fetchColumn(),
            'total_cotisations' => $pdo->query("SELECT COALESCE(SUM(montant_cotisation), 0) FROM tontine WHERE statut = 'active'")->fetchColumn(),
            'total_seances' => $pdo->query("SELECT COUNT(*) FROM seance")->fetchColumn(),
            'montant_total_seances' => $pdo->query("SELECT COALESCE(SUM(c.montant), 0) FROM seance s LEFT JOIN cotisation c ON s.id_seance = c.id_seance")->fetchColumn(),
            'total_credits' => $pdo->query("SELECT COUNT(*) FROM credit")->fetchColumn(),
            'montant_total_credits' => $pdo->query("SELECT COALESCE(SUM(montant), 0) FROM credit")->fetchColumn(),
        ];

        $query = "
            (SELECT 'membre' as type, CONCAT(nom, ' ', prenom) as nom, date_inscription as date FROM membre ORDER BY date_inscription DESC LIMIT 5)
            UNION ALL
            (SELECT 'tontine' as type, nom_tontine as nom, date_debut as date FROM tontine ORDER BY date_debut DESC LIMIT 5)
            UNION ALL
            (SELECT 'seance' as type, CONCAT('Séance #', id_seance) as nom, date_seance as date FROM seance ORDER BY date_seance DESC LIMIT 5)
            ORDER BY date DESC LIMIT 10
        ";
        $activites = $pdo->query($query)->fetchAll();
        $membres_recents = $pdo->query("SELECT * FROM membre ORDER BY date_inscription DESC LIMIT 5")->fetchAll();
    } else {
        $stmt_tontines = $pdo->prepare("SELECT COUNT(DISTINCT t.id_tontine) 
            FROM tontine t 
            INNER JOIN beneficiaire b ON t.id_tontine = b.id_tontine 
            WHERE b.id_membre = ?");
        $stmt_tontines->execute([$user_id]);
        $mes_tontines_count = $stmt_tontines->fetchColumn();

        $stmt_cotisations = $pdo->prepare("SELECT COUNT(*) FROM cotisation WHERE id_membre = ?");
        $stmt_cotisations->execute([$user_id]);
        $mes_cotisations_count = $stmt_cotisations->fetchColumn();

        $stmt_montant_cotise = $pdo->prepare("SELECT COALESCE(SUM(montant), 0) FROM cotisation WHERE id_membre = ?");
        $stmt_montant_cotise->execute([$user_id]);
        $montant_total_cotise = $stmt_montant_cotise->fetchColumn();

        $stmt_credits = $pdo->prepare("SELECT COUNT(*) FROM credit WHERE id_membre = ?");
        $stmt_credits->execute([$user_id]);
        $mes_credits_count = $stmt_credits->fetchColumn();

        $stmt_montant_credit = $pdo->prepare("SELECT COALESCE(SUM(montant), 0) FROM credit WHERE id_membre = ?");
        $stmt_montant_credit->execute([$user_id]);
        $montant_credit_total = $stmt_montant_credit->fetchColumn();

        $stats = [
            'mes_tontines' => $mes_tontines_count,
            'mes_cotisations' => $mes_cotisations_count,
            'montant_total_cotise' => $montant_total_cotise,
            'mes_credits' => $mes_credits_count,
            'montant_credit_total' => $montant_credit_total,
        ];

        $query = "
            (SELECT 'cotisation' as type, CONCAT('Cotisation #', id_cotisation) as nom, date_paiement as date, montant 
             FROM cotisation WHERE id_membre = ? ORDER BY date_paiement DESC LIMIT 5)
            UNION ALL
            (SELECT 'credit' as type, CONCAT('Crédit #', id_credit) as nom, date_emprunt as date, montant 
             FROM credit WHERE id_membre = ? ORDER BY date_emprunt DESC LIMIT 5)
            ORDER BY date DESC LIMIT 10
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$user_id, $user_id]);
        $activites = $stmt->fetchAll();

        $stmt_tontines_user = $pdo->prepare("
            SELECT t.*, b.montant_gagne, b.date_gain 
            FROM tontine t 
            INNER JOIN beneficiaire b ON t.id_tontine = b.id_tontine 
            WHERE b.id_membre = ?
            ORDER BY t.date_debut DESC
            LIMIT 5
        ");
        $stmt_tontines_user->execute([$user_id]);
        $mes_tontines = $stmt_tontines_user->fetchAll();
    }
} catch (PDOException $e) {
    $erreur = "Erreur lors de la récupération des données: " . $e->getMessage();
    // Initialiser avec des valeurs par défaut pour éviter les erreurs
    if ($is_admin) {
        $stats = [
            'total_membres' => 0,
            'membres_actifs' => 0,
            'total_tontines' => 0,
            'total_cotisations' => 0,
            'total_seances' => 0,
            'montant_total_seances' => 0,
            'total_credits' => 0,
            'montant_total_credits' => 0,
        ];
    } else {
        $stats = [
            'mes_tontines' => 0,
            'mes_cotisations' => 0,
            'montant_total_cotise' => 0,
            'mes_credits' => 0,
            'montant_credit_total' => 0,
        ];
    }
    $activites = [];
    $membres_recents = [];
    $mes_tontines = [];
}

// Vérifier si on est sur mobile
function isMobile()
{
    return preg_match("/(android|webos|iphone|ipad|ipod|blackberry|windows phone)/i", $_SERVER['HTTP_USER_AGENT']);
}

$is_mobile = isMobile();
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Gestion de Tontine</title>
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

        /* Quick Actions */
        .quick-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 30px;
        }

        .action-card {
            flex: 1;
            min-width: 200px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 25px;
            text-align: center;
            transition: var(--transition);
            text-decoration: none;
            border: 2px solid transparent;
            position: relative;
            overflow: hidden;
        }

        .action-card:hover {
            transform: translateY(-5px);
            border-color: var(--accent-gold);
            box-shadow: 0 15px 40px rgba(212, 175, 55, 0.3);
        }

        .action-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--accent-gold), var(--light-blue));
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }

        .action-card:hover::before {
            transform: scaleX(1);
        }

        .action-icon {
            width: 60px;
            height: 60px;
            margin: 0 auto 15px;
            background: linear-gradient(135deg, var(--medium-blue), var(--light-blue));
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            color: white;
            transition: var(--transition);
        }

        .action-card:hover .action-icon {
            transform: rotate(5deg) scale(1.1);
            background: linear-gradient(135deg, var(--accent-gold), var(--accent-light));
        }

        .action-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 5px;
        }

        .action-subtitle {
            font-size: 0.85rem;
            color: var(--text-light);
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

        /* Content Grid */
        .content-grid {
            display: flex;
            gap: 25px;
            margin-bottom: 30px;
        }

        .main-column {
            flex: 2;
            display: flex;
            flex-direction: column;
            gap: 25px;
        }

        .sidebar-column {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 25px;
            max-width: 400px;
        }

        .content-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 25px;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .card-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-title i {
            color: var(--accent-gold);
        }

        .view-all-btn {
            color: var(--medium-blue);
            font-size: 0.85rem;
            font-weight: 600;
            text-decoration: none;
            transition: var(--transition);
        }

        .view-all-btn:hover {
            color: var(--accent-gold);
        }

        /* Table */
        .table-container {
            overflow-x: auto;
            border-radius: 12px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }

        thead {
            background: linear-gradient(135deg, var(--medium-blue), var(--light-blue));
            color: white;
        }

        th {
            padding: 14px 16px;
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
            padding: 14px 16px;
            font-size: 0.9rem;
        }

        .type-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            color: white;
            display: inline-block;
        }

        /* Member List */
        .member-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .member-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: linear-gradient(135deg, rgba(212, 175, 55, 0.05), rgba(58, 95, 192, 0.05));
            border-radius: 12px;
            transition: var(--transition);
        }

        .member-item:hover {
            transform: translateX(5px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
        }

        .member-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent-gold), var(--light-blue));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .member-info {
            flex: 1;
            min-width: 0;
        }

        .member-name {
            font-weight: 700;
            color: var(--text-dark);
            font-size: 0.95rem;
            margin-bottom: 3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .member-phone {
            font-size: 0.8rem;
            color: var(--text-light);
        }

        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            white-space: nowrap;
        }

        /* Tontine List */
        .tontine-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .tontine-item {
            padding: 20px;
            background: linear-gradient(135deg, rgba(58, 95, 192, 0.08), rgba(212, 175, 55, 0.08));
            border-radius: 12px;
            border-left: 4px solid var(--accent-gold);
            transition: var(--transition);
        }

        .tontine-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .tontine-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }

        .tontine-name {
            font-weight: 700;
            color: var(--text-dark);
            font-size: 1rem;
        }

        .tontine-amount {
            padding: 5px 12px;
            background: white;
            border-radius: 20px;
            font-weight: 700;
            color: var(--medium-blue);
            font-size: 0.85rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .tontine-date {
            font-size: 0.85rem;
            color: var(--text-light);
            margin-bottom: 12px;
        }

        /* System Card */
        .system-card {
            background: linear-gradient(135deg, var(--navy-blue), var(--dark-blue));
            color: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
        }

        .system-title {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .system-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .system-item:last-child {
            border-bottom: none;
        }

        /* Modal Styles */
        .modal-overlay {
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
            max-width: 500px;
            width: 100%;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.5);
            position: relative;
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
            margin-bottom: 20px;
        }

        .modal-title {
            color: white;
            font-size: 1.3rem;
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

        .share-options {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .share-option {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            color: white;
            text-decoration: none;
            transition: var(--transition);
        }

        .share-option:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateX(5px);
        }

        .share-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: white;
        }

        .share-info h4 {
            font-size: 1rem;
            margin-bottom: 3px;
        }

        .share-info p {
            font-size: 0.8rem;
            opacity: 0.8;
        }

        .share-link-container {
            margin-top: 20px;
        }

        .share-link-label {
            color: white;
            font-size: 0.9rem;
            margin-bottom: 8px;
            display: block;
        }

        .share-link-wrapper {
            display: flex;
            gap: 10px;
        }

        .share-link-input {
            flex: 1;
            padding: 12px 16px;
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid rgba(255, 255, 255, 0.2);
            border-radius: 10px;
            color: white;
            font-family: 'Poppins', sans-serif;
        }

        .copy-btn {
            padding: 12px 20px;
            background: linear-gradient(135deg, var(--accent-gold), var(--accent-light));
            border: none;
            border-radius: 10px;
            color: var(--navy-blue);
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition);
        }

        .copy-btn:hover {
            transform: scale(1.05);
            box-shadow: 0 5px 20px rgba(212, 175, 55, 0.4);
        }

        .copy-success {
            margin-top: 10px;
            padding: 8px 12px;
            background: rgba(34, 197, 94, 0.2);
            border: 1px solid rgba(34, 197, 94, 0.3);
            border-radius: 8px;
            color: #4ade80;
            font-size: 0.85rem;
            display: none;
            align-items: center;
            gap: 8px;
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

        .mobile-nav-icon {
            font-size: 22px;
            margin-bottom: 4px;
        }

        .mobile-nav-text {
            font-size: 10px;
            font-weight: 600;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-light);
        }

        .empty-state i {
            font-size: 3rem;
            opacity: 0.3;
            margin-bottom: 15px;
        }

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .fade-in {
            animation: fadeInUp 0.5s ease-out;
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
            
            .content-grid {
                flex-direction: column;
            }
            
            .sidebar-column {
                max-width: 100%;
            }
        }
        
        /* Medium Mobile (768px) */
        @media (max-width: 768px) {
            .main-content {
                padding: 20px;
            }
            
            .header-top {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .header-actions {
                width: 100%;
                justify-content: space-between;
            }
            
            .quick-actions {
                gap: 15px;
            }
            
            .action-card {
                min-width: calc(50% - 15px);
            }
            
            .stats-grid {
                gap: 15px;
            }
            
            .stat-card {
                min-width: calc(50% - 15px);
            }
            
            .content-card {
                padding: 20px;
            }
        }
        
        /* Small Mobile (480px) */
        @media (max-width: 480px) {
            .main-content {
                padding: 15px;
            }
            
            .header-title h1 {
                font-size: 1.5rem;
            }
            
            .quick-actions {
                gap: 10px;
            }
            
            .action-card {
                min-width: 100%;
            }
            
            .stats-grid {
                gap: 10px;
            }
            
            .stat-card {
                min-width: 100%;
                padding: 20px;
            }
            
            .stat-value {
                font-size: 1.5rem;
            }
            
            .mobile-nav-text {
                font-size: 9px;
            }
            
            .mobile-nav-icon {
                font-size: 20px;
            }
        }
        
        /* Very Small Mobile (360px) */
        @media (max-width: 360px) {
            .header-title h1 {
                font-size: 1.3rem;
            }
            
            .date-badge, .role-badge {
                font-size: 0.75rem;
                padding: 8px 12px;
            }
            
            .mobile-nav-text {
                font-size: 8px;
            }
            
            .mobile-nav-icon {
                font-size: 18px;
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
                <a href="dashboard.php" class="nav-item active">
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
        <?php endif; ?>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <header class="header">
                <div class="header-top">
                    <div class="header-title">
                        <h1><?php echo $is_admin ? '🎯 Tableau de Bord Admin' : '👋 Mon Espace'; ?></h1>
                        <p>Bienvenue <strong><?php echo htmlspecialchars($user['prenom'] . ' ' . $user['nom']); ?></strong> !</p>
                    </div>
                    <div class="header-actions">
                        <div class="date-badge">
                            <i class="fas fa-calendar-alt"></i>
                            <span id="currentDate"></span>
                        </div>
                        <div class="role-badge">
                            <?php echo $is_admin ? 'Admin' : 'Membre'; ?>
                        </div>
                        <?php if (!$is_admin): ?>
                            <button class="share-btn" onclick="openShareModal()" style="background: linear-gradient(135deg, var(--medium-blue), var(--light-blue)); color: white; border: none; padding: 10px 16px; border-radius: 10px; cursor: pointer; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                                <i class="fas fa-share-alt"></i>
                                Partager
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </header>

            <!-- Quick Actions -->
            <div class="quick-actions fade-in" style="animation-delay: 0.1s">
                <?php if ($is_admin): ?>
                    <a href="membre.php?action=add" class="action-card">
                        <div class="action-icon">
                            <i class="fas fa-user-plus"></i>
                        </div>
                        <h3 class="action-title">Ajouter Membre</h3>
                        <p class="action-subtitle">Nouveau membre</p>
                    </a>
                    <a href="tontine.php?action=add" class="action-card">
                        <div class="action-icon">
                            <i class="fas fa-hand-holding-usd"></i>
                        </div>
                        <h3 class="action-title">Créer Tontine</h3>
                        <p class="action-subtitle">Nouvelle tontine</p>
                    </a>
                    <a href="seance.php?action=add" class="action-card">
                        <div class="action-icon">
                            <i class="fas fa-calendar-plus"></i>
                        </div>
                        <h3 class="action-title">Nouvelle Séance</h3>
                        <p class="action-subtitle">Planifier séance</p>
                    </a>
                    <a href="rapports.php" class="action-card">
                        <div class="action-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <h3 class="action-title">Rapports</h3>
                        <p class="action-subtitle">Statistiques</p>
                    </a>
                <?php else: ?>
                    <a href="cotisation.php" class="action-card">
                        <div class="action-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <h3 class="action-title">Mes Cotisations</h3>
                        <p class="action-subtitle">Voir et payer</p>
                    </a>
                    <a href="seances.php" class="action-card">
                        <div class="action-icon">
                            <i class="fas fa-hand-holding-usd"></i>
                        </div>
                        <h3 class="action-title">Mes Tontines</h3>
                        <p class="action-subtitle">Participations</p>
                    </a>
                    <a href="demander_credit.php" class="action-card">
                        <div class="action-icon">
                            <i class="fas fa-credit-card"></i>
                        </div>
                        <h3 class="action-title">Demander Crédit</h3>
                        <p class="action-subtitle">Nouvelle demande</p>
                    </a>
                <?php endif; ?>
            </div>

            <!-- Statistics -->
            <div class="stats-grid fade-in" style="animation-delay: 0.2s">
                <?php if ($is_admin): ?>
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon" style="background: linear-gradient(135deg, #667eea, #764ba2);">
                                <i class="fas fa-users"></i>
                            </div>
                            <span class="stat-badge" style="background: #d1fae5; color: #065f46;">
                                +<?php echo isset($stats['membres_actifs']) ? $stats['membres_actifs'] : 0; ?> actifs
                            </span>
                        </div>
                        <div class="stat-value"><?php echo isset($stats['total_membres']) ? $stats['total_membres'] : 0; ?></div>
                        <div class="stat-label">Total Membres</div>
                        <div class="stat-footer" style="color: #10b981;">
                            <i class="fas fa-arrow-up"></i>
                            <strong><?php echo (isset($stats['total_membres']) && $stats['total_membres'] > 0) ? round(($stats['membres_actifs'] / $stats['total_membres']) * 100) : 0; ?>%</strong>
                            <span>de taux d'activité</span>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon" style="background: linear-gradient(135deg, #f093fb, #f5576c);">
                                <i class="fas fa-hand-holding-usd"></i>
                            </div>
                            <span class="stat-badge" style="background: #dbeafe; color: #1e40af;">
                                Actives
                            </span>
                        </div>
                        <div class="stat-value"><?php echo isset($stats['total_tontines']) ? $stats['total_tontines'] : 0; ?></div>
                        <div class="stat-label">Tontines</div>
                        <div class="stat-footer" style="color: #3b82f6;">
                            <i class="fas fa-coins"></i>
                            <strong><?php echo isset($stats['total_cotisations']) ? number_format($stats['total_cotisations'], 0, ',', ' ') : 0; ?> FCFA</strong>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon" style="background: linear-gradient(135deg, #4facfe, #00f2fe);">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                            <span class="stat-badge" style="background: #e9d5ff; color: #6b21a8;">
                                Réalisées
                            </span>
                        </div>
                        <div class="stat-value"><?php echo isset($stats['total_seances']) ? $stats['total_seances'] : 0; ?></div>
                        <div class="stat-label">Séances</div>
                        <div class="stat-footer" style="color: #a855f7;">
                            <i class="fas fa-chart-line"></i>
                            <strong><?php echo isset($stats['montant_total_seances']) ? number_format($stats['montant_total_seances'], 0, ',', ' ') : 0; ?> FCFA</strong>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon" style="background: linear-gradient(135deg, #fa709a, #fee140);">
                                <i class="fas fa-credit-card"></i>
                            </div>
                            <span class="stat-badge" style="background: #fed7aa; color: #92400e;">
                                En cours
                            </span>
                        </div>
                        <div class="stat-value"><?php echo isset($stats['total_credits']) ? $stats['total_credits'] : 0; ?></div>
                        <div class="stat-label">Crédits</div>
                        <div class="stat-footer" style="color: #f59e0b;">
                            <i class="fas fa-percentage"></i>
                            <strong><?php echo isset($stats['montant_total_credits']) ? number_format($stats['montant_total_credits'], 0, ',', ' ') : 0; ?> FCFA</strong>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon" style="background: linear-gradient(135deg, #667eea, #764ba2);">
                                <i class="fas fa-hand-holding-usd"></i>
                            </div>
                            <span class="stat-badge" style="background: #dbeafe; color: #1e40af;">
                                Participations
                            </span>
                        </div>
                        <div class="stat-value"><?php echo isset($stats['mes_tontines']) ? $stats['mes_tontines'] : 0; ?></div>
                        <div class="stat-label">Mes Tontines</div>
                        <div class="stat-footer" style="color: #3b82f6;">
                            <i class="fas fa-check-circle"></i>
                            <span>Participations actives</span>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon" style="background: linear-gradient(135deg, #fa709a, #fee140);">
                                <i class="fas fa-money-bill-wave"></i>
                            </div>
                            <span class="stat-badge" style="background: #d1fae5; color: #065f46;">
                                Paiements
                            </span>
                        </div>
                        <div class="stat-value"><?php echo isset($stats['mes_cotisations']) ? $stats['mes_cotisations'] : 0; ?></div>
                        <div class="stat-label">Cotisations</div>
                        <div class="stat-footer" style="color: #10b981;">
                            <i class="fas fa-coins"></i>
                            <strong><?php echo isset($stats['montant_total_cotise']) ? number_format($stats['montant_total_cotise'], 0, ',', ' ') : 0; ?> FCFA</strong>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon" style="background: linear-gradient(135deg, #4facfe, #00f2fe);">
                                <i class="fas fa-credit-card"></i>
                            </div>
                            <span class="stat-badge" style="background: #fed7aa; color: #92400e;">
                                Emprunts
                            </span>
                        </div>
                        <div class="stat-value"><?php echo isset($stats['mes_credits']) ? $stats['mes_credits'] : 0; ?></div>
                        <div class="stat-label">Mes Crédits</div>
                        <div class="stat-footer" style="color: #f59e0b;">
                            <i class="fas fa-percentage"></i>
                            <strong><?php echo isset($stats['montant_credit_total']) ? number_format($stats['montant_credit_total'], 0, ',', ' ') : 0; ?> FCFA</strong>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon" style="background: linear-gradient(135deg, #f093fb, #f5576c);">
                                <i class="fas fa-trophy"></i>
                            </div>
                            <span class="stat-badge" style="background: #fef3c7; color: #78350f;">
                                Gains
                            </span>
                        </div>
                        <div class="stat-value">
                            <?php
                            if (isset($pdo)) {
                                $stmt_gains = $pdo->prepare("SELECT COUNT(*) FROM beneficiaire WHERE id_membre = ?");
                                $stmt_gains->execute([$user_id]);
                                echo $stmt_gains->fetchColumn();
                            } else {
                                echo "0";
                            }
                            ?>
                        </div>
                        <div class="stat-label">Gains Tontines</div>
                        <div class="stat-footer" style="color: #eab308;">
                            <i class="fas fa-gift"></i>
                            <span>Tontines gagnées</span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Content Grid -->
            <div class="content-grid fade-in" style="animation-delay: 0.3s">
                <div class="main-column">
                    <!-- Activities Table -->
                    <div class="content-card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-history"></i>
                                <?php echo $is_admin ? 'Activités Récentes' : 'Mes Activités'; ?>
                            </h3>
                            <a href="#" class="view-all-btn">
                                Voir tout <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>

                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Type</th>
                                        <th>Description</th>
                                        <th>Date</th>
                                        <?php if (!$is_admin): ?>
                                            <th>Montant</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($activites)): ?>
                                        <?php foreach ($activites as $activite): ?>
                                            <tr>
                                                <td>
                                                    <span class="type-badge" style="background: <?php
                                                                                                if ($activite['type'] == 'membre') echo 'linear-gradient(135deg, #3b82f6, #2563eb)';
                                                                                                elseif ($activite['type'] == 'tontine') echo 'linear-gradient(135deg, #f59e0b, #d97706)';
                                                                                                elseif ($activite['type'] == 'seance') echo 'linear-gradient(135deg, #10b981, #059669)';
                                                                                                elseif ($activite['type'] == 'cotisation') echo 'linear-gradient(135deg, #a855f7, #9333ea)';
                                                                                                else echo 'linear-gradient(135deg, #6b7280, #4b5563)';
                                                                                                ?>;">
                                                        <?php echo substr(ucfirst($activite['type']), 0, 3); ?>
                                                    </span>
                                                </td>
                                                <td style="font-weight: 600; color: var(--text-dark);">
                                                    <?php echo htmlspecialchars($activite['nom']); ?>
                                                </td>
                                                <td style="color: var(--text-light);">
                                                    <i class="fas fa-calendar" style="color: var(--accent-gold); margin-right: 5px;"></i>
                                                    <?php echo date('d/m/Y', strtotime($activite['date'])); ?>
                                                </td>
                                                <?php if (!$is_admin && isset($activite['montant'])): ?>
                                                    <td style="font-weight: 700; color: #10b981;">
                                                        <?php echo number_format($activite['montant'], 0, ',', ' '); ?> FCFA
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="<?php echo $is_admin ? 3 : 4; ?>">
                                                <div class="empty-state">
                                                    <i class="fas fa-inbox"></i>
                                                    <p>Aucune activité récente</p>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="sidebar-column">
                    <?php if ($is_admin): ?>
                        <!-- Recent Members -->
                        <div class="content-card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-users"></i>
                                    Membres Récents
                                </h3>
                            </div>
                            <div class="member-list">
                                <?php if (!empty($membres_recents)): ?>
                                    <?php foreach ($membres_recents as $membre): ?>
                                        <div class="member-item">
                                            <div class="member-avatar">
                                                <?php echo strtoupper(substr($membre['prenom'], 0, 1)); ?>
                                            </div>
                                            <div class="member-info">
                                                <div class="member-name">
                                                    <?php echo htmlspecialchars($membre['prenom'] . ' ' . $membre['nom']); ?>
                                                </div>
                                                <div class="member-phone">
                                                    <i class="fas fa-phone"></i>
                                                    <?php echo $membre['telephone']; ?>
                                                </div>
                                            </div>
                                            <span class="status-badge" style="<?php echo $membre['statut'] == 'actif' ? 'background: #d1fae5; color: #065f46;' : 'background: #f3f4f6; color: #6b7280;'; ?>">
                                                <?php echo $membre['statut'] == 'actif' ? 'Actif' : 'Inactif'; ?>
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <i class="fas fa-user-plus"></i>
                                        <p>Aucun membre récent</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- My Tontines -->
                        <div class="content-card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-hand-holding-usd"></i>
                                    Mes Tontines
                                </h3>
                            </div>
                            <div class="tontine-list">
                                <?php if (!empty($mes_tontines)): ?>
                                    <?php foreach ($mes_tontines as $tontine): ?>
                                        <div class="tontine-item">
                                            <div class="tontine-header">
                                                <h4 class="tontine-name">
                                                    <?php echo htmlspecialchars($tontine['nom_tontine']); ?>
                                                </h4>
                                                <span class="tontine-amount">
                                                    <?php echo number_format($tontine['montant_cotisation'], 0, ',', ' '); ?> F
                                                </span>
                                            </div>
                                            <div class="tontine-date">
                                                <i class="fas fa-calendar"></i>
                                                <?php echo date('d/m/Y', strtotime($tontine['date_debut'])); ?>
                                            </div>
                                            <?php if ($tontine['montant_gagne']): ?>
                                                <div class="tontine-gain">
                                                    <span class="gain-label">
                                                        <i class="fas fa-trophy"></i>
                                                        Gagné !
                                                    </span>
                                                    <span class="gain-amount">
                                                        <?php echo number_format($tontine['montant_gagne'], 0, ',', ' '); ?> FCFA
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <i class="fas fa-hand-holding-usd"></i>
                                        <p>Aucune tontine active</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- System Info -->
                    <div class="system-card">
                        <h3 class="system-title">
                            <i class="fas fa-server"></i>
                            Système
                        </h3>
                        <div class="system-item">
                            <span class="system-label">Version</span>
                            <span class="system-value">1.0.0</span>
                        </div>
                        <div class="system-item">
                            <span class="system-label">Statut</span>
                            <span class="status-online">
                                <span class="status-dot"></span>
                                <strong>En ligne</strong>
                            </span>
                        </div>
                        <div class="system-item">
                            <span class="system-label">Dernière MAJ</span>
                            <span class="system-value"><?php echo date('d/m/Y'); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Share Modal -->
    <div id="shareModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">
                    <i class="fas fa-share-alt"></i>
                    Partager l'Application
                </h2>
                <button class="close-modal" onclick="closeShareModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="share-options">
                <a href="#" class="share-option" onclick="shareOnWhatsApp()">
                    <div class="share-icon" style="background: linear-gradient(135deg, #25D366, #128C7E);">
                        <i class="fab fa-whatsapp"></i>
                    </div>
                    <div class="share-info">
                        <h4>WhatsApp</h4>
                        <p>Partager via WhatsApp</p>
                    </div>
                </a>
                <a href="#" class="share-option" onclick="shareOnFacebook()">
                    <div class="share-icon" style="background: linear-gradient(135deg, #1877F2, #0D5F9E);">
                        <i class="fab fa-facebook-f"></i>
                    </div>
                    <div class="share-info">
                        <h4>Facebook</h4>
                        <p>Partager sur Facebook</p>
                    </div>
                </a>
                <a href="#" class="share-option" onclick="copyLink()">
                    <div class="share-icon" style="background: linear-gradient(135deg, var(--accent-gold), var(--accent-light));">
                        <i class="fas fa-link"></i>
                    </div>
                    <div class="share-info">
                        <h4>Copier le lien</h4>
                        <p>Copier le lien de partage</p>
                    </div>
                </a>
            </div>
            <div class="share-link-container">
                <label class="share-link-label">Lien de partage :</label>
                <div class="share-link-wrapper">
                    <input type="text" id="shareLink" class="share-link-input" value="<?php echo 'https://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']); ?>" readonly>
                    <button class="copy-btn" onclick="copyLink()">
                        <i class="fas fa-copy"></i>
                        Copier
                    </button>
                </div>
                <div id="copySuccess" class="copy-success">
                    <i class="fas fa-check-circle"></i>
                    Lien copié dans le presse-papiers !
                </div>
            </div>
        </div>
    </div>

    <!-- Mobile Navigation -->
    <nav class="mobile-nav">
        <div class="mobile-nav-container">
            <a href="dashboard.php" class="mobile-nav-item active">
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
                <a href="seance.php" class="mobile-nav-item">
                    <i class="fas fa-calendar-alt mobile-nav-icon"></i>
                    <span class="mobile-nav-text">Séances</span>
                </a>
                <a href="#" class="mobile-nav-item" onclick="openShareModal()">
                    <i class="fas fa-share-alt mobile-nav-icon"></i>
                    <span class="mobile-nav-text">Partager</span>
                </a>
            <?php else: ?>
                <a href="cotisation.php" class="mobile-nav-item">
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
                <a href="#" class="mobile-nav-item" onclick="openShareModal()">
                    <i class="fas fa-share-alt mobile-nav-icon"></i>
                    <span class="mobile-nav-text">Partager</span>
                </a>
            <?php endif; ?>
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
        // Mettre à jour la date chaque minute
        setInterval(updateDateTime, 60000);

        // Gérer l'inactivité côté client
        let inactivityTimer;
        const TIMEOUT = 600000; // 10 minutes

        function resetInactivityTimer() {
            clearTimeout(inactivityTimer);
            inactivityTimer = setTimeout(logoutDueToInactivity, TIMEOUT);
        }

        function logoutDueToInactivity() {
            fetch('logout.php?timeout=1')
                .then(() => {
                    window.location.href = 'index.php?expired=1';
                })
                .catch(() => {
                    window.location.href = 'index.php?expired=1';
                });
        }

        // Fonctions de partage
        function openShareModal() {
            document.getElementById('shareModal').style.display = 'flex';
        }

        function closeShareModal() {
            document.getElementById('shareModal').style.display = 'none';
            document.getElementById('copySuccess').style.display = 'none';
        }

        function copyLink() {
            const linkInput = document.getElementById('shareLink');
            linkInput.select();
            linkInput.setSelectionRange(0, 99999);
            
            navigator.clipboard.writeText(linkInput.value).then(() => {
                const successMessage = document.getElementById('copySuccess');
                successMessage.style.display = 'flex';
                
                setTimeout(() => {
                    successMessage.style.display = 'none';
                }, 3000);
            }).catch(err => {
                console.error('Erreur lors de la copie: ', err);
            });
        }

        function shareOnWhatsApp() {
            const message = encodeURIComponent('Découvrez cette application de gestion de tontines ! ' + window.location.href);
            window.open(`https://wa.me/?text=${message}`, '_blank');
            closeShareModal();
        }

        function shareOnFacebook() {
            const url = encodeURIComponent(window.location.href);
            window.open(`https://www.facebook.com/sharer/sharer.php?u=${url}`, '_blank', 'width=600,height=400');
            closeShareModal();
        }

        // Fermer la modal en cliquant à l'extérieur
        document.getElementById('shareModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeShareModal();
            }
        });

        // Détecter l'activité utilisateur
        document.addEventListener('DOMContentLoaded', () => {
            resetInactivityTimer();

            ['mousedown', 'mousemove', 'keydown', 'scroll', 'click', 'touchstart'].forEach(event => {
                document.addEventListener(event, resetInactivityTimer);
            });

            // Animation des cartes
            const cards = document.querySelectorAll('.fade-in');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
            });

            // Effets de survol
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-8px) scale(1.02)';
                    this.style.boxShadow = '0 20px 50px rgba(0, 0, 0, 0.2)';
                });

                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                    this.style.boxShadow = 'none';
                });
            });
        });

        // Gérer la déconnexion
        document.querySelectorAll('a[href="logout.php"]').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();

                if (confirm('Voulez-vous vraiment vous déconnecter ?')) {
                    const icon = this.querySelector('i');
                    if (icon) {
                        const originalClass = icon.className;
                        icon.className = 'fas fa-spinner fa-spin';

                        setTimeout(() => {
                            window.location.href = 'logout.php';
                        }, 500);
                    } else {
                        window.location.href = 'logout.php';
                    }
                }
            });
        });
    </script>
</body>

</html>