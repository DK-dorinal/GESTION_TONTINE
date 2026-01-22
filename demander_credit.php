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
            $taux_interet = 5.00; // Taux par défaut
            
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
                        (id_membre, montant, date_emprunt, taux_interet, duree_mois, montant_restant, statut, raison, mode_remboursement, type_credit)
                        VALUES (?, ?, NOW(), ?, ?, ?, 'demande', ?, ?, ?)
                    ");
                    
                    $stmt_insert->execute([
                        $user_id,
                        $montant,
                        $taux_interet,
                        $duree_mois,
                        $montant, // montant_restant initial = montant emprunté
                        $raison,
                        $mode_remboursement,
                        $type_credit
                    ]);
                    
                    $credit_id = $pdo->lastInsertId();
                    
                    // Enregistrer l'historique de la demande
                    $stmt_histo = $pdo->prepare("
                        INSERT INTO historique_demandes_credit 
                        (id_credit, action, date_action, commentaire)
                        VALUES (?, 'demande_soumise', NOW(), 'Demande de crédit soumise par le membre')
                    ");
                    $stmt_histo->execute([$credit_id]);
                    
                    $success = "Votre demande de crédit a été soumise avec succès ! 
                               <br><strong>ID Demande:</strong> #{$credit_id}
                               <br><strong>Montant:</strong> " . number_format($montant, 0, ',', ' ') . " FCFA
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
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            font-family: 'Inter', sans-serif;
        }

        body {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            min-height: 100vh;
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .stat-card {
            transition: all 0.3s ease;
            border-left: 4px solid;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }

        .input-group {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
        }

        .input-with-icon {
            padding-left: 45px !important;
        }

        .credit-type-card {
            border: 2px solid transparent;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .credit-type-card:hover {
            transform: translateY(-3px);
            border-color: #3b82f6;
        }

        .credit-type-card.selected {
            border-color: #3b82f6;
            background: #eff6ff;
        }

        .range-value {
            color: #3b82f6;
            font-weight: 600;
            font-size: 1.1rem;
        }

        .table-container {
            overflow-x: auto;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        thead {
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
            color: white;
        }

        th {
            padding: 16px;
            text-align: left;
            font-weight: 600;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        tbody tr {
            transition: all 0.2s ease;
        }

        tbody tr:hover {
            background: #f0f9ff;
        }

        td {
            padding: 14px 16px;
            border-bottom: 1px solid #e2e8f0;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .status-demande {
            background: #fef3c7;
            color: #92400e;
        }

        .status-en_cours {
            background: #d1fae5;
            color: #065f46;
        }

        .status-en_retard {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-rembourse {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-rejete {
            background: #f3f4f6;
            color: #6b7280;
        }

        .montant-cell {
            font-weight: 700;
            font-size: 15px;
        }

        .progress-bar {
            height: 8px;
            border-radius: 4px;
            background: #e5e7eb;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            border-radius: 4px;
            background: linear-gradient(90deg, #0ea5e9, #3b82f6);
            transition: width 0.5s ease;
        }

        .simulation-card {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border: 1px solid #bae6fd;
        }

        .simulation-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #0f766e;
        }
    </style>
</head>
<body class="p-4 md:p-6">
    <div class="max-w-6xl mx-auto">
        <!-- Header -->
        <div class="mb-8">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <h1 class="text-3xl font-bold text-gray-800 mb-2">
                        <i class="fas fa-hand-holding-usd text-green-600 mr-2"></i>
                        Demande de Crédit
                    </h1>
                    <p class="text-gray-600">Soumettez votre demande de crédit et suivez son statut</p>
                </div>

                <div class="flex items-center gap-3">
                    <a href="dashboard.php" class="px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-lg font-medium flex items-center gap-2">
                        <i class="fas fa-arrow-left"></i>
                        Retour
                    </a>
                    <div class="bg-gradient-to-r from-green-600 to-emerald-700 text-white px-4 py-2 rounded-lg shadow-md">
                        <span class="text-xs uppercase font-semibold"><?php echo htmlspecialchars($user['prenom'] . ' ' . $user['nom']); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistiques personnelles -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="stat-card bg-white p-6 rounded-xl border-l-4 border-blue-500">
                <div class="flex items-center justify-between mb-4">
                    <div class="p-3 bg-blue-100 rounded-lg">
                        <i class="fas fa-wallet text-blue-600 text-2xl"></i>
                    </div>
                    <span class="text-2xl font-bold text-blue-600"><?php echo count($mes_credits); ?></span>
                </div>
                <h3 class="font-bold text-gray-800 mb-1">Mes Crédits</h3>
                <p class="text-sm text-gray-600">Total des demandes</p>
            </div>

            <div class="stat-card bg-white p-6 rounded-xl border-l-4 border-green-500">
                <div class="flex items-center justify-between mb-4">
                    <div class="p-3 bg-green-100 rounded-lg">
                        <i class="fas fa-money-bill-wave text-green-600 text-2xl"></i>
                    </div>
                    <span class="text-2xl font-bold text-green-600">
                        <?php echo number_format($total_emprunte, 0, ',', ' '); ?> FCFA
                    </span>
                </div>
                <h3 class="font-bold text-gray-800 mb-1">Total Emprunté</h3>
                <p class="text-sm text-gray-600">Somme empruntée</p>
            </div>

            <div class="stat-card bg-white p-6 rounded-xl border-l-4 border-yellow-500">
                <div class="flex items-center justify-between mb-4">
                    <div class="p-3 bg-yellow-100 rounded-lg">
                        <i class="fas fa-hourglass-half text-yellow-600 text-2xl"></i>
                    </div>
                    <span class="text-2xl font-bold text-yellow-600">
                        <?php echo $credits_en_cours; ?>
                    </span>
                </div>
                <h3 class="font-bold text-gray-800 mb-1">Crédits en Cours</h3>
                <p class="text-sm text-gray-600">En cours de remboursement</p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Formulaire de demande -->
            <div class="lg:col-span-2">
                <div class="glass-card p-6 mb-6">
                    <h3 class="text-xl font-bold text-gray-800 mb-6 flex items-center">
                        <i class="fas fa-edit mr-2 text-blue-600"></i>
                        Formulaire de Demande de Crédit
                    </h3>

                    <?php if ($error): ?>
                        <div class="mb-6 bg-red-50 border-l-4 border-red-500 p-4 rounded-r-lg">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-exclamation-circle text-red-500 mt-1"></i>
                                </div>
                                <div class="ml-3">
                                    <p class="text-sm text-red-700"><?php echo $error; ?></p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="mb-6 bg-green-50 border-l-4 border-green-500 p-4 rounded-r-lg">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-check-circle text-green-500 mt-1"></i>
                                </div>
                                <div class="ml-3">
                                    <p class="text-sm text-green-700"><?php echo $success; ?></p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="" id="creditForm" class="space-y-6">
                        <!-- Type de crédit -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-3">
                                <i class="fas fa-tag mr-1"></i> Type de Crédit
                            </label>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="credit-type-card p-4 border rounded-lg" onclick="selectCreditType('standard')">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                                            <i class="fas fa-money-bill-wave text-blue-600"></i>
                                        </div>
                                        <div>
                                            <h4 class="font-semibold text-gray-800">Crédit Standard</h4>
                                            <p class="text-xs text-gray-600">Taux: 5.0%</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="credit-type-card p-4 border rounded-lg" onclick="selectCreditType('urgent')">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center">
                                            <i class="fas fa-bolt text-red-600"></i>
                                        </div>
                                        <div>
                                            <h4 class="font-semibold text-gray-800">Crédit Urgent</h4>
                                            <p class="text-xs text-gray-600">Taux: 6.5%</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="credit-type-card p-4 border rounded-lg" onclick="selectCreditType('long_terme')">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                                            <i class="fas fa-calendar-alt text-green-600"></i>
                                        </div>
                                        <div>
                                            <h4 class="font-semibold text-gray-800">Crédit Long Terme</h4>
                                            <p class="text-xs text-gray-600">Taux: 4.5%</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="credit-type-card p-4 border rounded-lg" onclick="selectCreditType('projet')">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center">
                                            <i class="fas fa-project-diagram text-purple-600"></i>
                                        </div>
                                        <div>
                                            <h4 class="font-semibold text-gray-800">Crédit Projet</h4>
                                            <p class="text-xs text-gray-600">Taux: 4.0%</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <input type="hidden" name="type_credit" id="type_credit" value="standard">
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Montant -->
                            <div>
                                <label for="montant" class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-money-bill-wave mr-1"></i> Montant souhaité (FCFA)
                                </label>
                                <div class="input-group">
                                    <div class="input-icon">
                                        <i class="fas fa-franc-sign"></i>
                                    </div>
                                    <input type="number" id="montant" name="montant" min="10000" max="10000000" step="1000"
                                        value="<?php echo htmlspecialchars($_POST['montant'] ?? ''); ?>"
                                        class="input-with-icon w-full px-4 py-3 rounded-lg border-2 border-gray-200 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
                                        placeholder="Ex: 500000"
                                        required
                                        oninput="updateSimulation()">
                                </div>
                                <div class="mt-2 text-sm text-gray-500">
                                    Min: 10,000 FCFA - Max: 10,000,000 FCFA
                                </div>
                                <div class="mt-2">
                                    <input type="range" id="montant_range" min="10000" max="10000000" step="1000" 
                                        value="<?php echo $_POST['montant'] ?? 500000; ?>"
                                        class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer"
                                        oninput="document.getElementById('montant').value = this.value; updateSimulation()">
                                </div>
                            </div>

                            <!-- Durée -->
                            <div>
                                <label for="duree_mois" class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-calendar-alt mr-1"></i> Durée (mois)
                                </label>
                                <div class="input-group">
                                    <div class="input-icon">
                                        <i class="fas fa-clock"></i>
                                    </div>
                                    <input type="number" id="duree_mois" name="duree_mois" min="3" max="60" step="1"
                                        value="<?php echo htmlspecialchars($_POST['duree_mois'] ?? '12'); ?>"
                                        class="input-with-icon w-full px-4 py-3 rounded-lg border-2 border-gray-200 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
                                        placeholder="Ex: 12"
                                        required
                                        oninput="updateSimulation()">
                                </div>
                                <div class="mt-2 text-sm text-gray-500">
                                    Min: 3 mois - Max: 60 mois
                                </div>
                                <div class="mt-2">
                                    <input type="range" id="duree_range" min="3" max="60" step="1"
                                        value="<?php echo $_POST['duree_mois'] ?? 12; ?>"
                                        class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer"
                                        oninput="document.getElementById('duree_mois').value = this.value; updateSimulation()">
                                </div>
                            </div>
                        </div>

                        <!-- Mode de remboursement -->
                        <div>
                            <label for="mode_remboursement" class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-calendar-check mr-1"></i> Mode de Remboursement
                            </label>
                            <select name="mode_remboursement" id="mode_remboursement"
                                class="w-full px-4 py-3 rounded-lg border-2 border-gray-200 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
                                onchange="updateSimulation()">
                                <option value="mensuel" <?php echo ($_POST['mode_remboursement'] ?? 'mensuel') == 'mensuel' ? 'selected' : ''; ?>>Mensuel</option>
                                <option value="trimestriel" <?php echo ($_POST['mode_remboursement'] ?? '') == 'trimestriel' ? 'selected' : ''; ?>>Trimestriel</option>
                                <option value="semestriel" <?php echo ($_POST['mode_remboursement'] ?? '') == 'semestriel' ? 'selected' : ''; ?>>Semestriel</option>
                                <option value="annuel" <?php echo ($_POST['mode_remboursement'] ?? '') == 'annuel' ? 'selected' : ''; ?>>Annuel</option>
                            </select>
                        </div>

                        <!-- Raison -->
                        <div>
                            <label for="raison" class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-comment-dots mr-1"></i> Raison de la demande
                            </label>
                            <textarea id="raison" name="raison" rows="4"
                                class="w-full px-4 py-3 rounded-lg border-2 border-gray-200 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
                                placeholder="Décrivez l'utilisation prévue du crédit..."
                                required><?php echo htmlspecialchars($_POST['raison'] ?? ''); ?></textarea>
                        </div>

                        <!-- Simulation -->
                        <div class="simulation-card p-6 rounded-lg">
                            <h4 class="font-bold text-gray-800 mb-4 flex items-center">
                                <i class="fas fa-calculator mr-2 text-blue-600"></i>
                                Simulation du Remboursement
                            </h4>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div class="text-center">
                                    <div class="text-sm text-gray-600 mb-1">Mensualité</div>
                                    <div id="mensualite" class="simulation-value">0 FCFA</div>
                                </div>
                                <div class="text-center">
                                    <div class="text-sm text-gray-600 mb-1">Total Intérêts</div>
                                    <div id="interet_total" class="simulation-value">0 FCFA</div>
                                </div>
                                <div class="text-center">
                                    <div class="text-sm text-gray-600 mb-1">Total à Rembourser</div>
                                    <div id="total_rembourser" class="simulation-value">0 FCFA</div>
                                </div>
                            </div>
                        </div>

                        <!-- Bouton de soumission -->
                        <div class="pt-4 border-t border-gray-200">
                            <button type="submit" class="w-full py-3 bg-gradient-to-r from-green-600 to-emerald-700 hover:from-green-700 hover:to-emerald-800 text-white rounded-lg font-bold text-lg flex items-center justify-center gap-2 transition-all duration-300 transform hover:scale-[1.02]">
                                <i class="fas fa-paper-plane"></i>
                                Soumettre la Demande
                            </button>
                            <p class="text-xs text-gray-500 text-center mt-3">
                                <i class="fas fa-info-circle mr-1"></i>
                                Votre demande sera examinée par l'administrateur. Vous recevrez une notification une fois la décision prise.
                            </p>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Mes crédits -->
            <div>
                <div class="glass-card p-6 mb-6">
                    <h3 class="text-xl font-bold text-gray-800 mb-6 flex items-center">
                        <i class="fas fa-history mr-2 text-purple-600"></i>
                        Mes Demandes de Crédit
                    </h3>

                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Montant</th>
                                    <th>Statut</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($mes_credits)): ?>
                                    <?php foreach ($mes_credits as $credit): ?>
                                        <tr onclick="window.location.href='detail_credit.php?id=<?php echo $credit['id_credit']; ?>'" style="cursor: pointer;">
                                            <td class="font-mono text-sm text-gray-500">#<?php echo $credit['id_credit']; ?></td>
                                            <td class="montant-cell text-blue-600">
                                                <?php echo number_format($credit['montant'], 0, ',', ' '); ?> F
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
                                        <td colspan="3" class="text-center py-8">
                                            <div class="text-gray-400">
                                                <i class="fas fa-credit-card text-4xl mb-4 opacity-50"></i>
                                                <p class="font-semibold">Aucun crédit</p>
                                                <p class="text-sm mt-2">Aucune demande de crédit</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (!empty($mes_credits)): ?>
                        <div class="mt-6 p-4 bg-blue-50 rounded-lg">
                            <h4 class="font-bold text-blue-800 mb-2 text-sm">📊 Récapitulatif</h4>
                            <div class="space-y-2 text-sm">
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Total emprunté:</span>
                                    <span class="font-bold text-blue-700"><?php echo number_format($total_emprunte, 0, ',', ' '); ?> FCFA</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Reste à payer:</span>
                                    <span class="font-bold text-orange-600"><?php echo number_format($total_restant, 0, ',', ' '); ?> FCFA</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Crédits en cours:</span>
                                    <span class="font-bold text-green-600"><?php echo $credits_en_cours; ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Informations importantes -->
                <div class="glass-card p-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
                        <i class="fas fa-info-circle text-blue-600 mr-2"></i>
                        Informations Importantes
                    </h3>
                    <div class="space-y-4">
                        <div class="p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
                            <h4 class="font-bold text-yellow-800 mb-2 text-sm">⚠️ Conditions d'éligibilité</h4>
                            <ul class="text-xs text-yellow-700 space-y-1">
                                <li><i class="fas fa-check-circle mr-1"></i> Être membre actif depuis 3 mois</li>
                                <li><i class="fas fa-check-circle mr-1"></i> Avoir moins de 3 crédits en cours</li>
                                <li><i class="fas fa-check-circle mr-1"></i> Solde total crédit ≤ 2,000,000 FCFA</li>
                                <li><i class="fas fa-check-circle mr-1"></i> Avoir une cotisation à jour</li>
                            </ul>
                        </div>

                        <div class="p-3 bg-blue-50 border border-blue-200 rounded-lg">
                            <h4 class="font-bold text-blue-800 mb-2 text-sm">💰 Taux d'Intérêt</h4>
                            <div class="text-xs text-blue-700 space-y-1">
                                <div class="flex justify-between">
                                    <span>Crédit Standard:</span>
                                    <span class="font-bold">5.0%</span>
                                </div>
                                <div class="flex justify-between">
                                    <span>Crédit Urgent:</span>
                                    <span class="font-bold">6.5%</span>
                                </div>
                                <div class="flex justify-between">
                                    <span>Crédit Long Terme:</span>
                                    <span class="font-bold">4.5%</span>
                                </div>
                                <div class="flex justify-between">
                                    <span>Crédit Projet:</span>
                                    <span class="font-bold">4.0%</span>
                                </div>
                            </div>
                        </div>

                        <div class="p-3 bg-green-50 border border-green-200 rounded-lg">
                            <h4 class="font-bold text-green-800 mb-2 text-sm">⚡ Délais de Traitement</h4>
                            <p class="text-xs text-green-700">
                                <i class="fas fa-clock mr-1"></i>
                                Les demandes sont traitées sous 48h ouvrables. Vous serez notifié par SMS.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
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
            // Sélectionner le type standard par défaut
            selectCreditType('standard');
            updateSimulation();
            
            // Mettre à jour les sliders avec les valeurs des inputs
            document.getElementById('montant_range').value = document.getElementById('montant').value || 500000;
            document.getElementById('duree_range').value = document.getElementById('duree_mois').value || 12;
            
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
                
                // Afficher un message de confirmation
                if (!confirm('Êtes-vous sûr de vouloir soumettre cette demande de crédit ?')) {
                    e.preventDefault();
                    return false;
                }
                
                return true;
            });
            
            // Effet de survol sur les lignes du tableau
            const tableRows = document.querySelectorAll('tbody tr');
            tableRows.forEach(row => {
                row.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateX(4px)';
                    this.style.boxShadow = '0 4px 12px rgba(0,0,0,0.1)';
                });
                
                row.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateX(0)';
                    this.style.boxShadow = 'none';
                });
            });
        });

        // Gestion des plages de valeurs
        document.getElementById('montant').addEventListener('input', function() {
            const value = parseInt(this.value);
            if (value < 10000) this.value = 10000;
            if (value > 10000000) this.value = 10000000;
            document.getElementById('montant_range').value = this.value;
        });
        
        document.getElementById('duree_mois').addEventListener('input', function() {
            const value = parseInt(this.value);
            if (value < 3) this.value = 3;
            if (value > 60) this.value = 60;
            document.getElementById('duree_range').value = this.value;
        });
    </script>
</body>
</html>