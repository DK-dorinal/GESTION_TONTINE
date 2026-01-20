<?php
session_start();
@include './fonctions/config.php';

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

function validateDecimal($value)
{
    return preg_match('/^\d+(\.\d{1,2})?$/', $value);
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

// Déterminer l'onglet actif
$onglet_actif = sanitizeInput($_GET['onglet'] ?? 'a_payer');

// Traitement du paiement de cotisation
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['payer_cotisation'])) {
        $id_tontine = sanitizeInput($_POST['id_tontine'] ?? '');
        $id_seance = sanitizeInput($_POST['id_seance'] ?? '');
        $montant = sanitizeInput($_POST['montant'] ?? '');
        $date_paiement = sanitizeInput($_POST['date_paiement'] ?? date('Y-m-d'));

        $errors = [];

        if (empty($id_tontine)) $errors[] = "La tontine est obligatoire";
        if (empty($id_seance)) $errors[] = "La séance est obligatoire";
        if (empty($montant)) {
            $errors[] = "Le montant est obligatoire";
        } elseif (!validateDecimal($montant) || $montant <= 0) {
            $errors[] = "Le montant doit être un nombre positif";
        }

        if (empty($errors)) {
            try {
                // Vérifier si l'utilisateur a déjà payé pour cette séance
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM cotisation WHERE id_membre = ? AND id_seance = ?");
                $checkStmt->execute([$user_id, $id_seance]);

                if ($checkStmt->fetchColumn() > 0) {
                    $message = "Vous avez déjà payé cette cotisation";
                    $message_type = "warning";
                } else {
                    // Insérer la cotisation
                    $sql = "INSERT INTO cotisation (id_membre, id_seance, montant, date_paiement, statut) 
                            VALUES (?, ?, ?, ?, 'payé')";

                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$user_id, $id_seance, $montant, $date_paiement]);

                    if ($stmt->rowCount() > 0) {
                        $message = "Cotisation payée avec succès!";
                        $message_type = "success";
                        $onglet_actif = 'historique'; // Basculer vers l'onglet historique
                    } else {
                        $message = "Erreur lors du paiement";
                        $message_type = "error";
                    }
                }
            } catch (PDOException $e) {
                $message = "Erreur lors du paiement: " . $e->getMessage();
                $message_type = "error";
                error_log("Erreur paiement cotisation: " . $e->getMessage());
            }
        } else {
            $message = implode("<br>", $errors);
            $message_type = "error";
        }
    }
}

// CORRECTION: Récupérer les tontines auxquelles l'utilisateur participe
try {
    // Correction: Utiliser la table participation_tontine au lieu de beneficiaire
    $stmt = $pdo->prepare("SELECT DISTINCT t.* 
                          FROM tontine t 
                          INNER JOIN participation_tontine pt ON t.id_tontine = pt.id_tontine 
                          WHERE pt.id_membre = ? AND t.statut = 'active'
                          ORDER BY t.nom_tontine");
    $stmt->execute([$user_id]);
    $mes_tontines = $stmt->fetchAll();
} catch (PDOException $e) {
    $mes_tontines = [];
    error_log("Erreur récupération tontines: " . $e->getMessage());
}

// Récupérer les séances à payer (séances de mes tontines où je n'ai pas encore payé)
$seances_a_payer = [];
if (!empty($mes_tontines)) {
    $tontines_ids = array_column($mes_tontines, 'id_tontine');
    $placeholders = str_repeat('?,', count($tontines_ids) - 1) . '?';

    try {
        $sql = "SELECT s.*, t.nom_tontine, t.montant_cotisation 
                FROM seance s 
                INNER JOIN tontine t ON s.id_tontine = t.id_tontine 
                WHERE s.id_tontine IN ($placeholders) 
                  AND s.id_seance NOT IN (
                      SELECT id_seance FROM cotisation WHERE id_membre = ?
                  )
                ORDER BY s.date_seance ASC";

        $stmt = $pdo->prepare($sql);
        $params = array_merge($tontines_ids, [$user_id]);
        $stmt->execute($params);
        $seances_a_payer = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Erreur récupération séances à payer: " . $e->getMessage());
    }
}

// Récupérer l'historique des cotisations de l'utilisateur
try {
    $sql = "SELECT c.*, s.date_seance, t.nom_tontine, t.montant_cotisation 
            FROM cotisation c 
            INNER JOIN seance s ON c.id_seance = s.id_seance 
            INNER JOIN tontine t ON s.id_tontine = t.id_tontine 
            WHERE c.id_membre = ? 
            ORDER BY c.date_paiement DESC, c.id_cotisation DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $historique_cotisations = $stmt->fetchAll();
} catch (PDOException $e) {
    $historique_cotisations = [];
    error_log("Erreur récupération historique: " . $e->getMessage());
}

// NOUVEAU: Récupérer les dates où l'utilisateur est bénéficiaire
try {
    $sql_beneficiaire = "SELECT b.*, t.nom_tontine, s.date_seance 
                         FROM beneficiaire b 
                         INNER JOIN tontine t ON b.id_tontine = t.id_tontine 
                         INNER JOIN seance s ON b.id_tontine = s.id_tontine 
                         WHERE b.id_membre = ? 
                         AND s.id_beneficiaire = b.id_beneficiaire
                         ORDER BY b.date_gain DESC";

    $stmt_beneficiaire = $pdo->prepare($sql_beneficiaire);
    $stmt_beneficiaire->execute([$user_id]);
    $mes_benefices = $stmt_beneficiaire->fetchAll();
} catch (PDOException $e) {
    $mes_benefices = [];
    error_log("Erreur récupération bénéfices: " . $e->getMessage());
}

// NOUVEAU: Récupérer toutes les séances de mes tontines (pour information)
try {
    $sql_mes_seances = "SELECT s.*, t.nom_tontine, t.montant_cotisation,
                               b.id_membre as id_beneficiaire,
                               CASE 
                                   WHEN b.id_membre = ? THEN 'Oui' 
                                   ELSE 'Non' 
                               END as est_beneficiaire,
                               m.nom as nom_beneficiaire, 
                               m.prenom as prenom_beneficiaire
                        FROM seance s 
                        INNER JOIN tontine t ON s.id_tontine = t.id_tontine 
                        LEFT JOIN beneficiaire b ON s.id_beneficiaire = b.id_beneficiaire
                        LEFT JOIN membre m ON b.id_membre = m.id_membre
                        WHERE s.id_tontine IN (
                            SELECT id_tontine FROM participation_tontine WHERE id_membre = ?
                        )
                        ORDER BY s.date_seance DESC";

    $stmt_mes_seances = $pdo->prepare($sql_mes_seances);
    $stmt_mes_seances->execute([$user_id, $user_id]);
    $mes_seances = $stmt_mes_seances->fetchAll();
} catch (PDOException $e) {
    $mes_seances = [];
    error_log("Erreur récupération séances: " . $e->getMessage());
}

// Calculer les statistiques
$total_a_payer = 0;
foreach ($seances_a_payer as $seance) {
    $total_a_payer += $seance['montant_cotisation'];
}

$total_paye = 0;
foreach ($historique_cotisations as $cotisation) {
    $total_paye += $cotisation['montant'];
}

// NOUVEAU: Calculer les gains totaux comme bénéficiaire
$total_gagnes = 0;
foreach ($mes_benefices as $benefice) {
    $total_gagnes += $benefice['montant_gagne'];
}
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Cotisations | Système de Gestion</title>
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

        /* Navigation */
        .navigation {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .nav-link {
            padding: 12px 24px;
            background: var(--pure-white);
            border-radius: var(--border-radius);
            text-decoration: none;
            color: var(--text-dark);
            font-weight: 500;
            transition: var(--transition);
            box-shadow: 0 2px 8px var(--shadow-light);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .nav-link:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px var(--shadow-medium);
            background: var(--light-blue);
            color: var(--pure-white);
        }

        .nav-link.active {
            background: var(--light-blue);
            color: var(--pure-white);
        }

        /* User Info */
        .user-info-card {
            background: var(--pure-white);
            border-radius: var(--border-radius);
            padding: 25px;
            box-shadow: 0 5px 20px var(--shadow-light);
            display: flex;
            align-items: center;
            gap: 20px;
            animation: fadeInUp 0.8s;
        }

        .user-avatar {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--medium-blue) 0%, var(--light-blue) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--pure-white);
            font-size: 2rem;
            font-weight: bold;
        }

        .user-details h3 {
            font-size: 1.5rem;
            color: var(--navy-blue);
            margin-bottom: 5px;
        }

        .user-details p {
            color: var(--text-light);
            margin-bottom: 5px;
        }

        /* Tabs */
        .tabs-container {
            background: var(--pure-white);
            border-radius: var(--border-radius);
            box-shadow: 0 5px 20px var(--shadow-light);
            overflow: hidden;
            animation: fadeInUp 0.8s;
        }

        .tabs-header {
            display: flex;
            background: var(--bg-light);
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            flex-wrap: wrap;
        }

        .tab-button {
            padding: 20px 30px;
            background: none;
            border: none;
            font-family: "Poppins", sans-serif;
            font-size: 1rem;
            font-weight: 500;
            color: var(--text-light);
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 10px;
            position: relative;
        }

        .tab-button:hover {
            color: var(--medium-blue);
            background: rgba(58, 95, 192, 0.05);
        }

        .tab-button.active {
            color: var(--light-blue);
            background: var(--pure-white);
        }

        .tab-button.active::after {
            content: "";
            position: absolute;
            bottom: -1px;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--light-blue);
        }

        .tab-badge {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
            margin-left: 5px;
        }

        .badge-danger {
            background: #fecaca;
            color: #991b1b;
        }

        .badge-success {
            background: #bbf7d0;
            color: #166534;
        }

        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }

        .tab-content {
            padding: 30px;
            display: none;
        }

        .tab-content.active {
            display: block;
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

        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--pure-white);
            border-radius: var(--border-radius);
            padding: 20px;
            text-align: center;
            box-shadow: 0 3px 10px var(--shadow-light);
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px var(--shadow-medium);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            color: var(--navy-blue);
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--text-light);
        }

        /* Cotisation Cards */
        .cotisations-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
            margin-top: 20px;
        }

        @media (max-width: 768px) {
            .cotisations-grid {
                grid-template-columns: 1fr;
            }
        }

        .cotisation-card {
            background: var(--pure-white);
            border-radius: var(--border-radius);
            padding: 25px;
            box-shadow: 0 3px 15px var(--shadow-light);
            transition: var(--transition);
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .cotisation-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px var(--shadow-medium);
        }

        .cotisation-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .cotisation-name {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--navy-blue);
            margin-bottom: 5px;
        }

        .cotisation-details {
            margin-bottom: 20px;
        }

        .cotisation-detail {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .cotisation-detail i {
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

        .status-paye {
            background: rgba(34, 197, 94, 0.1);
            color: #166534;
        }

        .status-en-attente {
            background: rgba(245, 158, 11, 0.1);
            color: #92400e;
        }

        .status-en-retard {
            background: rgba(239, 68, 68, 0.1);
            color: #991b1b;
        }

        .status-beneficiaire {
            background: rgba(168, 85, 247, 0.1);
            color: #7c3aed;
        }

        /* Amount styling */
        .amount {
            font-weight: 600;
            color: var(--navy-blue);
        }

        .amount-positive {
            color: #10b981;
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

        /* Buttons */
        .btn {
            padding: 12px 24px;
            border-radius: var(--border-radius);
            font-family: "Poppins", sans-serif;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            text-align: center;
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
            color: var(--text-dark);
            border: 1px solid var(--bg-light);
        }

        .btn-secondary:hover {
            background: #e2e8f0;
            color: var(--text-dark);
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #f87171 100%);
            color: var(--pure-white);
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(239, 68, 68, 0.3);
        }

        .btn-purple {
            background: linear-gradient(135deg, #8b5cf6 0%, #a78bfa 100%);
            color: var(--pure-white);
        }

        .btn-purple:hover {
            background: linear-gradient(135deg, #7c3aed 0%, #8b5cf6 100%);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(139, 92, 246, 0.3);
        }

        .btn-group {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            align-items: center;
            flex-wrap: wrap;
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

        .message.info {
            background: rgba(59, 130, 246, 0.1);
            color: #1e40af;
            border-left: 4px solid #3b82f6;
        }

        /* Table Styles */
        .table-container {
            overflow-x: auto;
            border-radius: var(--border-radius);
            box-shadow: 0 2px 10px var(--shadow-light);
            margin-top: 20px;
        }

        .cotisations-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        .cotisations-table thead {
            background: linear-gradient(135deg, var(--navy-blue) 0%, var(--dark-blue) 100%);
        }

        .cotisations-table th {
            padding: 18px 20px;
            text-align: left;
            font-weight: 500;
            color: var(--pure-white);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .cotisations-table tbody tr {
            border-bottom: 1px solid var(--bg-light);
            transition: var(--transition);
        }

        .cotisations-table tbody tr:hover {
            background: var(--bg-light);
        }

        .cotisations-table td {
            padding: 18px 20px;
            color: var(--text-dark);
            font-size: 0.9rem;
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

        /* Alert cards */
        .alert-card {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1) 0%, rgba(254, 202, 202, 0.1) 100%);
            border-left: 4px solid #ef4444;
            padding: 20px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
        }

        .alert-card.warning {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1) 0%, rgba(254, 243, 199, 0.1) 100%);
            border-left-color: #f59e0b;
        }

        .alert-card.success {
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.1) 0%, rgba(220, 252, 231, 0.1) 100%);
            border-left-color: #22c55e;
        }

        .alert-card.info {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1) 0%, rgba(219, 234, 254, 0.1) 100%);
            border-left-color: #3b82f6;
        }

        /* Payment form */
        .payment-form {
            background: linear-gradient(135deg, rgba(248, 250, 252, 0.9) 0%, rgba(241, 245, 249, 0.9) 100%);
            border-radius: var(--border-radius);
            padding: 25px;
            margin-top: 20px;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        /* Benefice card */
        .benefice-card {
            background: linear-gradient(135deg, rgba(168, 85, 247, 0.05) 0%, rgba(221, 214, 254, 0.1) 100%);
            border-left: 4px solid #8b5cf6;
            padding: 20px;
            border-radius: var(--border-radius);
            margin-bottom: 15px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .dashboard-header {
                padding: 30px 20px;
            }

            .dashboard-header h1 {
                font-size: 2rem;
            }

            .tabs-header {
                flex-direction: column;
            }

            .tab-button {
                padding: 15px;
                justify-content: center;
            }

            .tab-content {
                padding: 20px;
            }

            .btn-group {
                flex-direction: column;
            }

            .btn-group .btn {
                width: 100%;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .user-info-card {
                flex-direction: column;
                text-align: center;
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

            .cotisation-card {
                padding: 20px;
            }

            .btn {
                padding: 10px 15px;
                font-size: 0.85rem;
            }
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

        /* Chip for status */
        .chip {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .chip-success {
            background: #dcfce7;
            color: #166534;
        }

        .chip-warning {
            background: #fef3c7;
            color: #92400e;
        }

        .chip-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .chip-info {
            background: #dbeafe;
            color: #1e40af;
        }
    </style>
</head>

<body>
    <div class="dashboard-container">
        <!-- Header -->
        <header class="dashboard-header animate__animated animate__fadeInDown">
            <h1>Gestion des Cotisations</h1>
            <p class="dashboard-subtitle">Payez vos cotisations et consultez votre historique</p>
        </header>

        <!-- Navigation -->
        <div class="navigation">
            <a href="dashboard.php" class="nav-link">
                <i class="fas fa-tachometer-alt"></i> Tableau de Bord
            </a>
            <a href="participer_tontine.php" class="nav-link">
                <i class="fas fa-hand-holding-usd"></i> Mes Tontines
            </a>
            <a href="cotisation.php?onglet=a_payer" class="nav-link <?php echo $onglet_actif === 'a_payer' ? 'active' : ''; ?>">
                <i class="fas fa-money-bill-wave"></i> Cotisations à Payer
            </a>
            <a href="cotisation.php?onglet=historique" class="nav-link <?php echo $onglet_actif === 'historique' ? 'active' : ''; ?>">
                <i class="fas fa-history"></i> Historique
            </a>
            <a href="cotisation.php?onglet=benefices" class="nav-link <?php echo $onglet_actif === 'benefices' ? 'active' : ''; ?>">
                <i class="fas fa-trophy"></i> Mes Bénéfices
            </a>
        </div>

        <!-- User Info -->
        <div class="user-info-card animate__animated animate__fadeInUp">
            <div class="user-avatar">
                <?php echo strtoupper(substr($user['prenom'], 0, 1) . substr($user['nom'], 0, 1)); ?>
            </div>
            <div class="user-details">
                <h3><?php echo htmlspecialchars($user['prenom'] . ' ' . $user['nom']); ?></h3>
                <p><i class="fas fa-phone"></i> <?php echo htmlspecialchars($user['telephone']); ?></p>
                <p><i class="fas fa-hand-holding-usd"></i> Membre de <?php echo count($mes_tontines); ?> tontine(s)</p>
                <?php if (count($mes_benefices) > 0): ?>
                    <p><i class="fas fa-trophy"></i> Bénéficiaire <?php echo count($mes_benefices); ?> fois</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?> animate__animated animate__fadeIn">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'error' ? 'exclamation-circle' : ($message_type === 'warning' ? 'exclamation-triangle' : 'info-circle')); ?>"></i>
                <span><?php echo $message; ?></span>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-grid animate__animated animate__fadeInUp">
            <div class="stat-card">
                <div class="stat-value"><?php echo count($mes_tontines); ?></div>
                <div class="stat-label">Tontines Actives</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo count($seances_a_payer); ?></div>
                <div class="stat-label">Séances à Payer</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($total_a_payer, 0, ',', ' '); ?> FCFA</div>
                <div class="stat-label">Total à Payer</div>
            </div>
            <div class="stat-card">
                <div class="stat-value amount-positive">+<?php echo number_format($total_gagnes, 0, ',', ' '); ?> FCFA</div>
                <div class="stat-label">Gains Totaux</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($total_paye, 0, ',', ' '); ?> FCFA</div>
                <div class="stat-label">Total Payé</div>
            </div>
        </div>

        <!-- Tabs Container -->
        <div class="tabs-container">
            <div class="tabs-header">
                <button class="tab-button <?php echo $onglet_actif === 'a_payer' ? 'active' : ''; ?>" data-tab="a_payer">
                    <i class="fas fa-money-bill-wave"></i>
                    <span>À Payer</span>
                    <?php if (count($seances_a_payer) > 0): ?>
                        <span class="tab-badge badge-danger"><?php echo count($seances_a_payer); ?></span>
                    <?php endif; ?>
                </button>
                <button class="tab-button <?php echo $onglet_actif === 'historique' ? 'active' : ''; ?>" data-tab="historique">
                    <i class="fas fa-history"></i>
                    <span>Historique</span>
                    <?php if (count($historique_cotisations) > 0): ?>
                        <span class="tab-badge badge-success"><?php echo count($historique_cotisations); ?></span>
                    <?php endif; ?>
                </button>
                <button class="tab-button <?php echo $onglet_actif === 'benefices' ? 'active' : ''; ?>" data-tab="benefices">
                    <i class="fas fa-trophy"></i>
                    <span>Mes Bénéfices</span>
                    <?php if (count($mes_benefices) > 0): ?>
                        <span class="tab-badge badge-warning"><?php echo count($mes_benefices); ?></span>
                    <?php endif; ?>
                </button>
            </div>

            <!-- Onglet: À Payer -->
            <div id="tab-a_payer" class="tab-content <?php echo $onglet_actif === 'a_payer' ? 'active' : ''; ?>">
                <div class="dashboard-card">
                    <div class="card-header">
                        <div class="card-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <h2 class="card-title">Cotisations en Attente de Paiement</h2>
                    </div>

                    <?php if (count($seances_a_payer) > 0): ?>
                        <?php if (count($seances_a_payer) > 3): ?>
                            <div class="alert-card warning">
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <i class="fas fa-exclamation-triangle" style="color: #f59e0b; font-size: 1.5rem;"></i>
                                    <div>
                                        <strong>Attention : <?php echo count($seances_a_payer); ?> cotisations en attente</strong>
                                        <p style="margin-top: 5px; font-size: 0.9rem;">Vous avez plusieurs cotisations en retard. Veuillez les régulariser au plus vite.</p>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="cotisations-grid">
                            <?php foreach ($seances_a_payer as $seance):
                                // Déterminer si la séance est en retard
                                $date_seance = new DateTime($seance['date_seance']);
                                $today = new DateTime();
                                $is_en_retard = $date_seance < $today;
                            ?>
                                <div class="cotisation-card">
                                    <div class="cotisation-header">
                                        <div>
                                            <div class="cotisation-name"><?php echo htmlspecialchars($seance['nom_tontine']); ?></div>
                                            <span class="status-badge <?php echo $is_en_retard ? 'status-en-retard' : 'status-en-attente'; ?>">
                                                <?php echo $is_en_retard ? 'En retard' : 'À payer'; ?>
                                            </span>
                                        </div>
                                    </div>

                                    <div class="cotisation-details">
                                        <div class="cotisation-detail">
                                            <i class="fas fa-calendar-alt"></i>
                                            <span>Date de la séance: <strong><?php echo date('d/m/Y', strtotime($seance['date_seance'])); ?></strong></span>
                                        </div>
                                        <div class="cotisation-detail">
                                            <i class="fas fa-money-bill-wave"></i>
                                            <span>Montant dû: <strong class="amount"><?php echo number_format($seance['montant_cotisation'], 0, ',', ' '); ?> FCFA</strong></span>
                                        </div>
                                        <?php if ($is_en_retard): ?>
                                            <div class="cotisation-detail">
                                                <i class="fas fa-exclamation-triangle"></i>
                                                <span style="color: #ef4444;">En retard depuis: <strong><?php echo $date_seance->diff($today)->days; ?> jour(s)</strong></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <form method="POST" action="" class="payment-form">
                                        <input type="hidden" name="id_tontine" value="<?php echo $seance['id_tontine']; ?>">
                                        <input type="hidden" name="id_seance" value="<?php echo $seance['id_seance']; ?>">
                                        <input type="hidden" name="montant" value="<?php echo $seance['montant_cotisation']; ?>">

                                        <div class="form-group">
                                            <label for="date_paiement_<?php echo $seance['id_seance']; ?>">Date de paiement:</label>
                                            <input type="date"
                                                id="date_paiement_<?php echo $seance['id_seance']; ?>"
                                                name="date_paiement"
                                                class="form-control"
                                                value="<?php echo date('Y-m-d'); ?>"
                                                required>
                                        </div>

                                        <div class="btn-group">
                                            <button type="submit" name="payer_cotisation" class="btn btn-primary">
                                                <i class="fas fa-credit-card"></i> Payer <?php echo number_format($seance['montant_cotisation'], 0, ',', ' '); ?> FCFA
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <h3>Aucune cotisation à payer</h3>
                            <p>Vous êtes à jour dans toutes vos tontines. Bravo!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Onglet: Historique -->
            <div id="tab-historique" class="tab-content <?php echo $onglet_actif === 'historique' ? 'active' : ''; ?>">
                <div class="dashboard-card">
                    <div class="card-header">
                        <div class="card-icon">
                            <i class="fas fa-history"></i>
                        </div>
                        <h2 class="card-title">Historique des Cotisations</h2>
                    </div>

                    <?php if (count($historique_cotisations) > 0): ?>
                        <div class="table-container">
                            <table class="cotisations-table">
                                <thead>
                                    <tr>
                                        <th>Date Paiement</th>
                                        <th>Tontine</th>
                                        <th>Séance</th>
                                        <th>Montant</th>
                                        <th>Statut</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($historique_cotisations as $cotisation): ?>
                                        <tr>
                                            <td>
                                                <i class="fas fa-calendar-check" style="color: var(--text-light); margin-right: 5px;"></i>
                                                <?php echo date('d/m/Y', strtotime($cotisation['date_paiement'])); ?>
                                            </td>
                                            <td>
                                                <div style="font-weight: 500; color: var(--navy-blue);">
                                                    <?php echo htmlspecialchars($cotisation['nom_tontine']); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <i class="fas fa-calendar-alt" style="color: var(--text-light); margin-right: 5px;"></i>
                                                <?php echo date('d/m/Y', strtotime($cotisation['date_seance'])); ?>
                                            </td>
                                            <td>
                                                <strong class="amount"><?php echo number_format($cotisation['montant'], 0, ',', ' '); ?> FCFA</strong>
                                            </td>
                                            <td>
                                                <span class="status-badge status-paye">
                                                    <i class="fas fa-check-circle"></i> Payé
                                                </span>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-secondary" onclick="showReceipt(<?php echo $cotisation['id_cotisation']; ?>)">
                                                    <i class="fas fa-receipt"></i> Reçu
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Summary -->
                        <div class="stats-grid" style="margin-top: 30px;">
                            <div class="stat-card">
                                <div class="stat-value"><?php echo count($historique_cotisations); ?></div>
                                <div class="stat-label">Cotisations Payées</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value"><?php echo number_format($total_paye, 0, ',', ' '); ?> FCFA</div>
                                <div class="stat-label">Total Dépensé</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value">
                                    <?php
                                    $current_year = date('Y');
                                    $year_total = 0;
                                    foreach ($historique_cotisations as $cotisation) {
                                        if (date('Y', strtotime($cotisation['date_paiement'])) == $current_year) {
                                            $year_total += $cotisation['montant'];
                                        }
                                    }
                                    echo number_format($year_total, 0, ',', ' ') . ' FCFA';
                                    ?>
                                </div>
                                <div class="stat-label">Total <?php echo $current_year; ?></div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-history"></i>
                            <h3>Aucune cotisation payée</h3>
                            <p>Votre historique de cotisations apparaîtra ici après vos premiers paiements.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- NOUVEAU Onglet: Mes Bénéfices -->
            <div id="tab-benefices" class="tab-content <?php echo $onglet_actif === 'benefices' ? 'active' : ''; ?>">
                <div class="dashboard-card">
                    <div class="card-header">
                        <div class="card-icon">
                            <i class="fas fa-trophy"></i>
                        </div>
                        <h2 class="card-title">Mes Gains et Bénéfices</h2>
                    </div>

                    <?php if (count($mes_benefices) > 0): ?>
                        <div class="alert-card success">
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <i class="fas fa-trophy" style="color: #22c55e; font-size: 1.5rem;"></i>
                                <div>
                                    <strong>Félicitations !</strong>
                                    <p style="margin-top: 5px; font-size: 0.9rem;">Vous avez été bénéficiaire <?php echo count($mes_benefices); ?> fois pour un total de <?php echo number_format($total_gagnes, 0, ',', ' '); ?> FCFA.</p>
                                </div>
                            </div>
                        </div>

                        <div class="cotisations-grid">
                            <?php foreach ($mes_benefices as $benefice): ?>
                                <div class="cotisation-card">
                                    <div class="cotisation-header">
                                        <div>
                                            <div class="cotisation-name"><?php echo htmlspecialchars($benefice['nom_tontine']); ?></div>
                                            <span class="status-badge status-beneficiaire">
                                                <i class="fas fa-trophy"></i> Gagnant
                                            </span>
                                        </div>
                                    </div>

                                    <div class="cotisation-details">
                                        <div class="cotisation-detail">
                                            <i class="fas fa-calendar-alt"></i>
                                            <span>Date du gain: <strong><?php echo date('d/m/Y', strtotime($benefice['date_gain'])); ?></strong></span>
                                        </div>
                                        <div class="cotisation-detail">
                                            <i class="fas fa-calendar-check"></i>
                                            <span>Séance du: <strong><?php echo date('d/m/Y', strtotime($benefice['date_seance'])); ?></strong></span>
                                        </div>
                                        <div class="cotisation-detail">
                                            <i class="fas fa-money-bill-wave"></i>
                                            <span>Montant gagné: <strong class="amount amount-positive"><?php echo number_format($benefice['montant_gagne'], 0, ',', ' '); ?> FCFA</strong></span>
                                        </div>
                                    </div>

                                    <div class="btn-group">
                                        <button type="button" class="btn btn-purple" onclick="showBeneficeDetails(<?php echo $benefice['id_beneficiaire']; ?>)">
                                            <i class="fas fa-info-circle"></i> Détails
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Résumé des gains -->
                        <div class="stats-grid" style="margin-top: 30px;">
                            <div class="stat-card">
                                <div class="stat-value"><?php echo count($mes_benefices); ?></div>
                                <div class="stat-label">Gains Totaux</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value amount-positive"><?php echo number_format($total_gagnes, 0, ',', ' '); ?> FCFA</div>
                                <div class="stat-label">Montant Total Gagné</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value">
                                    <?php
                                    $current_year = date('Y');
                                    $year_gains = 0;
                                    foreach ($mes_benefices as $benefice) {
                                        if (date('Y', strtotime($benefice['date_gain'])) == $current_year) {
                                            $year_gains += $benefice['montant_gagne'];
                                        }
                                    }
                                    echo number_format($year_gains, 0, ',', ' ') . ' FCFA';
                                    ?>
                                </div>
                                <div class="stat-label">Gains <?php echo $current_year; ?></div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-trophy"></i>
                            <h3>Vous n'avez pas encore gagné</h3>
                            <p>Vos gains apparaîtront ici lorsque vous serez bénéficiaire d'une tontine.</p>
                            <div class="btn-group" style="justify-content: center; margin-top: 20px;">
                                <a href="participer_tontine.php" class="btn btn-primary">
                                    <i class="fas fa-hand-holding-usd"></i> Participer à une tontine
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Section: Toutes mes séances -->
                    <div style="margin-top: 40px;">
                        <div class="card-header">
                            <div class="card-icon">
                                <i class="fas fa-list"></i>
                            </div>
                            <h2 class="card-title">Toutes mes séances de tontine</h2>
                        </div>

                        <?php if (count($mes_seances) > 0): ?>
                            <div class="table-container">
                                <table class="cotisations-table">
                                    <thead>
                                        <tr>
                                            <th>Date Séance</th>
                                            <th>Tontine</th>
                                            <th>Montant Cotisation</th>
                                            <th>Bénéficiaire</th>
                                            <th>Statut</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($mes_seances as $seance):
                                            // Vérifier si l'utilisateur a payé cette séance
                                            $stmt_paye = $pdo->prepare("SELECT COUNT(*) FROM cotisation WHERE id_membre = ? AND id_seance = ?");
                                            $stmt_paye->execute([$user_id, $seance['id_seance']]);
                                            $est_payee = $stmt_paye->fetchColumn() > 0;

                                            $est_beneficiaire = $seance['id_beneficiaire'] == $user_id;
                                        ?>
                                            <tr>
                                                <td>
                                                    <i class="fas fa-calendar-alt" style="color: var(--text-light); margin-right: 5px;"></i>
                                                    <?php echo date('d/m/Y', strtotime($seance['date_seance'])); ?>
                                                </td>
                                                <td>
                                                    <div style="font-weight: 500; color: var(--navy-blue);">
                                                        <?php echo htmlspecialchars($seance['nom_tontine']); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <strong><?php echo number_format($seance['montant_cotisation'], 0, ',', ' '); ?> FCFA</strong>
                                                </td>
                                                <td>
                                                    <?php if ($seance['id_beneficiaire']): ?>
                                                        <?php if ($est_beneficiaire): ?>
                                                            <span class="chip chip-success">
                                                                <i class="fas fa-user"></i> Vous
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="chip chip-info">
                                                                <i class="fas fa-user"></i> <?php echo htmlspecialchars($seance['prenom_beneficiaire'] . ' ' . $seance['nom_beneficiaire']); ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="chip chip-warning">
                                                            <i class="fas fa-question"></i> À déterminer
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($est_payee): ?>
                                                        <span class="chip chip-success">
                                                            <i class="fas fa-check"></i> Payé
                                                        </span>
                                                    <?php else: ?>
                                                        <?php
                                                        $date_seance = new DateTime($seance['date_seance']);
                                                        $today = new DateTime();
                                                        if ($date_seance < $today) {
                                                            echo '<span class="chip chip-danger"><i class="fas fa-exclamation"></i> En retard</span>';
                                                        } else {
                                                            echo '<span class="chip chip-warning"><i class="fas fa-clock"></i> À payer</span>';
                                                        }
                                                        ?>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-calendar-times"></i>
                                <h3>Aucune séance trouvée</h3>
                                <p>Vous ne participez à aucune tontine ou aucune séance n'a été planifiée.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function changeTab(tabName) {
            // Mettre à jour l'URL sans recharger la page
            const url = new URL(window.location);
            url.searchParams.set('onglet', tabName);
            window.history.pushState({}, '', url);

            // Changer l'onglet actif
            $('.tab-button').removeClass('active');
            $('.tab-content').removeClass('active');

            $(`.tab-button[data-tab="${tabName}"]`).addClass('active');
            $(`#tab-${tabName}`).addClass('active');

            // Mettre à jour la navigation
            $('.nav-link').removeClass('active');
            $(`.nav-link[href*="onglet=${tabName}"]`).addClass('active');
        }

        function showReceipt(cotisationId) {
            Swal.fire({
                title: 'Reçu de Paiement',
                html: `
                    <div style="text-align: center; padding: 20px;">
                        <i class="fas fa-receipt" style="font-size: 3rem; color: #3a5fc0; margin-bottom: 15px;"></i>
                        <h3 style="color: var(--navy-blue); margin-bottom: 10px;">Reçu de Cotisation</h3>
                        <p style="color: var(--text-light); margin-bottom: 5px;">ID: <strong>#${cotisationId}</strong></p>
                        <p style="color: var(--text-light);">Ce reçu confirme le paiement de votre cotisation.</p>
                        <div style="margin-top: 20px; padding: 15px; background: #f8fafc; border-radius: 8px;">
                            <p style="margin: 5px 0;">Conservez ce reçu comme preuve de paiement.</p>
                        </div>
                    </div>
                `,
                showCloseButton: true,
                showCancelButton: true,
                focusConfirm: false,
                confirmButtonText: 'Imprimer',
                cancelButtonText: 'Fermer',
                confirmButtonColor: '#3a5fc0',
            }).then((result) => {
                if (result.isConfirmed) {
                    window.print();
                }
            });
        }

        function showBeneficeDetails(beneficeId) {
            Swal.fire({
                title: 'Détails du Gain',
                html: `
                    <div style="text-align: center; padding: 20px;">
                        <i class="fas fa-trophy" style="font-size: 3rem; color: #8b5cf6; margin-bottom: 15px;"></i>
                        <h3 style="color: var(--navy-blue); margin-bottom: 10px;">Félicitations !</h3>
                        <p style="color: var(--text-light);">Vous avez été sélectionné comme bénéficiaire de cette tontine.</p>
                        <div style="margin-top: 20px; padding: 15px; background: linear-gradient(135deg, rgba(168, 85, 247, 0.1) 0%, rgba(221, 214, 254, 0.1) 100%); border-radius: 8px;">
                            <p style="margin: 5px 0; color: #7c3aed;">
                                <i class="fas fa-info-circle"></i> Le montant gagné correspond au total des cotisations de la séance.
                            </p>
                        </div>
                    </div>
                `,
                showCloseButton: true,
                showCancelButton: false,
                focusConfirm: false,
                confirmButtonText: 'Fermer',
                confirmButtonColor: '#8b5cf6',
            });
        }

        $(document).ready(function() {
            // Gestion des onglets
            $('.tab-button').on('click', function() {
                const tabName = $(this).data('tab');
                changeTab(tabName);
            });

            // Animation des cartes
            $('.cotisation-card').each(function(index) {
                $(this).css('animation-delay', (index * 0.1) + 's');
                $(this).addClass('animate__animated animate__fadeIn');
            });

            // Confirmation avant paiement
            $('.payment-form').on('submit', function(e) {
                e.preventDefault();

                const form = this;
                const montant = $(this).find('input[name="montant"]').val();
                const tontineName = $(this).closest('.cotisation-card').find('.cotisation-name').text();

                Swal.fire({
                    title: 'Confirmer le paiement',
                    html: `Voulez-vous confirmer le paiement de <strong>${parseInt(montant).toLocaleString('fr-FR')} FCFA</strong> pour la tontine <strong>${tontineName}</strong> ?`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Oui, payer',
                    cancelButtonText: 'Annuler',
                    confirmButtonColor: '#3a5fc0',
                    cancelButtonColor: '#64748b',
                    reverseButtons: true
                }).then((result) => {
                    if (result.isConfirmed) {
                        form.submit();
                    }
                });
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

            // Gestion de l'historique du navigateur
            window.onpopstate = function(event) {
                const urlParams = new URLSearchParams(window.location.search);
                const onglet = urlParams.get('onglet') || 'a_payer';
                changeTab(onglet);
            };

            // Définir la date d'aujourd'hui par défaut pour tous les champs date
            const today = new Date().toISOString().split('T')[0];
            $('input[type="date"]').each(function() {
                if (!$(this).val()) {
                    $(this).val(today);
                }
            });
        });
    </script>
</body>

</html>