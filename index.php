<?php
session_start();
@include './fonctions/config.php';

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
    // Supprime tous les espaces et tirets
    $phone = preg_replace('/[\s\-]/', '', $phone);

    // Pour 9 chiffres (commence par 6 ou 2)
    if (strlen($phone) == 9) {
        return preg_match('/^[62][0-9]{8}$/', $phone);
    }

    // Pour 8 chiffres (commence par 67, 65 ou 69)
    if (strlen($phone) == 8) {
        return preg_match('/^(67|65|69)[0-9]{6}$/', $phone);
    }

    return false;
}

// Fonction pour normaliser le numéro de téléphone camerounais
function normalizePhone($phone)
{
    // Supprime tous les caractères non numériques
    $phone = preg_replace('/[^\d]/', '', $phone);

    // Si le numéro a 9 chiffres et commence par 6 ou 2, ajoute +237
    if (strlen($phone) == 9 && in_array($phone[0], ['6', '2'])) {
        return '+237' . $phone;
    }

    // Si le numéro a 8 chiffres et commence par 67, 65 ou 69, ajoute +237
    if (strlen($phone) == 8 && in_array(substr($phone, 0, 2), ['67', '65', '69'])) {
        return '+237' . $phone;
    }

    // Si déjà avec l'indicatif +237, le retourner tel quel
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

    // Validation du format du téléphone camerounais
    if (!empty($telephone) && !isValidPhone($telephone)) {
        $errors[] = "Format de téléphone invalide. Formats acceptés pour le Cameroun :<br>
                    • 677123456 (9 chiffres)<br>
                    • 699987654 (9 chiffres)<br>
                    • 67712345 (8 chiffres)<br>
                    • 65523456 (8 chiffres)<br>
                    • 69012345 (8 chiffres)";
    }

    if (empty($errors)) {
        // Normaliser le téléphone
        $telephone = normalizePhone($telephone);

        // Connexion à la base de données
        $conn = new mysqli('localhost', 'root', '', 'gestion_tontine');

        if ($conn->connect_error) {
            $errors[] = "Erreur de connexion à la base de données";
        } else {
            // Rechercher le membre dans la base de données
            // On vérifie soit par nom seul, soit par combinaison nom + téléphone
            $sql = "SELECT * FROM membre WHERE telephone = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $telephone);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $membre = $result->fetch_assoc();

                // Vérifier si le nom correspond
                // On accepte soit le nom seul, soit nom+prenom
                $nom_complet = $membre['nom'] . ' ' . $membre['prenom'];
                $nom_simple = $membre['nom'];

                // Comparaison insensible à la casse
                $usernameLower = strtolower($username);
                $nom_complet_lower = strtolower($nom_complet);
                $nom_simple_lower = strtolower($nom_simple);

                if ($usernameLower === $nom_complet_lower || $usernameLower === $nom_simple_lower) {
                    // Authentification réussie
                    $_SESSION['user_id'] = $membre['id_membre'];
                    $_SESSION['username'] = $membre['nom'];
                    $_SESSION['role'] = $membre['role'];
                    $_SESSION['nom'] = $nom_complet;
                    $_SESSION['telephone'] = $membre['telephone'];
                    $_SESSION['prenom'] = $membre['prenom'];
                    $_SESSION['adresse'] = $membre['adresse'];
                    $_SESSION['login_time'] = time();
                    $_SESSION['login_method'] = 'nom_telephone';

                    // Message de bienvenue
                    $_SESSION['welcome_message'] = "Bienvenue " . $nom_complet . " !";

                    // Fermer la connexion
                    $stmt->close();
                    $conn->close();

                    // Rediriger vers la page d'accueil
                    header('Location: dashboard.php');
                    exit();
                } else {
                    $errors[] = "Nom d'utilisateur incorrect. Essayez avec: '" . $nom_simple . "' ou '" . $nom_complet . "'";
                }
            } else {
                // Essayer aussi sans le préfixe +237
                $telephone_sans_prefixe = str_replace('+237', '', $telephone);
                if (!empty($telephone_sans_prefixe) && $telephone_sans_prefixe !== $telephone) {
                    $sql2 = "SELECT * FROM membre WHERE telephone LIKE ?";
                    $stmt2 = $conn->prepare($sql2);
                    $search_phone = '%' . $telephone_sans_prefixe . '%';
                    $stmt2->bind_param("s", $search_phone);
                    $stmt2->execute();
                    $result2 = $stmt2->get_result();

                    if ($result2->num_rows > 0) {
                        $membre = $result2->fetch_assoc();

                        $nom_complet = $membre['nom'] . ' ' . $membre['prenom'];
                        $nom_simple = $membre['nom'];

                        $usernameLower = strtolower($username);
                        $nom_complet_lower = strtolower($nom_complet);
                        $nom_simple_lower = strtolower($nom_simple);

                        if ($usernameLower === $nom_complet_lower || $usernameLower === $nom_simple_lower) {
                            // Authentification réussie
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

                            $stmt2->close();
                            $conn->close();

                            header('Location: membre.php');
                            exit();
                        } else {
                            $errors[] = "Nom d'utilisateur incorrect. Essayez avec: '" . $nom_simple . "' ou '" . $nom_complet . "'";
                        }
                    } else {
                        $errors[] = "Numéro de téléphone non trouvé dans la base de données";
                    }
                    $stmt2->close();
                } else {
                    $errors[] = "Numéro de téléphone non trouvé dans la base de données";
                }
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

// Récupérer quelques membres pour afficher dans les exemples
$conn = new mysqli('localhost', 'root', '', 'gestion_tontine');
$demo_membres = [];
if (!$conn->connect_error) {
    $sql = "SELECT id_membre, nom, prenom, telephone, role FROM membre LIMIT 5";
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $demo_membres[] = [
                'id' => $row['id_membre'],
                'nom' => $row['nom'] . ' ' . $row['prenom'],
                'username' => $row['nom'], // Utiliser le nom comme username
                'telephone' => $row['telephone'],
                'role' => $row['role']
            ];
        }
    }
    $conn->close();
}

// Si pas de membres dans la base, utiliser des exemples fictifs
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
    <title>Connexion | Système de Gestion de Tontine</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
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
            font-family: 'Poppins', 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #0f1a3a 0%, #1a2b55 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }

        body::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100" height="100" fill="none"/><path d="M0,0 L100,0 L100,100" stroke="rgba(255,255,255,0.03)" stroke-width="1"/></svg>');
            background-size: 50px 50px;
            opacity: 0.3;
            animation: gridMove 20s linear infinite;
        }

        @keyframes gridMove {
            0% {
                background-position: 0 0;
            }

            100% {
                background-position: 50px 50px;
            }
        }

        .login-container {
            width: 100%;
            max-width: 450px;
            z-index: 1;
        }

        .login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: var(--border-radius);
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.2);
            animation: slideUp 0.6s ease-out;
            position: relative;
            overflow: hidden;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .login-card::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--accent-gold) 0%, var(--light-blue) 50%, var(--accent-gold) 100%);
            animation: shimmer 3s infinite linear;
        }

        @keyframes shimmer {
            0% {
                background-position: -200px 0;
            }

            100% {
                background-position: 200px 0;
            }
        }

        .logo-section {
            text-align: center;
            margin-bottom: 30px;
        }

        .logo-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--accent-gold) 0%, var(--light-blue) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            color: var(--pure-white);
            font-size: 2rem;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
        }

        .logo-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--navy-blue);
            margin-bottom: 5px;
        }

        .logo-subtitle {
            color: var(--text-light);
            font-size: 0.9rem;
            font-weight: 500;
        }

        .login-info {
            background: linear-gradient(135deg, rgba(212, 175, 55, 0.1) 0%, rgba(58, 95, 192, 0.1) 100%);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 25px;
            border-left: 4px solid var(--accent-gold);
        }

        .login-info p {
            color: var(--text-dark);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .login-info i {
            color: var(--accent-gold);
        }

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

        .input-with-icon {
            position: relative;
        }

        .input-with-icon i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-light);
            z-index: 1;
        }

        .form-control {
            width: 100%;
            padding: 14px 15px 14px 45px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-family: "Poppins", sans-serif;
            font-size: 0.95rem;
            transition: var(--transition);
            background: var(--pure-white);
            color: var(--text-dark);
        }

        .form-control:focus {
            border-color: var(--light-blue);
            box-shadow: 0 0 0 3px rgba(58, 95, 192, 0.2);
            outline: none;
        }

        .input-hint {
            font-size: 0.8rem;
            color: var(--text-light);
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .btn-login {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, var(--medium-blue) 0%, var(--light-blue) 100%);
            color: var(--pure-white);
            border: none;
            border-radius: 8px;
            font-family: "Poppins", sans-serif;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-login:hover {
            background: linear-gradient(135deg, var(--dark-blue) 0%, var(--medium-blue) 100%);
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(45, 74, 138, 0.3);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: fadeIn 0.5s;
        }

        .message i {
            font-size: 1.2rem;
        }

        .message.error {
            background: rgba(239, 68, 68, 0.1);
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }

        .message.success {
            background: rgba(34, 197, 94, 0.1);
            color: #166534;
            border-left: 4px solid #22c55e;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .login-footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
            color: var(--text-light);
            font-size: 0.85rem;
        }

        .login-footer a {
            color: var(--medium-blue);
            text-decoration: none;
            font-weight: 500;
        }

        .login-footer a:hover {
            text-decoration: underline;
        }

        .demo-credentials {
            background: rgba(248, 250, 252, 0.8);
            border-radius: 8px;
            padding: 15px;
            margin-top: 25px;
            font-size: 0.85rem;
        }

        .demo-credentials h4 {
            color: var(--navy-blue);
            margin-bottom: 15px;
            font-size: 0.9rem;
            text-align: center;
        }

        .credentials-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
        }

        .credential-item {
            background: var(--pure-white);
            padding: 12px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            cursor: pointer;
            transition: var(--transition);
        }

        .credential-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            border-color: var(--light-blue);
        }

        .credential-name {
            font-weight: 600;
            color: var(--navy-blue);
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .credential-name i {
            color: var(--accent-gold);
        }

        .credential-details {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .credential-detail {
            display: flex;
            justify-content: space-between;
        }

        .detail-label {
            color: var(--text-light);
            font-size: 0.8rem;
        }

        .detail-value {
            font-weight: 500;
            color: var(--text-dark);
            font-size: 0.85rem;
        }

        /* Floating particles animation */
        .particles {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            pointer-events: none;
        }

        .particle {
            position: absolute;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            animation: float 15s infinite linear;
        }

        @keyframes float {
            0% {
                transform: translateY(100vh) translateX(0) rotate(0deg);
            }

            100% {
                transform: translateY(-100px) translateX(100px) rotate(360deg);
            }
        }

        /* Responsive */
        @media (max-width: 480px) {
            .login-card {
                padding: 30px 20px;
            }

            .logo-icon {
                width: 70px;
                height: 70px;
                font-size: 1.8rem;
            }

            .logo-title {
                font-size: 1.6rem;
            }

            .credentials-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (min-width: 768px) {
            .credentials-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>

<body>
    <!-- Floating particles -->
    <div class="particles">
        <?php for ($i = 0; $i < 20; $i++): ?>
            <div class="particle" style="
                width: <?php echo rand(2, 10); ?>px;
                height: <?php echo rand(2, 10); ?>px;
                left: <?php echo rand(0, 100); ?>%;
                animation-delay: <?php echo rand(0, 15); ?>s;
                animation-duration: <?php echo rand(10, 20); ?>s;">
            </div>
        <?php endfor; ?>
    </div>

    <div class="login-container">
        <div class="login-card">
            <!-- Logo & Title -->
            <div class="logo-section">
                <div class="logo-icon">
                    <i class="fas fa-hand-holding-usd"></i>
                </div>
                <h1 class="logo-title">Gestion Tontine</h1>
                <p class="logo-subtitle">Connexion sécurisée par nom et téléphone</p>
            </div>

            <!-- Information -->
            <div class="login-info">
                <p>
                    <i class="fas fa-info-circle"></i>
                    Connectez-vous avec votre nom d'utilisateur et votre numéro de téléphone camerounais.<br>
                    <small><strong>Astuce :</strong> Utilisez soit "Nom" seul, soit "Nom Prénom"</small>
                </p>
            </div>

            <!-- Messages -->
            <?php if ($message): ?>
                <div class="message <?php echo $message_type; ?>">
                    <i class="fas fa-<?php echo $message_type === 'error' ? 'exclamation-circle' : 'check-circle'; ?>"></i>
                    <span><?php echo $message; ?></span>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form method="POST" action="" id="loginForm">
                <div class="form-group">
                    <label for="username">Nom d'utilisateur</label>
                    <div class="input-with-icon">
                        <i class="fas fa-user"></i>
                        <input type="text"
                            id="username"
                            name="username"
                            class="form-control"
                            required
                            placeholder="Entrez votre nom (ex: Kouam ou Kouam Dorinal)"
                            value="<?php echo htmlspecialchars($username ?? ''); ?>"
                            autocomplete="username">
                    </div>
                    <div class="input-hint">
                        <i class="fas fa-lightbulb"></i>
                        Utilisez votre nom de famille seul (ex: "Kouam") ou nom complet (ex: "Kouam Dorinal")
                    </div>
                </div>

                <div class="form-group">
                    <label for="telephone">Numéro de téléphone</label>
                    <div class="input-with-icon">
                        <i class="fas fa-phone"></i>
                        <input type="tel"
                            id="telephone"
                            name="telephone"
                            class="form-control"
                            required
                            placeholder="Ex: 698179835 ou 677123456"
                            value="<?php echo htmlspecialchars($telephone ?? ''); ?>"
                            autocomplete="tel">
                    </div>
                    <div class="input-hint">
                        <i class="fas fa-info-circle"></i>
                        Formats camerounais acceptés: 698179835 (9 chiffres) ou 67712345 (8 chiffres)
                    </div>
                </div>

                <button type="submit" class="btn-login">
                    <i class="fas fa-sign-in-alt"></i>
                    Se connecter
                </button>
            </form>

            <!-- Demo Credentials -->
            <div class="demo-credentials">
                <h4>Membres disponibles :</h4>
                <div class="credentials-grid">
                    <?php foreach ($demo_membres as $user): ?>
                        <div class="credential-item">
                            <div class="credential-name">
                                <i class="fas fa-user"></i>
                                <?php echo htmlspecialchars($user['nom']); ?>
                                <span style="margin-left: auto; font-size: 0.7rem; background: <?php
                                                                                                echo $user['role'] === 'admin' ? '#ef4444' : ($user['role'] === 'superadmin' ? '#8b5cf6' : '#10b981');
                                                                                                ?>; color: white; padding: 2px 8px; border-radius: 10px;">
                                    <?php echo htmlspecialchars($user['role']); ?>
                                </span>
                            </div>
                            <div class="credential-details">
                                <div class="credential-detail">
                                    <span class="detail-label">Nom à utiliser :</span>
                                    <span class="detail-value"><?php echo htmlspecialchars($user['username']); ?></span>
                                </div>
                                <div class="credential-detail">
                                    <span class="detail-label">Téléphone :</span>
                                    <span class="detail-value"><?php echo str_replace('+237', '', $user['telephone']); ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Footer -->
            <div class="login-footer">
                <p>Version 1.0 • © 2024 Gestion Tontine</p>
                <p>Système sécurisé - Authentification par téléphone camerounais</p>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        $(document).ready(function() {
            // Validation côté client spécifique au Cameroun
            $('#loginForm').on('submit', function(e) {
                const username = $('#username').val().trim();
                const telephone = $('#telephone').val().trim();
                let isValid = true;
                let errorMessage = '';

                if (!username) {
                    errorMessage = "Le nom d'utilisateur est obligatoire";
                    $('#username').css('border-color', '#ef4444');
                    isValid = false;
                }

                if (!telephone) {
                    errorMessage = errorMessage ? errorMessage + ' et le téléphone est obligatoire' : 'Le numéro de téléphone est obligatoire';
                    $('#telephone').css('border-color', '#ef4444');
                    isValid = false;
                }

                // Validation du format du téléphone camerounais
                if (telephone && isValid) {
                    const cleanPhone = telephone.replace(/[\s\-]/g, '');
                    let phoneRegex;

                    // Pour 9 chiffres (commence par 6 ou 2)
                    if (cleanPhone.length == 9) {
                        phoneRegex = /^[62][0-9]{8}$/;
                    }
                    // Pour 8 chiffres (commence par 67, 65 ou 69)
                    else if (cleanPhone.length == 8) {
                        phoneRegex = /^(67|65|69)[0-9]{6}$/;
                    } else {
                        phoneRegex = false;
                    }

                    if (!phoneRegex) {
                        errorMessage = 'Format de téléphone invalide. Formats camerounais acceptés :\n' +
                            '• 677123456 (9 chiffres)\n' +
                            '• 699987654 (9 chiffres)\n' +
                            '• 67712345 (8 chiffres)\n' +
                            '• 65523456 (8 chiffres)\n' +
                            '• 69012345 (8 chiffres)';
                        $('#telephone').css('border-color', '#ef4444');
                        isValid = false;
                    }
                }

                if (!isValid) {
                    e.preventDefault();
                    showToast('error', 'Erreur de validation', errorMessage || 'Veuillez remplir correctement tous les champs.');
                }
            });

            // Réinitialiser les bordures
            $('input').on('input', function() {
                $(this).css('border-color', '#e2e8f0');
            });

            // Auto-format du téléphone camerounais
            $('#telephone').on('input', function() {
                let value = $(this).val().replace(/\D/g, '');

                if (value.length > 0) {
                    // Format pour les numéros camerounais: 677 123 456
                    if (value.length <= 9) {
                        value = value.replace(/(\d{3})(?=\d)/g, '$1 ');
                    }
                    $(this).val(value.trim());
                }
            });

            // Focus sur le champ username
            $('#username').focus();

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

            <?php if ($message_type === 'error'): ?>
                showToast('error', 'Erreur de connexion', '<?php echo addslashes($message); ?>');
            <?php endif; ?>

            // Animation des cartes d'utilisateurs
            $('.credential-item').each(function(index) {
                $(this).css('animation-delay', (index * 0.1) + 's');
                $(this).addClass('animate__animated animate__fadeIn');
            });

            // Sélection rapide d'un utilisateur
            $('.credential-item').on('click', function() {
                const nom = $(this).find('.detail-value').first().text().trim();
                const phone = $(this).find('.detail-value').last().text().trim();

                $('#username').val(nom).css('border-color', '#22c55e');
                $('#telephone').val(phone).css('border-color', '#22c55e');

                showToast('info', 'Champs remplis', 'Les informations ont été pré-remplies.');
            });
        });
    </script>
</body>

</html>