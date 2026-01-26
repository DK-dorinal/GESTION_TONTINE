<?php
session_start();
@include './fonctions/config.php';

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
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

// Fonction pour désigner un bénéficiaire aléatoire pour une séance
function designerBeneficiaireAleatoire($pdo, $id_tontine, $date_seance) {
    try {
        // Vérifier si la date est valide
        if (!$date_seance || $date_seance === '0000-00-00') {
            return false;
        }
        
        // Récupérer tous les participants de la tontine
        $stmt = $pdo->prepare("
            SELECT pt.id_membre 
            FROM participation_tontine pt
            WHERE pt.id_tontine = ? 
            AND pt.statut = 'active'
        ");
        $stmt->execute([$id_tontine]);
        $participants = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($participants)) {
            return false;
        }
        
        // Choisir un bénéficiaire aléatoire
        $random_index = array_rand($participants);
        $id_beneficiaire = $participants[$random_index];
        
        // Récupérer le montant total de la séance
        $stmt = $pdo->prepare("
            SELECT t.montant_cotisation, 
                   (SELECT COUNT(*) FROM participation_tontine WHERE id_tontine = ? AND statut = 'active') as nb_participants
            FROM tontine t 
            WHERE t.id_tontine = ?
        ");
        $stmt->execute([$id_tontine, $id_tontine]);
        $tontine_info = $stmt->fetch();
        
        if (!$tontine_info) {
            return false;
        }
        
        $montant_total = $tontine_info['montant_cotisation'] * $tontine_info['nb_participants'];
        
        // Vérifier si un bénéficiaire existe déjà pour cette tontine et cette date
        $check_stmt = $pdo->prepare("
            SELECT id_beneficiaire 
            FROM beneficiaire 
            WHERE id_tontine = ?
            AND DATE(date_gain) = DATE(?)
        ");
        $check_stmt->execute([$id_tontine, $date_seance]);
        
        if ($check_stmt->rowCount() == 0) {
            // Insérer un nouveau bénéficiaire
            $insert_stmt = $pdo->prepare("
                INSERT INTO beneficiaire (id_membre, id_tontine, montant_gagne, date_gain)
                VALUES (?, ?, ?, ?)
            ");
            $insert_stmt->execute([$id_beneficiaire, $id_tontine, $montant_total, $date_seance]);
            
            return $id_beneficiaire;
        }
        
        return $check_stmt->fetchColumn();
        
    } catch (PDOException $e) {
        error_log("Erreur désignation bénéficiaire: " . $e->getMessage());
        return false;
    }
}

// Fonction pour récupérer le bénéficiaire d'une séance
function getBeneficiaireSeance($pdo, $id_tontine, $date_seance) {
    try {
        // Vérifier si la date est valide
        if (!$date_seance || $date_seance === '0000-00-00') {
            return null;
        }
        
        $stmt = $pdo->prepare("
            SELECT b.id_membre, b.montant_gagne, b.date_gain,
                   m.nom, m.prenom
            FROM beneficiaire b
            INNER JOIN membre m ON b.id_membre = m.id_membre
            WHERE b.id_tontine = ?
            AND DATE(b.date_gain) = DATE(?)
            LIMIT 1
        ");
        $stmt->execute([$id_tontine, $date_seance]);
        
        if ($stmt->rowCount() > 0) {
            return $stmt->fetch();
        }
        
        return null;
    } catch (PDOException $e) {
        error_log("Erreur récupération bénéficiaire: " . $e->getMessage());
        return null;
    }
}

// Récupérer les informations de l'utilisateur
$stmt = $pdo->prepare("SELECT * FROM membre WHERE id_membre = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header('Location: index.php');
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
                    $_SESSION['flash_message'] = "Vous avez déjà payé cette cotisation";
                    $_SESSION['flash_type'] = "warning";
                } else {
                    // Insérer la cotisation
                    $sql = "INSERT INTO cotisation (id_membre, id_seance, montant, date_paiement, statut) 
                            VALUES (?, ?, ?, ?, 'payé')";

                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$user_id, $id_seance, $montant, $date_paiement]);

                    if ($stmt->rowCount() > 0) {
                        $_SESSION['flash_message'] = "Cotisation payée avec succès!";
                        $_SESSION['flash_type'] = "success";
                        $onglet_actif = 'historique'; // Basculer vers l'onglet historique
                    } else {
                        $_SESSION['flash_message'] = "Erreur lors du paiement";
                        $_SESSION['flash_type'] = "error";
                    }
                }
            } catch (PDOException $e) {
                $_SESSION['flash_message'] = "Erreur lors du paiement: " . $e->getMessage();
                $_SESSION['flash_type'] = "error";
                error_log("Erreur paiement cotisation: " . $e->getMessage());
            }
        } else {
            $_SESSION['flash_message'] = implode("<br>", $errors);
            $_SESSION['flash_type'] = "error";
        }
        
        header("Location: " . $_SERVER['PHP_SELF'] . "?onglet=" . $onglet_actif);
        exit();
    }
}

// Récupérer les messages flash
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $message_type = $_SESSION['flash_type'];
    unset($_SESSION['flash_message']);
    unset($_SESSION['flash_type']);
}

// Récupérer les tontines auxquelles l'utilisateur participe
try {
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

// Récupérer les séances à payer
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
        
        // Pour chaque séance, récupérer ou désigner le bénéficiaire
        foreach ($seances_a_payer as &$seance) {
            // Vérifier si la date est valide avant de faire des opérations
            if (isset($seance['date_seance']) && $seance['date_seance'] && $seance['date_seance'] !== '0000-00-00') {
                // Désigner un bénéficiaire aléatoire si la date de séance est passée et qu'il n'y a pas encore de bénéficiaire
                if ($seance['date_seance'] <= date('Y-m-d')) {
                    $beneficiaire = getBeneficiaireSeance($pdo, $seance['id_tontine'], $seance['date_seance']);
                    
                    if (!$beneficiaire) {
                        $id_beneficiaire = designerBeneficiaireAleatoire($pdo, $seance['id_tontine'], $seance['date_seance']);
                        if ($id_beneficiaire) {
                            $beneficiaire = getBeneficiaireSeance($pdo, $seance['id_tontine'], $seance['date_seance']);
                        }
                    }
                    
                    if ($beneficiaire) {
                        $seance['beneficiaire_id'] = $beneficiaire['id_membre'];
                        $seance['beneficiaire_nom'] = $beneficiaire['nom'];
                        $seance['beneficiaire_prenom'] = $beneficiaire['prenom'];
                        $seance['montant_gagne'] = $beneficiaire['montant_gagne'];
                    }
                }
            }
        }
        unset($seance);
        
    } catch (PDOException $e) {
        error_log("Erreur récupération séances à payer: " . $e->getMessage());
    }
}

// Récupérer l'historique des cotisations
try {
    $sql = "SELECT c.*, s.date_seance, t.nom_tontine, t.montant_cotisation, s.id_tontine
            FROM cotisation c 
            INNER JOIN seance s ON c.id_seance = s.id_seance 
            INNER JOIN tontine t ON s.id_tontine = t.id_tontine 
            WHERE c.id_membre = ? 
            ORDER BY c.date_paiement DESC, c.id_cotisation DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $historique_cotisations = $stmt->fetchAll();
    
    // Pour chaque cotisation, récupérer le bénéficiaire de la séance
    foreach ($historique_cotisations as &$cotisation) {
        // Vérifier si la date est valide
        if (isset($cotisation['date_seance']) && $cotisation['date_seance'] && $cotisation['date_seance'] !== '0000-00-00') {
            $beneficiaire = getBeneficiaireSeance($pdo, $cotisation['id_tontine'], $cotisation['date_seance']);
            if ($beneficiaire) {
                $cotisation['beneficiaire_id'] = $beneficiaire['id_membre'];
                $cotisation['beneficiaire_nom'] = $beneficiaire['nom'];
                $cotisation['beneficiaire_prenom'] = $beneficiaire['prenom'];
            }
        }
    }
    unset($cotisation);
    
} catch (PDOException $e) {
    $historique_cotisations = [];
    error_log("Erreur récupération historique: " . $e->getMessage());
}

// Récupérer les dates où l'utilisateur est bénéficiaire
try {
    $sql_beneficiaire = "SELECT b.*, t.nom_tontine, 
                                (SELECT s.date_seance FROM seance s 
                                 WHERE s.id_tontine = b.id_tontine 
                                 AND DATE(s.date_seance) = DATE(b.date_gain)
                                 LIMIT 1) as date_seance
                         FROM beneficiaire b 
                         INNER JOIN tontine t ON b.id_tontine = t.id_tontine 
                         WHERE b.id_membre = ? 
                         ORDER BY b.date_gain DESC";

    $stmt_beneficiaire = $pdo->prepare($sql_beneficiaire);
    $stmt_beneficiaire->execute([$user_id]);
    $mes_benefices = $stmt_beneficiaire->fetchAll();
} catch (PDOException $e) {
    $mes_benefices = [];
    error_log("Erreur récupération bénéfices: " . $e->getMessage());
}

// Récupérer toutes les séances de mes tontines
try {
    $sql_mes_seances = "SELECT s.*, t.nom_tontine, t.montant_cotisation, t.id_tontine
                        FROM seance s 
                        INNER JOIN tontine t ON s.id_tontine = t.id_tontine 
                        WHERE s.id_tontine IN (
                            SELECT id_tontine FROM participation_tontine WHERE id_membre = ?
                        )
                        ORDER BY s.date_seance DESC";

    $stmt_mes_seances = $pdo->prepare($sql_mes_seances);
    $stmt_mes_seances->execute([$user_id]);
    $mes_seances = $stmt_mes_seances->fetchAll();
    
    // Pour chaque séance, déterminer si payée et récupérer le bénéficiaire
    foreach ($mes_seances as &$seance) {
        // Vérifier si payée
        $stmt_paye = $pdo->prepare("SELECT COUNT(*) FROM cotisation WHERE id_membre = ? AND id_seance = ?");
        $stmt_paye->execute([$user_id, $seance['id_seance']]);
        $seance['est_payee'] = $stmt_paye->fetchColumn() > 0;
        
        // Récupérer le bénéficiaire seulement si la date est valide
        if (isset($seance['date_seance']) && $seance['date_seance'] && $seance['date_seance'] !== '0000-00-00') {
            $beneficiaire = getBeneficiaireSeance($pdo, $seance['id_tontine'], $seance['date_seance']);
            if ($beneficiaire) {
                $seance['beneficiaire_id'] = $beneficiaire['id_membre'];
                $seance['beneficiaire_nom'] = $beneficiaire['nom'];
                $seance['beneficiaire_prenom'] = $beneficiaire['prenom'];
            }
        }
    }
    unset($seance);
    
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

$total_gagnes = 0;
foreach ($mes_benefices as $benefice) {
    $total_gagnes += $benefice['montant_gagne'];
}

// Calcul pour les statistiques
$total_cotisations = count($historique_cotisations) + count($seances_a_payer);
$pourcentage_paye = $total_cotisations > 0 ? round((count($historique_cotisations) / $total_cotisations) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Cotisations | TontinePro</title>
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
            --info-color: #17a2b8;
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

        .action-btn.primary {
            background: linear-gradient(135deg, var(--medium-blue) 0%, var(--light-blue) 100%);
        }

        .action-btn.primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(45, 74, 138, 0.4);
        }

        .action-btn.success {
            background: linear-gradient(135deg, var(--success-color) 0%, #20c997 100%);
        }

        .action-btn.success:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(40, 167, 69, 0.4);
        }

        .action-btn.warning {
            background: linear-gradient(135deg, var(--warning-color) 0%, #ffd54f 100%);
            color: var(--text-dark);
        }

        .action-btn.warning:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(255, 193, 7, 0.4);
        }

        .action-btn.danger {
            background: linear-gradient(135deg, var(--danger-color) 0%, #e74c3c 100%);
        }

        .action-btn.danger:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(220, 53, 69, 0.4);
        }

        /* Tabs */
        .tabs-container {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: 0 8px 32px var(--shadow-light);
            border: 2px solid rgba(212, 175, 55, 0.2);
            margin-bottom: 30px;
        }

        .tabs-header {
            display: flex;
            background: linear-gradient(135deg, var(--dark-blue) 0%, var(--medium-blue) 100%);
            flex-wrap: wrap;
        }

        .tab-button {
            padding: 20px 30px;
            background: transparent;
            border: none;
            font-family: inherit;
            font-size: 1rem;
            font-weight: 600;
            color: rgba(255, 255, 255, 0.8);
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 12px;
            position: relative;
        }

        .tab-button:hover {
            color: var(--pure-white);
            background: rgba(255, 255, 255, 0.1);
        }

        .tab-button.active {
            color: var(--pure-white);
            background: rgba(255, 255, 255, 0.15);
            border-bottom: 3px solid var(--accent-gold);
        }

        .tab-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 700;
            margin-left: 5px;
        }

        .badge-danger {
            background: rgba(220, 53, 69, 0.2);
            color: #fff;
            border: 1px solid rgba(220, 53, 69, 0.4);
        }

        .badge-success {
            background: rgba(40, 167, 69, 0.2);
            color: #fff;
            border: 1px solid rgba(40, 167, 69, 0.4);
        }

        .badge-warning {
            background: rgba(255, 193, 7, 0.2);
            color: var(--text-dark);
            border: 1px solid rgba(255, 193, 7, 0.4);
        }

        .badge-info {
            background: rgba(23, 162, 184, 0.2);
            color: #fff;
            border: 1px solid rgba(23, 162, 184, 0.4);
        }

        .tab-content {
            padding: 30px;
            display: none;
            animation: fadeInUp 0.5s ease-out;
        }

        .tab-content.active {
            display: block;
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
            margin-bottom: 30px;
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

        /* Payment Cards */
        .cotisations-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 25px;
            margin-top: 20px;
        }

        @media (max-width: 768px) {
            .cotisations-grid {
                grid-template-columns: 1fr;
            }
        }

        .cotisation-card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.95) 0%, rgba(248, 250, 252, 0.95) 100%);
            border-radius: var(--border-radius);
            padding: 25px;
            box-shadow: 0 4px 20px var(--shadow-light);
            transition: var(--transition);
            border: 2px solid rgba(212, 175, 55, 0.2);
            position: relative;
            overflow: hidden;
        }

        .cotisation-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px var(--shadow-medium);
            border-color: var(--accent-gold);
        }

        .cotisation-card.beneficiaire-card {
            border: 3px solid var(--accent-gold);
            background: linear-gradient(135deg, rgba(212, 175, 55, 0.05) 0%, rgba(248, 250, 252, 0.95) 100%);
        }

        .cotisation-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(212, 175, 55, 0.1);
        }

        .cotisation-name {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--navy-blue);
            margin-bottom: 5px;
        }

        .cotisation-details {
            margin-bottom: 25px;
        }

        .cotisation-detail {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
            color: var(--text-light);
            font-size: 0.95rem;
        }

        .cotisation-detail i {
            width: 20px;
            text-align: center;
            color: var(--accent-gold);
            font-size: 1.1rem;
        }

        .cotisation-detail strong {
            color: var(--text-dark);
            font-weight: 600;
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
            border: 2px solid transparent;
        }

        .status-badge i {
            font-size: 0.7rem;
        }

        .status-paye {
            background: linear-gradient(135deg, rgba(40, 167, 69, 0.2), rgba(32, 201, 151, 0.2));
            color: var(--success-color);
            border-color: rgba(40, 167, 69, 0.4);
        }

        .status-en-attente {
            background: linear-gradient(135deg, rgba(255, 193, 7, 0.2), rgba(255, 213, 79, 0.2));
            color: #856404;
            border-color: rgba(255, 193, 7, 0.4);
        }

        .status-en-retard {
            background: linear-gradient(135deg, rgba(220, 53, 69, 0.2), rgba(231, 76, 60, 0.2));
            color: var(--danger-color);
            border-color: rgba(220, 53, 69, 0.4);
        }

        .status-beneficiaire {
            background: linear-gradient(135deg, rgba(212, 175, 55, 0.2), rgba(230, 195, 77, 0.2));
            color: #b58900;
            border-color: rgba(212, 175, 55, 0.4);
        }

        /* Amount styling */
        .amount {
            font-weight: 700;
            color: var(--navy-blue);
            font-size: 1.2rem;
        }

        .amount-positive {
            color: var(--success-color);
        }

        .amount-negative {
            color: var(--danger-color);
        }

        /* Form Styles */
        .payment-form {
            background: linear-gradient(135deg, rgba(248, 250, 252, 0.95) 0%, rgba(241, 245, 249, 0.95) 100%);
            border-radius: 12px;
            padding: 25px;
            margin-top: 20px;
            border: 2px solid rgba(212, 175, 55, 0.2);
        }

        .form-group {
            margin-bottom: 20px;
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
            padding: 14px 18px;
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

        /* Buttons */
        .btn-group {
            display: flex;
            gap: 16px;
            margin-top: 20px;
            align-items: center;
            flex-wrap: wrap;
        }

        .btn {
            padding: 14px 28px;
            border-radius: 12px;
            font-family: inherit;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            font-size: 0.95rem;
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

        .btn-success {
            background: linear-gradient(135deg, var(--success-color) 0%, #20c997 100%);
            color: var(--pure-white);
            box-shadow: 0 4px 20px rgba(40, 167, 69, 0.3);
        }

        .btn-success:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(40, 167, 69, 0.4);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning-color) 0%, #ffd54f 100%);
            color: var(--text-dark);
            box-shadow: 0 4px 20px rgba(255, 193, 7, 0.3);
        }

        .btn-warning:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(255, 193, 7, 0.4);
        }

        .btn-secondary {
            background: var(--pure-white);
            color: var(--medium-blue);
            border: 2px solid rgba(45, 74, 138, 0.3);
        }

        .btn-secondary:hover {
            background: var(--bg-light);
            border-color: var(--accent-gold);
            transform: translateY(-2px);
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

        .alert-info {
            background: rgba(23, 162, 184, 0.15);
            color: #0c5460;
            border-left-color: var(--info-color);
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
            scrollbar-width: 1px solid var(--accent-light);
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
            font-weight: 600;
            color: var(--pure-white);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .cotisations-table tbody tr {
            border-bottom: 1px solid rgba(212, 175, 55, 0.1);
            transition: var(--transition);
        }

        .cotisations-table tbody tr:hover {
            background: linear-gradient(90deg, rgba(212, 175, 55, 0.08), transparent);
            transform: scale(1.01);
        }

        .cotisations-table td {
            padding: 18px 20px;
            color: var(--text-dark);
            font-size: 0.95rem;
            vertical-align: middle;
        }

        /* Chip for status */
        .chip {
            display: inline-flex;
            align-items: center;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            border: 2px solid transparent;
        }

        .chip-success {
            background: rgba(40, 167, 69, 0.1);
            color: var(--success-color);
            border-color: rgba(40, 167, 69, 0.3);
        }

        .chip-warning {
            background: rgba(255, 193, 7, 0.1);
            color: #856404;
            border-color: rgba(255, 193, 7, 0.3);
        }

        .chip-danger {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger-color);
            border-color: rgba(220, 53, 69, 0.3);
        }

        .chip-info {
            background: rgba(23, 162, 184, 0.1);
            color: var(--info-color);
            border-color: rgba(23, 162, 184, 0.3);
        }

        .chip-purple {
            background: rgba(111, 66, 193, 0.1);
            color: #6f42c1;
            border-color: rgba(111, 66, 193, 0.3);
        }

        .chip-gold {
            background: rgba(212, 175, 55, 0.1);
            color: #b58900;
            border-color: rgba(212, 175, 55, 0.3);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 10px;
            line-height: 1;
        }

        .stat-number.positive {
            background: linear-gradient(135deg, var(--success-color), #20c997);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .stat-number.negative {
            background: linear-gradient(135deg, var(--danger-color), #e74c3c);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .stat-number.neutral {
            background: linear-gradient(135deg, var(--medium-blue), var(--light-blue));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
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
@media (max-width: 1024px) {
    .empty-state{
        padding: 0;
    }
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
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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
                padding: 10px;
            }
            .tab-content.active{
                padding: 5px;
            }

            .btn-group {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }

            .tabs-header {
                flex-direction: column;
            }

            .tab-button {
                padding: 15px 20px;
                justify-content: center;
            }

            .cotisations-grid {
                grid-template-columns: 1fr;
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
                display: none;
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
                font-size: 2rem;
            }

            .cotisation-card {
                padding: 20px;
            }
        }

        @media print {
            .main-header,
            .btn-group,
            .quick-actions,
            .back-to-dashboard,
            .tabs-header,
            .payment-form {
                display: none;
            }
            
            .card {
                box-shadow: none;
                border: 1px solid #ddd;
            }
            
            .cotisations-table {
                min-width: auto;
            }
        }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
        <p>Traitement en cours...</p>
        <div class="loading-text">Veuillez patienter</div>
    </div>

    <!-- Header -->
    <header class="main-header">
        <div class="header-content">
            <div class="logo">
                <div class="logo-icon">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="logo-text">
                    <h1>Gestion des Cotisations</h1>
                    <p>Paiement et suivi de vos cotisations</p>
                </div>
            </div>
            
            <div class="user-info">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($user['prenom'], 0, 1) . substr($user['nom'], 0, 1)); ?>
                </div>
                <div class="user-details">
                    <h4><?php echo htmlspecialchars($user['prenom'] . ' ' . $user['nom']); ?></h4>
                    <p><?php echo count($mes_tontines); ?> Tontine(s)</p>
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
            <button class="action-btn primary" id="showStatsBtn">
                <i class="fas fa-chart-bar"></i> Voir Statistiques
            </button>
            
            <button class="action-btn success" onclick="window.print()">
                <i class="fas fa-print"></i> Imprimer
            </button>
            
            <?php if (count($seances_a_payer) > 0): ?>
            <button class="action-btn warning" id="payAllBtn">
                <i class="fas fa-credit-card"></i> Payer Tout (<?php echo number_format($total_a_payer, 0, ',', ' '); ?> FCFA)
            </button>
            <?php endif; ?>
        </div>

        <!-- Statistics -->
        <div class="stats-grid" id="statsSection" style="display: none;">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-hand-holding-usd"></i>
                </div>
                <div class="stat-number neutral"><?php echo count($mes_tontines); ?></div>
                <div class="stat-label">Tontines Actives</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-number negative"><?php echo count($seances_a_payer); ?></div>
                <div class="stat-label">Séances à Payer</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="stat-number negative"><?php echo number_format($total_a_payer, 0, ',', ' '); ?> FCFA</div>
                <div class="stat-label">Total à Payer</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-trophy"></i>
                </div>
                <div class="stat-number positive">+<?php echo number_format($total_gagnes, 0, ',', ' '); ?> FCFA</div>
                <div class="stat-label">Gains Totaux</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-history"></i>
                </div>
                <div class="stat-number neutral"><?php echo number_format($total_paye, 0, ',', ' '); ?> FCFA</div>
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
                <button class="tab-button <?php echo $onglet_actif === 'toutes' ? 'active' : ''; ?>" data-tab="toutes">
                    <i class="fas fa-list"></i>
                    <span>Toutes les Séances</span>
                    <?php if (count($mes_seances) > 0): ?>
                        <span class="tab-badge badge-info"><?php echo count($mes_seances); ?></span>
                    <?php endif; ?>
                </button>
            </div>

            <!-- Messages -->
            <?php if ($message): ?>
                <div class="tab-content active" style="padding: 20px 30px;">
                    <div class="alert alert-<?php echo $message_type; ?>">
                        <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'warning' ? 'exclamation-triangle' : 'exclamation-circle'); ?>"></i>
                        <span><?php echo $message; ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Onglet: À Payer -->
            <div id="tab-a_payer" class="tab-content <?php echo $onglet_actif === 'a_payer' ? 'active' : ''; ?>">
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-clock"></i> Cotisations en Attente de Paiement</h2>
                    </div>
                    <div class="card-body">
                        <?php if (count($seances_a_payer) > 0): ?>
                            <?php if (count($seances_a_payer) > 3): ?>
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <div>
                                        <strong>Attention : <?php echo count($seances_a_payer); ?> cotisations en attente</strong>
                                        <p style="margin-top: 5px; font-size: 0.9rem;">Vous avez plusieurs cotisations en retard. Veuillez les régulariser au plus vite.</p>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="cotisations-grid">
                                <?php foreach ($seances_a_payer as $seance): 
                                    // Vérifier si la date est valide avant de créer un objet DateTime
                                    $is_date_valid = isset($seance['date_seance']) && $seance['date_seance'] && $seance['date_seance'] !== '0000-00-00';
                                    $is_en_retard = false;
                                    $jours_retard = 0;
                                    
                                    if ($is_date_valid) {
                                        $date_seance = new DateTime($seance['date_seance']);
                                        $today = new DateTime();
                                        $is_en_retard = $date_seance < $today;
                                        $jours_retard = $is_en_retard ? $date_seance->diff($today)->days : 0;
                                    }
                                    
                                    // Vérifier si l'utilisateur est le bénéficiaire
                                    $est_beneficiaire = isset($seance['beneficiaire_id']) && ($seance['beneficiaire_id'] == $user_id);
                                ?>
                                    <div class="cotisation-card <?php echo $est_beneficiaire ? 'beneficiaire-card' : ''; ?>" 
                                         style="<?php echo $est_beneficiaire ? 'border: 3px solid var(--accent-gold); background: linear-gradient(135deg, rgba(212, 175, 55, 0.05) 0%, rgba(248, 250, 252, 0.95) 100%);' : ''; ?>">
                                        
                                        <div class="cotisation-header">
                                            <div>
                                                <div class="cotisation-name"><?php echo htmlspecialchars($seance['nom_tontine']); ?></div>
                                                <span class="status-badge <?php echo $is_en_retard ? 'status-en-retard' : ($est_beneficiaire ? 'status-beneficiaire' : 'status-en-attente'); ?>">
                                                    <i class="fas fa-<?php echo $est_beneficiaire ? 'trophy' : ($is_en_retard ? 'exclamation-triangle' : 'clock'); ?>"></i>
                                                    <?php echo $est_beneficiaire ? 'Vous êtes bénéficiaire' : ($is_en_retard ? 'En retard' : 'À payer'); ?>
                                                </span>
                                            </div>
                                        </div>

                                        <div class="cotisation-details">
                                            <?php if ($is_date_valid): ?>
                                            <div class="cotisation-detail">
                                                <i class="fas fa-calendar-alt"></i>
                                                <span>Date de la séance: <strong><?php echo date('d/m/Y', strtotime($seance['date_seance'])); ?></strong></span>
                                            </div>
                                            <?php else: ?>
                                            <div class="cotisation-detail">
                                                <i class="fas fa-calendar-alt"></i>
                                                <span>Date de la séance: <strong>Non définie</strong></span>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <div class="cotisation-detail">
                                                <i class="fas fa-money-bill-wave"></i>
                                                <span>Montant dû: <strong class="amount"><?php echo number_format($seance['montant_cotisation'], 0, ',', ' '); ?> FCFA</strong></span>
                                            </div>
                                            
                                            <!-- Information du bénéficiaire -->
                                            <div class="cotisation-detail">
                                                <i class="fas fa-user-check"></i>
                                                <span>
                                                    <?php if($est_beneficiaire): ?>
                                                        <strong style="color: var(--accent-gold);">🎉 Félicitations ! Vous recevez les cotisations de cette séance</strong>
                                                    <?php elseif(isset($seance['beneficiaire_id'])): ?>
                                                        Bénéficiaire: <strong><?php echo htmlspecialchars($seance['beneficiaire_prenom'] . ' ' . $seance['beneficiaire_nom']); ?></strong>
                                                    <?php elseif($is_en_retard): ?>
                                                        <em>Bénéficiaire en cours de désignation...</em>
                                                    <?php else: ?>
                                                        <em>Bénéficiaire sera désigné le jour de la séance</em>
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                            
                                            <?php if ($is_en_retard): ?>
                                                <div class="cotisation-detail">
                                                    <i class="fas fa-exclamation-triangle"></i>
                                                    <span style="color: #dc3545;">En retard depuis: <strong><?php echo $jours_retard; ?> jour(s)</strong></span>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Si l'utilisateur est bénéficiaire, afficher le montant total qu'il recevra -->
                                        <?php if($est_beneficiaire && isset($seance['montant_gagne'])): ?>
                                            <div class="cotisation-detail" style="background: rgba(212, 175, 55, 0.1); padding: 15px; border-radius: 8px; margin-top: 15px;">
                                                <i class="fas fa-trophy"></i>
                                                <span>
                                                    <strong style="color: var(--accent-gold);">
                                                        💰 Montant à recevoir: <?php echo number_format($seance['montant_gagne'], 0, ',', ' '); ?> FCFA
                                                    </strong>
                                                    <small style="display: block; color: #8a6d3b; margin-top: 5px;">
                                                        (Total des cotisations de cette séance)
                                                    </small>
                                                </span>
                                            </div>
                                        <?php endif; ?>

                                        <form method="POST" action="" class="payment-form">
                                            <input type="hidden" name="id_tontine" value="<?php echo $seance['id_tontine'] ?? ''; ?>">
                                            <input type="hidden" name="id_seance" value="<?php echo $seance['id_seance'] ?? ''; ?>">
                                            <input type="hidden" name="montant" value="<?php echo $seance['montant_cotisation'] ?? 0; ?>">

                                            <div class="form-group">
                                                <label for="date_paiement_<?php echo $seance['id_seance'] ?? ''; ?>" class="form-label">
                                                    <i class="fas fa-calendar-check"></i> Date de paiement
                                                </label>
                                                <input type="date"
                                                    id="date_paiement_<?php echo $seance['id_seance'] ?? ''; ?>"
                                                    name="date_paiement"
                                                    class="form-control"
                                                    value="<?php echo date('Y-m-d'); ?>"
                                                    required>
                                            </div>

                                            <div class="btn-group">
                                                <button type="submit" name="payer_cotisation" class="btn btn-primary">
                                                    <i class="fas fa-credit-card"></i> Payer <?php echo number_format($seance['montant_cotisation'] ?? 0, 0, ',', ' '); ?> FCFA
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Total à payer -->
                            <div class="payment-form" style="margin-top: 30px; background: linear-gradient(135deg, rgba(220, 53, 69, 0.05), rgba(231, 76, 60, 0.05)); border-color: rgba(220, 53, 69, 0.3);">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <div>
                                        <h4 style="color: var(--danger-color); margin-bottom: 5px;">Total à payer</h4>
                                        <p style="color: var(--text-light); font-size: 0.9rem;"><?php echo count($seances_a_payer); ?> cotisation(s) en attente</p>
                                    </div>
                                    <div style="text-align: right;">
                                        <div class="amount" style="font-size: 2rem; color: var(--danger-color);"><?php echo number_format($total_a_payer, 0, ',', ' '); ?> FCFA</div>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-check-circle"></i>
                                <h3>Aucune cotisation à payer</h3>
                                <p>Vous êtes à jour dans toutes vos tontines. Bravo!</p>
                                <div class="btn-group" style="justify-content: center; margin-top: 20px;">
                                    <a href="participer_tontine.php" class="btn btn-primary">
                                        <i class="fas fa-hand-holding-usd"></i> Participer à une tontine
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Onglet: Historique -->
            <div id="tab-historique" class="tab-content <?php echo $onglet_actif === 'historique' ? 'active' : ''; ?>">
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-history"></i> Historique des Cotisations</h2>
                    </div>
                    <div class="card-body">
                        <?php if (count($historique_cotisations) > 0): ?>
                            <div class="table-responsive">
                                <table class="cotisations-table">
                                    <thead>
                                        <tr>
                                            <th>Date Paiement</th>
                                            <th>Tontine</th>
                                            <th>Séance</th>
                                            <th>Montant</th>
                                            <th>Bénéficiaire</th>
                                            <th>Statut</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($historique_cotisations as $cotisation): 
                                            $est_beneficiaire = isset($cotisation['beneficiaire_id']) && ($cotisation['beneficiaire_id'] == $user_id);
                                            $is_date_valid = isset($cotisation['date_seance']) && $cotisation['date_seance'] && $cotisation['date_seance'] !== '0000-00-00';
                                        ?>
                                            <tr>
                                                <td>
                                                    <div style="font-weight: 500; color: var(--text-dark);">
                                                        <i class="fas fa-calendar-check" style="color: var(--accent-gold); margin-right: 8px;"></i>
                                                        <?php echo date('d/m/Y', strtotime($cotisation['date_paiement'])); ?>
                                                    </div>
                                                    <small style="color: var(--text-light);"><?php echo date('H:i', strtotime($cotisation['date_paiement'])); ?></small>
                                                </td>
                                                <td>
                                                    <div style="font-weight: 700; color: var(--navy-blue);">
                                                        <?php echo htmlspecialchars($cotisation['nom_tontine']); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if ($is_date_valid): ?>
                                                    <div style="color: var(--text-dark);">
                                                        <i class="fas fa-calendar-alt" style="color: var(--text-light); margin-right: 8px;"></i>
                                                        <?php echo date('d/m/Y', strtotime($cotisation['date_seance'])); ?>
                                                    </div>
                                                    <?php else: ?>
                                                    <div style="color: var(--text-light);">
                                                        <i class="fas fa-question" style="color: var(--text-light); margin-right: 8px;"></i>
                                                        Date non définie
                                                    </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <strong class="amount"><?php echo number_format($cotisation['montant'], 0, ',', ' '); ?> FCFA</strong>
                                                </td>
                                                <td>
                                                    <?php if($est_beneficiaire): ?>
                                                        <span class="chip chip-gold">
                                                            <i class="fas fa-user"></i> Vous
                                                        </span>
                                                    <?php elseif(isset($cotisation['beneficiaire_prenom'])): ?>
                                                        <span class="chip chip-info">
                                                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($cotisation['beneficiaire_prenom'] . ' ' . $cotisation['beneficiaire_nom']); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="chip chip-warning">
                                                            <i class="fas fa-question"></i> Non désigné
                                                        </span>
                                                    <?php endif; ?>
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

                            <!-- Statistics -->
                            <div class="stats-grid" style="margin-top: 30px;">
                                <div class="stat-card">
                                    <div class="stat-icon">
                                        <i class="fas fa-money-bill-wave"></i>
                                    </div>
                                    <div class="stat-number neutral"><?php echo count($historique_cotisations); ?></div>
                                    <div class="stat-label">Cotisations Payées</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-icon">
                                        <i class="fas fa-coins"></i>
                                    </div>
                                    <div class="stat-number neutral"><?php echo number_format($total_paye, 0, ',', ' '); ?> FCFA</div>
                                    <div class="stat-label">Total Dépensé</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-icon">
                                        <i class="fas fa-calendar-star"></i>
                                    </div>
                                    <div class="stat-number neutral">
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
                                <div class="stat-card">
                                    <div class="stat-icon">
                                        <i class="fas fa-trophy"></i>
                                    </div>
                                    <div class="stat-number neutral">
                                        <?php
                                        $beneficiaire_count = 0;
                                        foreach ($historique_cotisations as $cotisation) {
                                            if (isset($cotisation['beneficiaire_id']) && $cotisation['beneficiaire_id'] == $user_id) {
                                                $beneficiaire_count++;
                                            }
                                        }
                                        echo $beneficiaire_count;
                                        ?>
                                    </div>
                                    <div class="stat-label">Séances Gagnées</div>
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
            </div>

            <!-- Onglet: Mes Bénéfices -->
            <div id="tab-benefices" class="tab-content <?php echo $onglet_actif === 'benefices' ? 'active' : ''; ?>">
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-trophy"></i> Mes Gains et Bénéfices</h2>
                    </div>
                    <div class="card-body">
                        <?php if (count($mes_benefices) > 0): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-trophy"></i>
                                <div>
                                    <strong>Félicitations !</strong>
                                    <p style="margin-top: 5px; font-size: 0.9rem;">Vous avez été bénéficiaire <?php echo count($mes_benefices); ?> fois pour un total de <?php echo number_format($total_gagnes, 0, ',', ' '); ?> FCFA.</p>
                                </div>
                            </div>

                            <div class="cotisations-grid">
                                <?php foreach ($mes_benefices as $benefice): ?>
                                    <div class="cotisation-card beneficiaire-card">
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
                                            <?php if (isset($benefice['date_seance']) && $benefice['date_seance'] && $benefice['date_seance'] !== '0000-00-00'): ?>
                                            <div class="cotisation-detail">
                                                <i class="fas fa-calendar-check"></i>
                                                <span>Séance du: <strong><?php echo date('d/m/Y', strtotime($benefice['date_seance'])); ?></strong></span>
                                            </div>
                                            <?php endif; ?>
                                            <div class="cotisation-detail">
                                                <i class="fas fa-money-bill-wave"></i>
                                                <span>Montant gagné: <strong class="amount amount-positive">+<?php echo number_format($benefice['montant_gagne'], 0, ',', ' '); ?> FCFA</strong></span>
                                            </div>
                                        </div>

                                        <div class="btn-group">
                                            <button type="button" class="btn btn-primary" onclick="showBeneficeDetails(<?php echo $benefice['id_beneficiaire']; ?>)">
                                                <i class="fas fa-info-circle"></i> Détails
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Résumé des gains -->
                            <div class="stats-grid" style="margin-top: 30px;">
                                <div class="stat-card">
                                    <div class="stat-icon">
                                        <i class="fas fa-trophy"></i>
                                    </div>
                                    <div class="stat-number neutral"><?php echo count($mes_benefices); ?></div>
                                    <div class="stat-label">Gains Totaux</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-icon">
                                        <i class="fas fa-coins"></i>
                                    </div>
                                    <div class="stat-number positive"><?php echo number_format($total_gagnes, 0, ',', ' '); ?> FCFA</div>
                                    <div class="stat-label">Montant Total Gagné</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-icon">
                                        <i class="fas fa-calendar-star"></i>
                                    </div>
                                    <div class="stat-number positive">
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
                                <div class="stat-card">
                                    <div class="stat-icon">
                                        <i class="fas fa-chart-line"></i>
                                    </div>
                                    <div class="stat-number neutral">
                                        <?php echo number_format($total_gagnes / max(1, count($mes_benefices)), 0, ',', ' '); ?> FCFA
                                    </div>
                                    <div class="stat-label">Moyenne par gain</div>
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
                    </div>
                </div>
            </div>

            <!-- Onglet: Toutes les Séances -->
            <div id="tab-toutes" class="tab-content <?php echo $onglet_actif === 'toutes' ? 'active' : ''; ?>">
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-list"></i> Toutes mes séances de tontine</h2>
                    </div>
                    <div class="card-body">
                        <?php if (count($mes_seances) > 0): ?>
                            <div class="table-responsive">
                                <table class="cotisations-table">
                                    <thead>
                                        <tr>
                                            <th>Date Séance</th>
                                            <th>Tontine</th>
                                            <th>Montant</th>
                                            <th>Bénéficiaire</th>
                                            <th>Statut Paiement</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($mes_seances as $seance):
                                            $est_beneficiaire = isset($seance['beneficiaire_id']) && ($seance['beneficiaire_id'] == $user_id);
                                            $est_payee = $seance['est_payee'];
                                            $is_date_valid = isset($seance['date_seance']) && $seance['date_seance'] && $seance['date_seance'] !== '0000-00-00';
                                            $is_en_retard = false;
                                            
                                            if ($is_date_valid) {
                                                $date_seance_obj = new DateTime($seance['date_seance']);
                                                $today = new DateTime();
                                                $is_en_retard = $date_seance_obj < $today;
                                            }
                                        ?>
                                            <tr>
                                                <td>
                                                    <?php if ($is_date_valid): ?>
                                                    <div style="font-weight: 500; color: var(--text-dark);">
                                                        <i class="fas fa-calendar-alt" style="color: var(--accent-gold); margin-right: 8px;"></i>
                                                        <?php echo date('d/m/Y', strtotime($seance['date_seance'])); ?>
                                                    </div>
                                                    <?php else: ?>
                                                    <div style="color: var(--text-light);">
                                                        <i class="fas fa-question" style="color: var(--text-light); margin-right: 8px;"></i>
                                                        Date non définie
                                                    </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div style="font-weight: 700; color: var(--navy-blue);">
                                                        <?php echo htmlspecialchars($seance['nom_tontine']); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <strong><?php echo number_format($seance['montant_cotisation'], 0, ',', ' '); ?> FCFA</strong>
                                                </td>
                                                <td>
                                                    <?php if (isset($seance['beneficiaire_id'])): ?>
                                                        <?php if ($est_beneficiaire): ?>
                                                            <span class="chip chip-gold">
                                                                <i class="fas fa-user"></i> Vous
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="chip chip-info">
                                                                <i class="fas fa-user"></i> <?php echo htmlspecialchars($seance['beneficiaire_prenom'] . ' ' . $seance['beneficiaire_nom']); ?>
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
                                                        <?php if ($is_en_retard): ?>
                                                            <span class="chip chip-danger"><i class="fas fa-exclamation"></i> En retard</span>
                                                        <?php else: ?>
                                                            <span class="chip chip-warning"><i class="fas fa-clock"></i> À payer</span>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!$est_payee): ?>
                                                        <form method="POST" action="" style="display: inline;">
                                                            <input type="hidden" name="id_tontine" value="<?php echo $seance['id_tontine'] ?? ''; ?>">
                                                            <input type="hidden" name="id_seance" value="<?php echo $seance['id_seance'] ?? ''; ?>">
                                                            <input type="hidden" name="montant" value="<?php echo $seance['montant_cotisation'] ?? 0; ?>">
                                                            <input type="hidden" name="date_paiement" value="<?php echo date('Y-m-d'); ?>">
                                                            <button type="submit" name="payer_cotisation" class="btn btn-primary btn-sm">
                                                                <i class="fas fa-credit-card"></i> Payer
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <button type="button" class="btn btn-secondary btn-sm" onclick="showReceipt(<?php echo $seance['id_seance']; ?>)">
                                                            <i class="fas fa-receipt"></i> Reçu
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Statistiques résumé -->
                            <div class="stats-grid" style="margin-top: 30px;">
                                <div class="stat-card">
                                    <div class="stat-icon">
                                        <i class="fas fa-calendar-alt"></i>
                                    </div>
                                    <div class="stat-number neutral"><?php echo count($mes_seances); ?></div>
                                    <div class="stat-label">Total Séances</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-icon">
                                        <i class="fas fa-check-circle"></i>
                                    </div>
                                    <div class="stat-number neutral"><?php echo count($historique_cotisations); ?></div>
                                    <div class="stat-label">Séances Payées</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-icon">
                                        <i class="fas fa-clock"></i>
                                    </div>
                                    <div class="stat-number negative"><?php echo count($seances_a_payer); ?></div>
                                    <div class="stat-label">Séances à Payer</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-icon">
                                        <i class="fas fa-trophy"></i>
                                    </div>
                                    <div class="stat-number neutral">
                                        <?php
                                        $beneficiaire_count = 0;
                                        foreach ($mes_seances as $seance) {
                                            if (isset($seance['beneficiaire_id']) && $seance['beneficiaire_id'] == $user_id) {
                                                $beneficiaire_count++;
                                            }
                                        }
                                        echo $beneficiaire_count;
                                        ?>
                                    </div>
                                    <div class="stat-label">Séances Gagnées</div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-calendar-times"></i>
                                <h3>Aucune séance trouvée</h3>
                                <p>Vous ne participez à aucune tontine ou aucune séance n'a été planifiée.</p>
                                <div class="btn-group" style="justify-content: center; margin-top: 20px;">
                                    <a href="participer_tontine.php" class="btn btn-primary">
                                        <i class="fas fa-hand-holding-usd"></i> Participer à une tontine
                                    </a>
                                </div>
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
        $(document).ready(function() {
            // Gestion des onglets
            $('.tab-button').on('click', function() {
                const tabName = $(this).data('tab');
                changeTab(tabName);
            });

            // Afficher/masquer les statistiques
            $('#showStatsBtn').on('click', function() {
                const statsSection = $('#statsSection');
                if (statsSection.is(':visible')) {
                    statsSection.slideUp();
                } else {
                    statsSection.slideDown();
                }
            });

            // Payer toutes les cotisations
            $('#payAllBtn').on('click', function() {
                <?php if (count($seances_a_payer) > 0): ?>
                const totalAmount = <?php echo $total_a_payer; ?>;
                const totalSeances = <?php echo count($seances_a_payer); ?>;
                
                Swal.fire({
                    title: 'Payer toutes les cotisations',
                    html: `Voulez-vous payer <strong>${totalSeances}</strong> cotisations pour un total de <strong>${totalAmount.toLocaleString('fr-FR')} FCFA</strong> ?`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Oui, tout payer',
                    cancelButtonText: 'Annuler',
                    confirmButtonColor: '#3a5fc0',
                    cancelButtonColor: '#64748b',
                    reverseButtons: true
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Pour chaque séance à payer, soumettre le formulaire
                        $('form.payment-form').each(function(index) {
                            setTimeout(() => {
                                $(this).submit();
                            }, index * 1000); // Délai de 1 seconde entre chaque paiement
                        });
                        
                        Swal.fire({
                            title: 'Paiements en cours',
                            text: 'Les paiements sont en cours de traitement...',
                            icon: 'info',
                            showConfirmButton: false,
                            timer: 3000
                        });
                    }
                });
                <?php endif; ?>
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
                        $('#loadingOverlay').fadeIn();
                        setTimeout(() => {
                            form.submit();
                        }, 500);
                    }
                });
            });

            // Définir la date d'aujourd'hui par défaut pour tous les champs date
            const today = new Date().toISOString().split('T')[0];
            $('input[type="date"]').each(function() {
                if (!$(this).val()) {
                    $(this).val(today);
                }
            });

            // Gestion de l'historique du navigateur
            window.onpopstate = function(event) {
                const urlParams = new URLSearchParams(window.location.search);
                const onglet = urlParams.get('onglet') || 'a_payer';
                changeTab(onglet);
            };

            // Animation des cartes de bénéficiaire
            $('.beneficiaire-card').each(function(index) {
                $(this).css({
                    'animation': 'pulse 2s infinite',
                    'animation-delay': (index * 0.3) + 's'
                });
            });
        });

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
                        <i class="fas fa-trophy" style="font-size: 3rem; color: #d4af37; margin-bottom: 15px;"></i>
                        <h3 style="color: var(--navy-blue); margin-bottom: 10px;">Félicitations !</h3>
                        <p style="color: var(--text-light);">Vous avez été sélectionné comme bénéficiaire de cette tontine.</p>
                        <div style="margin-top: 20px; padding: 15px; background: linear-gradient(135deg, rgba(212, 175, 55, 0.1) 0%, rgba(230, 195, 77, 0.1) 100%); border-radius: 8px;">
                            <p style="margin: 5px 0; color: #b58900;">
                                <i class="fas fa-info-circle"></i> Le montant gagné correspond au total des cotisations de la séance.
                            </p>
                        </div>
                    </div>
                `,
                showCloseButton: true,
                showCancelButton: false,
                focusConfirm: false,
                confirmButtonText: 'Fermer',
                confirmButtonColor: '#d4af37',
            });
        }

        // Animation des cartes
        $('.cotisation-card').each(function(index) {
            $(this).css('animation-delay', (index * 0.1) + 's');
            $(this).addClass('animate__animated animate__fadeIn');
        });

        // Animation pulsante pour les cartes de bénéficiaire
        const style = document.createElement('style');
        style.innerHTML = `
            @keyframes pulse {
                0% {
                    box-shadow: 0 4px 20px rgba(212, 175, 55, 0.4);
                }
                50% {
                    box-shadow: 0 4px 30px rgba(212, 175, 55, 0.6);
                }
                100% {
                    box-shadow: 0 4px 20px rgba(212, 175, 55, 0.4);
                }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>
<?php
if (isset($pdo)) {
    $pdo = null;
}
?>