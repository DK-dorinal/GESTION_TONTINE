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

function validateDecimal($value)
{
    return preg_match('/^\d+(\.\d{1,2})?$/', $value);
}

function validateDate($date)
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

// Traitement du formulaire
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom_tontine = sanitizeInput($_POST['nom_tontine'] ?? '');
    $type_tontine = sanitizeInput($_POST['type_tontine'] ?? '');
    $montant_cotisation = sanitizeInput($_POST['montant_cotisation'] ?? '');
    $frequence = sanitizeInput($_POST['frequence'] ?? '');
    $date_debut = sanitizeInput($_POST['date_debut'] ?? '');
    $date_fin = sanitizeInput($_POST['date_fin'] ?? '');
    $statut = 'active';

    $errors = [];

    if (empty($nom_tontine)) $errors[] = "Le nom de la tontine est obligatoire";
    if (empty($type_tontine)) $errors[] = "Le type de tontine est obligatoire";

    if (empty($montant_cotisation)) {
        $errors[] = "Le montant de cotisation est obligatoire";
    } elseif (!validateDecimal($montant_cotisation)) {
        $errors[] = "Le montant de cotisation n'est pas valide";
    }

    if (empty($frequence)) {
        $errors[] = "La fréquence est obligatoire";
    }

    if (empty($date_debut)) {
        $errors[] = "La date de début est obligatoire";
    } elseif (!validateDate($date_debut)) {
        $errors[] = "La date de début n'est pas valide";
    }

    if (!empty($date_fin) && !validateDate($date_fin)) {
        $errors[] = "La date de fin n'est pas valide";
    }

    if (empty($errors)) {
        try {
            $sql = "INSERT INTO tontine (nom_tontine, type_tontine, montant_cotisation, frequence, date_debut, date_fin, statut) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$nom_tontine, $type_tontine, $montant_cotisation, $frequence, $date_debut, $date_fin, $statut]);

            $message = "Tontine créée avec succès!";
            $message_type = "success";

            // Réinitialiser les champs
            $nom_tontine = $type_tontine = $montant_cotisation = $frequence = $date_debut = $date_fin = '';
        } catch (PDOException $e) {
            $message = "Erreur lors de la création: " . $e->getMessage();
            $message_type = "error";
        }
    } else {
        $message = implode("<br>", $errors);
        $message_type = "error";
    }
}

// Récupérer la liste des tontines
try {
    $stmt = $pdo->query("SELECT * FROM tontine ORDER BY date_debut DESC");
    $tontines = $stmt->fetchAll();
} catch (PDOException $e) {
    $tontines = [];
    $message = "Erreur lors de la récupération des tontines: " . $e->getMessage();
    $message_type = "error";
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Tontines | Tontine</title>
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

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 15px center;
            background-size: 16px;
            padding-right: 40px;
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

        /* Type Badges */
        .type-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .type-obligatoire {
            background: rgba(239, 68, 68, 0.1);
            color: #991b1b;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .type-optionnel {
            background: rgba(59, 130, 246, 0.1);
            color: #1e40af;
            border: 1px solid rgba(59, 130, 246, 0.3);
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

        .tontines-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        .tontines-table thead {
            background: linear-gradient(135deg, var(--navy-blue) 0%, var(--dark-blue) 100%);
        }

        .tontines-table th {
            padding: 18px 20px;
            text-align: left;
            font-weight: 500;
            color: var(--pure-white);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .tontines-table tbody tr {
            border-bottom: 1px solid var(--bg-light);
            transition: var(--transition);
        }

        .tontines-table tbody tr:hover {
            background: var(--bg-light);
        }

        .tontines-table td {
            padding: 18px 20px;
            color: var(--text-dark);
            font-size: 0.9rem;
        }

        .tontine-name {
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

        .status-pending {
            background: rgba(245, 158, 11, 0.1);
            color: #92400e;
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

        /* Amount display */
        .amount {
            font-weight: 600;
            color: var(--dark-blue);
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

            .stats-container {
                grid-template-columns: 1fr;
            }

            .tontines-table th:nth-child(6),
            .tontines-table td:nth-child(6) {
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
            <h1>Création de Tontine</h1>
            <p class="dashboard-subtitle">Système de gestion de tontine - Créez de nouvelles tontines</p>
        </header>

        <!-- Main Content -->
        <div class="dashboard-content">
            <!-- Form Card -->
            <div class="dashboard-card animate__animated animate__fadeInUp">
                <div class="card-header">
                    <div class="card-icon">
                        <i class="fas fa-hand-holding-usd"></i>
                    </div>
                    <h2 class="card-title">Créer une Nouvelle Tontine</h2>
                </div>

                <?php if ($message): ?>
                    <div class="message <?php echo $message_type; ?>">
                        <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'error' ? 'exclamation-circle' : 'info-circle'); ?>"></i>
                        <span><?php echo $message; ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" id="tontineForm">
                    <div class="form-group">
                        <label for="nom_tontine">Nom de la Tontine <span class="required">*</span></label>
                        <input type="text"
                            id="nom_tontine"
                            name="nom_tontine"
                            class="form-control"
                            value="<?php echo htmlspecialchars($nom_tontine ?? ''); ?>"
                            required
                            maxlength="100"
                            placeholder="Ex: Tontine Mensuelle 2026">
                    </div>

                    <div class="form-group">
                        <label for="type_tontine">Type de Tontine <span class="required">*</span></label>
                        <select id="type_tontine" name="type_tontine" class="form-control" required>
                            <option value="">Sélectionnez un type</option>
                            <option value="obligatoire" <?php echo ($type_tontine ?? '') === 'obligatoire' ? 'selected' : ''; ?>>Obligatoire</option>
                            <option value="optionnel" <?php echo ($type_tontine ?? '') === 'optionnel' ? 'selected' : ''; ?>>Optionnel</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="montant_cotisation">Montant de Cotisation <span class="required">*</span></label>
                        <div class="input-with-icon">
                            <input type="number"
                                id="montant_cotisation"
                                name="montant_cotisation"
                                class="form-control"
                                value="<?php echo htmlspecialchars($montant_cotisation ?? ''); ?>"
                                required
                                min="0"
                                step="0.01"
                                placeholder="0.00">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <small style="color: var(--text-light); font-size: 0.8rem;">Montant en FCFA</small>
                    </div>

                    <div class="form-group">
                        <label for="frequence">Fréquence des Cotisations <span class="required">*</span></label>
                        <select id="frequence" name="frequence" class="form-control" required>
                            <option value="">Sélectionnez une fréquence</option>
                            <option value="quotidienne" <?php echo ($frequence ?? '') === 'quotidienne' ? 'selected' : ''; ?>>Quotidienne</option>
                            <option value="hebdomadaire" <?php echo ($frequence ?? '') === 'hebdomadaire' ? 'selected' : ''; ?>>Hebdomadaire</option>
                            <option value="mensuelle" <?php echo ($frequence ?? '') === 'mensuelle' ? 'selected' : ''; ?>>Mensuelle</option>
                            <option value="trimestrielle" <?php echo ($frequence ?? '') === 'trimestrielle' ? 'selected' : ''; ?>>Trimestrielle</option>
                            <option value="annuelle" <?php echo ($frequence ?? '') === 'annuelle' ? 'selected' : ''; ?>>Annuelle</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="date_debut">Date de Début <span class="required">*</span></label>
                        <input type="date"
                            id="date_debut"
                            name="date_debut"
                            class="form-control"
                            value="<?php echo htmlspecialchars($date_debut ?? ''); ?>"
                            required>
                    </div>

                    <div class="form-group">
                        <label for="date_fin">Date de Fin (Optionnelle)</label>
                        <input type="date"
                            id="date_fin"
                            name="date_fin"
                            class="form-control"
                            value="<?php echo htmlspecialchars($date_fin ?? ''); ?>">
                    </div>

                    <div class="btn-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-plus-circle"></i> Créer la Tontine
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
                        <i class="fas fa-list"></i>
                    </div>
                    <h2 class="card-title">Liste des Tontines <span style="font-size: 0.9rem; color: var(--text-light); font-weight: normal;">(<?php echo count($tontines); ?>)</span></h2>
                </div>

                <?php if (count($tontines) > 0): ?>
                    <div class="table-container">
                        <table class="tontines-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Nom</th>
                                    <th>Type</th>
                                    <th>Montant</th>
                                    <th>Fréquence</th>
                                    <th>Date Début</th>
                                    <th>Date Fin</th>
                                    <th>Statut</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tontines as $tontine): ?>
                                    <tr>
                                        <td><strong style="color: var(--medium-blue);">#<?php echo $tontine['id_tontine']; ?></strong></td>
                                        <td>
                                            <div class="tontine-name"><?php echo htmlspecialchars($tontine['nom_tontine']); ?></div>
                                        </td>
                                        <td>
                                            <span class="type-badge type-<?php echo $tontine['type_tontine']; ?>">
                                                <?php echo ucfirst($tontine['type_tontine']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="amount">
                                                <i class="fas fa-money-bill-wave" style="color: var(--text-light); margin-right: 5px; font-size: 0.8rem;"></i>
                                                <?php echo number_format($tontine['montant_cotisation'], 0, ',', ' '); ?> FCFA
                                            </div>
                                        </td>
                                        <td>
                                            <i class="fas fa-calendar-alt" style="color: var(--text-light); margin-right: 5px; font-size: 0.8rem;"></i>
                                            <?php echo htmlspecialchars($tontine['frequence']); ?>
                                        </td>
                                        <td>
                                            <?php echo date('d/m/Y', strtotime($tontine['date_debut'])); ?>
                                        </td>
                                        <td>
                                            <?php echo $tontine['date_fin'] ? date('d/m/Y', strtotime($tontine['date_fin'])) : '---'; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $tontine['statut']; ?>">
                                                <?php echo ucfirst($tontine['statut']); ?>
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
                                <i class="fas fa-hand-holding-usd"></i>
                            </div>
                            <div class="stat-number"><?php echo count($tontines); ?></div>
                            <div class="stat-label">Tontines Totales</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-exclamation-circle"></i>
                            </div>
                            <div class="stat-number">
                                <?php
                                $obligatoires = array_filter($tontines, function ($t) {
                                    return $t['type_tontine'] === 'obligatoire';
                                });
                                echo count($obligatoires);
                                ?>
                            </div>
                            <div class="stat-label">Tontines Obligatoires</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div class="stat-number">
                                <?php
                                $optionnels = array_filter($tontines, function ($t) {
                                    return $t['type_tontine'] === 'optionnel';
                                });
                                echo count($optionnels);
                                ?>
                            </div>
                            <div class="stat-label">Tontines Optionnelles</div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-money-check-alt"></i>
                        <h3>Aucune tontine créée</h3>
                        <p>Commencez par créer une nouvelle tontine en utilisant le formulaire ci-dessus.</p>
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
            // Définir la date minimale à aujourd'hui
            const today = new Date().toISOString().split('T')[0];
            $('#date_debut').attr('min', today);
            $('#date_fin').attr('min', today);

            $('#tontineForm').on('submit', function(e) {
                let isValid = true;
                const inputs = $(this).find('input[required], select[required]');

                inputs.each(function() {
                    if (!$(this).val().trim()) {
                        isValid = false;
                        $(this).css('border-color', '#ef4444');
                    } else {
                        $(this).css('border-color', '#e2e8f0');
                    }
                });

                // Validation des dates
                const dateDebut = $('#date_debut');
                const dateFin = $('#date_fin');

                if (dateFin.val() && dateDebut.val() && dateFin.val() < dateDebut.val()) {
                    isValid = false;
                    dateFin.css('border-color', '#ef4444');
                    showToast('warning', 'Date invalide', 'La date de fin doit être postérieure à la date de début.');
                }

                // Validation montant
                const montant = $('#montant_cotisation');
                if (montant.val() && parseFloat(montant.val()) <= 0) {
                    isValid = false;
                    montant.css('border-color', '#ef4444');
                    showToast('warning', 'Montant invalide', 'Le montant de cotisation doit être supérieur à 0.');
                }

                if (!isValid) {
                    e.preventDefault();
                    showToast('error', 'Champs manquants', 'Veuillez remplir tous les champs obligatoires correctement.');
                }
            });

            // Réinitialiser les bordures
            $('input, select, textarea').on('input change', function() {
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