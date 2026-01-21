<?php
@include './fonctions/config.php';
session_start();

// Vérification de session ADMIN
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header("Location: index.php");
    exit();
}

// Fonctions utilitaires
function sanitizeInput($data)
{
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
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

// Initialisation des variables
$message = '';
$message_type = '';
$tontines = [];
$form_data = [
    'nom_tontine' => '',
    'type_tontine' => '',
    'montant_cotisation' => '',
    'frequence' => '',
    'date_debut' => '',
    'date_fin' => ''
];

// --- TRAITEMENT DE L'EXPORT PDF avec TCPDF ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_pdf'])) {
    try {
        // Vérification rapide s'il y a des données
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM tontine");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['total'] == 0) {
            $_SESSION['flash_message'] = "Aucune tontine à exporter.";
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
        $pdf->Cell(0, 10, 'LISTE DES TONTINES', 0, 1, 'C');

        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(100);
        $pdf->Cell(0, 6, 'Date d\'export : ' . date('d/m/Y à H:i'), 0, 1, 'C');
        $pdf->Cell(0, 6, 'Généré par : ' . ($_SESSION['nom'] ?? 'Administrateur'), 0, 1, 'C');

        $pdf->Ln(10);

        // =====================================
        //          STATISTIQUES
        // =====================================
        $stmt = $pdo->query("SELECT * FROM tontine ORDER BY date_debut DESC");
        $tontines_pdf = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total = count($tontines_pdf);
        $actives = count(array_filter($tontines_pdf, fn($t) => ($t['statut'] ?? '') === 'active'));
        $inactives = count(array_filter($tontines_pdf, fn($t) => ($t['statut'] ?? '') === 'inactive'));
        $pending = $total - $actives - $inactives;
        
        $obligatoires = count(array_filter($tontines_pdf, fn($t) => ($t['type_tontine'] ?? '') === 'obligatoire'));
        $optionnels = count(array_filter($tontines_pdf, fn($t) => ($t['type_tontine'] ?? '') === 'optionnel'));

        $pdf->SetFillColor(240, 248, 255);
        $pdf->SetTextColor(45, 74, 138);
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 10, "Statistiques", 0, 1, 'L', true);

        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(0);
        $pdf->Cell(0, 8, "Total tontines : $total", 0, 1);
        $pdf->SetTextColor(40, 167, 69);
        $pdf->Cell(0, 8, "Tontines actives : $actives", 0, 1);
        $pdf->SetTextColor(220, 53, 69);
        $pdf->Cell(0, 8, "Tontines inactives : $inactives", 0, 1);
        $pdf->SetTextColor(255, 193, 7);
        $pdf->Cell(0, 8, "Tontines en attente : $pending", 0, 1);
        $pdf->SetTextColor(45, 74, 138);
        $pdf->Cell(0, 8, "Tontines obligatoires : $obligatoires", 0, 1);
        $pdf->SetTextColor(58, 95, 192);
        $pdf->Cell(0, 8, "Tontines optionnelles : $optionnels", 0, 1);

        $pdf->Ln(12);

        // =====================================
        //              TABLEAU
        // =====================================
        $header = ['N°', 'Nom', 'Type', 'Montant', 'Fréquence', 'Début', 'Fin', 'Statut'];
        $w = [12, 45, 25, 30, 25, 25, 25, 20];

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
        foreach ($tontines_pdf as $t) {
            $nom = $t['nom_tontine'] ?? '';
            if (mb_strlen($nom) > 35) {
                $nom = mb_substr($nom, 0, 32) . '...';
            }

            $montant = number_format($t['montant_cotisation'] ?? 0, 0, ',', ' ') . ' FCFA';
            $date_debut = $t['date_debut'] ? date('d/m/Y', strtotime($t['date_debut'])) : '---';
            $date_fin = $t['date_fin'] ? date('d/m/Y', strtotime($t['date_fin'])) : '---';
            $statut = strtoupper($t['statut'] ?? 'inconnu');
            
            // Couleur selon le statut
            if ($t['statut'] === 'active') {
                $statut_color = [40, 167, 69];
            } elseif ($t['statut'] === 'inactive') {
                $statut_color = [220, 53, 69];
            } else {
                $statut_color = [255, 193, 7];
            }

            $pdf->SetTextColor(0);
            $pdf->Cell($w[0], 9, $counter, 1, 0, 'C', $fill);
            $pdf->Cell($w[1], 9, $nom, 1, 0, 'L', $fill);
            
            // Type avec couleur
            $type = $t['type_tontine'] ?? '';
            if ($type === 'obligatoire') {
                $pdf->SetTextColor(220, 53, 69);
            } else {
                $pdf->SetTextColor(58, 95, 192);
            }
            $pdf->Cell($w[2], 9, ucfirst($type), 1, 0, 'C', $fill);
            
            $pdf->SetTextColor(0);
            $pdf->Cell($w[3], 9, $montant, 1, 0, 'R', $fill);
            $pdf->Cell($w[4], 9, ucfirst($t['frequence'] ?? ''), 1, 0, 'C', $fill);
            $pdf->Cell($w[5], 9, $date_debut, 1, 0, 'C', $fill);
            $pdf->Cell($w[6], 9, $date_fin, 1, 0, 'C', $fill);
            
            // Statut avec couleur
            $pdf->SetTextColor(...$statut_color);
            $pdf->Cell($w[7], 9, $statut, 1, 0, 'C', $fill);
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
        $filename = 'tontines_' . date('Ymd_His') . '.pdf';

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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nom_tontine'])) {
    $form_data['nom_tontine'] = sanitizeInput($_POST['nom_tontine'] ?? '');
    $form_data['type_tontine'] = sanitizeInput($_POST['type_tontine'] ?? '');
    $form_data['montant_cotisation'] = sanitizeInput($_POST['montant_cotisation'] ?? '');
    $form_data['frequence'] = sanitizeInput($_POST['frequence'] ?? '');
    $form_data['date_debut'] = sanitizeInput($_POST['date_debut'] ?? '');
    $form_data['date_fin'] = sanitizeInput($_POST['date_fin'] ?? '');
    $statut = 'active';

    $errors = [];

    if (empty($form_data['nom_tontine'])) $errors[] = "Le nom de la tontine est obligatoire";
    if (empty($form_data['type_tontine'])) $errors[] = "Le type de tontine est obligatoire";

    if (empty($form_data['montant_cotisation'])) {
        $errors[] = "Le montant de cotisation est obligatoire";
    } elseif (!validateDecimal($form_data['montant_cotisation'])) {
        $errors[] = "Le montant de cotisation n'est pas valide";
    }

    if (empty($form_data['frequence'])) {
        $errors[] = "La fréquence est obligatoire";
    }

    if (empty($form_data['date_debut'])) {
        $errors[] = "La date de début est obligatoire";
    } elseif (!validateDate($form_data['date_debut'])) {
        $errors[] = "La date de début n'est pas valide";
    }

    if (!empty($form_data['date_fin']) && !validateDate($form_data['date_fin'])) {
        $errors[] = "La date de fin n'est pas valide";
    }

    if (empty($errors)) {
        try {
            $sql = "INSERT INTO tontine (nom_tontine, type_tontine, montant_cotisation, frequence, date_debut, date_fin, statut) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $form_data['nom_tontine'], 
                $form_data['type_tontine'], 
                $form_data['montant_cotisation'], 
                $form_data['frequence'], 
                $form_data['date_debut'], 
                $form_data['date_fin'], 
                $statut
            ]);

            $_SESSION['flash_message'] = "Tontine créée avec succès!";
            $_SESSION['flash_type'] = "success";

            $form_data = [
                'nom_tontine' => '',
                'type_tontine' => '',
                'montant_cotisation' => '',
                'frequence' => '',
                'date_debut' => '',
                'date_fin' => ''
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

// --- RÉCUPÉRATION DES TONTINES ---
try {
    $stmt = $pdo->query("SELECT * FROM tontine ORDER BY date_debut DESC");
    $tontines = $stmt->fetchAll();
} catch (PDOException $e) {
    $tontines = [];
    if (empty($message)) {
        $message = "Erreur lors de la récupération des tontines: " . $e->getMessage();
        $message_type = "error";
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Tontines | TontinePro</title>
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

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 20px center;
            background-size: 16px;
            padding-right: 40px;
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
        }

        .tontines-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
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
            background: linear-gradient(90deg, rgba(212, 175, 55, 0.08), transparent);
            transform: scale(1.01);
        }

        .tontines-table td {
            padding: 18px;
            color: var(--text-dark);
            font-size: 0.95rem;
            vertical-align: middle;
        }

        .tontine-id {
            font-weight: 800;
            color: var(--medium-blue);
            font-size: 1.1rem;
        }

        .tontine-name {
            font-weight: 700;
            color: var(--navy-blue);
            font-size: 1rem;
        }

        .tontine-info {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            color: var(--text-light);
            margin-top: 5px;
        }

        .tontine-info i {
            width: 16px;
            color: var(--accent-gold);
        }

        /* Type Badge */
        .type-badge {
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

        .type-obligatoire {
            background: linear-gradient(135deg, rgba(220, 53, 69, 0.2), rgba(231, 76, 60, 0.2));
            color: var(--danger-color);
            border: 2px solid rgba(220, 53, 69, 0.4);
        }

        .type-optionnel {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.2), rgba(96, 165, 250, 0.2));
            color: #1e40af;
            border: 2px solid rgba(59, 130, 246, 0.4);
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

        .status-active {
            background: linear-gradient(135deg, rgba(40, 167, 69, 0.2), rgba(32, 201, 151, 0.2));
            color: var(--success-color);
            border: 2px solid rgba(40, 167, 69, 0.4);
        }

        .status-inactive {
            background: linear-gradient(135deg, rgba(220, 53, 69, 0.2), rgba(231, 76, 60, 0.2));
            color: var(--danger-color);
            border: 2px solid rgba(220, 53, 69, 0.4);
        }

        .status-pending {
            background: linear-gradient(135deg, rgba(255, 193, 7, 0.2), rgba(255, 221, 87, 0.2));
            color: var(--warning-color);
            border: 2px solid rgba(255, 193, 7, 0.4);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
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

            .tontines-table th:nth-child(5),
            .tontines-table td:nth-child(5),
            .tontines-table th:nth-child(6),
            .tontines-table td:nth-child(6) {
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

            .tontines-table th:nth-child(3),
            .tontines-table td:nth-child(3) {
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
            
            .tontines-table {
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
                    <i class="fas fa-hand-holding-usd"></i>
                </div>
                <div class="logo-text">
                    <h1>Gestion des Tontines</h1>
                    <p>Création et administration des tontines</p>
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
            <form method="POST" action="" class="export-form" id="exportPdfForm">
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
            <div class="card" style="animation: fadeInUp 0.6s ease-out;height:130vh;">
                <div class="card-header">
                    <h2><i class="fas fa-hand-holding-usd"></i> Créer une Nouvelle Tontine</h2>
                </div>
                <div class="card-body">
                    <?php if ($message): ?>
                        <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : ($message_type === 'warning' ? 'warning' : 'error'); ?>">
                            <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'warning' ? 'exclamation-triangle' : 'exclamation-circle'); ?>"></i>
                            <span><?php echo $message; ?></span>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="" id="tontineForm">
                        <div class="form-group">
                            <label for="nom_tontine" class="form-label">
                                <i class="fas fa-signature"></i> Nom de la Tontine <span class="required">*</span>
                            </label>
                            <input type="text"
                                id="nom_tontine"
                                name="nom_tontine"
                                class="form-control"
                                value="<?php echo htmlspecialchars($form_data['nom_tontine']); ?>"
                                required
                                maxlength="100"
                                placeholder="Ex: Tontine Mensuelle 2026"
                                autocomplete="off">
                        </div>

                        <div class="form-group">
                            <label for="type_tontine" class="form-label">
                                <i class="fas fa-tags"></i> Type de Tontine <span class="required">*</span>
                            </label>
                            <select id="type_tontine" name="type_tontine" class="form-control" required>
                                <option value="">Sélectionnez un type</option>
                                <option value="obligatoire" <?php echo ($form_data['type_tontine'] ?? '') === 'obligatoire' ? 'selected' : ''; ?>>Obligatoire</option>
                                <option value="optionnel" <?php echo ($form_data['type_tontine'] ?? '') === 'optionnel' ? 'selected' : ''; ?>>Optionnel</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="montant_cotisation" class="form-label">
                                <i class="fas fa-money-bill-wave"></i> Montant de Cotisation <span class="required">*</span>
                            </label>
                            <div class="input-with-icon">
                                <input type="number"
                                    id="montant_cotisation"
                                    name="montant_cotisation"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($form_data['montant_cotisation']); ?>"
                                    required
                                    min="0"
                                    step="0.01"
                                    placeholder="0.00">
                                <i class="fas fa-money-bill"></i>
                            </div>
                            <small style="color: var(--text-light); font-size: 0.85rem; margin-top: 6px; display: block;">
                                Montant en FCFA (Ex: 50000.00)
                            </small>
                        </div>

                        <div class="form-group">
                            <label for="frequence" class="form-label">
                                <i class="fas fa-calendar-alt"></i> Fréquence des Cotisations <span class="required">*</span>
                            </label>
                            <select id="frequence" name="frequence" class="form-control" required>
                                <option value="">Sélectionnez une fréquence</option>
                                <option value="quotidienne" <?php echo ($form_data['frequence'] ?? '') === 'quotidienne' ? 'selected' : ''; ?>>Quotidienne</option>
                                <option value="hebdomadaire" <?php echo ($form_data['frequence'] ?? '') === 'hebdomadaire' ? 'selected' : ''; ?>>Hebdomadaire</option>
                                <option value="mensuelle" <?php echo ($form_data['frequence'] ?? '') === 'mensuelle' ? 'selected' : ''; ?>>Mensuelle</option>
                                <option value="trimestrielle" <?php echo ($form_data['frequence'] ?? '') === 'trimestrielle' ? 'selected' : ''; ?>>Trimestrielle</option>
                                <option value="annuelle" <?php echo ($form_data['frequence'] ?? '') === 'annuelle' ? 'selected' : ''; ?>>Annuelle</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="date_debut" class="form-label">
                                <i class="fas fa-calendar-plus"></i> Date de Début <span class="required">*</span>
                            </label>
                            <input type="date"
                                id="date_debut"
                                name="date_debut"
                                class="form-control"
                                value="<?php echo htmlspecialchars($form_data['date_debut']); ?>"
                                required>
                        </div>

                        <div class="form-group">
                            <label for="date_fin" class="form-label">
                                <i class="fas fa-calendar-minus"></i> Date de Fin (Optionnelle)
                            </label>
                            <input type="date"
                                id="date_fin"
                                name="date_fin"
                                class="form-control"
                                value="<?php echo htmlspecialchars($form_data['date_fin']); ?>">
                        </div>

                        <div class="btn-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-plus-circle"></i> Créer la Tontine
                            </button>
                            <button type="reset" class="btn btn-secondary" id="resetFormBtn">
                                <i class="fas fa-redo"></i> Réinitialiser
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Tontines List Card -->
            <div class="card" style="animation: fadeInUp 0.6s ease-out 0.2s both;">
                <div class="card-header">
                    <h2><i class="fas fa-list"></i> Liste des Tontines <span style="font-weight: normal; opacity: 0.9;">(<?php echo count($tontines); ?>)</span></h2>
                </div>
                <div class="card-body">
                    <?php if (count($tontines) > 0): ?>
                        <div class="table-responsive">
                            <table class="tontines-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Nom</th>
                                        <th>Type</th>
                                        <th>Montant</th>
                                        <th>Fréquence</th>
                                        <th>Début</th>
                                        <th>Fin</th>
                                        <th>Statut</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tontines as $tontine): ?>
                                        <tr>
                                            <td><span class="tontine-id">#<?php echo $tontine['id_tontine']; ?></span></td>
                                            <td>
                                                <div class="tontine-name"><?php echo htmlspecialchars($tontine['nom_tontine']); ?></div>
                                            </td>
                                            <td>
                                                <span class="type-badge type-<?php echo $tontine['type_tontine']; ?>">
                                                    <i class="fas fa-<?php echo $tontine['type_tontine'] === 'obligatoire' ? 'exclamation-circle' : 'check-circle'; ?>"></i>
                                                    <?php echo ucfirst($tontine['type_tontine']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="tontine-info">
                                                    <i class="fas fa-money-bill-wave"></i>
                                                    <strong><?php echo number_format($tontine['montant_cotisation'], 0, ',', ' '); ?> FCFA</strong>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="tontine-info">
                                                    <i class="fas fa-calendar"></i>
                                                    <?php echo htmlspecialchars(ucfirst($tontine['frequence'])); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="tontine-info">
                                                    <i class="fas fa-play-circle"></i>
                                                    <?php echo date('d/m/Y', strtotime($tontine['date_debut'])); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="tontine-info">
                                                    <i class="fas fa-stop-circle"></i>
                                                    <?php echo $tontine['date_fin'] ? date('d/m/Y', strtotime($tontine['date_fin'])) : '---'; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $tontine['statut']; ?>">
                                                    <i class="fas fa-circle"></i>
                                                    <?php echo ucfirst($tontine['statut']); ?>
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
                                    <i class="fas fa-hand-holding-usd"></i>
                                </div>
                                <div class="stat-number"><?php echo count($tontines); ?></div>
                                <div class="stat-label">Total Tontines</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="fas fa-exclamation-circle"></i>
                                </div>
                                <div class="stat-number">
                                    <?php 
                                    $obligatoires = array_filter($tontines, function ($t) { 
                                        return isset($t['type_tontine']) && $t['type_tontine'] === 'obligatoire'; 
                                    });
                                    echo count($obligatoires); 
                                    ?>
                                </div>
                                <div class="stat-label">Obligatoires</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                                <div class="stat-number">
                                    <?php
                                    $optionnels = array_filter($tontines, function ($t) {
                                        return isset($t['type_tontine']) && $t['type_tontine'] === 'optionnel';
                                    });
                                    echo count($optionnels);
                                    ?>
                                </div>
                                <div class="stat-label">Optionnelles</div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-money-check-alt"></i>
                            <h3>Aucune tontine créée</h3>
                            <p>Commencez par créer une nouvelle tontine en utilisant le formulaire ci-contre.</p>
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
                <?php if (count($tontines) == 0): ?>
                    e.preventDefault();
                    Swal.fire({
                        icon: 'warning',
                        title: 'Aucune tontine',
                        text: 'Il n\'y a aucune tontine à exporter.',
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

            $('#tontineForm').on('submit', function(e) {
                let isValid = true;
                const inputs = $(this).find('input[required], select[required]');
                
                inputs.css('border-color', 'rgba(45, 74, 138, 0.2)');
                
                inputs.each(function() {
                    if (!$(this).val().trim()) {
                        isValid = false;
                        $(this).css('border-color', '#dc3545');
                    }
                });

                const montant = $('#montant_cotisation');
                if (montant.val() && parseFloat(montant.val()) <= 0) {
                    isValid = false;
                    montant.css('border-color', '#dc3545');
                }

                const dateDebut = $('#date_debut');
                const dateFin = $('#date_fin');
                if (dateFin.val() && dateDebut.val() && dateFin.val() < dateDebut.val()) {
                    isValid = false;
                    dateFin.css('border-color', '#dc3545');
                }

                if (!isValid) {
                    e.preventDefault();
                    Swal.fire({
                        icon: 'error',
                        title: 'Champs manquants',
                        text: 'Veuillez remplir tous les champs obligatoires correctement.',
                        confirmButtonColor: '#2d4a8a'
                    });
                } else {
                    const submitBtn = $(this).find('button[type="submit"]');
                    submitBtn.html('<i class="fas fa-spinner fa-spin"></i> Création en cours...');
                    submitBtn.prop('disabled', true);
                }
            });

            $('input, select').on('input change', function() {
                $(this).css('border-color', 'rgba(45, 74, 138, 0.2)');
            });

            $('#resetFormBtn').on('click', function() {
                $('#tontineForm')[0].reset();
                $('input, select').css('border-color', 'rgba(45, 74, 138, 0.2)');
            });

            // Définir la date minimale à aujourd'hui
            const today = new Date().toISOString().split('T')[0];
            $('#date_debut').attr('min', today);
            $('#date_fin').attr('min', today);

            $('#showStatsBtn').on('click', function() {
                <?php
                $total = count($tontines);
                $actives = array_filter($tontines, function($t) { 
                    return isset($t['statut']) && $t['statut'] === 'active'; 
                });
                $inactives = array_filter($tontines, function($t) { 
                    return isset($t['statut']) && $t['statut'] === 'inactive'; 
                });
                $pending = $total - count($actives) - count($inactives);
                
                $obligatoires = array_filter($tontines, function($t) { 
                    return isset($t['type_tontine']) && $t['type_tontine'] === 'obligatoire'; 
                });
                $optionnels = array_filter($tontines, function($t) {
                    return isset($t['type_tontine']) && $t['type_tontine'] === 'optionnel';
                });
                
                $totalMontant = array_sum(array_column($tontines, 'montant_cotisation'));
                ?>
                
                Swal.fire({
                    title: '📊 Statistiques des Tontines',
                    html: `
                        <div style="text-align: left; margin: 20px 0;">
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px;">
                                <div style="padding: 20px; background: linear-gradient(135deg, rgba(45, 74, 138, 0.1), rgba(58, 95, 192, 0.1)); border-radius: 12px; border-left: 4px solid #2d4a8a;">
                                    <strong style="color: #2d4a8a;">Total Tontines:</strong><br>
                                    <span style="font-size: 2rem; color: #2d4a8a; font-weight: 800;"><?php echo $total; ?></span>
                                </div>
                                <div style="padding: 20px; background: linear-gradient(135deg, rgba(40, 167, 69, 0.1), rgba(32, 201, 151, 0.1)); border-radius: 12px; border-left: 4px solid #28a745;">
                                    <strong style="color: #28a745;">Actives:</strong><br>
                                    <span style="font-size: 2rem; color: #28a745; font-weight: 800;"><?php echo count($actives); ?></span>
                                </div>
                            </div>
                            <div style="background: rgba(248, 250, 252, 0.9); padding: 20px; border-radius: 12px; border: 2px solid rgba(212, 175, 55, 0.3);">
                                <h4 style="margin-top: 0; color: #1a2b55;">📈 Détails</h4>
                                <div style="margin: 16px 0;">
                                    <p><strong>Montant total des cotisations :</strong> <?php echo number_format($totalMontant, 0, ',', ' '); ?> FCFA</p>
                                    <p><strong>Tontines obligatoires :</strong> <?php echo count($obligatoires); ?></p>
                                    <p><strong>Tontines optionnelles :</strong> <?php echo count($optionnels); ?></p>
                                    <p><strong>En attente :</strong> <?php echo $pending; ?></p>
                                    <p><strong>Inactives :</strong> <?php echo count($inactives); ?></p>
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
        });
    </script>
</body>
</html>
<?php
if (isset($pdo)) {
    $pdo = null;
}
?>