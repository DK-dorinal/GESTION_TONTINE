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
$stmt = $pdo->prepare("SELECT * FROM membre WHERE id_membre = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Si l'utilisateur n'existe pas
if (!$user) {
    session_destroy();
    header('Location: index.php');
    exit();
}

// Variables pour le formulaire
$error = '';
$success = '';

// Traitement du formulaire de demande de crédit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Récupérer et valider les données
    $montant = floatval($_POST['montant'] ?? 0);
    $duree_mois = intval($_POST['duree_mois'] ?? 0);
    $raison = trim($_POST['raison'] ?? '');
    $type_credit = $_POST['type_credit'] ?? 'standard';
    $mode_remboursement = $_POST['mode_remboursement'] ?? 'mensuel';
    
    // Validation des données
    if ($montant <= 0) {
        $error = "Le montant doit être supérieur à 0.";
    } elseif ($montant > 10000000) { // Limite de 10 millions
        $error = "Le montant maximum autorisé est de 10,000,000 FCFA.";
    } elseif ($duree_mois < 3 || $duree_mois > 60) {
        $error = "La durée doit être comprise entre 3 et 60 mois.";
    } elseif (empty($raison)) {
        $error = "Veuillez préciser la raison de votre demande de crédit.";
    } else {
        try {
            // Calculer le taux d'intérêt selon le type de crédit
            $taux_interet = 5.00; // Taux par défaut pour 'standard'
            
            if ($type_credit === 'urgent') {
                $taux_interet = 6.50;
            } elseif ($type_credit === 'long_terme') {
                $taux_interet = 4.50;
            } elseif ($type_credit === 'projet') {
                $taux_interet = 4.00;
            }
            
            // Vérifier si l'utilisateur n'a pas déjà trop de crédits en cours
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM credit WHERE id_membre = ? AND statut IN ('en_cours', 'en_retard')");
            $stmt_check->execute([$user_id]);
            $credits_en_cours = $stmt_check->fetchColumn();
            
            if ($credits_en_cours >= 3) {
                $error = "Vous avez déjà 3 crédits en cours. Vous ne pouvez pas faire de nouvelle demande.";
            } else {
                // Vérifier le solde total des crédits en cours
                $stmt_solde = $pdo->prepare("SELECT COALESCE(SUM(montant_restant), 0) FROM credit WHERE id_membre = ? AND statut IN ('en_cours', 'en_retard')");
                $stmt_solde->execute([$user_id]);
                $solde_total = $stmt_solde->fetchColumn();
                
                if ($solde_total + $montant > 2000000) { // Limite de 2 millions de solde total
                    $error = "Votre solde total de crédit ne peut pas dépasser 2,000,000 FCFA. Solde actuel: " . number_format($solde_total, 0, ',', ' ') . " FCFA";
                } else {
                    // Calculer les intérêts et le montant total
                    $interet_total = ($montant * $taux_interet / 100) * ($duree_mois / 12);
                    $montant_total = $montant + $interet_total;
                    $mensualite = $montant_total / $duree_mois;
                    
                    // Insérer la demande dans la table crédit avec statut "demande"
                    $stmt_insert = $pdo->prepare("
                        INSERT INTO credit 
                        (id_membre, montant, date_emprunt, taux_interet, duree_mois, montant_restant, statut, type_credit)
                        VALUES (?, ?, NOW(), ?, ?, ?, 'demande', ?)
                    ");
                    
                    $stmt_insert->execute([
                        $user_id,
                        $montant,
                        $taux_interet,
                        $duree_mois,
                        $montant, // montant_restant initial = montant emprunté
                        $type_credit
                    ]);
                    
                    $credit_id = $pdo->lastInsertId();
                    
                    // Enregistrer la raison dans une table séparée ou dans un champ additionnel
                    // Note: La table crédit dans votre base de données n'a pas de champ 'raison'
                    // Si vous avez besoin de stocker la raison, vous devrez ajouter ce champ à la table
                    // Pour l'instant, on va stocker la raison dans une table séparée si elle existe
                    try {
                        $stmt_raison = $pdo->prepare("
                            INSERT INTO historique_demandes_credit 
                            (id_credit, action, date_action, commentaire)
                            VALUES (?, 'demande_soumise', NOW(), ?)
                        ");
                        $stmt_raison->execute([$credit_id, "Raison: " . $raison]);
                    } catch (Exception $e) {
                        // La table n'existe peut-être pas, on continue sans erreur
                    }
                    
                    $success = "Votre demande de crédit a été soumise avec succès ! 
                               <br><strong>ID Demande:</strong> #{$credit_id}
                               <br><strong>Montant:</strong> " . number_format($montant, 0, ',', ' ') . " FCFA
                               <br><strong>Type:</strong> " . ucfirst(str_replace('_', ' ', $type_credit)) . "
                               <br><strong>Durée:</strong> {$duree_mois} mois
                               <br><strong>Taux:</strong> {$taux_interet}%
                               <br><strong>Mensualité estimée:</strong> " . number_format($mensualite, 0, ',', ' ') . " FCFA
                               <br><strong>Montant total à rembourser:</strong> " . number_format($montant_total, 0, ',', ' ') . " FCFA
                               <br><br>Votre demande sera traitée par l'administrateur dans les plus brefs délais.";
                    
                    // Réinitialiser les champs du formulaire
                    $_POST = array();
                }
            }
        } catch (PDOException $e) {
            $error = "Erreur lors de l'enregistrement de la demande: " . $e->getMessage();
        }
    }
}

// Récupérer les crédits en cours de l'utilisateur
$stmt_credits = $pdo->prepare("
    SELECT * FROM credit 
    WHERE id_membre = ? 
    ORDER BY date_emprunt DESC
");
$stmt_credits->execute([$user_id]);
$mes_credits = $stmt_credits->fetchAll();

// Calculer les statistiques des crédits
$total_emprunte = 0;
$total_restant = 0;
$credits_en_cours = 0;

foreach ($mes_credits as $credit) {
    $total_emprunte += $credit['montant'];
    $total_restant += $credit['montant_restant'];
    if ($credit['statut'] === 'en_cours') {
        $credits_en_cours++;
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Demande de Crédit | Gestion de Tontine</title>
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

        .app-container {
            display: flex;
            min-height: 100vh;
            width: 100%;
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

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 30px;
            width: 100%;
            padding-bottom: 100px;
        }

        /* Content Card */
        .content-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 30px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            margin-bottom: 30px;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .card-title {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-title i {
            color: var(--accent-gold);
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
            min-width: 200px;
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

        /* Form Styles */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-group label {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.9rem;
        }

        .form-group label i {
            color: var(--accent-gold);
            margin-right: 8px;
        }

        .form-control {
            padding: 14px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 1rem;
            transition: var(--transition);
            background: white;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--accent-gold);
            box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.1);
        }

        .credit-type-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-top: 10px;
        }

        .credit-type-card {
            background: linear-gradient(135deg, rgba(212, 175, 55, 0.08), rgba(58, 95, 192, 0.08));
            border: 2px solid transparent;
            border-radius: 12px;
            padding: 20px;
            cursor: pointer;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .credit-type-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .credit-type-card.selected {
            border-color: var(--accent-gold);
            background: linear-gradient(135deg, rgba(212, 175, 55, 0.15), rgba(58, 95, 192, 0.15));
        }

        .credit-type-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            margin-bottom: 15px;
        }

        .credit-type-title {
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 5px;
        }

        .credit-type-desc {
            font-size: 0.8rem;
            color: var(--text-light);
        }

        .credit-type-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            color: white;
        }

        /* Sliders */
        .range-group {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-top: 10px;
        }

        .range-value {
            min-width: 100px;
            font-weight: 700;
            color: var(--medium-blue);
            font-size: 1.2rem;
        }

        input[type="range"] {
            flex: 1;
            height: 8px;
            border-radius: 4px;
            background: #e2e8f0;
            outline: none;
            -webkit-appearance: none;
        }

        input[type="range"]::-webkit-slider-thumb {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--accent-gold);
            cursor: pointer;
            -webkit-appearance: none;
            box-shadow: 0 4px 10px rgba(212, 175, 55, 0.5);
        }

        /* Simulation Card */
        .simulation-card {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border: 2px solid var(--light-blue);
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 30px;
        }

        .simulation-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--dark-blue);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .simulation-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }

        .simulation-item {
            text-align: center;
        }

        .simulation-label {
            font-size: 0.9rem;
            color: var(--text-light);
            margin-bottom: 8px;
        }

        .simulation-value {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--dark-blue);
        }

        /* Table */
        .table-container {
            overflow-x: auto;
            border-radius: 12px;
            margin-top: 20px;
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
            cursor: pointer;
        }

        td {
            padding: 14px 16px;
            font-size: 0.9rem;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            display: inline-block;
        }

        .status-demande { background: #fef3c7; color: #92400e; }
        .status-en_cours { background: #d1fae5; color: #065f46; }
        .status-en_retard { background: #fee2e2; color: #991b1b; }
        .status-rembourse { background: #dbeafe; color: #1e40af; }
        .status-rejete { background: #f3f4f6; color: #6b7280; }

        /* Info Cards */
        .info-cards {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-top: 30px;
        }

        .info-card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.9), rgba(255, 255, 255, 0.7));
            backdrop-filter: blur(10px);
            border-radius: 12px;
            padding: 20px;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .info-card-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-card-title i {
            color: var(--accent-gold);
        }

        .info-card-list {
            list-style: none;
        }

        .info-card-list li {
            padding: 8px 0;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            color: var(--text-light);
        }

        .info-card-list li:last-child {
            border-bottom: none;
        }

        .info-card-list li i {
            color: var(--medium-blue);
            font-size: 0.8rem;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 14px 28px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 1rem;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: var(--transition);
            justify-content: center;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent-gold), var(--accent-light));
            color: var(--navy-blue);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(212, 175, 55, 0.4);
        }

        .btn-secondary {
            background: linear-gradient(135deg, var(--medium-blue), var(--light-blue));
            color: white;
        }

        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(58, 95, 192, 0.4);
        }

        /* Alerts */
        .alert {
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 30px;
            display: flex;
            align-items: flex-start;
            gap: 15px;
        }

        .alert-error {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(239, 68, 68, 0.05));
            border-left: 4px solid #ef4444;
        }

        .alert-success {
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.1), rgba(34, 197, 94, 0.05));
            border-left: 4px solid #22c55e;
        }

        .alert-icon {
            font-size: 24px;
            flex-shrink: 0;
        }

        .alert-error .alert-icon { color: #ef4444; }
        .alert-success .alert-icon { color: #22c55e; }

        .alert-content {
            flex: 1;
        }

        .alert-title {
            font-weight: 700;
            margin-bottom: 5px;
        }

        .alert-error .alert-title { color: #ef4444; }
        .alert-success .alert-title { color: #22c55e; }

        .alert-message {
            color: white;
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

        .mobile-nav-icon {
            font-size: 22px;
            margin-bottom: 4px;
        }

        .mobile-nav-text {
            font-size: 10px;
            font-weight: 600;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                padding: 20px;
                padding-bottom: 80px;
            }
            
            .form-grid,
            .credit-type-grid,
            .simulation-grid,
            .info-cards {
                grid-template-columns: 1fr;
            }
            
            .mobile-nav {
                display: block;
            }
        }

        @media (max-width: 768px) {
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
            
            .content-card {
                padding: 20px;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 15px;
                padding-bottom: 80px;
            }
            
            .header-title h1 {
                font-size: 1.5rem;
            }
            
            .stat-card {
                min-width: 100%;
            }
            
            .stat-value {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <header class="header">
                <div class="header-top">
                    <div class="header-title">
                        <h1><i class="fas fa-hand-holding-usd"></i> Demande de Crédit</h1>
                        <p>Bienvenue <strong><?php echo htmlspecialchars($user['prenom'] . ' ' . $user['nom']); ?></strong> !</p>
                    </div>
                    <div class="header-actions">
                        <div class="date-badge">
                            <i class="fas fa-calendar-alt"></i>
                            <span id="currentDate"></span>
                        </div>
                        <a href="dashboard.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i>
                            Retour au Dashboard
                        </a>
                    </div>
                </div>
            </header>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #667eea, #764ba2);">
                            <i class="fas fa-credit-card"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo count($mes_credits); ?></div>
                    <div class="stat-label">Mes Crédits</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #f093fb, #f5576c);">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($total_emprunte, 0, ',', ' '); ?> F</div>
                    <div class="stat-label">Total Emprunté</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #4facfe, #00f2fe);">
                            <i class="fas fa-hourglass-half"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo $credits_en_cours; ?></div>
                    <div class="stat-label">Crédits en Cours</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #fa709a, #fee140);">
                            <i class="fas fa-balance-scale"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($total_restant, 0, ',', ' '); ?> F</div>
                    <div class="stat-label">Reste à Payer</div>
                </div>
            </div>

            <!-- Alerts -->
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle alert-icon"></i>
                    <div class="alert-content">
                        <div class="alert-title">Erreur</div>
                        <div class="alert-message"><?php echo $error; ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle alert-icon"></i>
                    <div class="alert-content">
                        <div class="alert-title">Succès !</div>
                        <div class="alert-message"><?php echo $success; ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Form Section -->
            <div class="content-card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-edit"></i>
                        Formulaire de Demande
                    </h3>
                </div>

                <form method="POST" action="" id="creditForm">
                    <!-- Type de crédit -->
                    <div class="form-group">
                        <label><i class="fas fa-tag"></i> Type de Crédit</label>
                        <div class="credit-type-grid">
                            <div class="credit-type-card" onclick="selectCreditType('standard')">
                                <div class="credit-type-icon" style="background: linear-gradient(135deg, #3b82f6, #2563eb);">
                                    <i class="fas fa-money-bill-wave"></i>
                                </div>
                                <h4 class="credit-type-title">Standard</h4>
                                <p class="credit-type-desc">Pour les besoins courants</p>
                                <span class="credit-type-badge" style="background: #3b82f6;">5.0%</span>
                            </div>

                            <div class="credit-type-card" onclick="selectCreditType('urgent')">
                                <div class="credit-type-icon" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
                                    <i class="fas fa-bolt"></i>
                                </div>
                                <h4 class="credit-type-title">Urgent</h4>
                                <p class="credit-type-desc">Besoins immédiats</p>
                                <span class="credit-type-badge" style="background: #ef4444;">6.5%</span>
                            </div>

                            <div class="credit-type-card" onclick="selectCreditType('long_terme')">
                                <div class="credit-type-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
                                    <i class="fas fa-calendar-alt"></i>
                                </div>
                                <h4 class="credit-type-title">Long Terme</h4>
                                <p class="credit-type-desc">Investissements</p>
                                <span class="credit-type-badge" style="background: #10b981;">4.5%</span>
                            </div>

                            <div class="credit-type-card" onclick="selectCreditType('projet')">
                                <div class="credit-type-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                                    <i class="fas fa-project-diagram"></i>
                                </div>
                                <h4 class="credit-type-title">Projet</h4>
                                <p class="credit-type-desc">Financement de projets</p>
                                <span class="credit-type-badge" style="background: #8b5cf6;">4.0%</span>
                            </div>
                        </div>
                        <input type="hidden" name="type_credit" id="type_credit" value="standard">
                    </div>

                    <!-- Montant et Durée -->
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-money-bill-wave"></i> Montant (FCFA)</label>
                            <input type="number" id="montant" name="montant" min="10000" max="10000000" step="1000"
                                value="<?php echo htmlspecialchars($_POST['montant'] ?? ''); ?>"
                                class="form-control"
                                placeholder="Ex: 500000"
                                required
                                oninput="updateSimulation()">
                            <div class="range-group">
                                <span class="range-value" id="montantValue">0 FCFA</span>
                                <input type="range" id="montant_range" min="10000" max="10000000" step="1000" 
                                    value="<?php echo $_POST['montant'] ?? 500000; ?>"
                                    oninput="document.getElementById('montant').value = this.value; updateSimulation()">
                            </div>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-calendar-alt"></i> Durée (mois)</label>
                            <input type="number" id="duree_mois" name="duree_mois" min="3" max="60" step="1"
                                value="<?php echo htmlspecialchars($_POST['duree_mois'] ?? '12'); ?>"
                                class="form-control"
                                placeholder="Ex: 12"
                                required
                                oninput="updateSimulation()">
                            <div class="range-group">
                                <span class="range-value" id="dureeValue">12 mois</span>
                                <input type="range" id="duree_range" min="3" max="60" step="1"
                                    value="<?php echo $_POST['duree_mois'] ?? 12; ?>"
                                    oninput="document.getElementById('duree_mois').value = this.value; updateSimulation()">
                            </div>
                        </div>
                    </div>

                    <!-- Raison -->
                    <div class="form-group">
                        <label><i class="fas fa-comment-dots"></i> Raison de la demande</label>
                        <textarea id="raison" name="raison" rows="4"
                            class="form-control"
                            placeholder="Décrivez l'utilisation prévue du crédit..."
                            required><?php echo htmlspecialchars($_POST['raison'] ?? ''); ?></textarea>
                    </div>

                    <!-- Simulation -->
                    <div class="simulation-card">
                        <h4 class="simulation-title">
                            <i class="fas fa-calculator"></i>
                            Simulation du Remboursement
                        </h4>
                        <div class="simulation-grid">
                            <div class="simulation-item">
                                <div class="simulation-label">Mensualité</div>
                                <div id="mensualite" class="simulation-value">0 FCFA</div>
                            </div>
                            <div class="simulation-item">
                                <div class="simulation-label">Total Intérêts</div>
                                <div id="interet_total" class="simulation-value">0 FCFA</div>
                            </div>
                            <div class="simulation-item">
                                <div class="simulation-label">Total à Rembourser</div>
                                <div id="total_rembourser" class="simulation-value">0 FCFA</div>
                            </div>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div style="text-align: center; margin-top: 30px;">
                        <button type="submit" class="btn btn-primary" style="min-width: 260px;">
                            <i class="fas fa-paper-plane"></i>
                            Soumettre la Demande
                        </button>
                    </div>
                </form>
            </div>

            <!-- My Credits -->
            <div class="content-card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-history"></i>
                        Mes Demandes de Crédit
                    </h3>
                </div>

                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Montant</th>
                                <th>Type</th>
                                <th>Date</th>
                                <th>Statut</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($mes_credits)): ?>
                                <?php foreach ($mes_credits as $credit): ?>
                                    <tr onclick="window.location.href='detail_credit.php?id=<?php echo $credit['id_credit']; ?>'">
                                        <td style="font-weight: 600;">#<?php echo $credit['id_credit']; ?></td>
                                        <td style="font-weight: 700; color: var(--medium-blue);">
                                            <?php echo number_format($credit['montant'], 0, ',', ' '); ?> FCFA
                                        </td>
                                        <td>
                                            <?php
                                            if (isset($credit['type_credit'])) {
                                                switch ($credit['type_credit']) {
                                                    case 'standard': echo 'Standard'; break;
                                                    case 'urgent': echo 'Urgent'; break;
                                                    case 'long_terme': echo 'Long Terme'; break;
                                                    case 'projet': echo 'Projet'; break;
                                                    default: echo ucfirst(str_replace('_', ' ', $credit['type_credit']));
                                                }
                                            } else {
                                                echo 'Standard';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php echo date('d/m/Y', strtotime($credit['date_emprunt'])); ?>
                                        </td>
                                        <td>
                                            <?php
                                            $status_class = '';
                                            $status_text = '';
                                            switch ($credit['statut']) {
                                                case 'demande':
                                                    $status_class = 'status-demande';
                                                    $status_text = 'En attente';
                                                    break;
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
                                                case 'rejete':
                                                    $status_class = 'status-rejete';
                                                    $status_text = 'Rejeté';
                                                    break;
                                                default:
                                                    $status_class = 'status-demande';
                                                    $status_text = $credit['statut'];
                                            }
                                            ?>
                                            <span class="status-badge <?php echo $status_class; ?>">
                                                <?php echo $status_text; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 40px;">
                                        <div style="color: var(--text-light);">
                                            <i class="fas fa-credit-card" style="font-size: 3rem; opacity: 0.3; margin-bottom: 15px;"></i>
                                            <p style="font-weight: 600;">Aucune demande de crédit</p>
                                            <p style="font-size: 0.9rem;">Soumettez votre première demande</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Information Cards -->
            <div class="info-cards">
                <div class="info-card">
                    <h4 class="info-card-title">
                        <i class="fas fa-check-circle"></i>
                        Conditions d'éligibilité
                    </h4>
                    <ul class="info-card-list">
                        <li><i class="fas fa-check"></i> Membre actif depuis 3 mois</li>
                        <li><i class="fas fa-check"></i> Maximum 3 crédits en cours</li>
                        <li><i class="fas fa-check"></i> Solde total ≤ 2,000,000 FCFA</li>
                        <li><i class="fas fa-check"></i> Cotisation à jour</li>
                    </ul>
                </div>

                <div class="info-card">
                    <h4 class="info-card-title">
                        <i class="fas fa-percentage"></i>
                        Taux d'Intérêt
                    </h4>
                    <ul class="info-card-list">
                        <li><i class="fas fa-money-bill-wave"></i> Standard: <strong>5.0%</strong></li>
                        <li><i class="fas fa-bolt"></i> Urgent: <strong>6.5%</strong></li>
                        <li><i class="fas fa-calendar-alt"></i> Long Terme: <strong>4.5%</strong></li>
                        <li><i class="fas fa-project-diagram"></i> Projet: <strong>4.0%</strong></li>
                    </ul>
                </div>

                <div class="info-card">
                    <h4 class="info-card-title">
                        <i class="fas fa-clock"></i>
                        Délais de Traitement
                    </h4>
                    <ul class="info-card-list">
                        <li><i class="fas fa-hourglass-half"></i> Traitement sous 48h</li>
                        <li><i class="fas fa-bell"></i> Notification par SMS</li>
                        <li><i class="fas fa-file-contract"></i> Contrat à signer</li>
                        <li><i class="fas fa-money-check-alt"></i> Délivrance rapide</li>
                    </ul>
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
            <a href="cotisation.php" class="mobile-nav-item">
                <i class="fas fa-money-bill-wave mobile-nav-icon"></i>
                <span class="mobile-nav-text">Cotisations</span>
            </a>
            <a href="seances.php" class="mobile-nav-item">
                <i class="fas fa-hand-holding-usd mobile-nav-icon"></i>
                <span class="mobile-nav-text">Tontines</span>
            </a>
            <a href="demander_credit.php" class="mobile-nav-item active">
                <i class="fas fa-credit-card mobile-nav-icon"></i>
                <span class="mobile-nav-text">Crédit</span>
            </a>
            <a href="logout.php" class="mobile-nav-item">
                <i class="fas fa-sign-out-alt mobile-nav-icon"></i>
                <span class="mobile-nav-text">Sortir</span>
            </a>
        </div>
    </nav>

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

        // Sélection du type de crédit
        function selectCreditType(type) {
            const cards = document.querySelectorAll('.credit-type-card');
            cards.forEach(card => card.classList.remove('selected'));
            
            event.currentTarget.classList.add('selected');
            document.getElementById('type_credit').value = type;
            updateSimulation();
        }

        // Simulation du crédit
        function updateSimulation() {
            const montant = parseFloat(document.getElementById('montant').value) || 0;
            const duree = parseInt(document.getElementById('duree_mois').value) || 12;
            const type = document.getElementById('type_credit').value;
            
            // Mettre à jour les valeurs affichées
            document.getElementById('montantValue').textContent = formatMoney(montant) + ' FCFA';
            document.getElementById('dureeValue').textContent = duree + ' mois';
            
            // Taux selon le type
            let taux = 5.0;
            switch(type) {
                case 'urgent': taux = 6.5; break;
                case 'long_terme': taux = 4.5; break;
                case 'projet': taux = 4.0; break;
            }
            
            // Calcul des intérêts
            const interetTotal = (montant * taux / 100) * (duree / 12);
            const totalARembourser = montant + interetTotal;
            const mensualite = totalARembourser / duree;
            
            // Mise à jour de l'affichage
            document.getElementById('mensualite').textContent = formatMoney(mensualite) + ' FCFA';
            document.getElementById('interet_total').textContent = formatMoney(interetTotal) + ' FCFA';
            document.getElementById('total_rembourser').textContent = formatMoney(totalARembourser) + ' FCFA';
        }

        // Formatage de l'argent
        function formatMoney(amount) {
            return amount.toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g, " ");
        }

        // Initialisation
        document.addEventListener('DOMContentLoaded', function() {
            // Initialiser la date
            updateDateTime();
            setInterval(updateDateTime, 60000);
            
            // Sélectionner le type standard par défaut
            selectCreditType('standard');
            
            // Initialiser les sliders
            document.getElementById('montant_range').value = document.getElementById('montant').value || 500000;
            document.getElementById('duree_range').value = document.getElementById('duree_mois').value || 12;
            
            // Mettre à jour la simulation
            updateSimulation();
            
            // Validation du formulaire
            document.getElementById('creditForm').addEventListener('submit', function(e) {
                const montant = parseFloat(document.getElementById('montant').value);
                const duree = parseInt(document.getElementById('duree_mois').value);
                const raison = document.getElementById('raison').value.trim();
                
                if (montant < 10000 || montant > 10000000) {
                    e.preventDefault();
                    alert('Le montant doit être compris entre 10,000 et 10,000,000 FCFA.');
                    return false;
                }
                
                if (duree < 3 || duree > 60) {
                    e.preventDefault();
                    alert('La durée doit être comprise entre 3 et 60 mois.');
                    return false;
                }
                
                if (raison.length < 10) {
                    e.preventDefault();
                    alert('Veuillez décrire la raison de votre demande (minimum 10 caractères).');
                    return false;
                }
                
                if (!confirm('Êtes-vous sûr de vouloir soumettre cette demande de crédit ?')) {
                    e.preventDefault();
                    return false;
                }
                
                return true;
            });
        });
    </script>
</body>
</html>