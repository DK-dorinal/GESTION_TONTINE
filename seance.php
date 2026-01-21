<?php
@include './fonctions/config.php';
session_start();

// Vérification de session ADMIN
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header("Location: index.php");
    exit();
}

// Fonctions utilitaires
function sanitizeInput($data) {
    return htmlspecialchars(stripslashes(trim($data)), ENT_QUOTES, 'UTF-8');
}

function validateDate($date) {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

function validateTime($time) {
    return preg_match('/^([0-1][0-9]|2[0-3]):([0-5][0-9])$/', $time);
}

// Initialisation des variables
$message = '';
$message_type = '';
$seances = [];
$tontines = [];
$form_data = [
    'id_tontine' => '',
    'date_seance' => '',
    'heure_debut' => '',
    'heure_fin' => '',
    'lieu' => '',
    'ordre_du_jour' => ''
];

// --- TRAITEMENT DE L'EXPORT PDF avec TCPDF ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_pdf'])) {
    try {
        // Vérification rapide s'il y a des données
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM seance");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['total'] == 0) {
            $_SESSION['flash_message'] = "Aucune séance à exporter.";
            $_SESSION['flash_type'] = "warning";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }

        // Chargement de TCPDF
        require_once './lib/tcpdf/tcpdf.php';

        // Création du document PDF
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

        // Désactiver les en-têtes et pieds de page par défaut
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Ajout d'une page
        $pdf->AddPage();

        // Police par défaut + taille
        $pdf->SetFont('helvetica', '', 10);

        // =====================================
        //          EN-TÊTE DU DOCUMENT
        // =====================================
        $pdf->SetY(15);
        $pdf->SetFont('helvetica', 'B', 22);
        $pdf->SetTextColor(15, 26, 58); // navy-blue
        $pdf->Cell(0, 10, 'TONTINEPRO', 0, 1, 'C');

        $pdf->SetFont('helvetica', '', 11);
        $pdf->SetTextColor(212, 175, 55); // gold
        $pdf->Cell(0, 8, 'Système de Gestion de Tontine', 0, 1, 'C');

        $pdf->Ln(8);

        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetTextColor(45, 74, 138);
        $pdf->Cell(0, 10, 'LISTE DES SÉANCES', 0, 1, 'C');

        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(100);
        $pdf->Cell(0, 6, 'Date d\'export : ' . date('d/m/Y à H:i'), 0, 1, 'C');
        $pdf->Cell(0, 6, 'Généré par : ' . ($_SESSION['nom'] ?? 'Administrateur'), 0, 1, 'C');

        $pdf->Ln(10);

        // =====================================
        //          STATISTIQUES
        // =====================================
        $stmt = $pdo->query("SELECT s.*, t.nom_tontine 
                             FROM seance s 
                             LEFT JOIN tontine t ON s.id_tontine = t.id_tontine 
                             ORDER BY s.date_seance DESC, s.heure_debut");
        $seances_pdf = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total = count($seances_pdf);
        $passees = count(array_filter($seances_pdf, fn($s) => strtotime($s['date_seance']) < strtotime(date('Y-m-d'))));
        $a_venir = $total - $passees;

        $pdf->SetFillColor(240, 248, 255);
        $pdf->SetTextColor(45, 74, 138);
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 10, "Statistiques", 0, 1, 'L', true);

        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(0);
        $pdf->Cell(0, 8, "Total séances : $total", 0, 1);
        $pdf->SetTextColor(40, 167, 69);
        $pdf->Cell(0, 8, "Séances à venir : $a_venir", 0, 1);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(0, 8, "Séances passées : $passees", 0, 1);

        $pdf->Ln(12);

        // =====================================
        //              TABLEAU
        // =====================================
        $header = ['N°', 'Tontine', 'Date', 'Heure Début', 'Heure Fin', 'Lieu', 'Statut'];
        $w = [10, 45, 25, 25, 25, 35, 25];

        // En-tête tableau
        $pdf->SetFillColor(15, 26, 58);
        $pdf->SetTextColor(255);
        $pdf->SetFont('helvetica', 'B', 10);

        for ($i = 0; $i < count($header); $i++) {
            $pdf->Cell($w[$i], 10, $header[$i], 1, 0, 'C', true);
        }
        $pdf->Ln();

        // Lignes de données
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor(0);
        $fill = false;

        $counter = 1;
        foreach ($seances_pdf as $s) {
            $date_seance = date('d/m/Y', strtotime($s['date_seance']));
            $today = date('Y-m-d');
            $statut = strtotime($s['date_seance']) < strtotime($today) ? 'PASSÉE' : 'À VENIR';
            $statut_color = strtotime($s['date_seance']) < strtotime($today) ? [100, 100, 100] : [40, 167, 69];

            $pdf->SetTextColor(0);
            $pdf->Cell($w[0], 9, $counter, 1, 0, 'C', $fill);
            $pdf->Cell($w[1], 9, substr($s['nom_tontine'] ?? 'Non spécifiée', 0, 25), 1, 0, 'L', $fill);
            $pdf->Cell($w[2], 9, $date_seance, 1, 0, 'C', $fill);
            $pdf->Cell($w[3], 9, $s['heure_debut'], 1, 0, 'C', $fill);
            $pdf->Cell($w[4], 9, $s['heure_fin'] ?? '-', 1, 0, 'C', $fill);
            $pdf->Cell($w[5], 9, substr($s['lieu'] ?? '', 0, 20), 1, 0, 'L', $fill);

            // Statut avec couleur
            $pdf->SetTextColor(...$statut_color);
            $pdf->Cell($w[6], 9, $statut, 1, 0, 'C', $fill);
            $pdf->SetTextColor(0);

            $pdf->Ln();
            $fill = !$fill;
            $counter++;
        }

        // =====================================
        //             PIED DE PAGE
        // =====================================
        $pdf->SetY(-25);
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->SetTextColor(120);
        $pdf->Cell(0, 10, 'Document généré le ' . date('d/m/Y H:i:s') . '  © ' . date('Y') . ' TontinePro', 0, 0, 'C');

        // Sortie du PDF
        $filename = 'seances_tontine_' . date('Ymd_His') . '.pdf';

        ob_end_clean();
        $pdf->Output($filename, 'D');

        exit();

    } catch (Exception $e) {
        $_SESSION['flash_message'] = "Erreur lors de la génération du PDF : " . $e->getMessage();
        $_SESSION['flash_type'] = "error";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// --- TRAITEMENT DU FORMULAIRE D'AJOUT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_tontine'])) {
    $form_data['id_tontine'] = sanitizeInput($_POST['id_tontine'] ?? '');
    $form_data['date_seance'] = sanitizeInput($_POST['date_seance'] ?? '');
    $form_data['heure_debut'] = sanitizeInput($_POST['heure_debut'] ?? '');
    $form_data['heure_fin'] = sanitizeInput($_POST['heure_fin'] ?? '');
    $form_data['lieu'] = sanitizeInput($_POST['lieu'] ?? '');
    $form_data['ordre_du_jour'] = sanitizeInput($_POST['ordre_du_jour'] ?? '');

    $errors = [];

    if (empty($form_data['id_tontine'])) $errors[] = "La tontine est obligatoire";
    if (empty($form_data['date_seance'])) {
        $errors[] = "La date de séance est obligatoire";
    } elseif (!validateDate($form_data['date_seance'])) {
        $errors[] = "La date de séance n'est pas valide";
    }

    if (empty($form_data['heure_debut'])) {
        $errors[] = "L'heure de début est obligatoire";
    } elseif (!validateTime($form_data['heure_debut'])) {
        $errors[] = "L'heure de début n'est pas valide";
    }

    if (!empty($form_data['heure_fin']) && !validateTime($form_data['heure_fin'])) {
        $errors[] = "L'heure de fin n'est pas valide";
    }

    if (empty($form_data['lieu'])) {
        $errors[] = "Le lieu est obligatoire";
    }

    if (empty($errors)) {
        try {
            $sql = "INSERT INTO seance (id_tontine, date_seance, heure_debut, heure_fin, lieu, ordre_du_jour) 
                    VALUES (?, ?, ?, ?, ?, ?)";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $form_data['id_tontine'], 
                $form_data['date_seance'], 
                $form_data['heure_debut'], 
                $form_data['heure_fin'], 
                $form_data['lieu'], 
                $form_data['ordre_du_jour']
            ]);

            $_SESSION['flash_message'] = "Séance créée avec succès!";
            $_SESSION['flash_type'] = "success";

            $form_data = [
                'id_tontine' => '',
                'date_seance' => '',
                'heure_debut' => '',
                'heure_fin' => '',
                'lieu' => '',
                'ordre_du_jour' => ''
            ];
            
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
            
        } catch (PDOException $e) {
            $message = "Erreur lors de la création: " . $e->getMessage();
            $message_type = "error";
        }
    } else {
        $message = implode("<br>", $errors);
        $message_type = "error";
    }
}

// --- RÉCUPÉRATION DES MESSAGES FLASH ---
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $message_type = $_SESSION['flash_type'];
    unset($_SESSION['flash_message']);
    unset($_SESSION['flash_type']);
}

// --- RÉCUPÉRATION DES TONTINES POUR LE SELECT ---
try {
    $stmt = $pdo->query("SELECT id_tontine, nom_tontine FROM tontine WHERE statut = 'active' ORDER BY nom_tontine");
    $tontines = $stmt->fetchAll();
} catch (PDOException $e) {
    $tontines = [];
}

// --- RÉCUPÉRATION DES SÉANCES ---
try {
    $stmt = $pdo->query("SELECT s.*, t.nom_tontine 
                         FROM seance s 
                         LEFT JOIN tontine t ON s.id_tontine = t.id_tontine 
                         ORDER BY s.date_seance DESC, s.heure_debut");
    $seances = $stmt->fetchAll();
} catch (PDOException $e) {
    $seances = [];
    if (empty($message)) {
        $message = "Erreur lors de la récupération des séances: " . $e->getMessage();
        $message_type = "error";
    }
}

// Calcul des statistiques
$total = count($seances);
$today = date('Y-m-d');
$seances_passees = array_filter($seances, function ($s) use ($today) {
    return isset($s['date_seance']) && strtotime($s['date_seance']) < strtotime($today);
});
$seances_a_venir = $total - count($seances_passees);
$seances_aujourdhui = array_filter($seances, function ($s) use ($today) {
    return isset($s['date_seance']) && $s['date_seance'] === $today;
});
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Séances | TontinePro</title>
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

        .action-btn.export-pdf {
            background: linear-gradient(135deg, #dc3545 0%, #e74c3c 100%);
        }

        .action-btn.export-pdf:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(220, 53, 69, 0.4);
        }

        .action-btn.print {
            background: linear-gradient(135deg, var(--warning-color) 0%, #ffd54f 100%);
            color: var(--text-dark);
        }

        .action-btn.print:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(255, 193, 7, 0.4);
        }

        .action-btn.stats {
            background: linear-gradient(135deg, var(--success-color) 0%, #20c997 100%);
        }

        .action-btn.stats:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(40, 167, 69, 0.4);
        }

        .export-form {
            display: inline;
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
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

        /* Form Styles */
        .form-group {
            margin-bottom: 26px;
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
            padding: 16px 20px;
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

        .input-with-icon {
            position: relative;
        }

        .input-with-icon i {
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--accent-gold);
            font-size: 1.1rem;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 110px;
        }

        /* Buttons */
        .btn-group {
            display: flex;
            gap: 16px;
            margin-top: 24px;
        }

        .btn {
            padding: 16px 32px;
            border-radius: 12px;
            font-family: inherit;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            font-size: 1rem;
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

        .btn-secondary {
            background: var(--pure-white);
            color: var(--medium-blue);
            border: 2px solid rgba(45, 74, 138, 0.3);
        }

        .btn-secondary:hover {
            background: var(--bg-light);
            border-color: var(--accent-gold);
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
            height: 40vh;
            scrollbar-width: 1px solid var(--accent-light);
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
            background: linear-gradient(90deg, rgba(212, 175, 55, 0.08), transparent);
            transform: scale(1.01);
        }

        .seances-table td {
            padding: 18px;
            color: var(--text-dark);
            font-size: 0.95rem;
            vertical-align: middle;
        }

        .seance-id {
            font-weight: 800;
            color: var(--medium-blue);
            font-size: 1.1rem;
        }

        .seance-info {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            color: var(--text-light);
            margin-top: 5px;
        }

        .seance-info i {
            width: 16px;
            color: var(--accent-gold);
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
        }

        .status-badge i {
            font-size: 0.7rem;
        }

        .status-upcoming {
            background: linear-gradient(135deg, rgba(40, 167, 69, 0.2), rgba(32, 201, 151, 0.2));
            color: var(--success-color);
            border: 2px solid rgba(40, 167, 69, 0.4);
        }

        .status-past {
            background: linear-gradient(135deg, rgba(108, 117, 125, 0.2), rgba(173, 181, 189, 0.2));
            color: #6c757d;
            border: 2px solid rgba(108, 117, 125, 0.4);
        }

        .status-today {
            background: linear-gradient(135deg, rgba(255, 193, 7, 0.2), rgba(255, 220, 79, 0.2));
            color: var(--warning-color);
            border: 2px solid rgba(255, 193, 7, 0.4);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
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
            font-size: 2.8rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--medium-blue), var(--light-blue));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 10px;
            line-height: 1;
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
        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

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
                padding: 24px;
            }

            .btn-group {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .seances-table th:nth-child(5),
            .seances-table td:nth-child(5) {
                display: none;
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
                font-size: 2.2rem;
            }

            .seances-table th:nth-child(4),
            .seances-table td:nth-child(4) {
                display: none;
            }
        }

        @media print {
            .main-header,
            .btn-group,
            .quick-actions,
            .back-to-dashboard {
                display: none;
            }
            
            .card {
                box-shadow: none;
                border: 1px solid #ddd;
            }
            
            .seances-table {
                min-width: auto;
            }
        }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
        <p>Génération du document en cours...</p>
        <div class="loading-text">Veuillez patienter quelques secondes</div>
    </div>

    <!-- Header -->
    <header class="main-header">
        <div class="header-content">
            <div class="logo">
                <div class="logo-icon">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="logo-text">
                    <h1>Gestion des Séances</h1>
                    <p>Planification et suivi des séances de tontine</p>
                </div>
            </div>
            
            <div class="user-info">
                <div class="user-avatar">
                    <?php echo isset($_SESSION['nom']) ? strtoupper(substr($_SESSION['nom'], 0, 1)) : 'A'; ?>
                </div>
                <div class="user-details">
                    <h4><?php echo htmlspecialchars($_SESSION['nom'] ?? 'Administrateur'); ?></h4>
                    <p><?php echo htmlspecialchars($_SESSION['role'] ?? 'Admin'); ?></p>
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
            <form method="POST" action="" class="export-form" id="exportPdfForm" style="display: none;">
                <button type="submit" name="export_pdf" class="action-btn export-pdf" id="exportPdfBtn">
                    <i class="fas fa-file-pdf"></i> Exporter en PDF
                </button>
            </form>
            
            <button class="action-btn print" onclick="window.print()">
                <i class="fas fa-print"></i> Imprimer
            </button>
            
            <button class="action-btn stats" id="showStatsBtn">
                <i class="fas fa-chart-bar"></i> Voir Statistiques
            </button>
        </div>

        <!-- Content Grid -->
        <div class="content-grid">
            <!-- Form Card -->
            <div class="card" style="animation: fadeInUp 0.6s ease-out;">
                <div class="card-header">
                    <h2><i class="fas fa-plus-circle"></i> Planifier une Nouvelle Séance</h2>
                </div>
                <div class="card-body">
                    <?php if ($message): ?>
                        <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : ($message_type === 'warning' ? 'warning' : 'error'); ?>">
                            <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'warning' ? 'exclamation-triangle' : 'exclamation-circle'); ?>"></i>
                            <span><?php echo $message; ?></span>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="" id="seanceForm">
                        <div class="form-group">
                            <label for="id_tontine" class="form-label">
                                <i class="fas fa-hand-holding-usd"></i> Tontine <span class="required">*</span>
                            </label>
                            <select id="id_tontine" name="id_tontine" class="form-control" required>
                                <option value="">Sélectionnez une tontine</option>
                                <?php foreach ($tontines as $tontine): ?>
                                    <option value="<?php echo $tontine['id_tontine']; ?>" 
                                        <?php echo ($form_data['id_tontine'] == $tontine['id_tontine']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($tontine['nom_tontine']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="date_seance" class="form-label">
                                <i class="fas fa-calendar-day"></i> Date de la Séance <span class="required">*</span>
                            </label>
                            <input type="date"
                                id="date_seance"
                                name="date_seance"
                                class="form-control"
                                value="<?php echo htmlspecialchars($form_data['date_seance']); ?>"
                                required
                                min="<?php echo date('Y-m-d'); ?>">
                        </div>

                        <div class="form-group">
                            <label for="heure_debut" class="form-label">
                                <i class="fas fa-clock"></i> Heure de Début <span class="required">*</span>
                            </label>
                            <div class="input-with-icon">
                                <input type="time"
                                    id="heure_debut"
                                    name="heure_debut"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($form_data['heure_debut']); ?>"
                                    required>
                                <i class="fas fa-play-circle"></i>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="heure_fin" class="form-label">
                                <i class="fas fa-clock"></i> Heure de Fin (Optionnelle)
                            </label>
                            <div class="input-with-icon">
                                <input type="time"
                                    id="heure_fin"
                                    name="heure_fin"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($form_data['heure_fin']); ?>">
                                <i class="fas fa-stop-circle"></i>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="lieu" class="form-label">
                                <i class="fas fa-map-marker-alt"></i> Lieu <span class="required">*</span>
                            </label>
                            <input type="text"
                                id="lieu"
                                name="lieu"
                                class="form-control"
                                value="<?php echo htmlspecialchars($form_data['lieu']); ?>"
                                required
                                maxlength="100"
                                placeholder="Ex: Siège social, Salle de réunion..."
                                autocomplete="off">
                        </div>

                        <div class="form-group">
                            <label for="ordre_du_jour" class="form-label">
                                <i class="fas fa-clipboard-list"></i> Ordre du Jour (Optionnel)
                            </label>
                            <textarea
                                id="ordre_du_jour"
                                name="ordre_du_jour"
                                class="form-control"
                                rows="3"
                                placeholder="Points à aborder lors de la séance..."
                                autocomplete="off"><?php echo htmlspecialchars($form_data['ordre_du_jour']); ?></textarea>
                        </div>

                        <div class="btn-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-calendar-plus"></i> Planifier la Séance
                            </button>
                            <button type="reset" class="btn btn-secondary" id="resetFormBtn">
                                <i class="fas fa-redo"></i> Réinitialiser
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Seances List Card -->
            <div class="card" style="animation: fadeInUp 0.6s ease-out 0.2s both;">
                <div class="card-header">
                    <h2><i class="fas fa-list"></i> Calendrier des Séances <span style="font-weight: normal; opacity: 0.9;">(<?php echo count($seances); ?>)</span></h2>
                </div>
                <div class="card-body">
                    <?php if (count($seances) > 0): ?>
                        <div class="table-responsive">
                            <table class="seances-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Tontine</th>
                                        <th>Date</th>
                                        <th>Heure</th>
                                        <th>Lieu</th>
                                        <th>Statut</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($seances as $seance): ?>
                                        <?php
                                        $date_seance = $seance['date_seance'];
                                        $today = date('Y-m-d');
                                        $statut_class = '';
                                        $statut_text = '';
                                        
                                        if ($date_seance == $today) {
                                            $statut_class = 'status-today';
                                            $statut_text = 'AUJOURD\'HUI';
                                        } elseif (strtotime($date_seance) < strtotime($today)) {
                                            $statut_class = 'status-past';
                                            $statut_text = 'PASSÉE';
                                        } else {
                                            $statut_class = 'status-upcoming';
                                            $statut_text = 'À VENIR';
                                        }
                                        ?>
                                        <tr>
                                            <td><span class="seance-id">#<?php echo $seance['id_seance']; ?></span></td>
                                            <td>
                                                <div style="font-weight: 700; color: var(--navy-blue);">
                                                    <?php echo htmlspecialchars($seance['nom_tontine'] ?? 'Non spécifiée'); ?>
                                                </div>
                                                <?php if (!empty($seance['ordre_du_jour'])): ?>
                                                    <div class="seance-info">
                                                        <i class="fas fa-clipboard"></i>
                                                        <span><?php echo htmlspecialchars(substr($seance['ordre_du_jour'], 0, 25) . (strlen($seance['ordre_du_jour']) > 25 ? '...' : '')); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div style="font-weight: 600;">
                                                    <?php echo date('d/m/Y', strtotime($date_seance)); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="seance-info">
                                                    <i class="fas fa-clock"></i>
                                                    <?php echo htmlspecialchars($seance['heure_debut']); ?>
                                                    <?php if (!empty($seance['heure_fin'])): ?>
                                                        - <?php echo htmlspecialchars($seance['heure_fin']); ?>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="seance-info">
                                                    <i class="fas fa-map-marker-alt"></i>
                                                    <?php echo htmlspecialchars(substr($seance['lieu'] ?? '', 0, 20) . (strlen($seance['lieu'] ?? '') > 20 ? '...' : '')); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="status-badge <?php echo $statut_class; ?>">
                                                    <i class="fas fa-circle"></i>
                                                    <?php echo $statut_text; ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Statistics -->
                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="fas fa-calendar-alt"></i>
                                </div>
                                <div class="stat-number"><?php echo $total; ?></div>
                                <div class="stat-label">Total Séances</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="fas fa-calendar-check"></i>
                                </div>
                                <div class="stat-number"><?php echo $seances_a_venir; ?></div>
                                <div class="stat-label">À Venir</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="fas fa-history"></i>
                                </div>
                                <div class="stat-number"><?php echo count($seances_passees); ?></div>
                                <div class="stat-label">Passées</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="fas fa-sun"></i>
                                </div>
                                <div class="stat-number"><?php echo count($seances_aujourdhui); ?></div>
                                <div class="stat-label">Aujourd'hui</div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-calendar-times"></i>
                            <h3>Aucune séance planifiée</h3>
                            <p>Planifiez une nouvelle séance en utilisant le formulaire ci-contre.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        $(document).ready(function() {
            let pdfExportInProgress = false;
            
            $('#exportPdfForm').on('submit', function(e) {
                <?php if (count($seances) == 0): ?>
                    e.preventDefault();
                    Swal.fire({
                        icon: 'warning',
                        title: 'Aucune séance',
                        text: 'Il n\'y a aucune séance à exporter.',
                        confirmButtonColor: '#2d4a8a'
                    });
                    return false;
                <?php else: ?>
                    pdfExportInProgress = true;
                    $('#loadingOverlay').fadeIn();
                    $('#exportPdfBtn').prop('disabled', true).css('opacity', '0.7');
                    
                    setTimeout(function() {
                        if (pdfExportInProgress) {
                            $('#loadingOverlay').fadeOut();
                            $('#exportPdfBtn').prop('disabled', false).css('opacity', '1');
                            pdfExportInProgress = false;
                        }
                    }, 30000);
                <?php endif; ?>
            });
            
            $(window).on('pageshow load', function() {
                if (pdfExportInProgress) {
                    pdfExportInProgress = false;
                    $('#loadingOverlay').fadeOut();
                    $('#exportPdfBtn').prop('disabled', false).css('opacity', '1');
                }
            });

            $('#seanceForm').on('submit', function(e) {
                let isValid = true;
                const inputs = $(this).find('input[required], select[required], textarea[required]');
                
                inputs.css('border-color', 'rgba(45, 74, 138, 0.2)');
                
                inputs.each(function() {
                    if (!$(this).val().trim()) {
                        isValid = false;
                        $(this).css('border-color', '#dc3545');
                    }
                });

                const dateSeance = $('#date_seance');
                const heureDebut = $('#heure_debut');
                const heureFin = $('#heure_fin');
                const today = new Date().toISOString().split('T')[0];

                if (dateSeance.val() && dateSeance.val() < today) {
                    isValid = false;
                    dateSeance.css('border-color', '#dc3545');
                    showError('La date de séance doit être aujourd\'hui ou une date future.');
                }

                if (heureFin.val() && heureDebut.val() && heureFin.val() <= heureDebut.val()) {
                    isValid = false;
                    heureFin.css('border-color', '#dc3545');
                    showError('L\'heure de fin doit être postérieure à l\'heure de début.');
                }

                if (!isValid) {
                    e.preventDefault();
                    Swal.fire({
                        icon: 'error',
                        title: 'Données invalides',
                        text: 'Veuillez vérifier les informations saisies.',
                        confirmButtonColor: '#2d4a8a'
                    });
                } else {
                    const submitBtn = $(this).find('button[type="submit"]');
                    submitBtn.html('<i class="fas fa-spinner fa-spin"></i> Planification...');
                    submitBtn.prop('disabled', true);
                }
            });

            function showError(message) {
                $('.alert-error').remove();
                $('#seanceForm').prepend(`
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <span>${message}</span>
                    </div>
                `);
            }

            $('input, select, textarea').on('input change', function() {
                $(this).css('border-color', 'rgba(45, 74, 138, 0.2)');
                $('.alert-error').fadeOut();
            });

            $('#resetFormBtn').on('click', function() {
                $('#seanceForm')[0].reset();
                $('input, select, textarea').css('border-color', 'rgba(45, 74, 138, 0.2)');
                $('.alert-error').fadeOut();
            });

            $('#showStatsBtn').on('click', function() {
                <?php
                $total = count($seances);
                $today = date('Y-m-d');
                $seances_passees = array_filter($seances, function ($s) use ($today) {
                    return isset($s['date_seance']) && strtotime($s['date_seance']) < strtotime($today);
                });
                $seances_a_venir = $total - count($seances_passees);
                $seances_aujourdhui = array_filter($seances, function ($s) use ($today) {
                    return isset($s['date_seance']) && $s['date_seance'] === $today;
                });
                ?>
                
                Swal.fire({
                    title: '📊 Statistiques des Séances',
                    html: `
                        <div style="text-align: left; margin: 20px 0;">
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px;">
                                <div style="padding: 20px; background: linear-gradient(135deg, rgba(45, 74, 138, 0.1), rgba(58, 95, 192, 0.1)); border-radius: 12px; border-left: 4px solid #2d4a8a;">
                                    <strong style="color: #2d4a8a;">Total Séances:</strong><br>
                                    <span style="font-size: 2rem; color: #2d4a8a; font-weight: 800;"><?php echo $total; ?></span>
                                </div>
                                <div style="padding: 20px; background: linear-gradient(135deg, rgba(40, 167, 69, 0.1), rgba(32, 201, 151, 0.1)); border-radius: 12px; border-left: 4px solid #28a745;">
                                    <strong style="color: #28a745;">À Venir:</strong><br>
                                    <span style="font-size: 2rem; color: #28a745; font-weight: 800;"><?php echo $seances_a_venir; ?></span>
                                </div>
                                <div style="padding: 20px; background: linear-gradient(135deg, rgba(108, 117, 125, 0.1), rgba(173, 181, 189, 0.1)); border-radius: 12px; border-left: 4px solid #6c757d;">
                                    <strong style="color: #6c757d;">Passées:</strong><br>
                                    <span style="font-size: 2rem; color: #6c757d; font-weight: 800;"><?php echo count($seances_passees); ?></span>
                                </div>
                                <div style="padding: 20px; background: linear-gradient(135deg, rgba(255, 193, 7, 0.1), rgba(255, 220, 79, 0.1)); border-radius: 12px; border-left: 4px solid #ffc107;">
                                    <strong style="color: #ffc107;">Aujourd'hui:</strong><br>
                                    <span style="font-size: 2rem; color: #ffc107; font-weight: 800;"><?php echo count($seances_aujourdhui); ?></span>
                                </div>
                            </div>
                            <div style="background: rgba(248, 250, 252, 0.9); padding: 20px; border-radius: 12px; border: 2px solid rgba(212, 175, 55, 0.3);">
                                <h4 style="margin-top: 0; color: #1a2b55;">📈 Répartition</h4>
                                <div style="margin: 16px 0;">
                                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                                        <div style="flex: 1; height: 12px; background: #e2e8f0; border-radius: 6px; overflow: hidden;">
                                            <div style="height: 100%; width: <?php echo $total > 0 ? ($seances_a_venir/$total)*100 : 0; ?>%; background: linear-gradient(90deg, #28a745, #20c997);"></div>
                                        </div>
                                        <span style="font-size: 1rem; color: #28a745; font-weight: 700;"><?php echo $total > 0 ? round(($seances_a_venir/$total)*100) : 0; ?>% À Venir</span>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                                        <div style="flex: 1; height: 12px; background: #e2e8f0; border-radius: 6px; overflow: hidden;">
                                            <div style="height: 100%; width: <?php echo $total > 0 ? (count($seances_passees)/$total)*100 : 0; ?>%; background: linear-gradient(90deg, #6c757d, #adb5bd);"></div>
                                        </div>
                                        <span style="font-size: 1rem; color: #6c757d; font-weight: 700;"><?php echo $total > 0 ? round((count($seances_passees)/$total)*100) : 0; ?>% Passées</span>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 12px;">
                                        <div style="flex: 1; height: 12px; background: #e2e8f0; border-radius: 6px; overflow: hidden;">
                                            <div style="height: 100%; width: <?php echo $total > 0 ? (count($seances_aujourdhui)/$total)*100 : 0; ?>%; background: linear-gradient(90deg, #ffc107, #ffd54f);"></div>
                                        </div>
                                        <span style="font-size: 1rem; color: #ffc107; font-weight: 700;"><?php echo $total > 0 ? round((count($seances_aujourdhui)/$total)*100) : 0; ?>% Aujourd'hui</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `,
                    width: 700,
                    showCloseButton: true,
                    confirmButtonText: 'Fermer',
                    confirmButtonColor: '#2d4a8a',
                    backdrop: 'rgba(15, 26, 58, 0.5)'
                });
            });

            // Définir l'heure de début par défaut (maintenant + 1 heure)
            const now = new Date();
            now.setHours(now.getHours() + 1);
            const defaultTime = now.toTimeString().slice(0, 5);
            $('#heure_debut').val(defaultTime);

            // Définir l'heure de fin par défaut (début + 2 heures)
            now.setHours(now.getHours() + 2);
            const defaultEndTime = now.toTimeString().slice(0, 5);
            $('#heure_fin').val(defaultEndTime);

            // Définir la date minimale à aujourd'hui
            $('#date_seance').attr('min', new Date().toISOString().split('T')[0]);
        });
    </script>
</body>
</html>
<?php
if (isset($pdo)) {
    $pdo = null;
}
?>