<?php
session_start();
include './fonctions/config.php';

// Vérifier la connexion
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Récupérer les données de l'utilisateur connecté
$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT * FROM membre WHERE id_membre = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header('Location: index.php');
    exit();
}

// Variables
$errors = [];
$success_message = '';
$penalite_appliquee = false;
$montant_penalite = 0;
$montant_total = 0;

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_tontine = $_POST['id_tontine'] ?? '';
    $id_seance = $_POST['id_seance'] ?? '';
    $date_paiement = $_POST['date_paiement'] ?? date('Y-m-d');
    
    // Validation basique
    if (empty($id_tontine)) {
        $errors[] = "Veuillez sélectionner une tontine.";
    }
    
    if (empty($id_seance)) {
        $errors[] = "Veuillez sélectionner une séance.";
    }
    
    if (empty($errors)) {
        try {
            // Récupérer les informations de la séance
            $query_seance = "
                SELECT s.*, t.nom_tontine, t.montant_cotisation, 
                       DATEDIFF(?, s.date_seance) as jours_retard
                FROM seance s
                INNER JOIN tontine t ON s.id_tontine = t.id_tontine
                WHERE s.id_seance = ?
                AND t.id_tontine = ?
            ";
            $stmt_seance = $pdo->prepare($query_seance);
            $stmt_seance->execute([$date_paiement, $id_seance, $id_tontine]);
            $seance_info = $stmt_seance->fetch();
            
            if (!$seance_info) {
                $errors[] = "Séance non trouvée pour cette tontine.";
            } else {
                // Vérifier que la date de paiement n'est pas dans le futur (pas de paiement à l'avance)
                $date_seance = new DateTime($seance_info['date_seance']);
                $date_paiement_obj = new DateTime($date_paiement);
                
                if ($date_paiement_obj > $date_seance) {
                    // Vérifier que ce n'est pas plus d'un jour après
                    $interval = $date_seance->diff($date_paiement_obj);
                    if ($interval->days > 0) {
                        $errors[] = "Vous ne pouvez pas payer à l'avance. Le paiement doit être effectué le jour même de la séance ou en retard.";
                    }
                }
                
                // Vérifier que l'utilisateur participe à cette tontine
                $query_participation = "
                    SELECT * FROM participation_tontine 
                    WHERE id_membre = ? AND id_tontine = ? AND statut = 'active'
                ";
                $stmt_participation = $pdo->prepare($query_participation);
                $stmt_participation->execute([$user_id, $id_tontine]);
                
                if ($stmt_participation->rowCount() == 0) {
                    $errors[] = "Vous ne participez pas à cette tontine.";
                }
                
                // Vérifier si la cotisation n'a pas déjà été payée
                $query_cotisation_exist = "
                    SELECT * FROM cotisation 
                    WHERE id_membre = ? AND id_seance = ? AND statut = 'payé'
                ";
                $stmt_cotisation_exist = $pdo->prepare($query_cotisation_exist);
                $stmt_cotisation_exist->execute([$user_id, $id_seance]);
                
                if ($stmt_cotisation_exist->rowCount() > 0) {
                    $errors[] = "Vous avez déjà payé cette cotisation.";
                }
            }
        } catch (PDOException $e) {
            $errors[] = "Erreur de vérification: " . $e->getMessage();
        }
    }
    
    // Si pas d'erreurs, procéder au paiement
    if (empty($errors)) {
        // Calculer la pénalité si en retard
        $jours_retard = $seance_info['jours_retard'];
        $montant_base = $seance_info['montant_cotisation'];
        
        if ($jours_retard > 0) {
            // Pénalité de 10% pour retard
            $penalite_appliquee = true;
            $montant_penalite = $montant_base * 0.10;
            $montant_total = $montant_base + $montant_penalite;
        } else {
            // Paiement à jour (jour même)
            $montant_total = $montant_base;
        }
        
        try {
            // Commencer une transaction
            $pdo->beginTransaction();
            
            // 1. Enregistrer la cotisation
            $query_cotisation = "
                INSERT INTO cotisation (id_membre, id_seance, montant, date_paiement, statut) 
                VALUES (?, ?, ?, ?, 'payé')
            ";
            $stmt_cotisation = $pdo->prepare($query_cotisation);
            $stmt_cotisation->execute([$user_id, $id_seance, $montant_total, $date_paiement]);
            $id_cotisation = $pdo->lastInsertId();
            
            // 2. Si pénalité, enregistrer dans la table penalite
            if ($penalite_appliquee) {
                $raison_penalite = "Retard de " . $jours_retard . " jour(s) sur la séance #" . $id_seance . 
                                  " (Tontine: " . $seance_info['nom_tontine'] . ")";
                
                $query_penalite = "
                    INSERT INTO penalite (id_membre, montant, raison, date_penalite) 
                    VALUES (?, ?, ?, ?)
                ";
                $stmt_penalite = $pdo->prepare($query_penalite);
                $stmt_penalite->execute([$user_id, $montant_penalite, $raison_penalite, $date_paiement]);
                
                // 3. Ajouter au projet FIAC
                // Chercher un projet FIAC actif
                $query_projet = "SELECT id_projet FROM projet_fiac WHERE statut = 'actif' LIMIT 1";
                $projet_result = $pdo->query($query_projet);
                $projet = $projet_result->fetch();
                
                if ($projet) {
                    // Mettre à jour le budget du projet
                    $query_update_projet = "
                        UPDATE projet_fiac 
                        SET montant_budget = montant_budget + ? 
                        WHERE id_projet = ?
                    ";
                    $stmt_update = $pdo->prepare($query_update_projet);
                    $stmt_update->execute([$montant_penalite, $projet['id_projet']]);
                } else {
                    // Créer un nouveau projet FIAC
                    $query_new_projet = "
                        INSERT INTO projet_fiac (nom_projet, description, montant_budget, date_debut, statut) 
                        VALUES ('Fonds Pénalités', 'Fonds généré par les pénalités de retard', ?, ?, 'actif')
                    ";
                    $stmt_new_projet = $pdo->prepare($query_new_projet);
                    $stmt_new_projet->execute([$montant_penalite, $date_paiement]);
                }
            }
            
            // 4. Mettre à jour le montant total de la séance
            $query_update_seance = "
                UPDATE seance 
                SET montant_total = COALESCE(montant_total, 0) + ? 
                WHERE id_seance = ?
            ";
            $stmt_update_seance = $pdo->prepare($query_update_seance);
            $stmt_update_seance->execute([$montant_total, $id_seance]);
            
            // Valider la transaction
            $pdo->commit();
            
            // Message de succès
            if ($penalite_appliquee) {
                $success_message = "✅ Cotisation payée avec succès !<br>
                                   <strong>Montant base:</strong> " . number_format($montant_base, 0, ',', ' ') . " FCFA<br>
                                   <strong>Pénalité (10%):</strong> " . number_format($montant_penalite, 0, ',', ' ') . " FCFA<br>
                                   <strong>Total payé:</strong> " . number_format($montant_total, 0, ',', ' ') . " FCFA<br>
                                   <em>La pénalité a été ajoutée aux projets FIAC.</em>";
            } else {
                $success_message = "✅ Cotisation payée avec succès !<br>
                                   <strong>Montant:</strong> " . number_format($montant_total, 0, ',', ' ') . " FCFA<br>
                                   <strong>Date:</strong> " . date('d/m/Y', strtotime($date_paiement));
            }
            
            // Réinitialiser les champs du formulaire
            $_POST = array();
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = "Erreur lors de l'enregistrement: " . $e->getMessage();
        }
    }
}

// Récupérer les tontines actives de l'utilisateur
try {
    $query_tontines = "
        SELECT t.* 
        FROM tontine t
        INNER JOIN participation_tontine pt ON t.id_tontine = pt.id_tontine
        WHERE pt.id_membre = ? 
        AND t.statut = 'active'
        AND pt.statut = 'active'
        ORDER BY t.nom_tontine
    ";
    $stmt_tontines = $pdo->prepare($query_tontines);
    $stmt_tontines->execute([$user_id]);
    $mes_tontines = $stmt_tontines->fetchAll();
    
} catch (PDOException $e) {
    $errors[] = "Erreur lors de la récupération des tontines: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paiement de Cotisation | Gestion de Tontine</title>

<script src="/register-sw.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #0f1a3a 0%, #1a2b55 100%);
            min-height: 100vh;
        }
        
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(99, 102, 241, 0.3);
        }
        
        .success-message {
            animation: slideIn 0.5s ease-out;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .warning-badge {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.7; }
            100% { opacity: 1; }
        }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen p-4">
    <div class="w-full max-w-2xl">
        <!-- Header -->
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-white mb-2">
                <i class="fas fa-money-bill-wave mr-2"></i>
                Paiement de Cotisation
            </h1>
            <p class="text-white/80 mb-4">Effectuez vos paiements de cotisation</p>
            <div class="inline-flex items-center gap-2 bg-white/10 backdrop-blur-sm px-4 py-2 rounded-full">
                <i class="fas fa-user text-white"></i>
                <span class="text-white font-medium"><?php echo htmlspecialchars($user['prenom'] . ' ' . $user['nom']); ?></span>
            </div>
        </div>

        <!-- Messages d'alerte -->
        <?php if (!empty($errors)): ?>
            <div class="mb-6">
                <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-r-lg">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-circle text-red-500 text-xl"></i>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-red-800">Erreurs :</h3>
                            <div class="mt-2 text-sm text-red-700">
                                <ul class="list-disc pl-5 space-y-1">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?php echo htmlspecialchars($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Message de succès -->
        <?php if ($success_message): ?>
            <div class="mb-6 success-message">
                <div class="bg-green-50 border-l-4 border-green-500 p-4 rounded-r-lg">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-check-circle text-green-500 text-2xl"></i>
                        </div>
                        <div class="ml-3">
                            <div class="text-sm font-medium text-green-800">
                                <?php echo $success_message; ?>
                            </div>
                            <div class="mt-3">
                                <a href="dashboard.php" class="inline-flex items-center gap-1 text-green-700 hover:text-green-800 font-medium">
                                    <i class="fas fa-arrow-left"></i>
                                    Retour au tableau de bord
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Formulaire principal -->
        <div class="glass-card p-6 md:p-8">
            <?php if (empty($mes_tontines)): ?>
                <div class="text-center py-8">
                    <i class="fas fa-info-circle text-blue-500 text-4xl mb-4"></i>
                    <h3 class="text-xl font-bold text-gray-800 mb-2">Aucune tontine active</h3>
                    <p class="text-gray-600">Vous ne participez à aucune tontine active pour le moment.</p>
                    <div class="mt-6">
                        <a href="dashboard.php" class="inline-flex items-center gap-2 px-6 py-3 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
                            <i class="fas fa-arrow-left"></i>
                            Retour au tableau de bord
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <form method="POST" action="" class="space-y-6" id="cotisationForm">
                    <!-- Sélection de la tontine -->
                    <div>
                        <label for="id_tontine" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-hand-holding-usd mr-1"></i> Sélectionner une tontine
                        </label>
                        <select id="id_tontine" name="id_tontine" required
                                class="w-full px-4 py-3 rounded-lg border-2 border-gray-200 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                            <option value="">-- Choisir une tontine --</option>
                            <?php foreach ($mes_tontines as $tontine): ?>
                                <option value="<?php echo $tontine['id_tontine']; ?>" 
                                        <?php echo isset($_POST['id_tontine']) && $_POST['id_tontine'] == $tontine['id_tontine'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($tontine['nom_tontine']); ?> 
                                    (<?php echo number_format($tontine['montant_cotisation'], 0, ',', ' '); ?> FCFA - <?php echo $tontine['frequence']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Sélection de la séance -->
                    <div>
                        <label for="id_seance" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-calendar-alt mr-1"></i> Sélectionner une séance
                        </label>
                        <select id="id_seance" name="id_seance" required disabled
                                class="w-full px-4 py-3 rounded-lg border-2 border-gray-200 bg-gray-50 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                            <option value="">-- Choisir d'abord une tontine --</option>
                        </select>
                    </div>

                    <!-- Date de paiement -->
                    <div>
                        <label for="date_paiement" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-calendar-day mr-1"></i> Date de paiement
                        </label>
                        <input type="date" id="date_paiement" name="date_paiement"
                               value="<?php echo isset($_POST['date_paiement']) ? $_POST['date_paiement'] : date('Y-m-d'); ?>"
                               required
                               max="<?php echo date('Y-m-d'); ?>"
                               class="w-full px-4 py-3 rounded-lg border-2 border-gray-200 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                        <p class="mt-1 text-xs text-gray-500">⚠️ Vous ne pouvez pas payer à l'avance. Date maximum: aujourd'hui</p>
                    </div>

                    <!-- Informations sur le paiement (affichage dynamique) -->
                    <div id="payment_info" class="hidden">
                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                            <h4 class="font-medium text-gray-800 mb-3">
                                <i class="fas fa-info-circle mr-2"></i>
                                Informations sur le paiement
                            </h4>
                            <div class="space-y-2">
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Tontine :</span>
                                    <span id="info_tontine" class="font-medium"></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Montant de base :</span>
                                    <span id="info_montant_base" class="font-medium text-green-600"></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Date de la séance :</span>
                                    <span id="info_date_seance" class="font-medium"></span>
                                </div>
                                <div id="info_retard" class="hidden">
                                    <div class="mt-3 p-3 bg-yellow-50 border-l-4 border-yellow-500 rounded-r">
                                        <div class="flex">
                                            <div class="flex-shrink-0">
                                                <i class="fas fa-exclamation-triangle text-yellow-500"></i>
                                            </div>
                                            <div class="ml-3">
                                                <p class="text-sm text-yellow-700">
                                                    <strong>⚠️ PAIEMENT EN RETARD</strong><br>
                                                    Une pénalité de 10% sera ajoutée au montant.
                                                </p>
                                                <div class="mt-1 text-sm">
                                                    <span>Jours de retard : </span>
                                                    <span id="info_jours_retard" class="font-bold"></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="info_penalite" class="hidden">
                                    <div class="flex justify-between pt-2 border-t border-gray-200">
                                        <span class="text-gray-600">Pénalité (10%) :</span>
                                        <span id="info_montant_penalite" class="font-medium text-red-600"></span>
                                    </div>
                                    <div class="flex justify-between font-bold">
                                        <span class="text-gray-800">TOTAL À PAYER :</span>
                                        <span id="info_total" class="text-lg text-green-700"></span>
                                    </div>
                                    <div class="mt-2 text-xs text-blue-600">
                                        <i class="fas fa-hand-holding-heart mr-1"></i>
                                        La pénalité sera versée aux projets FIAC
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Boutons d'action -->
                    <div class="flex flex-col md:flex-row gap-4 pt-6 border-t border-gray-200">
                        <button type="submit" id="submitBtn" disabled
                                class="btn-primary text-white px-6 py-3 rounded-lg font-medium flex-1 flex items-center justify-center gap-2 opacity-50 cursor-not-allowed">
                            <i class="fas fa-check-circle"></i>
                            Payer la cotisation
                        </button>
                        
                        <a href="dashboard.php"
                           class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-6 py-3 rounded-lg font-medium flex-1 flex items-center justify-center gap-2">
                            <i class="fas fa-times"></i>
                            Annuler
                        </a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const tontineSelect = document.getElementById('id_tontine');
            const seanceSelect = document.getElementById('id_seance');
            const datePaiementInput = document.getElementById('date_paiement');
            const paymentInfoDiv = document.getElementById('payment_info');
            const submitBtn = document.getElementById('submitBtn');
            
            // Informations à afficher
            const infoTontine = document.getElementById('info_tontine');
            const infoMontantBase = document.getElementById('info_montant_base');
            const infoDateSeance = document.getElementById('info_date_seance');
            const infoRetard = document.getElementById('info_retard');
            const infoPenalite = document.getElementById('info_penalite');
            const infoJoursRetard = document.getElementById('info_jours_retard');
            const infoMontantPenalite = document.getElementById('info_montant_penalite');
            const infoTotal = document.getElementById('info_total');
            
            // Charger les séances quand une tontine est sélectionnée
            tontineSelect.addEventListener('change', function() {
                const tontineId = this.value;
                
                if (tontineId) {
                    // Activer le champ séance
                    seanceSelect.disabled = false;
                    seanceSelect.classList.remove('bg-gray-50');
                    
                    // Charger les séances via AJAX
                    fetch('./fonction/get_seances.php?tontine_id=' + tontineId + '&user_id=<?php echo $user_id; ?>')
                        .then(response => response.json())
                        .then(data => {
                            seanceSelect.innerHTML = '<option value="">-- Choisir une séance --</option>';
                            
                            if (data.seances && data.seances.length > 0) {
                                data.seances.forEach(seance => {
                                    const option = document.createElement('option');
                                    option.value = seance.id_seance;
                                    option.textContent = 'Séance du ' + seance.date_format + 
                                                        ' - ' + formatMoney(seance.montant_cotisation) + ' FCFA';
                                    option.dataset.date = seance.date_seance;
                                    option.dataset.montant = seance.montant_cotisation;
                                    option.dataset.tontine = seance.nom_tontine;
                                    seanceSelect.appendChild(option);
                                });
                            } else {
                                const option = document.createElement('option');
                                option.value = '';
                                option.textContent = 'Aucune séance disponible';
                                seanceSelect.appendChild(option);
                            }
                            
                            paymentInfoDiv.classList.add('hidden');
                            submitBtn.disabled = true;
                            submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
                        })
                        .catch(error => {
                            console.error('Erreur:', error);
                            seanceSelect.innerHTML = '<option value="">Erreur de chargement</option>';
                        });
                } else {
                    // Désactiver le champ séance
                    seanceSelect.disabled = true;
                    seanceSelect.classList.add('bg-gray-50');
                    seanceSelect.innerHTML = '<option value="">-- Choisir d\'abord une tontine --</option>';
                    paymentInfoDiv.classList.add('hidden');
                    submitBtn.disabled = true;
                    submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
                }
            });
            
            // Mettre à jour les informations quand une séance est sélectionnée
            seanceSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                
                if (this.value && selectedOption.dataset.date) {
                    const dateSeance = selectedOption.dataset.date;
                    const montantBase = parseFloat(selectedOption.dataset.montant);
                    const nomTontine = selectedOption.dataset.tontine;
                    const datePaiement = datePaiementInput.value;
                    
                    // Calculer le retard
                    const joursRetard = calculateDaysDifference(dateSeance, datePaiement);
                    
                    // Afficher les informations
                    infoTontine.textContent = nomTontine;
                    infoMontantBase.textContent = formatMoney(montantBase) + ' FCFA';
                    infoDateSeance.textContent = formatDate(dateSeance);
                    
                    // Gérer les retards
                    if (joursRetard < 0) {
                        // Paiement à l'avance (non autorisé)
                        alert('❌ Vous ne pouvez pas payer à l\'avance. Le paiement doit être effectué le jour même de la séance.');
                        this.value = '';
                        paymentInfoDiv.classList.add('hidden');
                        submitBtn.disabled = true;
                        submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
                        return;
                    } else if (joursRetard > 0) {
                        // Paiement en retard
                        infoRetard.classList.remove('hidden');
                        infoPenalite.classList.remove('hidden');
                        infoJoursRetard.textContent = joursRetard + ' jour(s)';
                        
                        const penalite = montantBase * 0.10;
                        const total = montantBase + penalite;
                        
                        infoMontantPenalite.textContent = formatMoney(penalite) + ' FCFA';
                        infoTotal.textContent = formatMoney(total) + ' FCFA';
                    } else {
                        // Paiement à jour
                        infoRetard.classList.add('hidden');
                        infoPenalite.classList.add('hidden');
                        infoTotal.textContent = formatMoney(montantBase) + ' FCFA';
                    }
                    
                    paymentInfoDiv.classList.remove('hidden');
                    submitBtn.disabled = false;
                    submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                } else {
                    paymentInfoDiv.classList.add('hidden');
                    submitBtn.disabled = true;
                    submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
                }
            });
            
            // Mettre à jour les infos quand la date de paiement change
            datePaiementInput.addEventListener('change', function() {
                if (seanceSelect.value) {
                    seanceSelect.dispatchEvent(new Event('change'));
                }
            });
            
            // Empêcher la sélection d'une date future
            datePaiementInput.max = new Date().toISOString().split('T')[0];
            
            // Validation avant soumission
            document.getElementById('cotisationForm').addEventListener('submit', function(e) {
                const selectedSeance = seanceSelect.options[seanceSelect.selectedIndex];
                const dateSeance = selectedSeance.dataset.date;
                const datePaiement = datePaiementInput.value;
                
                if (dateSeance) {
                    const joursRetard = calculateDaysDifference(dateSeance, datePaiement);
                    
                    if (joursRetard < 0) {
                        e.preventDefault();
                        alert('❌ Vous ne pouvez pas payer à l\'avance. Le paiement doit être effectué le jour même de la séance ou en retard.');
                        return false;
                    }
                    
                    if (joursRetard > 0) {
                        const confirmMessage = "⚠️ ATTENTION : Cette séance est en retard.\n\n" +
                                             "Une pénalité de 10% sera ajoutée au montant et versée aux projets FIAC.\n\n" +
                                             "Confirmez-vous le paiement avec pénalité ?";
                        
                        if (!confirm(confirmMessage)) {
                            e.preventDefault();
                            return false;
                        }
                    }
                }
                
                // Afficher un message de confirmation
                alert('✅ Votre paiement est en cours de traitement...');
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Traitement en cours...';
            });
            
            // Fonctions utilitaires
            function calculateDaysDifference(date1, date2) {
                const d1 = new Date(date1);
                const d2 = new Date(date2);
                const diffTime = d2 - d1;
                return Math.floor(diffTime / (1000 * 60 * 60 * 24));
            }
            
            function formatDate(dateStr) {
                const date = new Date(dateStr);
                return date.toLocaleDateString('fr-FR', {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric'
                });
            }
            
            function formatMoney(amount) {
                return parseFloat(amount).toLocaleString('fr-FR', {
                    minimumFractionDigits: 0,
                    maximumFractionDigits: 0
                });
            }
        });
    </script>
</body>
</html>