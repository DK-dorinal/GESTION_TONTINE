<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Inclure le fichier de configuration PDO
require_once './fonctions/config.php';

// Récupérer les informations de l'admin avec le numéro 699887766
$admin_info = [];
try {
    // Utiliser PDO depuis config.php
    global $pdo;
    
    // Chercher l'admin avec le numéro spécifique 699887766
    $sql_admin = "SELECT nom, prenom, telephone, role FROM membre WHERE role = 'admin'";
    $stmt_admin = $pdo->prepare($sql_admin);
    $stmt_admin->execute();
    $admin_info = $stmt_admin->fetch(PDO::FETCH_ASSOC);
    
    // Si non trouvé, chercher par ID 1 ou rôle admin
    if (!$admin_info) {
        $sql_admin = "SELECT nom, prenom, telephone, role FROM membre WHERE role = 'admin' LIMIT 1";
        $stmt_admin = $pdo->prepare($sql_admin);
        $stmt_admin->execute();
        $admin_info = $stmt_admin->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // Ne pas afficher l'erreur pour ne pas perturber l'utilisateur
    $admin_info = [];
}

// // Préparer les données pour l'affichage
// $admin_nom_complet = "Admin Principal";
// $admin_telephone = "+237 699 887 766";

if ($admin_info) {
    $admin_nom_complet = htmlspecialchars($admin_info['nom'] . ' ' . $admin_info['prenom']);
    $admin_telephone = htmlspecialchars($admin_info['telephone']);
    
    // Formater le numéro de téléphone pour l'affichage
    $admin_telephone_formatted = preg_replace('/(\d{3})(\d{3})(\d{3})/', '$1 $2 $3', $admin_telephone);
    if (strpos($admin_telephone_formatted, '+237') === false && strpos($admin_telephone, '237') === 0) {
        $admin_telephone_formatted = '+237 ' . substr($admin_telephone_formatted, 3);
    } elseif (strpos($admin_telephone_formatted, '+237') === false && strlen($admin_telephone_formatted) == 9) {
        $admin_telephone_formatted = '+237 ' . $admin_telephone_formatted;
    }
}

// Si l'admin avec le numéro spécifique n'est pas trouvé, afficher les infos de test
if (!$admin_info) {
    $admin_nom_complet = "Admin Test";
    $admin_telephone_formatted = "+237 699 887 766";
}

// Rediriger si déjà connecté
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
}

// Fonctions utilitaires
function sanitizeInput($data)
{
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Fonction pour valider le format du téléphone camerounais
function isValidPhone($phone)
{
    $phone = preg_replace('/[\s\-]/', '', $phone);
    if (strlen($phone) == 9) {
        return preg_match('/^[62][0-9]{8}$/', $phone);
    }
    if (strlen($phone) == 8) {
        return preg_match('/^(67|65|69)[0-9]{6}$/', $phone);
    }
    return false;
}

// Fonction pour normaliser le numéro de téléphone camerounais
function normalizePhone($phone)
{
    $phone = preg_replace('/[^\d]/', '', $phone);
    if (strlen($phone) == 9 && in_array($phone[0], ['6', '2'])) {
        return '+237' . $phone;
    }
    if (strlen($phone) == 8 && in_array(substr($phone, 0, 2), ['67', '65', '69'])) {
        return '+237' . $phone;
    }
    if (strlen($phone) >= 12 && substr($phone, 0, 3) == '237') {
        return '+' . $phone;
    }
    return $phone;
}

// Traitement du formulaire
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitizeInput($_POST['username'] ?? '');
    $telephone = sanitizeInput($_POST['telephone'] ?? '');

    $errors = [];

    if (empty($username)) {
        $errors[] = "Le nom d'utilisateur est obligatoire";
    }

    if (empty($telephone)) {
        $errors[] = "Le numéro de téléphone est obligatoire";
    }

    if (!empty($telephone) && !isValidPhone($telephone)) {
        $errors[] = "Format de téléphone invalide";
    }

    if (empty($errors)) {
        $telephone = normalizePhone($telephone);
        
        try {
            // Utiliser PDO depuis config.php
            global $pdo;
            
            $sql = "SELECT * FROM membre WHERE telephone = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$telephone]);
            $membre = $stmt->fetch();

            if ($membre) {
                // Supprimer les espaces multiples et normaliser les espaces
                $username = preg_replace('/\s+/', ' ', $username);
                
                // Créer différentes combinaisons possibles du nom
                $nom_complet = $membre['nom'] . ' ' . $membre['prenom'];
                $nom_simple = $membre['nom'];
                $prenom_nom = $membre['prenom'] . ' ' . $membre['nom'];
                
                // Convertir en minuscules pour comparaison insensible à la casse
                $usernameLower = strtolower($username);
                $nom_complet_lower = strtolower($nom_complet);
                $nom_simple_lower = strtolower($nom_simple);
                $prenom_nom_lower = strtolower($prenom_nom);
                
                // Vérifier si le nom d'utilisateur correspond à l'une des combinaisons
                // (insensible à la casse et avec gestion des espaces)
                if ($usernameLower === $nom_complet_lower || 
                    $usernameLower === $nom_simple_lower || 
                    $usernameLower === $prenom_nom_lower) {
                    
                    $_SESSION['user_id'] = $membre['id_membre'];
                    $_SESSION['username'] = $membre['nom'];
                    $_SESSION['role'] = $membre['role'];
                    $_SESSION['nom'] = $nom_complet;
                    $_SESSION['telephone'] = $membre['telephone'];
                    $_SESSION['prenom'] = $membre['prenom'];
                    $_SESSION['adresse'] = $membre['adresse'];
                    $_SESSION['login_time'] = time();
                    $_SESSION['login_method'] = 'nom_telephone';
                    $_SESSION['welcome_message'] = "Bienvenue " . $nom_complet . " !";

                    header('Location: dashboard.php');
                    exit();
                } else {
                    // Tentative supplémentaire : vérifier sans les espaces
                    $usernameNoSpaces = str_replace(' ', '', $usernameLower);
                    $nom_complet_no_spaces = str_replace(' ', '', $nom_complet_lower);
                    $nom_simple_no_spaces = str_replace(' ', '', $nom_simple_lower);
                    $prenom_nom_no_spaces = str_replace(' ', '', $prenom_nom_lower);
                    
                    if ($usernameNoSpaces === $nom_complet_no_spaces || 
                        $usernameNoSpaces === $nom_simple_no_spaces || 
                        $usernameNoSpaces === $prenom_nom_no_spaces) {
                        
                        $_SESSION['user_id'] = $membre['id_membre'];
                        $_SESSION['username'] = $membre['nom'];
                        $_SESSION['role'] = $membre['role'];
                        $_SESSION['nom'] = $nom_complet;
                        $_SESSION['telephone'] = $membre['telephone'];
                        $_SESSION['prenom'] = $membre['prenom'];
                        $_SESSION['adresse'] = $membre['adresse'];
                        $_SESSION['login_time'] = time();
                        $_SESSION['login_method'] = 'nom_telephone';
                        $_SESSION['welcome_message'] = "Bienvenue " . $nom_complet . " !";

                        header('Location: dashboard.php');
                        exit();
                    } else {
                        $errors[] = "Identifiants incorrects";
                    }
                }
            } else {
                $errors[] = "Numéro de téléphone non trouvé";
            }
        } catch (PDOException $e) {
            $errors[] = "Erreur de connexion à la base de données: " . $e->getMessage();
        }
    }

    if (!empty($errors)) {
        $message = implode("<br>", $errors);
        $message_type = "error";
    }
}
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - Gestion Tontine</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen', 'Ubuntu', sans-serif;
            background: linear-gradient(135deg, var(--navy-blue) 0%, var(--dark-blue) 100%);
            min-height: 100vh;
            display: flex;
            position: relative;
            overflow-x: hidden;
            color: var(--text-dark);
        }

        /* Left Section - Login Form */
        .login-section {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: clamp(20px, 5vw, 60px) clamp(20px, 4vw, 40px);
            position: relative;
            z-index: 10;
            min-height: 100vh;
        }

        .login-container {
            width: 100%;
            max-width: 480px;
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border-radius: var(--border-radius);
            padding: clamp(30px, 5vw, 50px);
            box-shadow: 0 20px 40px var(--shadow-medium),
                        0 0 0 1px rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .brand {
            margin-bottom: clamp(30px, 6vw, 48px);
            text-align: center;
        }

        .brand-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            margin-bottom: 16px;
        }

        .brand-icon {
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, var(--accent-gold) 0%, var(--accent-light) 100%);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--pure-white);
            font-size: 28px;
            box-shadow: 0 8px 20px rgba(212, 175, 55, 0.3);
        }

        .brand-name {
            font-size: clamp(28px, 4vw, 36px);
            font-weight: 800;
            background: linear-gradient(135deg, var(--navy-blue) 0%, var(--medium-blue) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -0.5px;
        }

        .brand-tagline {
            color: var(--text-light);
            font-size: clamp(14px, 2vw, 16px);
            margin-top: 8px;
        }

        .form-header {
            margin-bottom: clamp(24px, 5vw, 40px);
            text-align: center;
        }

        .form-title {
            font-size: clamp(28px, 4vw, 36px);
            font-weight: 700;
            color: var(--navy-blue);
            margin-bottom: 12px;
            letter-spacing: -0.5px;
            line-height: 1.2;
        }

        .form-subtitle {
            color: var(--text-light);
            font-size: clamp(14px, 2vw, 16px);
            line-height: 1.5;
        }

        .login-form {
            display: flex;
            flex-direction: column;
            gap: clamp(20px, 3vw, 28px);
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .form-label {
            color: var(--navy-blue);
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .form-label i {
            color: var(--accent-gold);
            font-size: 12px;
        }

        .input-wrapper {
            position: relative;
        }

        .form-input {
            width: 100%;
            padding: 18px 20px 18px 52px;
            background: var(--pure-white);
            border: 2px solid rgba(26, 43, 85, 0.1);
            border-radius: 12px;
            color: var(--navy-blue);
            font-size: 16px;
            transition: var(--transition);
            font-weight: 500;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--accent-gold);
            box-shadow: 0 0 0 4px rgba(212, 175, 55, 0.1);
            transform: translateY(-1px);
        }

        .form-input::placeholder {
            color: var(--text-light);
            opacity: 0.7;
        }

        .input-icon {
            position: absolute;
            left: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--accent-gold);
            font-size: 18px;
        }

        .input-hint {
            font-size: 13px;
            color: var(--text-light);
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: rgba(248, 250, 252, 0.8);
            border-radius: 8px;
            margin-top: 4px;
        }

        .input-hint i {
            color: var(--accent-gold);
            font-size: 14px;
        }

        .btn-login {
            padding: 18px 24px;
            background: linear-gradient(135deg, var(--accent-gold) 0%, var(--accent-light) 100%);
            border: none;
            border-radius: 12px;
            color: var(--pure-white);
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition);
            margin-top: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            letter-spacing: 0.5px;
        }

        .btn-login:hover {
            transform: translateY(-3px);
            box-shadow: 0 16px 32px rgba(212, 175, 55, 0.25);
        }

        .btn-login:active {
            transform: translateY(-1px);
        }

        .btn-login:focus {
            outline: none;
            box-shadow: 0 0 0 4px rgba(212, 175, 55, 0.2);
        }

        .alert {
            padding: 18px 20px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 14px;
            font-size: 15px;
            margin-bottom: 28px;
            background: rgba(239, 68, 68, 0.08);
            border: 2px solid rgba(239, 68, 68, 0.2);
            color: #dc2626;
        }

        .alert i {
            font-size: 20px;
            flex-shrink: 0;
        }

        /* Right Section - Info Panel */
        .info-section {
            flex: 1;
            background: linear-gradient(135deg, var(--medium-blue) 0%, var(--light-blue) 100%);
            padding: clamp(30px, 5vw, 60px) clamp(20px, 4vw, 40px);
            display: flex;
            align-items: center;
            position: relative;
            overflow: hidden;
            min-height: 100vh;
        }

        .info-section::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 80%, rgba(255, 255, 255, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(255, 255, 255, 0.05) 0%, transparent 50%);
            pointer-events: none;
        }

        .info-content {
            position: relative;
            z-index: 1;
            max-width: 600px;
            margin: 0 auto;
            width: 100%;
        }

        .info-title {
            font-size: clamp(32px, 5vw, 48px);
            font-weight: 800;
            color: var(--pure-white);
            margin-bottom: 24px;
            line-height: 1.1;
            letter-spacing: -0.5px;
        }

        .info-description {
            color: rgba(255, 255, 255, 0.9);
            font-size: clamp(16px, 2vw, 18px);
            line-height: 1.7;
            margin-bottom: clamp(40px, 6vw, 60px);
            font-weight: 300;
        }

        .features-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 28px;
        }

        .feature-item {
            display: flex;
            gap: 20px;
            align-items: flex-start;
            padding: 24px;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: var(--border-radius);
            border: 1px solid rgba(255, 255, 255, 0.15);
            transition: var(--transition);
            flex-direction: column;
            align-items: center;
        }

        .feature-item:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateY(-5px);
            border-color: rgba(255, 255, 255, 0.3);
        }

        .feature-icon {
            width: 60px;
            height: 60px;
            background: rgba(255, 255, 255, 0.15);
            border: 2px solid rgba(255, 255, 255, 0.25);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: var(--transition);
        }

        .feature-item:hover .feature-icon {
            background: var(--accent-gold);
            border-color: var(--accent-gold);
            transform: scale(1.1);
        }

        .feature-icon i {
            font-size: 26px;
            color: var(--pure-white);
        }

        .feature-content {
            flex: 1;

        }

        .feature-title {
            color: var(--pure-white);
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 10px;
            text-align: center;
        }

        .feature-description {
            color: rgba(255, 255, 255, 0.8);
            font-size: 15px;
            line-height: 1.6;
            text-align: justify;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            body {
                flex-direction: column;
            }

            .login-section {
                order: 2;
                min-height: auto;
                padding: 40px 20px;
            }

            .login-container {
                max-width: 600px;
                margin: 0 auto;
            }

            .info-section {
                order: 1;
                min-height: auto;
                padding: 60px 20px;
            }

            .info-content {
                max-width: 800px;
            }

            .features-list {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .login-container {
                padding: 30px 24px;
            }

            .info-title {
                font-size: 36px;
            }

            .features-list {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .feature-item {
                padding: 20px;
            }

            .brand-logo {
                flex-direction: column;
                gap: 12px;
            }

            .brand-name {
                text-align: center;
            }
        }

        @media (max-width: 480px) {
            .login-container {
                padding: 24px 20px;
            }

            .form-input {
                padding: 16px 16px 16px 48px;
            }

            .input-icon {
                left: 16px;
            }

            .btn-login {
                padding: 16px 20px;
            }

            .feature-item {
                flex-direction: column;
                text-align: center;
                align-items: center;
            }

            .feature-icon {
                width: 56px;
                height: 56px;
            }

            .feature-icon i {
                font-size: 24px;
            }

            .info-title {
                font-size: 28px;
            }

            .info-description {
                font-size: 16px;
            }
        }

        @media (max-width: 360px) {
            .login-container {
                padding: 20px 16px;
            }

            .form-input {
                padding: 14px 14px 14px 44px;
                font-size: 15px;
            }

            .btn-login {
                padding: 14px 16px;
                font-size: 15px;
            }
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

        .login-container {
            animation: fadeInUp 0.6s ease-out;
        }

        .info-content {
            animation: fadeInUp 0.6s ease-out 0.2s both;
        }

        @keyframes float {
            0%, 100% {
                transform: translateY(0);
            }
            50% {
                transform: translateY(-10px);
            }
        }

        .feature-item:hover .feature-icon {
            animation: float 0.6s ease-in-out;
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 10px;
        }

        ::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
        }

        ::-webkit-scrollbar-thumb {
            background: var(--accent-gold);
            border-radius: 5px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--accent-light);
        }

        /* Floating Admin Info */
        .floating-admin {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1000;
            transition: var(--transition);
        }

        .admin-toggle {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--accent-gold) 0%, var(--accent-light) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 8px 25px rgba(212, 175, 55, 0.4);
            border: 3px solid var(--pure-white);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .admin-toggle:hover {
            transform: scale(1.1);
            box-shadow: 0 12px 30px rgba(212, 175, 55, 0.6);
        }

        .admin-toggle i {
            color: var(--pure-white);
            font-size: 24px;
            transition: var(--transition);
        }

        .admin-toggle:hover i {
            transform: rotate(15deg);
        }

        .admin-toggle::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle, rgba(255,255,255,0.3) 1%, transparent 1%);
            transform: translate(-50%, -50%) scale(0);
            opacity: 0;
            transition: transform 0.5s, opacity 0.5s;
        }

        .admin-toggle:active::before {
            transform: translate(-50%, -50%) scale(10);
            opacity: 0.3;
            transition: 0s;
        }

        .admin-info-panel {
            position: absolute;
            bottom: 70px;
            right: 0;
            width: 320px;
            background: var(--pure-white);
            border-radius: var(--border-radius);
            box-shadow: 0 20px 40px var(--shadow-medium),
                        0 0 0 1px rgba(15, 26, 58, 0.1);
            padding: 0;
            opacity: 0;
            visibility: hidden;
            transform: translateY(20px);
            transition: var(--transition);
            overflow: hidden;
        }

        @media (max-width : 1024px) {
            .admin-info-panel{
                width: 290px
            }
        }
        .floating-admin:hover .admin-info-panel {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .admin-header {
            background: linear-gradient(135deg, var(--navy-blue) 0%, var(--dark-blue) 100%);
            padding: 25px;
            color: var(--pure-white);
            text-align: center;
            position: relative;
        }

        .admin-header::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 0;
            border-left: 10px solid transparent;
            border-right: 10px solid transparent;
            border-top: 10px solid var(--navy-blue);
        }

        .admin-avatar {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--accent-gold) 0%, var(--accent-light) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            border: 4px solid rgba(255, 255, 255, 0.3);
            font-size: 32px;
            color: var(--pure-white);
        }

        .admin-title {
            font-size: 14px;
            opacity: 0.8;
            margin-bottom: 5px;
            font-weight: 500;
        }

        .admin-name {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .admin-role {
            display: inline-block;
            background: rgba(212, 175, 55, 0.2);
            color: var(--accent-gold);
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.5px;
            border: 1px solid rgba(212, 175, 55, 0.3);
        }

        .admin-details {
            padding: 25px;
        }

        .admin-info-group {
            margin-bottom: 20px;
        }

        .admin-info-group:last-child {
            margin-bottom: 0;
        }

        .admin-label {
            font-size: 12px;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
        }

        .admin-label i {
            color: var(--accent-gold);
            font-size: 11px;
        }

        .admin-value {
            font-size: 15px;
            color: var(--navy-blue);
            font-weight: 500;
            padding: 10px 15px;
            background: var(--bg-light);
            border-radius: 8px;
            border: 1px solid rgba(15, 26, 58, 0.1);
            word-break: break-all;
        }

        .admin-note {
            background: linear-gradient(135deg, rgba(212, 175, 55, 0.1) 0%, rgba(230, 195, 77, 0.1) 100%);
            border: 1px solid rgba(212, 175, 55, 0.2);
            border-radius: 8px;
            padding: 15px;
            margin-top: 20px;
            font-size: 13px;
            color: var(--text-dark);
            line-height: 1.5;
            position: relative;
        }

        .admin-note::before {
            content: '💡';
            position: absolute;
            top: -10px;
            left: 15px;
            background: var(--pure-white);
            padding: 0 5px;
            font-size: 14px;
        }
    </style>
</head>

<body>
    <!-- Floating Admin Info -->
    <div class="floating-admin">
        <div class="admin-toggle">
            <i class="fas fa-user-shield"></i>
        </div>
        <div class="admin-info-panel">
            <div class="admin-header">
                <div class="admin-avatar">
                    <i class="fas fa-crown"></i>
                </div>
                <div class="admin-title">Super Administrateur</div>
                <div class="admin-name"><?php echo $admin_nom_complet; ?></div>
                <div class="admin-role">SUPER ADMIN</div>
            </div>
            <div class="admin-details">
                <div class="admin-info-group">
                    <div class="admin-label">
                        <i class="fas fa-id-card"></i>
                        ID ADMIN
                    </div>
                    <div class="admin-value"><?php echo $admin_nom_complet ?></div>
                </div>
                
                <div class="admin-info-group">
                    <div class="admin-label">
                        <i class="fas fa-phone-alt"></i>
                        TÉLÉPHONE
                    </div>
                    <div class="admin-value">
                        <?php 
                        if (isset($admin_telephone_formatted)) {
                            echo $admin_telephone_formatted;
                        }
                        ?>
                    </div>
                </div>
                
                <div class="admin-info-group">
                    <div class="admin-label">
                        <i class="fas fa-key"></i>
                        ID MEMBRE
                    </div>
                </div>
                
                <div class="admin-note">
                    Compte de test pour l'application. Numéro: 699887766
                </div>
            </div>
        </div>
    </div>
    <!-- Left Section - Login -->
    <div class="login-section">
        <div class="login-container">
            <!-- Brand -->
            <div class="brand">
                <div class="brand-logo">
                    <div class="brand-icon">
                        <i class="fas fa-hand-holding-usd"></i>
                    </div>
                    <div class="brand-name">TontinePro</div>
                </div>
                <div class="brand-tagline">La solution professionnelle pour gérer votre tontine</div>
            </div>

            <!-- Form Header -->
            <div class="form-header">
                <h1 class="form-title">Connexion</h1>
                <p class="form-subtitle">Entrez vos identifiants pour accéder à votre espace membre</p>
            </div>

            <!-- Alert -->
            <?php if ($message): ?>
                <div class="alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo $message; ?></span>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form method="POST" class="login-form" id="loginForm">
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-user-circle"></i>
                        Nom d'utilisateur
                    </label>
                    <div class="input-wrapper">
                        <i class="fas fa-user input-icon"></i>
                        <input type="text"
                            name="username"
                            id="username"
                            class="form-input"
                            placeholder="Votre nom ou nom complet"
                            value="<?php echo htmlspecialchars($username ?? ''); ?>"
                            required
                            autocomplete="username">
                    </div>
                    <div class="input-hint">
                        <i class="fas fa-lightbulb"></i>
                        Utilisez votre nom de famille ou votre nom complet
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-phone-alt"></i>
                        Numéro de téléphone
                    </label>
                    <div class="input-wrapper">
                        <i class="fas fa-mobile-alt input-icon"></i>
                        <input type="tel"
                            name="telephone"
                            id="telephone"
                            class="form-input"
                            placeholder="Ex: 699887766"
                            value="<?php echo htmlspecialchars($telephone ?? ''); ?>"
                            required
                            autocomplete="tel">
                    </div>
                    <div class="input-hint">
                        <i class="fas fa-info-circle"></i>
                        Numéro de test: 699887766
                    </div>
                </div>

                <button type="submit" class="btn-login">
                    Se connecter
                    <i class="fas fa-arrow-right"></i>
                </button>
            </form>
        </div>
    </div>

    <!-- Right Section - Info -->
    <div class="info-section">
        <div class="info-content">
            <h2 class="info-title">Simplifiez la gestion de votre tontine</h2>
            <p class="info-description">
                Une plateforme complète pour gérer vos cotisations, suivre les paiements et 
                maintenir la transparence entre tous les membres de votre groupe.
            </p>

            <div class="features-list">
                <div class="feature-item">
                    <div class="feature-icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <div class="feature-content">
                        <h3 class="feature-title">Sécurité maximale</h3>
                        <p class="feature-description">Authentification à deux facteurs et chiffrement des données pour une protection optimale</p>
                    </div>
                </div>

                <div class="feature-item">
                    <div class="feature-icon">
                        <i class="fas fa-chart-pie"></i>
                    </div>
                    <div class="feature-content">
                        <h3 class="feature-title">Tableau de bord complet</h3>
                        <p class="feature-description">Visualisez vos finances avec des graphiques clairs et des rapports détaillés</p>
                    </div>
                </div>

                <div class="feature-item">
                    <div class="feature-icon">
                        <i class="fas fa-bell"></i>
                    </div>
                    <div class="feature-content">
                        <h3 class="feature-title">Notifications intelligentes</h3>
                        <p class="feature-description">Recevez des alertes pour les échéances de paiement et les activités importantes</p>
                    </div>
                </div>

                <div class="feature-item">
                    <div class="feature-icon">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                    <div class="feature-content">
                        <h3 class="feature-title">Gestion automatisée</h3>
                        <p class="feature-description">Générez automatiquement les rapports et suivez l'historique des transactions</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script>
        $(document).ready(function() {
            // Animation au focus
            $('.form-input').on('focus', function() {
                $(this).parent().css('transform', 'translateY(-2px)');
            }).on('blur', function() {
                $(this).parent().css('transform', 'translateY(0)');
            });

            // Focus sur le premier champ
            setTimeout(() => {
                $('#username').focus();
            }, 300);

            // Effet de validation
            $('#loginForm').on('submit', function(e) {
                const inputs = $('.form-input');
                let isValid = true;
                
                inputs.each(function() {
                    if (!$(this).val().trim()) {
                        $(this).css({
                            'border-color': '#ef4444',
                            'box-shadow': '0 0 0 4px rgba(239, 68, 68, 0.1)'
                        });
                        isValid = false;
                    }
                });
                
                if (!isValid) {
                    e.preventDefault();
                    $('html, body').animate({
                        scrollTop: $('.alert').offset().top - 100
                    }, 500);
                }
            });

            // Reset validation style on input
            $('.form-input').on('input', function() {
                $(this).css({
                    'border-color': 'rgba(26, 43, 85, 0.1)',
                    'box-shadow': 'none'
                });
            });

            // Admin info panel hover effects
            $('.floating-admin').hover(
                function() {
                    $(this).find('.admin-info-panel').css({
                        'opacity': '1',
                        'visibility': 'visible',
                        'transform': 'translateY(0)'
                    });
                    $(this).find('.admin-toggle').css('transform', 'scale(1.1)');
                },
                function() {
                    $(this).find('.admin-info-panel').css({
                        'opacity': '0',
                        'visibility': 'hidden',
                        'transform': 'translateY(20px)'
                    });
                    $(this).find('.admin-toggle').css('transform', 'scale(1)');
                }
            );

            // Click to toggle (mobile friendly)
            $('.admin-toggle').on('click', function(e) {
                e.stopPropagation();
                const panel = $(this).siblings('.admin-info-panel');
                const isVisible = panel.css('opacity') === '1';
                
                if (isVisible) {
                    panel.css({
                        'opacity': '0',
                        'visibility': 'hidden',
                        'transform': 'translateY(20px)'
                    });
                } else {
                    panel.css({
                        'opacity': '1',
                        'visibility': 'visible',
                        'transform': 'translateY(0)'
                    });
                }
            });

            // Close panel when clicking outside
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.floating-admin').length) {
                    $('.admin-info-panel').css({
                        'opacity': '0',
                        'visibility': 'hidden',
                        'transform': 'translateY(20px)'
                    });
                }
            });
        });
    </script>
</body>

</html>