<?php
session_start();
include './fonctions/config.php';

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Fonctions utilitaires
function sanitizeInput($data)
{
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Fonction pour déterminer le statut d'une tontine
function getStatutTontine($date_debut, $date_fin)
{
    $now = time();
    $debut = strtotime($date_debut);
    $fin = strtotime($date_fin);

    if ($now < $debut) {
        return 'Prochaine';
    } elseif ($now >= $debut && $now <= $fin) {
        return 'En cours';
    } else {
        return 'Terminée';
    }
}

// Récupérer les informations de l'utilisateur
$stmt = $pdo->prepare("SELECT * FROM membre WHERE id_membre = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit();
}

// Vérifier si la table participation_tontine existe, sinon la créer
try {
    $pdo->query("SELECT 1 FROM participation_tontine LIMIT 1");
} catch (PDOException $e) {
    // Créer la table si elle n'existe pas
    $pdo->exec("CREATE TABLE IF NOT EXISTS participation_tontine (
        id_participation int NOT NULL AUTO_INCREMENT PRIMARY KEY,
        id_membre int NOT NULL,
        id_tontine int NOT NULL,
        date_participation date NOT NULL,
        statut enum('active','inactive') DEFAULT 'active',
        UNIQUE KEY unique_participation (id_membre, id_tontine),
        KEY id_membre (id_membre),
        KEY id_tontine (id_tontine)
    )");
}

// Traitement de la participation à une tontine
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['participer_tontine'])) {
    $id_tontine = sanitizeInput($_POST['id_tontine'] ?? '');

    if (empty($id_tontine)) {
        $message = "Veuillez sélectionner une tontine";
        $message_type = "error";
    } else {
        try {
            // Vérifier si l'utilisateur participe déjà
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM participation_tontine WHERE id_membre = ? AND id_tontine = ? AND statut = 'active'");
            $checkStmt->execute([$user_id, $id_tontine]);

            if ($checkStmt->fetchColumn() > 0) {
                $message = "Vous participez déjà à cette tontine";
                $message_type = "warning";
            } else {
                // Vérifier les informations de la tontine
                $checkTontineStmt = $pdo->prepare("SELECT * FROM tontine WHERE id_tontine = ?");
                $checkTontineStmt->execute([$id_tontine]);
                $tontine = $checkTontineStmt->fetch();

                if ($tontine) {
                    // Vérifier si la tontine est "Prochaine"
                    $statut = getStatutTontine($tontine['date_debut'], $tontine['date_fin']);

                    if ($statut !== 'Prochaine') {
                        $message = "Vous ne pouvez adhérer qu'aux tontines futures";
                        $message_type = "error";
                    } else {
                        // Vérifier le nombre maximum de participants
                        $checkParticipantsStmt = $pdo->prepare("SELECT COUNT(*) FROM participation_tontine WHERE id_tontine = ? AND statut = 'active'");
                        $checkParticipantsStmt->execute([$id_tontine]);
                        $nb_participants = $checkParticipantsStmt->fetchColumn();

                        if ($tontine['participants_max'] > 0 && $nb_participants >= $tontine['participants_max']) {
                            $message = "Cette tontine a atteint le nombre maximum de participants";
                            $message_type = "error";
                        } else {
                            // Ajouter l'utilisateur comme participant
                            $sql = "INSERT INTO participation_tontine (id_membre, id_tontine, date_participation, statut) 
                                    VALUES (?, ?, CURDATE(), 'active')";

                            $stmt = $pdo->prepare($sql);
                            $stmt->execute([$user_id, $id_tontine]);

                            if ($stmt->rowCount() > 0) {
                                $message = "Vous participez maintenant à cette tontine avec succès!";
                                $message_type = "success";
                            } else {
                                $message = "Erreur lors de l'adhésion à la tontine";
                                $message_type = "error";
                            }
                        }
                    }
                } else {
                    $message = "Cette tontine n'existe pas";
                    $message_type = "error";
                }
            }
        } catch (PDOException $e) {
            $message = "Erreur lors de la participation: " . $e->getMessage();
            $message_type = "error";
            error_log("Erreur adhésion tontine: " . $e->getMessage());
        }
    }
}

// Récupérer toutes les tontines
try {
    // Vérifier si la table tontine existe
    $pdo->query("SELECT 1 FROM tontine LIMIT 1");

    $stmt = $pdo->query("SELECT t.*, 
                        COALESCE((SELECT COUNT(*) FROM participation_tontine pt WHERE pt.id_tontine = t.id_tontine AND pt.statut = 'active'), 0) as nombre_participants
                        FROM tontine t 
                        ORDER BY date_debut ASC");
    $toutes_tontines = $stmt->fetchAll();

    // Ajouter le statut calculé
    foreach ($toutes_tontines as &$tontine) {
        $tontine['statut'] = getStatutTontine($tontine['date_debut'], $tontine['date_fin']);
    }
} catch (PDOException $e) {
    // Table tontine n'existe pas
    $toutes_tontines = [];
    error_log("Erreur récupération tontines: " . $e->getMessage());
}

// Récupérer les tontines auxquelles l'utilisateur participe
try {
    // Vérifier si la table participation_tontine existe
    $pdo->query("SELECT 1 FROM participation_tontine LIMIT 1");

    $stmt = $pdo->prepare("SELECT t.*, pt.date_participation,
                          COALESCE((SELECT COUNT(*) FROM participation_tontine pt2 WHERE pt2.id_tontine = t.id_tontine AND pt2.statut = 'active'), 0) as nombre_participants
                           FROM tontine t 
                           INNER JOIN participation_tontine pt ON t.id_tontine = pt.id_tontine 
                           WHERE pt.id_membre = ? AND pt.statut = 'active'
                           ORDER BY t.date_debut ASC");
    $stmt->execute([$user_id]);
    $mes_tontines = $stmt->fetchAll();

    // Ajouter le statut calculé
    foreach ($mes_tontines as &$tontine) {
        $tontine['statut'] = getStatutTontine($tontine['date_debut'], $tontine['date_fin']);
    }
} catch (PDOException $e) {
    $mes_tontines = [];
    error_log("Erreur récupération mes tontines: " . $e->getMessage());
}

// Filtrer les tontines disponibles pour adhésion
$tontines_prochaines = array_filter($toutes_tontines, function ($tontine) use ($mes_tontines) {
    // Vérifier que le statut est "Prochaine"
    if ($tontine['statut'] !== 'Prochaine') return false;

    // Vérifier que l'utilisateur n'est pas déjà membre
    foreach ($mes_tontines as $ma_tontine) {
        if ($ma_tontine['id_tontine'] == $tontine['id_tontine']) return false;
    }

    // Vérifier si la tontine a atteint sa capacité maximale
    if ($tontine['participants_max'] > 0 && $tontine['nombre_participants'] >= $tontine['participants_max']) {
        return false;
    }

    return true;
});

// Calculer les statistiques
$mes_tontines_en_cours = array_filter($mes_tontines, function ($t) {
    return $t['statut'] === 'En cours';
});

$mes_tontines_prochaines = array_filter($mes_tontines, function ($t) {
    return $t['statut'] === 'Prochaine';
});

$mes_tontines_terminees = array_filter($mes_tontines, function ($t) {
    return $t['statut'] === 'Terminée';
});

// Calculer les montants totaux
$montant_total_gagne = 0;
foreach ($mes_tontines as $tontine) {
    // Si vous avez un champ montant_gagne dans beneficiaire
    try {
        // Vérifier si la table beneficiaire existe
        $pdo->query("SELECT 1 FROM beneficiaire LIMIT 1");

        $stmt = $pdo->prepare("SELECT montant_gagne FROM beneficiaire WHERE id_membre = ? AND id_tontine = ?");
        $stmt->execute([$user_id, $tontine['id_tontine']]);
        $gains = $stmt->fetch();
        if ($gains && $gains['montant_gagne'] > 0) {
            $montant_total_gagne += $gains['montant_gagne'];
        }
    } catch (PDOException $e) {
        // Table beneficiaire n'existe pas ou erreur
        continue;
    }
}
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Tontines | Système de Tontine</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
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
            font-family: 'Poppins', 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            min-height: 100vh;
            padding: 20px;
            color: var(--text-dark);
        }

        .dashboard-container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 30px;
        }

        /* Header */
        .dashboard-header {
            background: linear-gradient(135deg, var(--navy-blue) 0%, var(--dark-blue) 100%);
            color: var(--pure-white);
            padding: 40px;
            border-radius: var(--border-radius);
            text-align: center;
            box-shadow: 0 10px 30px var(--shadow-medium);
            animation: fadeInDown 0.8s;
            position: relative;
            overflow: hidden;
        }

        .dashboard-header::before {
            content: "";
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, rgba(255, 255, 255, 0) 70%);
            transform: rotate(30deg);
            animation: shine 8s infinite linear;
        }

        @keyframes shine {
            0% {
                transform: rotate(30deg) translate(-10%, -10%);
            }

            100% {
                transform: rotate(30deg) translate(10%, 10%);
            }
        }

        .dashboard-header h1 {
            font-size: 2.5rem;
            margin-bottom: 15px;
            position: relative;
            z-index: 1;
        }

        .dashboard-subtitle {
            font-size: 1.1rem;
            color: var(--accent-gold);
            position: relative;
            z-index: 1;
        }

        /* Main Content */
        .dashboard-content {
            display: flex;
            flex-direction: column;
            gap: 30px;
            animation: fadeInUp 0.8s;
        }

        /* Cards */
        .dashboard-card {
            background: var(--pure-white);
            border-radius: var(--border-radius);
            padding: 30px;
            box-shadow: 0 5px 20px var(--shadow-light);
            transition: var(--transition);
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px var(--shadow-medium);
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--bg-light);
        }

        .card-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--medium-blue) 0%, var(--light-blue) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--pure-white);
            font-size: 1.2rem;
        }

        .card-title {
            font-size: 1.5rem;
            color: var(--navy-blue);
            font-weight: 600;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--pure-white);
            border-radius: var(--border-radius);
            padding: 25px;
            text-align: center;
            box-shadow: 0 3px 10px var(--shadow-light);
            transition: var(--transition);
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px var(--shadow-medium);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--medium-blue) 0%, var(--light-blue) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--pure-white);
            font-size: 1.2rem;
            margin: 0 auto 15px;
        }

        .stat-number {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--navy-blue);
            margin-bottom: 5px;
        }

        .stat-label {
            color: var(--text-light);
            font-size: 0.9rem;
            font-weight: 500;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 25px;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-dark);
            font-size: 0.9rem;
        }

        .form-select {
            width: 100%;
            padding: 14px 15px;
            border: 1px solid var(--bg-light);
            border-radius: var(--border-radius);
            font-family: "Poppins", sans-serif;
            font-size: 0.95rem;
            transition: var(--transition);
            background: var(--bg-light);
            color: var(--text-dark);
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 15px center;
            background-size: 16px;
            padding-right: 40px;
        }

        .form-select:focus {
            border-color: var(--medium-blue);
            box-shadow: 0 0 0 3px rgba(45, 74, 138, 0.2);
            outline: none;
            background: var(--pure-white);
        }

        /* Buttons */
        .btn {
            padding: 14px 28px;
            border-radius: var(--border-radius);
            font-family: "Poppins", sans-serif;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            font-size: 0.95rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--medium-blue) 0%, var(--light-blue) 100%);
            color: var(--pure-white);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--dark-blue) 0%, var(--medium-blue) 100%);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(45, 74, 138, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #34d399 100%);
            color: var(--pure-white);
        }

        .btn-success:hover {
            background: linear-gradient(135deg, #059669 0%, #10b981 100%);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(16, 185, 129, 0.3);
        }

        .btn-secondary {
            background: var(--bg-light);
            color: var(--text-light);
            border: 1px solid var(--bg-light);
        }

        .btn-secondary:hover {
            background: #e2e8f0;
            color: var(--text-dark);
        }

        .btn-group {
            display: flex;
            gap: 15px;
            margin-top: 10px;
        }

        /* Messages */
        .message {
            padding: 16px 20px;
            border-radius: var(--border-radius);
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: fadeIn 0.5s;
        }

        .message i {
            font-size: 1.2rem;
        }

        .message.success {
            background: rgba(21, 128, 61, 0.1);
            color: #166534;
            border-left: 4px solid #22c55e;
        }

        .message.error {
            background: rgba(220, 38, 38, 0.1);
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }

        .message.warning {
            background: rgba(245, 158, 11, 0.1);
            color: #92400e;
            border-left: 4px solid #f59e0b;
        }

        /* Tontine Cards Grid */
        .tontines-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
            margin-top: 20px;
        }

        @media (max-width: 768px) {
            .tontines-grid {
                grid-template-columns: 1fr;
            }
        }

        .tontine-card {
            background: var(--pure-white);
            border-radius: var(--border-radius);
            padding: 25px;
            box-shadow: 0 3px 15px var(--shadow-light);
            transition: var(--transition);
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .tontine-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px var(--shadow-medium);
        }

        .tontine-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .tontine-name {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--navy-blue);
            margin-bottom: 5px;
        }

        .tontine-details {
            margin-bottom: 20px;
        }

        .tontine-detail {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .tontine-detail i {
            width: 16px;
            text-align: center;
        }

        /* Status Badge */
        .status-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .status-prochaine {
            background: rgba(59, 130, 246, 0.1);
            color: #2563eb;
        }

        .status-en-cours {
            background: rgba(34, 197, 94, 0.1);
            color: #166534;
        }

        .status-terminee {
            background: rgba(148, 163, 184, 0.1);
            color: #475569;
        }

        .status-membre {
            background: rgba(99, 102, 241, 0.1);
            color: #6366f1;
        }

        /* Amount styling */
        .amount {
            font-weight: 600;
            color: var(--navy-blue);
        }

        .amount-large {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--navy-blue);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 20px;
            color: var(--bg-light);
        }

        .empty-state h3 {
            color: var(--text-light);
            font-weight: 500;
            margin-bottom: 10px;
        }

        /* Animations */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .dashboard-header {
                padding: 30px 20px;
            }

            .dashboard-header h1 {
                font-size: 2rem;
            }

            .dashboard-card {
                padding: 20px;
            }

            .btn-group {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .tontines-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            body {
                padding: 10px;
            }

            .dashboard-header {
                padding: 25px 15px;
            }

            .dashboard-header h1 {
                font-size: 1.8rem;
            }
        }

        /* Form Card */
        .form-card {
            background: linear-gradient(135deg, rgba(248, 250, 252, 0.9) 0%, rgba(241, 245, 249, 0.9) 100%);
            border-radius: var(--border-radius);
            padding: 25px;
            margin-top: 20px;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        /* Tontine Info */
        .tontine-info {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.05) 0%, rgba(255, 255, 255, 0.95) 100%);
            border-radius: var(--border-radius);
            padding: 16px;
            margin-top: 16px;
            border-left: 4px solid var(--medium-blue);
            display: none;
            animation: fadeIn 0.3s ease;
        }

        .tontine-info.show {
            display: block;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
        }

        .info-label {
            font-size: 0.75rem;
            color: var(--gray);
            margin-bottom: 4px;
        }

        .info-value {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary);
        }
    </style>
</head>

<body>
    <div class="dashboard-container">
        <!-- Header -->
        <header class="dashboard-header animate__animated animate__fadeInDown">
            <h1>Gestion des Tontines</h1>
            <p class="dashboard-subtitle">Adhérez aux tontines futures et gérez vos participations</p>
        </header>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'error' ? 'exclamation-circle' : 'exclamation-triangle'); ?>"></i>
                <span><?php echo $message; ?></span>
            </div>
        <?php endif; ?>

        <div class="dashboard-content">
            <!-- Section: Mes Tontines -->
            <div class="dashboard-card animate__animated animate__fadeInUp">
                <div class="card-header">
                    <div class="card-icon">
                        <i class="fas fa-hand-holding-usd"></i>
                    </div>
                    <h2 class="card-title">Mes Tontines</h2>
                </div>

                <!-- Mes Tontines Statistics -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-hand-holding-usd"></i>
                        </div>
                        <div class="stat-number"><?php echo count($mes_tontines); ?></div>
                        <div class="stat-label">Total Tontines</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-play-circle"></i>
                        </div>
                        <div class="stat-number"><?php echo count($mes_tontines_en_cours); ?></div>
                        <div class="stat-label">En Cours</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="stat-number"><?php echo count($mes_tontines_prochaines); ?></div>
                        <div class="stat-label">À Venir</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-trophy"></i>
                        </div>
                        <div class="stat-number"><?php echo number_format($montant_total_gagne, 0, ',', ' '); ?> FCFA</div>
                        <div class="stat-label">Gains Totaux</div>
                    </div>
                </div>

                <?php if (count($mes_tontines) > 0): ?>
                    <!-- Mes Tontines Grid -->
                    <div class="tontines-grid">
                        <?php foreach ($mes_tontines as $tontine): ?>
                            <div class="tontine-card">
                                <div class="tontine-header">
                                    <div>
                                        <div class="tontine-name"><?php echo htmlspecialchars($tontine['nom_tontine']); ?></div>
                                        <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $tontine['statut'])); ?>">
                                            <?php echo $tontine['statut']; ?>
                                        </span>
                                    </div>
                                    <div>
                                        <span class="status-badge status-membre">
                                            <i class="fas fa-check"></i> Membre
                                        </span>
                                    </div>
                                </div>

                                <div class="tontine-details">
                                    <div class="tontine-detail">
                                        <i class="fas fa-money-bill-wave"></i>
                                        <span>Cotisation: <strong class="amount"><?php echo number_format($tontine['montant_cotisation'], 0, ',', ' '); ?> FCFA</strong></span>
                                    </div>
                                    <div class="tontine-detail">
                                        <i class="fas fa-calendar"></i>
                                        <span>Début: <?php echo date('d/m/Y', strtotime($tontine['date_debut'])); ?></span>
                                    </div>
                                    <div class="tontine-detail">
                                        <i class="fas fa-calendar-check"></i>
                                        <span>Fin: <?php echo date('d/m/Y', strtotime($tontine['date_fin'])); ?></span>
                                    </div>
                                    <div class="tontine-detail">
                                        <i class="fas fa-users"></i>
                                        <span>Participants: <?php echo $tontine['nombre_participants']; ?><?php echo $tontine['participants_max'] > 0 ? '/' . $tontine['participants_max'] : ''; ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-hand-holding-usd"></i>
                        <h3>Aucune tontine</h3>
                        <p>Vous ne participez à aucune tontine pour le moment.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Section: Toutes les Tontines -->
            <div class="dashboard-card animate__animated animate__fadeInUp">
                <div class="card-header">
                    <div class="card-icon">
                        <i class="fas fa-list"></i>
                    </div>
                    <h2 class="card-title">Toutes les Tontines</h2>
                </div>

                <!-- Formulaire d'adhésion -->
                <div class="form-card">
                    <form method="POST" action="" id="formAdhesion">
                        <div class="form-group">
                            <label for="adhesion_tontine">Sélectionnez une tontine à venir</label>
                            <select name="id_tontine" id="adhesion_tontine" class="form-select" required>
                                <option value="">Choisissez une tontine...</option>
                                <?php if (count($tontines_prochaines) > 0): ?>
                                    <?php foreach ($tontines_prochaines as $tontine): ?>
                                        <option value="<?php echo $tontine['id_tontine']; ?>"
                                            data-montant="<?php echo $tontine['montant_cotisation']; ?>"
                                            data-date-debut="<?php echo date('d/m/Y', strtotime($tontine['date_debut'])); ?>"
                                            data-date-fin="<?php echo date('d/m/Y', strtotime($tontine['date_fin'])); ?>"
                                            data-participants="<?php echo $tontine['nombre_participants']; ?>"
                                            data-participants-max="<?php echo $tontine['participants_max'] ?? 'Illimité'; ?>">
                                            <?php echo htmlspecialchars($tontine['nom_tontine']); ?> -
                                            <?php echo number_format($tontine['montant_cotisation'], 0, ',', ' '); ?> FCFA
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="" disabled>Aucune tontine à venir disponible</option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div id="detailsTontine" class="tontine-info">
                            <div class="info-grid">
                                <div class="info-item">
                                    <div class="info-label">Montant de cotisation</div>
                                    <div class="info-value" id="detailMontant">-</div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Date de début</div>
                                    <div class="info-value" id="detailDateDebut">-</div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Date de fin</div>
                                    <div class="info-value" id="detailDateFin">-</div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Participants</div>
                                    <div class="info-value" id="detailParticipants">-</div>
                                </div>
                            </div>
                        </div>

                        <div class="btn-group">
                            <button type="submit" name="participer_tontine" class="btn btn-primary" id="btnAdherer" <?php echo count($tontines_prochaines) === 0 ? 'disabled' : ''; ?>>
                                <i class="fas fa-user-plus"></i> Adhérer
                            </button>
                            <button type="reset" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Annuler
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Liste de toutes les tontines -->
                <?php if (count($toutes_tontines) > 0): ?>
                    <div class="tontines-grid">
                        <?php foreach ($toutes_tontines as $tontine):
                            $est_membre = false;
                            foreach ($mes_tontines as $ma_tontine) {
                                if ($ma_tontine['id_tontine'] == $tontine['id_tontine']) {
                                    $est_membre = true;
                                    break;
                                }
                            }
                        ?>
                            <div class="tontine-card">
                                <div class="tontine-header">
                                    <div>
                                        <div class="tontine-name"><?php echo htmlspecialchars($tontine['nom_tontine']); ?></div>
                                        <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $tontine['statut'])); ?>">
                                            <?php echo $tontine['statut']; ?>
                                        </span>
                                    </div>
                                    <?php if ($est_membre): ?>
                                        <div>
                                            <span class="status-badge status-membre">
                                                <i class="fas fa-check"></i> Membre
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="tontine-details">
                                    <div class="tontine-detail">
                                        <i class="fas fa-money-bill-wave"></i>
                                        <span>Cotisation: <strong class="amount"><?php echo number_format($tontine['montant_cotisation'], 0, ',', ' '); ?> FCFA</strong></span>
                                    </div>
                                    <div class="tontine-detail">
                                        <i class="fas fa-calendar"></i>
                                        <span>Début: <?php echo date('d/m/Y', strtotime($tontine['date_debut'])); ?></span>
                                    </div>
                                    <div class="tontine-detail">
                                        <i class="fas fa-calendar-check"></i>
                                        <span>Fin: <?php echo date('d/m/Y', strtotime($tontine['date_fin'])); ?></span>
                                    </div>
                                    <div class="tontine-detail">
                                        <i class="fas fa-users"></i>
                                        <span>Participants: <?php echo $tontine['nombre_participants']; ?><?php echo $tontine['participants_max'] > 0 ? '/' . $tontine['participants_max'] : ''; ?></span>
                                    </div>
                                </div>

                                <div class="btn-group" style="margin-top: 15px;">
                                    <?php if ($est_membre): ?>
                                        <button class="btn btn-success" disabled style="width: 100%;">
                                            <i class="fas fa-check"></i> Déjà membre
                                        </button>
                                    <?php elseif ($tontine['statut'] === 'Prochaine'): ?>
                                        <?php if ($tontine['participants_max'] > 0 && $tontine['nombre_participants'] >= $tontine['participants_max']): ?>
                                            <button class="btn btn-secondary" disabled style="width: 100%;">
                                                <i class="fas fa-user-slash"></i> Complet
                                            </button>
                                        <?php else: ?>
                                            <form method="POST" action="" style="width: 100%;">
                                                <input type="hidden" name="id_tontine" value="<?php echo $tontine['id_tontine']; ?>">
                                                <button type="submit" name="participer_tontine" class="btn btn-primary" style="width: 100%;">
                                                    <i class="fas fa-user-plus"></i> Adhérer
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <button class="btn btn-secondary" disabled style="width: 100%;">
                                            <i class="fas fa-clock"></i> <?php echo $tontine['statut'] === 'En cours' ? 'En cours' : 'Terminée'; ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-list"></i>
                        <h3>Aucune tontine disponible</h3>
                        <p>Aucune tontine n'a été créée pour le moment.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        $(document).ready(function() {
            // Afficher les détails de la tontine sélectionnée
            $('#adhesion_tontine').on('change', function() {
                const option = $(this).find('option:selected');
                const montant = option.data('montant');
                const dateDebut = option.data('date-debut');
                const dateFin = option.data('date-fin');
                const participants = option.data('participants');
                const participantsMax = option.data('participants-max');

                if (option.val()) {
                    const formatter = new Intl.NumberFormat('fr-FR');
                    $('#detailMontant').text(formatter.format(montant) + ' FCFA');
                    $('#detailDateDebut').text(dateDebut);
                    $('#detailDateFin').text(dateFin);

                    let participantsText = participants;
                    if (participantsMax !== 'Illimité') {
                        participantsText += '/' + participantsMax;
                    }
                    $('#detailParticipants').text(participantsText);

                    $('#detailsTontine').addClass('show');
                    $('#btnAdherer').prop('disabled', false);
                } else {
                    $('#detailsTontine').removeClass('show');
                    $('#btnAdherer').prop('disabled', true);
                }
            });

            // Confirmation avant participation
            $('form').on('submit', function(e) {
                if ($(this).find('button[name="participer_tontine"]').length > 0) {
                    e.preventDefault();
                    const form = this;

                    Swal.fire({
                        title: 'Confirmer l\'adhésion',
                        text: 'Voulez-vous vraiment adhérer à cette tontine ?',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Oui, adhérer',
                        cancelButtonText: 'Annuler',
                        confirmButtonColor: '#2d4a8a',
                        cancelButtonColor: '#64748b',
                        reverseButtons: true
                    }).then((result) => {
                        if (result.isConfirmed) {
                            form.submit();
                        }
                    });
                }
            });

            // Toast messages
            function showToast(icon, title, text, timer = 4000) {
                Swal.fire({
                    icon: icon,
                    title: title,
                    text: text,
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: timer,
                    timerProgressBar: true,
                    background: 'var(--pure-white)',
                    color: 'var(--text-dark)'
                });
            }

            <?php if ($message_type === 'success'): ?>
                showToast('success', 'Succès!', '<?php echo addslashes($message); ?>');
                setTimeout(() => {
                    $('.message.success').fadeOut();
                }, 5000);
            <?php endif; ?>

            <?php if ($message_type === 'error'): ?>
                showToast('error', 'Erreur!', '<?php echo addslashes($message); ?>');
            <?php endif; ?>

            <?php if ($message_type === 'warning'): ?>
                showToast('warning', 'Attention!', '<?php echo addslashes($message); ?>');
            <?php endif; ?>

            // Animation des cartes de tontine
            $('.tontine-card').each(function(index) {
                $(this).css('animation-delay', (index * 0.1) + 's');
                $(this).addClass('animate__animated animate__fadeIn');
            });
        });
    </script>
</body>

</html>