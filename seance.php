<?php
@include './fonctions/config.php';

// Fonctions utilitaires
function sanitizeInput($data)
{
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Récupérer la liste des tontines
try {
    $stmt = $pdo->query("SELECT id_tontine, nom_tontine FROM tontine WHERE statut = 'active' ORDER BY nom_tontine");
    $tontines = $stmt->fetchAll();
} catch (PDOException $e) {
    $tontines = [];
}

// Récupérer la liste des membres pour bénéficiaire
try {
    $stmt = $pdo->query("SELECT id_membre, nom, prenom FROM membre WHERE statut = 'actif' ORDER BY nom, prenom");
    $membres = $stmt->fetchAll();
} catch (PDOException $e) {
    $membres = [];
}

// Traitement du formulaire
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_tontine = sanitizeInput($_POST['id_tontine'] ?? '');
    $date_seance = sanitizeInput($_POST['date_seance'] ?? '');
    $id_beneficiaire = sanitizeInput($_POST['id_beneficiaire'] ?? '');
    $montant_total = sanitizeInput($_POST['montant_total'] ?? '');

    $errors = [];

    if (empty($id_tontine)) $errors[] = "La tontine est obligatoire";
    if (empty($date_seance)) $errors[] = "La date de la séance est obligatoire";
    if (empty($montant_total) || !is_numeric($montant_total) || $montant_total <= 0) {
        $errors[] = "Le montant total doit être un nombre positif";
    }

    if (empty($errors)) {
        try {
            // Vérifier si une séance existe déjà pour cette tontine à cette date
            $checkSql = "SELECT COUNT(*) FROM seance WHERE id_tontine = ? AND date_seance = ?";
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute([$id_tontine, $date_seance]);

            if ($checkStmt->fetchColumn() > 0) {
                $message = "Une séance existe déjà pour cette tontine à cette date";
                $message_type = "error";
            } else {
                $sql = "INSERT INTO seance (id_tontine, date_seance, montant_total, id_beneficiaire) 
                        VALUES (?, ?, ?, ?)";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $id_tontine,
                    $date_seance,
                    $montant_total,
                    !empty($id_beneficiaire) ? $id_beneficiaire : NULL
                ]);

                // Si un bénéficiaire est désigné, l'ajouter aussi dans la table bénéficiaire
                if (!empty($id_beneficiaire)) {
                    $id_seance = $pdo->lastInsertId();
                    $benefSql = "INSERT INTO beneficiaire (id_membre, id_tontine, montant_gagne, date_gain) 
                                 VALUES (?, ?, ?, ?)";
                    $benefStmt = $pdo->prepare($benefSql);
                    $benefStmt->execute([
                        $id_beneficiaire,
                        $id_tontine,
                        $montant_total,
                        $date_seance
                    ]);
                }

                $message = "Séance créée avec succès!";
                $message_type = "success";

                // Réinitialiser les champs
                $id_tontine = $date_seance = $id_beneficiaire = $montant_total = '';
            }
        } catch (PDOException $e) {
            $message = "Erreur lors de la création de la séance: " . $e->getMessage();
            $message_type = "error";
        }
    } else {
        $message = implode("<br>", $errors);
        $message_type = "error";
    }
}

// Récupérer la liste des séances
try {
    $sql = "SELECT s.*, t.nom_tontine, m.nom, m.prenom 
            FROM seance s 
            LEFT JOIN tontine t ON s.id_tontine = t.id_tontine 
            LEFT JOIN membre m ON s.id_beneficiaire = m.id_membre 
            ORDER BY s.date_seance DESC, s.id_seance DESC";
    $stmt = $pdo->query($sql);
    $seances = $stmt->fetchAll();
} catch (PDOException $e) {
    $seances = [];
    if (empty($message)) {
        $message = "Erreur lors de la récupération des séances: " . $e->getMessage();
        $message_type = "error";
    }
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Séances | Tontine</title>
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

        .form-control,
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
        }

        .form-select {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 15px center;
            background-size: 16px;
            padding-right: 40px;
        }

        .form-control:focus,
        .form-select:focus {
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

        .seances-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        .seances-table thead {
            background: linear-gradient(135deg, var(--navy-blue) 0%, var(--dark-blue) 100%);
        }

        .seances-table th {
            padding: 18px 20px;
            text-align: left;
            font-weight: 500;
            color: var(--pure-white);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .seances-table tbody tr {
            border-bottom: 1px solid var(--bg-light);
            transition: var(--transition);
        }

        .seances-table tbody tr:hover {
            background: var(--bg-light);
        }

        .seances-table td {
            padding: 18px 20px;
            color: var(--text-dark);
            font-size: 0.9rem;
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

        .status-completed {
            background: rgba(34, 197, 94, 0.1);
            color: #166534;
        }

        .status-pending {
            background: rgba(245, 158, 11, 0.1);
            color: #92400e;
        }

        .status-cancelled {
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

        /* Amount formatting */
        .montant {
            font-weight: 600;
            color: var(--navy-blue);
        }

        .montant::before {
            content: "FCFA ";
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

            .seances-table th:nth-child(3),
            .seances-table td:nth-child(3) {
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

        /* Info message */
        .info-message {
            background: rgba(59, 130, 246, 0.1);
            color: #1e40af;
            padding: 12px 16px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            font-size: 0.9rem;
            border-left: 4px solid #3b82f6;
        }

        .info-message i {
            margin-right: 8px;
        }
    </style>
</head>

<body>
    <div class="dashboard-container">
        <!-- Header -->
        <header class="dashboard-header animate__animated animate__fadeInDown">
            <h1>Gestion des Séances de Tontine</h1>
            <p class="dashboard-subtitle">Création et consultation des séances de collecte</p>
        </header>

        <!-- Main Content -->
        <div class="dashboard-content">
            <!-- Form Card -->
            <div class="dashboard-card animate__animated animate__fadeInUp">
                <div class="card-header">
                    <div class="card-icon">
                        <i class="fas fa-calendar-plus"></i>
                    </div>
                    <h2 class="card-title">Créer une Nouvelle Séance</h2>
                </div>

                <?php if ($message): ?>
                    <div class="message <?php echo $message_type; ?>">
                        <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'error' ? 'exclamation-circle' : 'info-circle'); ?>"></i>
                        <span><?php echo $message; ?></span>
                    </div>
                <?php endif; ?>

                <div class="info-message">
                    <i class="fas fa-info-circle"></i>
                    <span>Une séance représente une session de collecte de fonds pour une tontine spécifique.</span>
                </div>

                <form method="POST" action="" id="seanceForm">
                    <div class="form-group">
                        <label for="id_tontine">Tontine <span class="required">*</span></label>
                        <select id="id_tontine" name="id_tontine" class="form-select" required>
                            <option value="">Sélectionnez une tontine</option>
                            <?php foreach ($tontines as $tontine): ?>
                                <option value="<?php echo $tontine['id_tontine']; ?>"
                                    <?php echo ($id_tontine ?? '') == $tontine['id_tontine'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($tontine['nom_tontine']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="date_seance">Date de la Séance <span class="required">*</span></label>
                        <div class="input-with-icon">
                            <input type="date"
                                id="date_seance"
                                name="date_seance"
                                class="form-control"
                                value="<?php echo htmlspecialchars($date_seance ?? date('Y-m-d')); ?>"
                                required>
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="montant_total">Montant Total Collecté (FCFA) <span class="required">*</span></label>
                        <div class="input-with-icon">
                            <input type="number"
                                id="montant_total"
                                name="montant_total"
                                class="form-control"
                                value="<?php echo htmlspecialchars($montant_total ?? ''); ?>"
                                required
                                min="1"
                                step="0.01"
                                placeholder="Entrez le montant total collecté">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="id_beneficiaire">Bénéficiaire (Optionnel)</label>
                        <select id="id_beneficiaire" name="id_beneficiaire" class="form-select">
                            <option value="">Aucun bénéficiaire désigné</option>
                            <?php foreach ($membres as $membre): ?>
                                <option value="<?php echo $membre['id_membre']; ?>"
                                    <?php echo ($id_beneficiaire ?? '') == $membre['id_membre'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($membre['nom'] . ' ' . $membre['prenom']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: var(--text-light); margin-top: 5px; display: block;">
                            <i class="fas fa-info-circle"></i> Si un bénéficiaire est désigné, il sera automatiquement enregistré comme gagnant de cette séance.
                        </small>
                    </div>

                    <div class="btn-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-calendar-check"></i> Créer la Séance
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
                        <i class="fas fa-list-alt"></i>
                    </div>
                    <h2 class="card-title">Liste des Séances <span style="font-size: 0.9rem; color: var(--text-light); font-weight: normal;">(<?php echo count($seances); ?>)</span></h2>
                </div>

                <?php if (count($seances) > 0): ?>
                    <div class="table-container">
                        <table class="seances-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Tontine</th>
                                    <th>Date</th>
                                    <th>Montant</th>
                                    <th>Bénéficiaire</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($seances as $seance): ?>
                                    <tr>
                                        <td><strong style="color: var(--medium-blue);">#<?php echo $seance['id_seance']; ?></strong></td>
                                        <td>
                                            <div style="font-weight: 500; color: var(--navy-blue);">
                                                <?php echo htmlspecialchars($seance['nom_tontine']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <i class="fas fa-calendar-alt" style="color: var(--text-light); margin-right: 5px; font-size: 0.8rem;"></i>
                                            <?php echo date('d/m/Y', strtotime($seance['date_seance'])); ?>
                                        </td>
                                        <td>
                                            <span class="montant"><?php echo number_format($seance['montant_total'], 0, ',', ' '); ?></span>
                                        </td>
                                        <td>
                                            <?php if (!empty($seance['nom'])): ?>
                                                <div style="display: flex; align-items: center; gap: 8px;">
                                                    <i class="fas fa-user-check" style="color: var(--accent-gold);"></i>
                                                    <span><?php echo htmlspecialchars($seance['nom'] . ' ' . $seance['prenom']); ?></span>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: var(--text-light); font-style: italic;">Aucun</span>
                                            <?php endif; ?>
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
                                <i class="fas fa-calendar-check"></i>
                            </div>
                            <div class="stat-number"><?php echo count($seances); ?></div>
                            <div class="stat-label">Séances Total</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-money-bill-wave"></i>
                            </div>
                            <div class="stat-number">
                                <?php
                                $totalMontant = array_sum(array_column($seances, 'montant_total'));
                                echo number_format($totalMontant, 0, ',', ' ');
                                ?>
                            </div>
                            <div class="stat-label">Total Collecté (FCFA)</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-trophy"></i>
                            </div>
                            <div class="stat-number">
                                <?php
                                $avecBeneficiaire = array_filter($seances, function ($s) {
                                    return !empty($s['nom']);
                                });
                                echo count($avecBeneficiaire);
                                ?>
                            </div>
                            <div class="stat-label">Avec Bénéficiaire</div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <h3>Aucune séance enregistrée</h3>
                        <p>Commencez par créer une nouvelle séance en utilisant le formulaire ci-dessus.</p>
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
            // Définir la date d'aujourd'hui par défaut
            const today = new Date().toISOString().split('T')[0];
            $('#date_seance').val(today);

            $('#seanceForm').on('submit', function(e) {
                let isValid = true;
                const requiredInputs = $(this).find('input[required], select[required]');

                requiredInputs.each(function() {
                    const value = $(this).val();
                    if (!value || value.trim() === '') {
                        isValid = false;
                        $(this).css('border-color', '#ef4444');
                    } else {
                        $(this).css('border-color', '#e2e8f0');
                    }
                });

                // Validation du montant
                const montant = $('#montant_total');
                if (montant.val() && (parseFloat(montant.val()) <= 0 || isNaN(parseFloat(montant.val())))) {
                    isValid = false;
                    montant.css('border-color', '#ef4444');
                    showToast('warning', 'Montant invalide', 'Le montant doit être un nombre positif.');
                }

                // Validation de la date
                const dateSeance = $('#date_seance');
                const selectedDate = new Date(dateSeance.val());
                const todayDate = new Date();
                todayDate.setHours(0, 0, 0, 0);

                if (selectedDate > todayDate) {
                    isValid = false;
                    dateSeance.css('border-color', '#ef4444');
                    showToast('warning', 'Date invalide', 'La date de la séance ne peut pas être dans le futur.');
                }

                // if (!isValid) {
                //     e.preventDefault();
                //     showToast('error', 'Erreur de validation', 'Veuillez corriger les erreurs dans le formulaire.');
                // }
            });

            // Réinitialiser les bordures
            $('input, select').on('input change', function() {
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

            // Formater le montant en temps réel
            $('#montant_total').on('input', function() {
                let value = $(this).val();
                // Supprimer les caractères non numériques sauf le point décimal
                value = value.replace(/[^0-9.]/g, '');
                $(this).val(value);
            });
        });
    </script>
</body>

</html>