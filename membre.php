<?php
@include './fonctions/config.php';
session_start();
if ($_SESSION['role'] != 'admin' && !$_SESSION['user_id']) {
    header("Location: index.php");
}

// Fonctions utilitaires
function sanitizeInput($data)
{
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

function validatePhone($phone)
{
    return preg_match('/^[0-9\s\-\+\(\)]{8,20}$/', $phone);
}

// Traitement du formulaire
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom = sanitizeInput($_POST['nom'] ?? '');
    $prenom = sanitizeInput($_POST['prenom'] ?? '');
    $telephone = sanitizeInput($_POST['telephone'] ?? '');
    $adresse = sanitizeInput($_POST['adresse'] ?? '');
    $date_inscription = date('Y-m-d');
    $statut = 'actif';

    $errors = [];

    if (empty($nom)) $errors[] = "Le nom est obligatoire";
    if (empty($prenom)) $errors[] = "Le prénom est obligatoire";

    if (empty($telephone)) {
        $errors[] = "Le téléphone est obligatoire";
    } elseif (!validatePhone($telephone)) {
        $errors[] = "Le numéro de téléphone n'est pas valide";
    }

    if (empty($adresse)) {
        $errors[] = "L'adresse est obligatoire";
    }

    if (empty($errors)) {
        try {
            $sql = "INSERT INTO membre (nom, prenom, telephone, adresse, date_inscription, statut) 
                    VALUES (?, ?, ?, ?, ?, ?)";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$nom, $prenom, $telephone, $adresse, $date_inscription, $statut]);

            $message = "Membre enregistré avec succès!";
            $message_type = "success";

            $nom = $prenom = $telephone = $adresse = '';
        } catch (PDOException $e) {
            $message = "Erreur lors de l'enregistrement: " . $e->getMessage();
            $message_type = "error";
        }
    } else {
        $message = implode("<br>", $errors);
        $message_type = "error";
    }
}

// Récupérer la liste des membres
try {
    $stmt = $pdo->query("SELECT * FROM membre ORDER BY date_inscription DESC");
    $membres = $stmt->fetchAll();
} catch (PDOException $e) {
    $membres = [];
    $message = "Erreur lors de la récupération des membres: " . $e->getMessage();
    $message_type = "error";
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Membres | Tontine</title>
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
            --border-radius: 8px;
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
            border-radius: 12px;
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
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            animation: fadeInUp 0.8s;
        }

        @media (max-width: 992px) {
            .dashboard-content {
                grid-template-columns: 1fr;
            }
        }

        /* Cards */
        .dashboard-card {
            background: var(--pure-white);
            border-radius: 12px;
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

        .required {
            color: var(--medium-blue);
        }

        .form-control {
            width: 100%;
            padding: 14px 15px;
            border: 1px solid var(--bg-light);
            border-radius: var(--border-radius);
            font-family: "Poppins", sans-serif;
            font-size: 0.95rem;
            transition: var(--transition);
            background: var(--bg-light);
            color: var(--text-dark);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }

        .form-control:focus {
            border-color: var(--medium-blue);
            box-shadow: 0 0 0 3px rgba(45, 74, 138, 0.2);
            outline: none;
            background: var(--pure-white);
        }

        .input-with-icon {
            position: relative;
        }

        .input-with-icon i {
            position: absolute;
            top: 50%;
            right: 15px;
            transform: translateY(-50%);
            color: var(--text-light);
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

        .btn-reset {
            background: var(--bg-light);
            color: var(--text-light);
            border: 1px solid var(--bg-light);
        }

        .btn-reset:hover {
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

        /* Table Styles */
        .table-container {
            overflow-x: auto;
            border-radius: var(--border-radius);
            box-shadow: 0 2px 10px var(--shadow-light);
        }

        .members-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        .members-table thead {
            background: linear-gradient(135deg, var(--navy-blue) 0%, var(--dark-blue) 100%);
        }

        .members-table th {
            padding: 18px 20px;
            text-align: left;
            font-weight: 500;
            color: var(--pure-white);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .members-table tbody tr {
            border-bottom: 1px solid var(--bg-light);
            transition: var(--transition);
        }

        .members-table tbody tr:hover {
            background: var(--bg-light);
        }

        .members-table td {
            padding: 18px 20px;
            color: var(--text-dark);
            font-size: 0.9rem;
        }

        .member-name {
            font-weight: 500;
            color: var(--navy-blue);
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

        .status-active {
            background: rgba(34, 197, 94, 0.1);
            color: #166534;
        }

        .status-inactive {
            background: rgba(239, 68, 68, 0.1);
            color: #991b1b;
        }

        /* Stats Cards */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .stat-card {
            background: var(--pure-white);
            padding: 25px;
            border-radius: var(--border-radius);
            box-shadow: 0 3px 15px var(--shadow-light);
            text-align: center;
            transition: var(--transition);
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 20px var(--shadow-medium);
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

        /* Address in table */
        .address-cell {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
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

            .stats-container {
                grid-template-columns: 1fr;
            }

            .members-table th:nth-child(5),
            .members-table td:nth-child(5) {
                display: none;
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
    </style>
</head>

<body>
    <div class="dashboard-container">
        <!-- Header -->
        <header class="dashboard-header animate__animated animate__fadeInDown">
            <h1>Ajout de Membres à la Tontine</h1>
            <p class="dashboard-subtitle">Système de tontine - Inscription des nouveaux membres</p>
        </header>

        <!-- Main Content -->
        <div class="dashboard-content">
            <!-- Form Card -->
            <div class="dashboard-card animate__animated animate__fadeInUp">
                <div class="card-header">
                    <div class="card-icon">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <h2 class="card-title">Ajouter un Nouveau Membre</h2>
                </div>

                <?php if ($message): ?>
                    <div class="message <?php echo $message_type; ?>">
                        <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'error' ? 'exclamation-circle' : 'info-circle'); ?>"></i>
                        <span><?php echo $message; ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" id="memberForm">
                    <div class="form-group">
                        <label for="nom">Nom <span class="required">*</span></label>
                        <input type="text"
                            id="nom"
                            name="nom"
                            class="form-control"
                            value="<?php echo htmlspecialchars($nom ?? ''); ?>"
                            required
                            maxlength="50"
                            placeholder="Entrez le nom du membre">
                    </div>

                    <div class="form-group">
                        <label for="prenom">Prénom <span class="required">*</span></label>
                        <input type="text"
                            id="prenom"
                            name="prenom"
                            class="form-control"
                            value="<?php echo htmlspecialchars($prenom ?? ''); ?>"
                            required
                            maxlength="50"
                            placeholder="Entrez le prénom du membre">
                    </div>

                    <div class="form-group">
                        <label for="telephone">Téléphone <span class="required">*</span></label>
                        <div class="input-with-icon">
                            <input type="tel"
                                id="telephone"
                                name="telephone"
                                class="form-control"
                                value="<?php echo htmlspecialchars($telephone ?? ''); ?>"
                                required
                                pattern="[0-9\s\-\+\(\)]{8,20}"
                                placeholder="+237 6XX XX XX XX">
                            <i class="fas fa-phone"></i>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="adresse">Adresse <span class="required">*</span></label>
                        <textarea
                            id="adresse"
                            name="adresse"
                            class="form-control"
                            required
                            maxlength="255"
                            placeholder="Entrez l'adresse complète du membre"><?php echo htmlspecialchars($adresse ?? ''); ?></textarea>
                    </div>

                    <div class="btn-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-user-plus"></i> Ajouter le Membre
                        </button>
                        <button type="reset" class="btn btn-reset">
                            <i class="fas fa-redo"></i> Réinitialiser
                        </button>
                    </div>
                </form>
            </div>

            <!-- List Card -->
            <div class="dashboard-card animate__animated animate__fadeInUp animate__delay-1s">
                <div class="card-header">
                    <div class="card-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <h2 class="card-title">Membres de la Tontine <span style="font-size: 0.9rem; color: var(--text-light); font-weight: normal;">(<?php echo count($membres); ?>)</span></h2>
                </div>

                <?php if (count($membres) > 0): ?>
                    <div class="table-container">
                        <table class="members-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Nom & Prénom</th>
                                    <th>Téléphone</th>
                                    <th>Adresse</th>
                                    <th>Date d'inscription</th>
                                    <th>Statut</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($membres as $membre): ?>
                                    <tr>
                                        <td><strong style="color: var(--medium-blue);">#<?php echo $membre['id_membre']; ?></strong></td>
                                        <td>
                                            <div class="member-name"><?php echo htmlspecialchars($membre['nom'] . ' ' . $membre['prenom']); ?></div>
                                        </td>
                                        <td>
                                            <div style="margin-bottom: 5px;">
                                                <i class="fas fa-phone" style="color: var(--text-light); margin-right: 5px; font-size: 0.8rem;"></i>
                                                <?php echo htmlspecialchars($membre['telephone']); ?>
                                            </div>
                                        </td>
                                        <td class="address-cell" title="<?php echo htmlspecialchars($membre['adresse'] ?? ''); ?>">
                                            <i class="fas fa-map-marker-alt" style="color: var(--text-light); margin-right: 5px; font-size: 0.8rem;"></i>
                                            <?php echo htmlspecialchars($membre['adresse'] ?? 'Non renseignée'); ?>
                                        </td>
                                        <td>
                                            <i class="fas fa-calendar-alt" style="color: var(--text-light); margin-right: 5px; font-size: 0.8rem;"></i>
                                            <?php echo date('d/m/Y', strtotime($membre['date_inscription'])); ?>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $membre['statut']; ?>">
                                                <?php echo ucfirst($membre['statut']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Statistics -->
                    <div class="stats-container">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="stat-number"><?php echo count($membres); ?></div>
                            <div class="stat-label">Membres Totaux</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-user-check"></i>
                            </div>
                            <div class="stat-number">
                                <?php
                                $actifs = array_filter($membres, function ($m) {
                                    return $m['statut'] === 'actif';
                                });
                                echo count($actifs);
                                ?>
                            </div>
                            <div class="stat-label">Membres Actifs</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-calendar-plus"></i>
                            </div>
                            <div class="stat-number">
                                <?php
                                $date_limite = date('Y-m-d', strtotime('-30 days'));
                                $recents = array_filter($membres, function ($m) use ($date_limite) {
                                    return $m['date_inscription'] >= $date_limite;
                                });
                                echo count($recents);
                                ?>
                            </div>
                            <div class="stat-label">Nouveaux (30j)</div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-users-slash"></i>
                        <h3>Aucun membre enregistré</h3>
                        <p>Commencez par ajouter un nouveau membre en utilisant le formulaire ci-dessus.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Validation côté client
        $(document).ready(function() {
            $('#memberForm').on('submit', function(e) {
                let isValid = true;
                const inputs = $(this).find('input[required], textarea[required]');

                inputs.each(function() {
                    if (!$(this).val().trim()) {
                        isValid = false;
                        $(this).css('border-color', '#ef4444');
                    } else {
                        $(this).css('border-color', '#e2e8f0');
                    }
                });

                // Validation téléphone
                const phone = $('#telephone');
                const phoneRegex = /^[0-9\s\-\+\(\)]{8,20}$/;
                if (phone.val() && !phoneRegex.test(phone.val())) {
                    isValid = false;
                    phone.css('border-color', '#ef4444');
                    showToast('warning', 'Téléphone invalide', 'Veuillez entrer un numéro de téléphone valide.');
                }

                if (!isValid) {
                    e.preventDefault();
                    showToast('error', 'Champs manquants', 'Veuillez remplir tous les champs obligatoires correctement.');
                }
            });

            // Réinitialiser les bordures
            $('input, textarea').on('input', function() {
                $(this).css('border-color', '#e2e8f0');
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
        });
    </script>
</body>

</html>