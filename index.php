
<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// @include './fonctions/config.php';

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
        $conn = new mysqli('localhost', 'root', '', 'gestion_tontine');

        if ($conn->connect_error) {
            $errors[] = "Erreur de connexion à la base de données";
        } else {
            $sql = "SELECT * FROM membre WHERE telephone = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $telephone);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $membre = $result->fetch_assoc();
                $nom_complet = $membre['nom'] . ' ' . $membre['prenom'];
                $nom_simple = $membre['nom'];

                $usernameLower = strtolower($username);
                $nom_complet_lower = strtolower($nom_complet);
                $nom_simple_lower = strtolower($nom_simple);

                if ($usernameLower === $nom_complet_lower || $usernameLower === $nom_simple_lower) {
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

                    $stmt->close();
                    $conn->close();

                    header('Location: dashboard.php');
                    exit();
                } else {
                    $errors[] = "Identifiants incorrects";
                }
            } else {
                $errors[] = "Numéro de téléphone non trouvé";
            }

            if (isset($stmt)) {
                $stmt->close();
            }
            $conn->close();
        }
    }

    if (!empty($errors)) {
        $message = implode("<br>", $errors);
        $message_type = "error";
    }
}

// Récupérer quelques membres
$conn = new mysqli('localhost', 'root', '', 'gestion_tontine');
$demo_membres = [];
if (!$conn->connect_error) {
    $sql = "SELECT id_membre, nom, prenom, telephone, role FROM membre LIMIT 4";
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $demo_membres[] = [
                'id' => $row['id_membre'],
                'nom' => $row['nom'] . ' ' . $row['prenom'],
                'username' => $row['nom'],
                'telephone' => $row['telephone'],
                'role' => $row['role']
            ];
        }
    }
    $conn->close();
}

if (empty($demo_membres)) {
    $demo_membres = [
        [
            'id' => 1,
            'nom' => 'Kouam Dorinal',
            'username' => 'Kouam',
            'telephone' => '+237698179835',
            'role' => 'user'
        ],
        [
            'id' => 2,
            'nom' => 'Admin Système',
            'username' => 'Admin',
            'telephone' => '+237677123456',
            'role' => 'admin'
        ]
    ];
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
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen', 'Ubuntu', sans-serif;
            background: #0a0e27;
            min-height: 100vh;
            display: flex;
            position: relative;
            overflow-x: hidden;
        }

        /* Left Section - Login Form */
        .login-section {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            position: relative;
            z-index: 10;
        }

        .login-container {
            width: 100%;
            max-width: 440px;
        }

        .brand {
            margin-bottom: 48px;
        }

        .brand-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }

        .brand-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
        }

        .brand-name {
            font-size: 28px;
            font-weight: 700;
            color: #ffffff;
            letter-spacing: -0.5px;
        }

        .brand-tagline {
            color: #8b92a7;
            font-size: 15px;
            margin-left: 60px;
        }

        .form-header {
            margin-bottom: 32px;
        }

        .form-title {
            font-size: 32px;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .form-subtitle {
            color: #8b92a7;
            font-size: 15px;
        }

        .login-form {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-label {
            color: #c7cad9;
            font-size: 14px;
            font-weight: 500;
        }

        .input-wrapper {
            position: relative;
        }

        .form-input {
            width: 100%;
            padding: 14px 16px 14px 44px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            color: #ffffff;
            font-size: 15px;
            transition: all 0.3s ease;
        }

        .form-input:focus {
            outline: none;
            background: rgba(255, 255, 255, 0.08);
            border-color: #667eea;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
        }

        .form-input::placeholder {
            color: #6b7280;
        }

        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #8b92a7;
            font-size: 16px;
        }

        .input-hint {
            font-size: 13px;
            color: #6b7280;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .input-hint i {
            font-size: 12px;
        }

        .btn-login {
            padding: 16px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 12px;
            color: white;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 8px;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 24px rgba(102, 126, 234, 0.3);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .alert {
            padding: 14px 16px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
            margin-bottom: 24px;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #fca5a5;
        }

        /* Right Section - Info Panel */
        .info-section {
            flex: 1;
            background: linear-gradient(135deg, #1e3a8a 0%, #312e81 100%);
            padding: 60px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        .info-section::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="40" fill="none" stroke="rgba(255,255,255,0.03)" stroke-width="0.5"/></svg>');
            background-size: 80px 80px;
            opacity: 0.4;
        }

        .info-content {
            position: relative;
            z-index: 1;
            max-width: 500px;
            margin: 0 auto;
        }

        .info-title {
            font-size: 36px;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 16px;
            line-height: 1.2;
        }

        .info-description {
            color: rgba(255, 255, 255, 0.8);
            font-size: 16px;
            line-height: 1.6;
            margin-bottom: 40px;
        }

        .features-list {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        .feature-item {
            display: flex;
            gap: 20px;
            align-items: flex-start;
        }

        .feature-icon {
            width: 56px;
            height: 56px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .feature-icon i {
            font-size: 24px;
            color: rgba(255, 255, 255, 0.9);
        }

        .feature-content {
            flex: 1;
        }

        .feature-title {
            color: #ffffff;
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 6px;
        }

        .feature-description {
            color: rgba(255, 255, 255, 0.7);
            font-size: 14px;
            line-height: 1.5;
        }

        .demo-cards {
            display: grid;
            gap: 16px;
        }

        .demo-card-header {
            color: rgba(255, 255, 255, 0.9);
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .demo-card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 16px;
            padding: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .demo-card:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateX(8px);
        }

        .demo-card-name {
            color: #ffffff;
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .role-badge {
            font-size: 11px;
            padding: 4px 10px;
            border-radius: 20px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .role-admin {
            background: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
        }

        .role-user {
            background: rgba(34, 197, 94, 0.2);
            color: #86efac;
        }

        .role-superadmin {
            background: rgba(168, 85, 247, 0.2);
            color: #c084fc;
        }

        .demo-card-info {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .demo-card-row {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
        }

        .demo-label {
            color: rgba(255, 255, 255, 0.6);
        }

        .demo-value {
            color: rgba(255, 255, 255, 0.9);
            font-weight: 500;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            body {
                flex-direction: column;
            }

            .info-section {
                order: -1;
                padding: 40px 20px;
            }

            .info-title {
                font-size: 28px;
            }

            .demo-cards {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 640px) {
            .form-title {
                font-size: 28px;
            }

            .demo-cards {
                grid-template-columns: 1fr;
            }
        }

        /* Animations */
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

        .login-container {
            animation: fadeInUp 0.6s ease-out;
        }

        .info-content {
            animation: fadeInUp 0.6s ease-out 0.2s both;
        }
    </style>
</head>

<body>
    <!-- Left Section - Login -->
    <div class="login-section">
        <div class="login-container">
            <!-- Brand -->
            <div class="brand">
                <div class="brand-logo">
                    <div class="brand-icon">
                        <i class="fas fa-hand-holding-usd"></i>
                    </div>
                    <div class="brand-name">Tontine</div>
                </div>
                <div class="brand-tagline">Gestion simplifiée de votre tontine</div>
            </div>

            <!-- Form Header -->
            <div class="form-header">
                <h1 class="form-title">Se connecter</h1>
                <p class="form-subtitle">Entrez vos informations pour accéder à votre compte</p>
            </div>

            <!-- Alert -->
            <?php if ($message): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo $message; ?></span>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form method="POST" class="login-form" id="loginForm">
                <div class="form-group">
                    <label class="form-label">Nom d'utilisateur</label>
                    <div class="input-wrapper">
                        <i class="fas fa-user input-icon"></i>
                        <input type="text"
                            name="username"
                            id="username"
                            class="form-input"
                            placeholder="Votre nom ou nom complet"
                            value="<?php echo htmlspecialchars($username ?? ''); ?>"
                            required>
                    </div>
                    <div class="input-hint">
                        <i class="fas fa-info-circle"></i>
                        Ex: "Kouam" ou "Kouam Dorinal"
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Numéro de téléphone</label>
                    <div class="input-wrapper">
                        <i class="fas fa-phone input-icon"></i>
                        <input type="tel"
                            name="telephone"
                            id="telephone"
                            class="form-input"
                            placeholder="698179835"
                            value="<?php echo htmlspecialchars($telephone ?? ''); ?>"
                            required>
                    </div>
                    <div class="input-hint">
                        <i class="fas fa-info-circle"></i>
                        Formats: 698179835 (9 chiffres) ou 67712345 (8 chiffres)
                    </div>
                </div>

                <button type="submit" class="btn-login">
                    Se connecter
                    <i class="fas fa-arrow-right" style="margin-left: 8px;"></i>
                </button>
            </form>
        </div>
    </div>

    <!-- Right Section - Info -->
    <div class="info-section">
        <div class="info-content">
            <h2 class="info-title">Gérez votre tontine en toute simplicité</h2>
            <p class="info-description">
                Accédez à votre espace membre pour suivre vos cotisations, consulter l'historique 
                et gérer vos transactions en toute sécurité.
            </p>

            <div class="features-list">
                <div class="feature-item">
                    <div class="feature-icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <div class="feature-content">
                        <h3 class="feature-title">Sécurité renforcée</h3>
                        <p class="feature-description">Vos données sont protégées par un système d'authentification sécurisé</p>
                    </div>
                </div>

                <div class="feature-item">
                    <div class="feature-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="feature-content">
                        <h3 class="feature-title">Suivi en temps réel</h3>
                        <p class="feature-description">Consultez vos cotisations et l'évolution de votre épargne à tout moment</p>
                    </div>
                </div>

                <div class="feature-item">
                    <div class="feature-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="feature-content">
                        <h3 class="feature-title">Gestion collaborative</h3>
                        <p class="feature-description">Gérez votre tontine avec tous les membres en toute transparence</p>
                    </div>
                </div>

                <div class="feature-item">
                    <div class="feature-icon">
                        <i class="fas fa-mobile-alt"></i>
                    </div>
                    <div class="feature-content">
                        <h3 class="feature-title">Accessible partout</h3>
                        <p class="feature-description">Accédez à votre compte depuis n'importe quel appareil connecté</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script>
        $(document).ready(function() {
            // Auto-format téléphone
            $('#telephone').on('input', function() {
                let value = $(this).val().replace(/\D/g, '');
                if (value.length > 0 && value.length <= 9) {
                    value = value.replace(/(\d{3})(?=\d)/g, '$1 ');
                }
                $(this).val(value.trim());
            });

            // Reset border on input
            $('input').on('input', function() {
                $(this).css('border-color', 'rgba(255, 255, 255, 0.1)');
            });

            $('#username').focus();
        });
    </script>
</body>

</html>