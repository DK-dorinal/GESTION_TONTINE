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
$timeout = 600;
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

// Récupérer les statistiques selon le rôle
try {
    if ($is_admin) {
        $stats = [
            'total_membres' => $pdo->query("SELECT COUNT(*) FROM membre")->fetchColumn(),
            'membres_actifs' => $pdo->query("SELECT COUNT(*) FROM membre WHERE statut = 'actif'")->fetchColumn(),
            'total_tontines' => $pdo->query("SELECT COUNT(*) FROM tontine")->fetchColumn(),
            'total_cotisations' => $pdo->query("SELECT SUM(montant_cotisation) FROM tontine WHERE statut = 'en cours'")->fetchColumn() ?? 0,
            'total_seances' => $pdo->query("SELECT COUNT(*) FROM seance")->fetchColumn(),
            'montant_total_seances' => $pdo->query("SELECT SUM(montant_total) FROM seance")->fetchColumn() ?? 0,
            'total_credits' => $pdo->query("SELECT COUNT(*) FROM credit")->fetchColumn(),
            'montant_total_credits' => $pdo->query("SELECT SUM(montant) FROM credit")->fetchColumn() ?? 0,
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
}
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Gestion de Tontine</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#0f1a3a">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="TontineApp">
    <link rel="apple-touch-icon" href="/icons/icon-192x192.png">
    <link rel="icon" type="image/png" href="/icons/icon-192x192.png">
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
            position: relative;
            overflow-x: hidden;
        }

        /* Background Pattern */
        body::before {
            content: "";
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100" height="100" fill="none"/><path d="M0,0 L100,0 L100,100" stroke="rgba(255,255,255,0.03)" stroke-width="1"/></svg>');
            background-size: 50px 50px;
            opacity: 0.3;
            z-index: 0;
            pointer-events: none;
        }

        /* Desktop Sidebar */
        .sidebar-desktop {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100vh;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100" height="100" fill="none"/><path d="M0,0 L100,0 L100,100" stroke="rgba(255,255,255,0.03)" stroke-width="1"/></svg>');
            backdrop-filter: blur(10px);
            z-index: 1000;
            overflow-y: auto;
            transition: var(--transition);
            box-shadow: 4px 0 20px rgba(0, 0, 0, 0.3);
        }

        .sidebar-logo {
            padding: 30px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .logo-container {
            display: flex;
            align-items: center;
            gap: 15px;
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
            box-shadow: 0 4px 15px rgba(212, 175, 55, 0.3);
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

        .sidebar-nav {
            padding: 20px 15px;
        }

        .nav-link {
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
            position: relative;
            overflow: hidden;
        }

        .nav-link:hover {
            background: rgba(212, 175, 55, 0.2);
            color: white;
            transform: translateX(5px);
        }

        .nav-link.active {
            background: rgba(212, 175, 55, 0.3);
            color: white;
            border-left: 3px solid var(--accent-gold);
        }

        .nav-link i {
            font-size: 1.1rem;
            width: 24px;
            text-align: center;
        }

        .sidebar-footer {
            padding: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            margin-top: 20px;
        }

        .logout-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            background: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
            text-decoration: none;
            border-radius: 10px;
            transition: var(--transition);
            font-weight: 500;
        }

        .logout-btn:hover {
            background: rgba(239, 68, 68, 0.3);
            color: white;
        }

        /* Mobile Bottom Navigation */
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
            box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.3);
        }

        .mobile-nav-container {
            display: flex;
            justify-content: space-around;
            align-items: center;
            height: 100%;
            max-width: 600px;
            margin: 0 auto;
        }

        .mobile-nav-item {
            flex: 1;
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
            max-width: 80px;
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
            text-align: center;
        }

        /* Main Content */
        .main-content {
            margin-left: 280px;
            min-height: 100vh;
            position: relative;
            z-index: 1;
            transition: var(--transition);
        }

        .content-wrapper {
            padding: 30px;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Header */
        .page-header {
            margin-bottom: 30px;
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 20px;
        }

        .header-title h1 {
            font-size: 2rem;
            font-weight: 700;
            color: white;
            margin-bottom: 5px;
        }

        .header-title p {
            color: rgba(255, 255, 255, 0.8);
            font-size: 1rem;
        }

        .header-actions {
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .date-badge {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            padding: 10px 16px;
            border-radius: 10px;
            color: white;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
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
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .action-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 25px;
            text-align: center;
            transition: var(--transition);
            border: 2px solid transparent;
            text-decoration: none;
            position: relative;
            overflow: hidden;
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

        .action-card:hover {
            transform: translateY(-5px);
            border-color: var(--accent-gold);
            box-shadow: 0 15px 40px rgba(212, 175, 55, 0.3);
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
            box-shadow: 0 8px 20px rgba(45, 74, 138, 0.3);
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

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 25px;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .stat-card::before {
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

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
        }

        .stat-card:hover::before {
            transform: scaleX(1);
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
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 25px;
            margin-bottom: 30px;
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
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
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
            transition: var(--transition);
            border-bottom: 1px solid #e2e8f0;
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

        /* Member Cards */
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
            border: 1px solid rgba(0, 0, 0, 0.05);
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
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
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

        .member-phone i {
            color: var(--accent-gold);
        }

        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            white-space: nowrap;
        }

        /* Tontine Cards */
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
            align-items: start;
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
            white-space: nowrap;
        }

        .tontine-date {
            font-size: 0.85rem;
            color: var(--text-light);
            margin-bottom: 12px;
        }

        .tontine-date i {
            color: var(--accent-gold);
            margin-right: 5px;
        }

        .tontine-gain {
            padding: 12px;
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(5, 150, 105, 0.1));
            border-radius: 8px;
            border: 1px solid rgba(16, 185, 129, 0.2);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .gain-label {
            font-size: 0.8rem;
            color: #047857;
            font-weight: 700;
        }

        .gain-label i {
            margin-right: 5px;
        }

        .gain-amount {
            font-size: 0.95rem;
            font-weight: 800;
            color: #065f46;
        }

        /* System Info Card */
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

        .system-label {
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.9rem;
        }

        .system-value {
            font-weight: 700;
            font-size: 0.9rem;
        }

        .status-online {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #10b981;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            background: #10b981;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.5;
            }
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

        .empty-state p {
            font-size: 0.9rem;
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

        /* Responsive Design */
        @media (max-width: 1200px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 1024px) {
            .sidebar-desktop {
                transform: translateX(-100%);
            }

            .main-content {
                margin-left: 0;
            }

            .mobile-nav {
                display: block;
            }

            body {
                padding-bottom: 70px;
            }

            .stats-grid {
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            }

            .quick-actions {
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .content-wrapper {
                padding: 20px 15px;
            }

            .header-title h1 {
                font-size: 1.5rem;
            }

            .header-top {
                flex-direction: column;
                align-items: flex-start;
            }

            .stats-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .quick-actions {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }

            .action-card {
                padding: 20px 15px;
            }

            .action-icon {
                width: 50px;
                height: 50px;
                font-size: 22px;
            }

            .stat-card {
                padding: 20px;
            }

            .content-card {
                padding: 20px;
            }

            .card-title {
                font-size: 1rem;
            }

            table {
                min-width: 500px;
            }

            th,
            td {
                padding: 10px 12px;
                font-size: 0.8rem;
            }

            .mobile-nav-text {
                font-size: 9px;
            }

            .mobile-nav-icon {
                font-size: 20px;
            }
        }

        @media (max-width: 480px) {
            .content-wrapper {
                padding: 15px 10px;
            }

            .header-title h1 {
                font-size: 1.3rem;
            }

            .quick-actions {
                grid-template-columns: 1fr;
            }

            .header-actions {
                width: 100%;
                justify-content: space-between;
            }

            .date-badge,
            .role-badge {
                font-size: 0.75rem;
                padding: 8px 12px;
            }

            .stat-value {
                font-size: 1.5rem;
            }

            .mobile-nav-item {
                min-width: 55px;
                padding: 6px 2px;
            }

            .mobile-nav-text {
                font-size: 8px;
            }
        }
    </style>
</head>

<body>
    <?php if ($is_admin): ?>
        <!-- Desktop Sidebar -->
        <aside class="sidebar-desktop">
            <div class="sidebar-logo">
                <div class="logo-container">
                    <div class="logo-icon">
                        <i class="fas fa-hand-holding-usd"></i>
                    </div>
                    <div class="logo-text">
                        <h2>Gestion Tontine</h2>
                        <p>Version 1.0</p>
                    </div>
                </div>
            </div>

            <nav class="sidebar-nav">
                <a href="dashboard.php" class="nav-link active">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Tableau de Bord</span>
                </a>
                <a href="membre.php" class="nav-link">
                    <i class="fas fa-users"></i>
                    <span>Membres</span>
                </a>
                <a href="tontine.php" class="nav-link">
                    <i class="fas fa-hand-holding-usd"></i>
                    <span>Tontines</span>
                </a>
                <a href="seance.php" class="nav-link">
                    <i class="fas fa-calendar-alt"></i>
                    <span>Séances</span>
                </a>
                <a href="cotisation.php" class="nav-link">
                    <i class="fas fa-money-bill-wave"></i>
                    <span>Cotisations</span>
                </a>
                <a href="credit.php" class="nav-link">
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
            <?php endif; ?>
            <a href="logout.php" class="mobile-nav-item">
                <i class="fas fa-sign-out-alt mobile-nav-icon"></i>
                <span class="mobile-nav-text">Sortir</span>
            </a>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <div class="content-wrapper">
            <!-- Header -->
            <header class="page-header fade-in">
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
                    </div>
                </div>
            </header>
            <?php
            // pwa-config.php
            class PWAConfig
            {
                private static $instance = null;
                private $config = [];

                private function __construct()
                {
                    $this->config = [
                        'name' => 'Gestion de Tontine',
                        'short_name' => 'TontineApp',
                        'theme_color' => '#0f1a3a',
                        'background_color' => '#0f1a3a',
                        'display' => 'standalone',
                        'scope' => '/',
                        'start_url' => '/dashboard.php',
                        'icons' => [
                            'src' => '/icons/icon-72x72.png',
                            'sizes' => '192x192',
                            'type' => 'image/png'
                        ]
                    ];
                }

                public static function getInstance()
                {
                    if (self::$instance === null) {
                        self::$instance = new self();
                    }
                    return self::$instance;
                }

                public function getConfig()
                {
                    return $this->config;
                }

                public function generateManifest()
                {
                    return json_encode($this->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                }

                public function getMetaTags()
                {
                    $config = $this->config;
                    $tags = "
            <meta name=\"application-name\" content=\"{$config['name']}\">
            <meta name=\"apple-mobile-web-app-capable\" content=\"yes\">
            <meta name=\"apple-mobile-web-app-status-bar-style\" content=\"black-translucent\">
            <meta name=\"apple-mobile-web-app-title\" content=\"{$config['short_name']}\">
            <meta name=\"description\" content=\"Application de gestion de tontine\">
            <meta name=\"format-detection\" content=\"telephone=no\">
            <meta name=\"mobile-web-app-capable\" content=\"yes\">
            <meta name=\"msapplication-TileColor\" content=\"{$config['theme_color']}\">
            <meta name=\"msapplication-tap-highlight\" content=\"no\">
            <meta name=\"theme-color\" content=\"{$config['theme_color']}\">
            
            <link rel=\"apple-touch-icon\" href=\"{$config['icons']['src']}\">
            <link rel=\"icon\" type=\"image/png\" href=\"/icons/icon-192x192.png\">
            <link rel=\"manifest\" href=\"/manifest.json\">
            <link rel=\"shortcut icon\" href=\"/favicon.ico\">
        ";

                    return $tags;
                }
            }

            // Fonction pour inclure les tags PWA dans vos pages
            function includePWATags()
            {
                $pwa = PWAConfig::getInstance();
                echo $pwa->getMetaTags();
            }

            // Fonction pour vérifier si l'utilisateur utilise l'app en PWA
            function isPWA()
            {
                if (isset($_SERVER['HTTP_USER_AGENT'])) {
                    $ua = strtolower($_SERVER['HTTP_USER_AGENT']);

                    // Détecter les apps mobiles
                    if (
                        strpos($ua, 'wv') !== false ||
                        strpos($ua, 'android') !== false ||
                        strpos($ua, 'iphone') !== false ||
                        strpos($ua, 'ipad') !== false
                    ) {
                        return true;
                    }
                }

                // Vérifier via le header display-mode
                if (isset($_SERVER['HTTP_SEC_FETCH_DEST']) && $_SERVER['HTTP_SEC_FETCH_DEST'] === 'document') {
                    return true;
                }

                return false;
            }

            // Fonction pour obtenir la version du cache
            function getCacheVersion()
            {
                return 'v1.0.' . date('Ymd');
            }
            ?>
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
                                +<?php echo $stats['membres_actifs']; ?> actifs
                            </span>
                        </div>
                        <div class="stat-value"><?php echo $stats['total_membres']; ?></div>
                        <div class="stat-label">Total Membres</div>
                        <div class="stat-footer" style="color: #10b981;">
                            <i class="fas fa-arrow-up"></i>
                            <strong><?php echo $stats['total_membres'] > 0 ? round(($stats['membres_actifs'] / $stats['total_membres']) * 100) : 0; ?>%</strong>
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
                        <div class="stat-value"><?php echo $stats['total_tontines']; ?></div>
                        <div class="stat-label">Tontines</div>
                        <div class="stat-footer" style="color: #3b82f6;">
                            <i class="fas fa-coins"></i>
                            <strong><?php echo number_format($stats['total_cotisations'], 0, ',', ' '); ?> FCFA</strong>
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
                        <div class="stat-value"><?php echo $stats['total_seances']; ?></div>
                        <div class="stat-label">Séances</div>
                        <div class="stat-footer" style="color: #a855f7;">
                            <i class="fas fa-chart-line"></i>
                            <strong><?php echo number_format($stats['montant_total_seances'], 0, ',', ' '); ?> FCFA</strong>
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
                        <div class="stat-value"><?php echo $stats['total_credits']; ?></div>
                        <div class="stat-label">Crédits</div>
                        <div class="stat-footer" style="color: #f59e0b;">
                            <i class="fas fa-percentage"></i>
                            <strong><?php echo number_format($stats['montant_total_credits'], 0, ',', ' '); ?> FCFA</strong>
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
                        <div class="stat-value"><?php echo $stats['mes_tontines']; ?></div>
                        <div class="stat-label">Mes Tontines</div>
                        <div class="stat-footer" style="color: #3b82f6;">
                            <i class="fas fa-check-circle"></i>
                            <span>Participations actives</span>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon" style="background: linear-gradient(135 deg, #fa709a, #fee140);">
                                <i class="fas fa-money-bill-wave"></i>
                            </div>
                            <span class="stat-badge" style="background: #d1fae5; color: #065f46;">
                                Paiements
                            </span>
                        </div>
                        <div class="stat-value"><?php echo $stats['mes_cotisations']; ?></div>
                        <div class="stat-label">Cotisations</div>
                        <div class="stat-footer" style="color: #10b981;">
                            <i class="fas fa-coins"></i>
                            <strong><?php echo number_format($stats['montant_total_cotise'], 0, ',', ' '); ?> FCFA</strong>
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
                        <div class="stat-value"><?php echo $stats['mes_credits']; ?></div>
                        <div class="stat-label">Mes Crédits</div>
                        <div class="stat-footer" style="color: #f59e0b;">
                            <i class="fas fa-percentage"></i>
                            <strong><?php echo number_format($stats['montant_credit_total'], 0, ',', ' '); ?> FCFA</strong>
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
                            $stmt_gains = $pdo->prepare("SELECT COUNT(*) FROM beneficiaire WHERE id_membre = ?");
                            $stmt_gains->execute([$user_id]);
                            echo $stmt_gains->fetchColumn();
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

                <!-- Sidebar Content -->
                <div style="display: flex; flex-direction: column; gap: 20px;">
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
        </div>
    </main>

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

        // Gérer l'inactivité côté client (backup)
        let inactivityTimer;
        const TIMEOUT = 600000; // 10 minutes en millisecondes

        function resetInactivityTimer() {
            clearTimeout(inactivityTimer);
            inactivityTimer = setTimeout(logoutDueToInactivity, TIMEOUT);
        }

        function logoutDueToInactivity() {
            // Envoyer une requête au serveur pour déconnecter
            fetch('logout.php?timeout=1')
                .then(() => {
                    window.location.href = 'index.php?expired=1';
                })
                .catch(() => {
                    window.location.href = 'index.php?expired=1';
                });
        }

        // Événements de détection d'activité
        document.addEventListener('DOMContentLoaded', () => {
            resetInactivityTimer();

            // Détecter l'activité utilisateur
            ['mousedown', 'mousemove', 'keydown', 'scroll', 'click', 'touchstart'].forEach(event => {
                document.addEventListener(event, resetInactivityTimer);
            });

            // Animation de chargement des cartes avec délai progressif
            const cards = document.querySelectorAll('.fade-in');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
            });

            // Effet de survol amélioré pour les cartes de stats
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-8px) scale(1.02)';
                    this.style.boxShadow = '0 20px 50px rgba(0, 0, 0, 0.2)';
                });

                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                    this.style.boxShadow = '0 15px 40px rgba(0, 0, 0, 0.15)';
                });
            });

            // Afficher/masquer les détails dans les tables sur mobile
            if (window.innerWidth <= 768) {
                const tableRows = document.querySelectorAll('tbody tr');
                tableRows.forEach(row => {
                    row.addEventListener('click', function() {
                        this.classList.toggle('expanded');
                    });
                });
            }

            // Ajouter une indication de chargement pour les actions
            const actionCards = document.querySelectorAll('.action-card');
            actionCards.forEach(card => {
                card.addEventListener('click', function(e) {
                    // Empêcher le clic multiple rapide
                    if (this.classList.contains('loading')) {
                        e.preventDefault();
                        return;
                    }

                    // Ajouter un effet de chargement
                    this.classList.add('loading');
                    const icon = this.querySelector('.action-icon i');
                    const originalIcon = icon.className;

                    // Changer l'icône temporairement
                    icon.className = 'fas fa-spinner fa-spin';

                    // Restaurer après 1.5 secondes
                    setTimeout(() => {
                        this.classList.remove('loading');
                        icon.className = originalIcon;
                    }, 1500);
                });
            });

            // Gestion des couleurs dynamiques pour les badges de type
            const typeBadges = document.querySelectorAll('.type-badge');
            const typeColors = {
                'membre': 'linear-gradient(135deg, #3b82f6, #2563eb)',
                'tontine': 'linear-gradient(135deg, #f59e0b, #d97706)',
                'seance': 'linear-gradient(135deg, #10b981, #059669)',
                'cotisation': 'linear-gradient(135deg, #a855f7, #9333ea)',
                'credit': 'linear-gradient(135deg, #ef4444, #dc2626)'
            };

            typeBadges.forEach(badge => {
                const badgeType = badge.textContent.toLowerCase();
                if (typeColors[badgeType]) {
                    badge.style.background = typeColors[badgeType];
                }
            });

            // Initialiser le tooltip pour les statuts
            const statusBadges = document.querySelectorAll('.status-badge');
            statusBadges.forEach(badge => {
                const status = badge.textContent.trim().toLowerCase();
                badge.title = `Statut: ${status === 'actif' ? 'Membre actif' : 'Membre inactif'}`;
            });

            // Gestion responsive pour le tableau
            function handleTableResponsive() {
                const tables = document.querySelectorAll('table');
                const isMobile = window.innerWidth <= 768;

                tables.forEach(table => {
                    if (isMobile) {
                        table.classList.add('mobile-view');
                        // Ajouter des attributs data-label pour les cellules
                        const headers = table.querySelectorAll('th');
                        const rows = table.querySelectorAll('tbody tr');

                        headers.forEach((header, index) => {
                            const headerText = header.textContent;
                            rows.forEach(row => {
                                const cell = row.children[index];
                                if (cell) {
                                    cell.setAttribute('data-label', headerText);
                                }
                            });
                        });
                    } else {
                        table.classList.remove('mobile-view');
                    }
                });
            }

            // Initialiser et surveiller les changements de taille
            handleTableResponsive();
            window.addEventListener('resize', handleTableResponsive);

            // Notification système pour les mises à jour importantes
            function checkForUpdates() {
                // Simuler une vérification de mise à jour
                const lastUpdate = localStorage.getItem('lastUpdateCheck');
                const now = new Date().getTime();

                if (!lastUpdate || (now - lastUpdate) > 3600000) { // Toutes les heures
                    localStorage.setItem('lastUpdateCheck', now.toString());

                    // Afficher une notification discrète
                    showNotification('Système à jour', 'Votre tableau de bord est à jour.', 'success');
                }
            }

            // Fonction pour afficher les notifications
            function showNotification(title, message, type = 'info') {
                const notification = document.createElement('div');
                notification.className = `notification ${type}`;
                notification.innerHTML = `
                    <div class="notification-icon">
                        <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                    </div>
                    <div class="notification-content">
                        <strong>${title}</strong>
                        <p>${message}</p>
                    </div>
                    <button class="notification-close">
                        <i class="fas fa-times"></i>
                    </button>
                `;

                notification.style.cssText = `
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    background: white;
                    padding: 15px;
                    border-radius: 10px;
                    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
                    display: flex;
                    align-items: center;
                    gap: 15px;
                    z-index: 9999;
                    animation: slideIn 0.3s ease;
                    border-left: 4px solid ${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#3b82f6'};
                    max-width: 350px;
                `;

                document.body.appendChild(notification);

                // Fermer la notification
                const closeBtn = notification.querySelector('.notification-close');
                closeBtn.addEventListener('click', () => {
                    notification.style.animation = 'slideOut 0.3s ease';
                    setTimeout(() => notification.remove(), 300);
                });

                // Auto-fermer après 5 secondes
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.style.animation = 'slideOut 0.3s ease';
                        setTimeout(() => notification.remove(), 300);
                    }
                }, 5000);
            }

            // Ajouter les styles d'animation pour les notifications
            const style = document.createElement('style');
            style.textContent = `
                @keyframes slideIn {
                    from {
                        transform: translateX(100%);
                        opacity: 0;
                    }
                    to {
                        transform: translateX(0);
                        opacity: 1;
                    }
                }
                @keyframes slideOut {
                    from {
                        transform: translateX(0);
                        opacity: 1;
                    }
                    to {
                        transform: translateX(100%);
                        opacity: 0;
                    }
                }
                .notification-close {
                    background: none;
                    border: none;
                    color: #6b7280;
                    cursor: pointer;
                    font-size: 14px;
                    padding: 5px;
                    border-radius: 50%;
                    transition: all 0.2s;
                }
                .notification-close:hover {
                    background: #f3f4f6;
                    color: #374151;
                }
                .notification-icon {
                    font-size: 20px;
                    color: ${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#3b82f6'};
                }
            `;
            document.head.appendChild(style);

            // Vérifier les mises à jour après 3 secondes
            setTimeout(checkForUpdates, 3000);

            // Initialiser les graphiques si nécessaire (pour les futures versions)
            function initCharts() {
                // Cette fonction peut être étendue pour initialiser des graphiques
                // avec Chart.js ou une autre bibliothèque
                console.log('Initialisation des graphiques...');
            }

            // Démarrer les graphiques
            initCharts();
        });

        // Gestion de la déconnexion propre
        document.querySelectorAll('a[href="logout.php"]').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();

                // Afficher une confirmation
                if (confirm('Voulez-vous vraiment vous déconnecter ?')) {
                    // Ajouter un effet de chargement
                    const icon = this.querySelector('i');
                    if (icon) {
                        const originalClass = icon.className;
                        icon.className = 'fas fa-spinner fa-spin';

                        // Rediriger après un délai
                        setTimeout(() => {
                            window.location.href = 'logout.php';
                        }, 500);
                    } else {
                        window.location.href = 'logout.php';
                    }
                }
            });
        });

        // Gestion du mode hors ligne
        window.addEventListener('online', () => {
            showNotification('Connexion rétablie', 'Vous êtes de nouveau en ligne.', 'success');
        });

        window.addEventListener('offline', () => {
            showNotification('Hors ligne', 'Vérifiez votre connexion internet.', 'error');
        });

        // Préchargement des pages fréquemment visitées
        function preloadPages() {
            if (navigator.connection && navigator.connection.saveData) {
                return; // Ne pas précharger en mode économie de données
            }

            const pagesToPreload = <?php echo $is_admin ? "['membre.php', 'tontine.php', 'seance.php']" : "['cotisation.php', 'seances.php', 'credit.php']"; ?>;

            pagesToPreload.forEach(page => {
                const link = document.createElement('link');
                link.rel = 'prefetch';
                link.href = page;
                document.head.appendChild(link);
            });
        }

        // Précharger après 2 secondes
        setTimeout(preloadPages, 2000);
    </script>
</body>

</html>