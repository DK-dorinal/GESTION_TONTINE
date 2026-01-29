<?php
@include './fonctions/config.php';
session_start();

// Vérification de session ADMIN
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header("Location: index.php");
    exit();
}

// Fonctions utilitaires
function sanitizeInput($data) {
    return htmlspecialchars(stripslashes(trim($data)), ENT_QUOTES, 'UTF-8');
}

// Fonction pour normaliser le numéro de téléphone au format international
function normalizePhoneNumber($phone) {
    // Supprimer tous les caractères non numériques
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    // Si le numéro commence par 0, on le remplace par +237
    if (strlen($phone) == 9 && substr($phone, 0, 1) == '0') {
        $phone = '237' . substr($phone, 1);
    }
    
    // Si le numéro a 9 chiffres et commence par 6, 7 ou 8 (Cameroun), ajouter 237
    if (strlen($phone) == 9 && in_array(substr($phone, 0, 1), ['6', '7', '8'])) {
        $phone = '237' . $phone;
    }
    
    // Si le numéro a déjà l'indicatif 237, ajouter le +
    if (substr($phone, 0, 3) == '237') {
        $phone = '+' . $phone;
    }
    
    // Si après traitement, le numéro n'a pas le bon format, on le retourne tel quel
    // (la validation se fera ensuite)
    return $phone;
}

// Fonction de validation du numéro de téléphone
function validatePhone($phone) {
    // Normaliser d'abord
    $phone = normalizePhoneNumber($phone);
    
    // Valider le format international Cameroun: +237 suivi de 9 chiffres
    // Les opérateurs camerounais: 6 (MTN), 7 (Orange), 8 (Camtel, Nexttel)
    return preg_match('/^\+237[6-8][0-9]{8}$/', $phone);
}

// Initialisation des variables
$message = '';
$message_type = '';
$membres = [];
$form_data = [
    'nom' => '',
    'prenom' => '',
    'telephone' => '',
    'adresse' => ''
];

// --- TRAITEMENT DU FORMULAIRE D'AJOUT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nom'])) {
    $form_data['nom'] = sanitizeInput($_POST['nom'] ?? '');
    $form_data['prenom'] = sanitizeInput($_POST['prenom'] ?? '');
    $raw_phone = sanitizeInput($_POST['telephone'] ?? '');
    $form_data['adresse'] = sanitizeInput($_POST['adresse'] ?? '');
    $date_inscription = date('Y-m-d');
    $statut = 'actif';

    // Normaliser le numéro de téléphone
    $form_data['telephone'] = normalizePhoneNumber($raw_phone);
    
    $errors = [];

    if (empty($form_data['nom'])) $errors[] = "Le nom est obligatoire";
    if (empty($form_data['prenom'])) $errors[] = "Le prénom est obligatoire";

    if (empty($raw_phone)) {
        $errors[] = "Le téléphone est obligatoire";
    } elseif (!validatePhone($raw_phone)) {
        $errors[] = "Le numéro de téléphone n'est pas valide. Format attendu: 6XX XXX XXX, 0XX XXX XXX, +237 6XX XXX XXX ou 237 6XX XXX XXX";
    }

    if (empty($form_data['adresse'])) {
        $errors[] = "L'adresse est obligatoire";
    }

    if (empty($errors)) {
        try {
            // Vérifier si le numéro existe déjà
            $checkStmt = $pdo->prepare("SELECT id_membre FROM membre WHERE telephone = ?");
            $checkStmt->execute([$form_data['telephone']]);
            
            if ($checkStmt->rowCount() > 0) {
                $errors[] = "Ce numéro de téléphone est déjà enregistré";
                $message = implode("<br>", $errors);
                $message_type = "error";
            } else {
                $sql = "INSERT INTO membre (nom, prenom, telephone, adresse, date_inscription, statut) 
                        VALUES (?, ?, ?, ?, ?, ?)";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $form_data['nom'], 
                    $form_data['prenom'], 
                    $form_data['telephone'], 
                    $form_data['adresse'], 
                    $date_inscription, 
                    $statut
                ]);

                $_SESSION['flash_message'] = "Membre enregistré avec succès!";
                $_SESSION['flash_type'] = "success";

                $form_data = [
                    'nom' => '',
                    'prenom' => '',
                    'telephone' => '',
                    'adresse' => ''
                ];
                
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            }
            
        } catch (PDOException $e) {
            $message = "Erreur lors de l'enregistrement: " . $e->getMessage();
            $message_type = "error";
        }
    } else {
        $message = implode("<br>", $errors);
        $message_type = "error";
    }
}

// --- RÉCUPÉRATION DES MESSAGES FLASH ---
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $message_type = $_SESSION['flash_type'];
    unset($_SESSION['flash_message']);
    unset($_SESSION['flash_type']);
}

// --- RÉCUPÉRATION DES MEMBRES ---
try {
    $stmt = $pdo->query("SELECT * FROM membre ORDER BY id_membre DESC");
    $membres = $stmt->fetchAll();
} catch (PDOException $e) {
    $membres = [];
    if (empty($message)) {
        $message = "Erreur lors de la récupération des membres: " . $e->getMessage();
        $message_type = "error";
    }
}

// Calcul des statistiques pour l'affichage
$total = count($membres);
$actifs = array_filter($membres, fn($m) => isset($m['statut']) && $m['statut'] === 'actif');
$inactifs = $total - count($actifs);
$pourcentageActifs = $total > 0 ? round((count($actifs) / $total) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Membres | TontinePro</title>
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
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --border-radius: 16px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, var(--navy-blue) 0%, var(--dark-blue) 100%);
            min-height: 100vh;
            color: var(--text-dark);
            line-height: 1.6;
            padding-bottom: 40px;
        }

        /* Header Elite */
        .main-header {
            background: linear-gradient(135deg, var(--medium-blue) 0%, var(--light-blue) 100%);
            padding: 30px 40px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            margin-bottom: 40px;
            position: relative;
            overflow: hidden;
        }

        .main-header::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background:
                radial-gradient(circle at 20% 50%, rgba(212, 175, 55, 0.15) 0%, transparent 50%),
                radial-gradient(circle at 80% 50%, rgba(255, 255, 255, 0.1) 0%, transparent 50%);
            pointer-events: none;
        }

        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
            position: relative;
            z-index: 1;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 18px;
        }

        .logo-icon {
            width: 65px;
            height: 65px;
            background: linear-gradient(135deg, var(--accent-gold) 0%, var(--accent-light) 100%);
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--navy-blue);
            font-size: 30px;
            box-shadow: 0 8px 24px rgba(212, 175, 55, 0.4);
            transition: var(--transition);
        }

        .logo-icon:hover {
            transform: scale(1.1) rotate(5deg);
        }

        .logo-text h1 {
            font-size: 2rem;
            color: var(--pure-white);
            margin-bottom: 5px;
            font-weight: 800;
            letter-spacing: -0.5px;
        }

        .logo-text p {
            color: rgba(255, 255, 255, 0.9);
            font-size: 0.95rem;
            font-weight: 300;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 18px;
            padding: 14px 26px;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            border-radius: 50px;
            border: 2px solid rgba(255, 255, 255, 0.25);
            transition: var(--transition);
        }

        .user-info:hover {
            background: rgba(255, 255, 255, 0.2);
            border-color: var(--accent-gold);
            transform: translateY(-2px);
        }

        .user-avatar {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--accent-gold) 0%, var(--accent-light) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--navy-blue);
            font-weight: 800;
            font-size: 1.2rem;
            border: 3px solid rgba(255, 255, 255, 0.3);
        }

        .user-details h4 {
            font-size: 1rem;
            color: var(--pure-white);
            font-weight: 600;
        }

        .user-details p {
            font-size: 0.85rem;
            color: var(--accent-light);
            font-weight: 500;
        }

        /* Container */
        .main-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px 40px;
        }

        /* Back Button */
        .back-to-dashboard {
            margin-bottom: 30px;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            padding: 14px 28px;
            background: rgba(255, 255, 255, 0.95);
            color: var(--medium-blue);
            text-decoration: none;
            border-radius: 12px;
            font-weight: 600;
            transition: var(--transition);
            border: 2px solid rgba(212, 175, 55, 0.3);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }

        .back-btn:hover {
            background: var(--pure-white);
            border-color: var(--accent-gold);
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(212, 175, 55, 0.3);
        }

        /* Quick Actions */
        .quick-actions {
            display: flex;
            gap: 16px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 16px 26px;
            border-radius: 12px;
            color: var(--pure-white);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            transition: var(--transition);
            cursor: pointer;
            font-family: inherit;
            font-size: 0.95rem;
            border: none;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
        }

        .action-btn.export-pdf {
            background: linear-gradient(135deg, #dc3545 0%, #e74c3c 100%);
        }

        .action-btn.export-pdf:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(220, 53, 69, 0.4);
        }

        .action-btn.print {
            background: linear-gradient(135deg, var(--warning-color) 0%, #ffd54f 100%);
            color: var(--text-dark);
        }

        .action-btn.print:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(255, 193, 7, 0.4);
        }

        .action-btn.stats {
            background: linear-gradient(135deg, var(--success-color) 0%, #20c997 100%);
        }

        .action-btn.stats:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(40, 167, 69, 0.4);
        }

        .export-form {
            display: inline;
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }

        /* Cards */
        .card {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: 0 8px 32px var(--shadow-light);
            transition: var(--transition);
            border: 2px solid rgba(212, 175, 55, 0.2);
        }

        .card:hover {
            box-shadow: 0 12px 48px var(--shadow-medium);
            transform: translateY(-5px);
            border-color: var(--accent-gold);
        }

        .card-header {
            background: linear-gradient(135deg, var(--medium-blue) 0%, var(--light-blue) 100%);
            color: var(--pure-white);
            padding: 28px 32px;
            position: relative;
            overflow: hidden;
        }

        .card-header::before {
            content: "";
            position: absolute;
            top: 0;
            right: 0;
            width: 150px;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(212, 175, 55, 0.2));
        }

        .card-header h2 {
            font-size: 1.5rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 14px;
            position: relative;
            z-index: 1;
        }

        .card-header h2 i {
            color: var(--accent-gold);
            font-size: 1.3rem;
        }

        .card-body {
            padding: 32px;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 26px;
        }

        .form-label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            color: var(--navy-blue);
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .required {
            color: var(--danger-color);
        }

        .form-control {
            width: 100%;
            padding: 16px 20px;
            border: 2px solid rgba(45, 74, 138, 0.2);
            border-radius: 12px;
            font-family: inherit;
            font-size: 1rem;
            transition: var(--transition);
            background: var(--pure-white);
            color: var(--text-dark);
        }

        .form-control:focus {
            border-color: var(--accent-gold);
            box-shadow: 0 0 0 4px rgba(212, 175, 55, 0.1);
            outline: none;
            transform: translateY(-2px);
        }

        .input-with-icon {
            position: relative;
        }

        .input-with-icon i {
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--accent-gold);
            font-size: 1.1rem;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 110px;
        }

        /* Buttons */
        .btn-group {
            display: flex;
            gap: 16px;
            margin-top: 24px;
        }

        .btn {
            padding: 16px 32px;
            border-radius: 12px;
            font-family: inherit;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            flex: 1;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--medium-blue) 0%, var(--light-blue) 100%);
            color: var(--pure-white);
            box-shadow: 0 4px 20px rgba(45, 74, 138, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(45, 74, 138, 0.4);
        }

        .btn-secondary {
            background: var(--pure-white);
            color: var(--medium-blue);
            border: 2px solid rgba(45, 74, 138, 0.3);
        }

        .btn-secondary:hover {
            background: var(--bg-light);
            border-color: var(--accent-gold);
        }

        /* Alerts */
        .alert {
            padding: 18px 24px;
            border-radius: 12px;
            margin-bottom: 26px;
            display: flex;
            align-items: center;
            gap: 16px;
            animation: slideIn 0.3s ease-out;
            border-left: 4px solid transparent;
            backdrop-filter: blur(10px);
        }

        .alert-success {
            background: rgba(40, 167, 69, 0.15);
            color: #155724;
            border-left-color: var(--success-color);
        }

        .alert-error {
            background: rgba(220, 53, 69, 0.15);
            color: #721c24;
            border-left-color: var(--danger-color);
        }

        .alert-warning {
            background: rgba(255, 193, 7, 0.15);
            color: #856404;
            border-left-color: var(--warning-color);
        }

        .alert i {
            font-size: 1.4rem;
        }

        /* Table */
        .table-responsive {
            overflow-x: auto;
            border-radius: 12px;
            margin: 20px 0;
            box-shadow: 0 4px 24px var(--shadow-light);
            border: 2px solid rgba(212, 175, 55, 0.2);
            height: 40vh;
            scrollbar-width: 1px solid var(--accent-light);
        }

        .members-table {
            width: 100%;
            border-collapse: collapse;
        }

        .members-table th {
            background: linear-gradient(135deg, var(--medium-blue) 0%, var(--light-blue) 100%);
            color: var(--pure-white);
            padding: 18px;
            text-align: left;
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .members-table tbody tr:hover {
            background: linear-gradient(90deg, rgba(212, 175, 55, 0.08), transparent);
            transform: scale(1.01);
        }

        .members-table td {
            padding: 18px;
            color: var(--text-dark);
            font-size: 0.95rem;
            vertical-align: middle;
        }

        .member-id {
            font-weight: 800;
            color: var(--medium-blue);
            font-size: 1.1rem;
        }

        .member-name {
            font-weight: 700;
            color: var(--navy-blue);
            font-size: 1rem;
        }

        .member-info {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            color: var(--text-light);
            margin-top: 5px;
        }

        .member-info i {
            width: 16px;
            color: var(--accent-gold);
        }

        /* Status Badge */
        .status-badge {
            padding: 8px 18px;
            border-radius: 25px;
            font-size: 0.85rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .status-badge i {
            font-size: 0.7rem;
        }

        .status-active {
            background: linear-gradient(135deg, rgba(40, 167, 69, 0.2), rgba(32, 201, 151, 0.2));
            color: var(--success-color);
            border: 2px solid rgba(40, 167, 69, 0.4);
        }

        .status-inactive {
            background: linear-gradient(135deg, rgba(220, 53, 69, 0.2), rgba(231, 76, 60, 0.2));
            color: var(--danger-color);
            border: 2px solid rgba(220, 53, 69, 0.4);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
            margin-top: 32px;
        }

        .stat-card {
            background: linear-gradient(135deg, var(--pure-white) 0%, rgba(248, 250, 252, 0.8) 100%);
            padding: 30px;
            border-radius: var(--border-radius);
            text-align: center;
            transition: var(--transition);
            border: 2px solid rgba(212, 175, 55, 0.3);
            box-shadow: 0 4px 24px var(--shadow-light);
        }

        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 40px var(--shadow-medium);
            border-color: var(--accent-gold);
        }

        .stat-icon {
            width: 72px;
            height: 72px;
            background: linear-gradient(135deg, var(--accent-gold) 0%, var(--accent-light) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--navy-blue);
            font-size: 1.8rem;
            margin: 0 auto 20px;
            box-shadow: 0 8px 24px rgba(212, 175, 55, 0.4);
        }

        .stat-number {
            font-size: 2.8rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--medium-blue), var(--light-blue));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 10px;
            line-height: 1;
        }

        .stat-label {
            color: var(--text-light);
            font-size: 0.9rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 70px 20px;
            color: var(--text-light);
        }

        .empty-state i {
            font-size: 5rem;
            margin-bottom: 24px;
            color: rgba(203, 213, 225, 0.6);
        }

        .empty-state h3 {
            color: var(--text-dark);
            font-weight: 600;
            margin-bottom: 12px;
            font-size: 1.5rem;
        }

        .empty-state p {
            max-width: 450px;
            margin: 0 auto;
            line-height: 1.8;
        }

        /* Loading Overlay */
        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(15, 26, 58, 0.97), rgba(26, 43, 85, 0.97));
            backdrop-filter: blur(10px);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            flex-direction: column;
        }

        .loading-spinner {
            width: 80px;
            height: 80px;
            border: 8px solid rgba(212, 175, 55, 0.2);
            border-top: 8px solid var(--accent-gold);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 30px;
            box-shadow: 0 0 40px rgba(212, 175, 55, 0.4);
        }

        .loading-text {
            font-size: 1.4rem;
            margin-top: 16px;
            text-align: center;
            color: var(--pure-white);
            font-weight: 600;
        }

        .loading-overlay p {
            color: var(--accent-light);
            font-size: 1.1rem;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

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

        /* Responsive */
        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .main-header {
                padding: 24px 20px;
            }

            .header-content {
                flex-direction: column;
                text-align: center;
            }

            .user-info {
                width: 100%;
                justify-content: center;
            }

            .main-container {
                padding: 0 15px 30px;
            }

            .card-body {
                padding: 24px;
            }

            .btn-group {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .members-table th:nth-child(4),
            .members-table td:nth-child(4) {
                display: none;
            }

            .quick-actions {
                justify-content: center;
            }

            .back-to-dashboard {
                text-align: center;
            }
        }

        @media (max-width: 480px) {
            .logo {
                flex-direction: column;
                text-align: center;
            }

            .card-header {
                padding: 24px;
            }

            .card-header h2 {
                font-size: 1.3rem;
            }

            .form-control {
                padding: 14px 16px;
            }

            .stat-number {
                font-size: 2.2rem;
            }

            .members-table th:nth-child(3),
            .members-table td:nth-child(3) {
                display: none;
            }
        }

        @media print {

            .main-header,
            .btn-group,
            .quick-actions,
            .back-to-dashboard {
                display: none;
            }

            .card {
                box-shadow: none;
                border: 1px solid #ddd;
            }

            .members-table {
                min-width: auto;
            }
        }
    </style>
</head>

<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
        <p>Génération du document en cours...</p>
        <div class="loading-text">Veuillez patienter quelques secondes</div>
    </div>

    <!-- Header -->
    <header class="main-header">
        <div class="header-content">
            <div class="logo">
                <div class="logo-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="logo-text">
                    <h1>Gestion des Membres</h1>
                    <p>Administration et inscription des membres</p>
                </div>
            </div>

            <div class="user-info">
                <div class="user-avatar">
                    <?php echo isset($_SESSION['nom']) ? strtoupper(substr($_SESSION['nom'], 0, 1)) : 'A'; ?>
                </div>
                <div class="user-details">
                    <h4><?php echo htmlspecialchars($_SESSION['nom'] ?? 'Administrateur'); ?></h4>
                    <p><?php echo htmlspecialchars($_SESSION['role'] ?? 'Admin'); ?></p>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <div class="main-container">
        <!-- Bouton Retour Dashboard -->
        <div class="back-to-dashboard">
            <a href="dashboard.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Retour au Dashboard
            </a>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <form method="POST" action="" class="export-form" style="display: none;">
                <button type="submit" name="export_pdf" class="action-btn export-pdf" id="exportPdfBtn">
                    <i class="fas fa-file-pdf"></i> Exporter en PDF
                </button>
            </form>

            <button class="action-btn print" onclick="window.print()">
                <i class="fas fa-print"></i> Imprimer
            </button>

            <button class="action-btn stats" id="showStatsBtn">
                <i class="fas fa-chart-bar"></i> Voir Statistiques
            </button>
        </div>

        <!-- Content Grid -->
        <div class="content-grid">
            <!-- Form Card -->
            <div class="card" style="animation: fadeInUp 0.6s ease-out;">
                <div class="card-header">
                    <h2><i class="fas fa-user-plus"></i> Ajouter un Nouveau Membre</h2>
                </div>
                <div class="card-body">
                    <?php if ($message): ?>
                        <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : ($message_type === 'warning' ? 'warning' : 'error'); ?>">
                            <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'warning' ? 'exclamation-triangle' : 'exclamation-circle'); ?>"></i>
                            <span><?php echo $message; ?></span>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="" id="memberForm">
                        <div class="form-group">
                            <label for="nom" class="form-label">
                                <i class="fas fa-user"></i> Nom <span class="required">*</span>
                            </label>
                            <input type="text"
                                id="nom"
                                name="nom"
                                class="form-control"
                                value="<?php echo htmlspecialchars($form_data['nom']); ?>"
                                required
                                maxlength="50"
                                placeholder="Entrez le nom du membre"
                                autocomplete="off">
                        </div>

                        <div class="form-group">
                            <label for="prenom" class="form-label">
                                <i class="fas fa-id-card"></i> Prénom <span class="required">*</span>
                            </label>
                            <input type="text"
                                id="prenom"
                                name="prenom"
                                class="form-control"
                                value="<?php echo htmlspecialchars($form_data['prenom']); ?>"
                                required
                                maxlength="50"
                                placeholder="Entrez le prénom du membre"
                                autocomplete="off">
                        </div>

                        <div class="form-group">
                            <label for="telephone" class="form-label">
                                <i class="fas fa-phone"></i> Téléphone <span class="required">*</span>
                            </label>
                            <div class="input-with-icon">
                                <input type="tel"
                                    id="telephone"
                                    name="telephone"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($form_data['telephone']); ?>"
                                    required
                                    pattern="[0-9\s\-\+\(\)]{8,20}"
                                    placeholder="Ex: +237 6XX XX XX XX"
                                    autocomplete="off">
                                <i class="fas fa-mobile-alt"></i>
                            </div>
                            <small style="color: var(--text-light); font-size: 0.85rem; margin-top: 6px; display: block;">
                                Format: 8 à 20 chiffres, espaces, +, -, ( ) acceptés
                            </small>
                        </div>

                        <div class="form-group">
                            <label for="adresse" class="form-label">
                                <i class="fas fa-map-marker-alt"></i> Adresse <span class="required">*</span>
                            </label>
                            <textarea
                                id="adresse"
                                name="adresse"
                                class="form-control"
                                required
                                maxlength="255"
                                rows="3"
                                placeholder="Entrez l'adresse complète du membre"
                                autocomplete="off"><?php echo htmlspecialchars($form_data['adresse']); ?></textarea>
                        </div>

                        <div class="btn-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-user-plus"></i> Ajouter le Membre
                            </button>
                            <button type="reset" class="btn btn-secondary" id="resetFormBtn">
                                <i class="fas fa-redo"></i> Réinitialiser
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Members List Card -->
            <div class="card" style="animation: fadeInUp 0.6s ease-out 0.2s both;">
                <div class="card-header">
                    <h2><i class="fas fa-list"></i> Liste des Membres <span style="font-weight: normal; opacity: 0.9;">(<?php echo count($membres); ?>)</span></h2>
                </div>
                <div class="card-body">
                    <?php if (count($membres) > 0): ?>
                        <div class="table-responsive">
                            <table class="members-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Membre</th>
                                        <th>Contact</th>
                                        <th>Date</th>
                                        <th>Statut</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($membres as $membre): ?>
                                        <tr>
                                            <td><span class="member-id">#<?php echo $membre['id_membre']; ?></span></td>
                                            <td>
                                                <div class="member-name"><?php echo htmlspecialchars($membre['nom'] . ' ' . $membre['prenom']); ?></div>
                                                <div class="member-info">
                                                    <i class="fas fa-map-marker-alt"></i>
                                                    <span><?php echo htmlspecialchars(substr($membre['adresse'] ?? 'Non renseignée', 0, 25) . (strlen($membre['adresse'] ?? '') > 25 ? '...' : '')); ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="member-info">
                                                    <i class="fas fa-phone"></i>
                                                    <?php echo htmlspecialchars($membre['telephone']); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="member-info">
                                                    <i class="fas fa-calendar-alt"></i>
                                                    <?php echo date('d/m/Y', strtotime($membre['date_inscription'])); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $membre['statut']; ?>">
                                                    <i class="fas fa-circle"></i>
                                                    <?php echo ucfirst($membre['statut']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Statistics -->
                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="fas fa-users"></i>
                                </div>
                                <div class="stat-number"><?php echo count($membres); ?></div>
                                <div class="stat-label">Total Membres</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="fas fa-user-check"></i>
                                </div>
                                <div class="stat-number">
                                    <?php
                                    $actifs = array_filter($membres, function ($m) {
                                        return isset($m['statut']) && $m['statut'] === 'actif';
                                    });
                                    echo count($actifs);
                                    ?>
                                </div>
                                <div class="stat-label">Actifs</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="fas fa-calendar-plus"></i>
                                </div>
                                <div class="stat-number">
                                    <?php
                                    $date_limite = date('Y-m-d', strtotime('-30 days'));
                                    $nouveaux = array_filter($membres, function ($m) use ($date_limite) {
                                        return isset($m['date_inscription']) && $m['date_inscription'] >= $date_limite;
                                    });
                                    echo count($nouveaux);
                                    ?>
                                </div>
                                <div class="stat-label">Nouveaux (30j)</div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-users-slash"></i>
                            <h3>Aucun membre enregistré</h3>
                            <p>Commencez par ajouter un nouveau membre en utilisant le formulaire ci-contre.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        $(document).ready(function() {
            $('form.export-form').on('submit', function(e) {
                <?php if (count($membres) == 0): ?>
                    e.preventDefault();
                    Swal.fire({
                        icon: 'warning',
                        title: 'Aucun membre',
                        text: 'Il n\'y a aucun membre à exporter.',
                        confirmButtonColor: '#2d4a8a'
                    });
                    return false;
                <?php else: ?>
                    // Afficher l'indicateur de chargement
                    $('#loadingOverlay').fadeIn();
                    $('#exportPdfBtn').prop('disabled', true).css('opacity', '0.7');

                    // Désactiver l'indicateur après 30 secondes (en cas de problème)
                    setTimeout(function() {
                        $('#loadingOverlay').fadeOut();
                        $('#exportPdfBtn').prop('disabled', false).css('opacity', '1');
                    }, 30000);
                <?php endif; ?>
            });

            // Cacher l'indicateur quand la page se recharge
            $(window).on('pageshow load', function() {
                $('#loadingOverlay').fadeOut();
                $('#exportPdfBtn').prop('disabled', false).css('opacity', '1');
            });

            $('#memberForm').on('submit', function(e) {
                let isValid = true;
                const inputs = $(this).find('input[required], textarea[required]');

                inputs.css('border-color', 'rgba(45, 74, 138, 0.2)');

                inputs.each(function() {
                    if (!$(this).val().trim()) {
                        isValid = false;
                        $(this).css('border-color', '#dc3545');
                    }
                });

                const phone = $('#telephone');
                const phoneRegex = /^[0-9\s\-\+\(\)]{8,20}$/;
                if (phone.val() && !phoneRegex.test(phone.val())) {
                    isValid = false;
                    phone.css('border-color', '#dc3545');
                }

                if (!isValid) {
                    e.preventDefault();
                    Swal.fire({
                        icon: 'error',
                        title: 'Champs manquants',
                        text: 'Veuillez remplir tous les champs obligatoires correctement.',
                        confirmButtonColor: '#2d4a8a'
                    });
                } else {
                    const submitBtn = $(this).find('button[type="submit"]');
                    submitBtn.html('<i class="fas fa-spinner fa-spin"></i> Ajout en cours...');
                    submitBtn.prop('disabled', true);
                }
            });

            $('input, textarea').on('input', function() {
                $(this).css('border-color', 'rgba(45, 74, 138, 0.2)');
            });

            $('#resetFormBtn').on('click', function() {
                $('#memberForm')[0].reset();
                $('input, textarea').css('border-color', 'rgba(45, 74, 138, 0.2)');
            });

            $('#showStatsBtn').on('click', function() {
                <?php
                $total = count($membres);
                $actifs = array_filter($membres, function ($m) {
                    return isset($m['statut']) && $m['statut'] === 'actif';
                });
                $inactifs = $total - count($actifs);
                ?>

                Swal.fire({
                    title: '📊 Statistiques des Membres',
                    html: `
                        <div style="text-align: left; margin: 20px 0;">
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px;">
                                <div style="padding: 20px; background: linear-gradient(135deg, rgba(45, 74, 138, 0.1), rgba(58, 95, 192, 0.1)); border-radius: 12px; border-left: 4px solid #2d4a8a;">
                                    <strong style="color: #2d4a8a;">Total Membres:</strong><br>
                                    <span style="font-size: 2rem; color: #2d4a8a; font-weight: 800;"><?php echo $total; ?></span>
                                </div>
                                <div style="padding: 20px; background: linear-gradient(135deg, rgba(40, 167, 69, 0.1), rgba(32, 201, 151, 0.1)); border-radius: 12px; border-left: 4px solid #28a745;">
                                    <strong style="color: #28a745;">Actifs:</strong><br>
                                    <span style="font-size: 2rem; color: #28a745; font-weight: 800;"><?php echo count($actifs); ?></span>
                                </div>
                            </div>
                            <div style="background: rgba(248, 250, 252, 0.9); padding: 20px; border-radius: 12px; border: 2px solid rgba(212, 175, 55, 0.3);">
                                <h4 style="margin-top: 0; color: #1a2b55;">📈 Répartition</h4>
                                <div style="margin: 16px 0;">
                                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                                        <div style="flex: 1; height: 12px; background: #e2e8f0; border-radius: 6px; overflow: hidden;">
                                            <div style="height: 100%; width: <?php echo $total > 0 ? (count($actifs) / $total) * 100 : 0; ?>%; background: linear-gradient(90deg, #28a745, #20c997);"></div>
                                        </div>
                                        <span style="font-size: 1rem; color: #28a745; font-weight: 700;"><?php echo $total > 0 ? round((count($actifs) / $total) * 100) : 0; ?>% Actifs</span>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 12px;">
                                        <div style="flex: 1; height: 12px; background: #e2e8f0; border-radius: 6px; overflow: hidden;">
                                            <div style="height: 100%; width: <?php echo $total > 0 ? ($inactifs / $total) * 100 : 0; ?>%; background: linear-gradient(90deg, #dc3545, #e74c3c);"></div>
                                        </div>
                                        <span style="font-size: 1rem; color: #dc3545; font-weight: 700;"><?php echo $total > 0 ? round(($inactifs / $total) * 100) : 0; ?>% Inactifs</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `,
                    width: 700,
                    showCloseButton: true,
                    confirmButtonText: 'Fermer',
                    confirmButtonColor: '#2d4a8a',
                    backdrop: 'rgba(15, 26, 58, 0.5)'
                });
            });

            // Auto-format pour le téléphone
            // Auto-format pour le téléphone (format camerounais amélioré)
            $('#telephone').on('input', function(e) {
                let value = $(this).val().replace(/\D/g, '');

                if (value.length > 0) {
                    // Si le numéro commence par 237 (indicatif Cameroun)
                    if (value.startsWith('237')) {
                        // On garde +237 puis on formate le reste
                        value = '+237 ' + value.substring(3);
                        // Ajouter un espace tous les 2 chiffres après +237
                        if (value.length > 7) { // Après "+237 "
                            let rest = value.substring(6); // Tout après "+237 "
                            rest = rest.match(/.{1,2}/g).join(' ');
                            value = value.substring(0, 6) + rest;
                        }
                    }
                    // Si le numéro commence par 6 (mobile Cameroun sans indicatif)
                    else if (value.startsWith('6')) {
                        // On ajoute +237 et on formate
                        value = '+237 ' + value;
                        // Ajouter un espace tous les 2 chiffres
                        let rest = value.substring(6); // Tout après "+237 "
                        rest = rest.match(/.{1,2}/g).join(' ');
                        value = value.substring(0, 6) + rest;
                    }
                    // Pour les autres formats internationaux
                    else if (value.length > 3) {
                        // Formater par groupes de 2 ou 3 chiffres
                        if (value.length <= 10) {
                            value = value.match(/.{1,2}/g).join(' ');
                        } else {
                            value = value.match(/.{1,3}/g).join(' ');
                        }
                    }
                }

                $(this).val(value);
            });
        });
    </script>
</body>

</html>
<?php
if (isset($pdo)) {
    $pdo = null;
}
?>