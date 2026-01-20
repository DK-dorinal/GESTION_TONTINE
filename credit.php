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
$stmt = $pdo->prepare("SELECT role FROM membre WHERE id_membre = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Vérifier si l'utilisateur est admin (seuls les admins peuvent voir tous les crédits)
if (!$user || $user['role'] !== 'admin') {
    header('Location: dashboard.php');
    exit();
}

// Variables pour le filtrage et la pagination
$search = $_GET['search'] ?? '';
$statut_filter = $_GET['statut'] ?? '';
$date_debut = $_GET['date_debut'] ?? '';
$date_fin = $_GET['date_fin'] ?? '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Construire la requête avec filtres
$whereClauses = [];
$params = [];

if (!empty($search)) {
    $whereClauses[] = "(m.nom LIKE ? OR m.prenom LIKE ? OR c.statut LIKE ?)";
    $searchParam = "%$search%";
    array_push($params, $searchParam, $searchParam, $searchParam);
}

if (!empty($statut_filter)) {
    $whereClauses[] = "c.statut = ?";
    $params[] = $statut_filter;
}

if (!empty($date_debut)) {
    $whereClauses[] = "c.date_emprunt >= ?";
    $params[] = $date_debut;
}

if (!empty($date_fin)) {
    $whereClauses[] = "c.date_emprunt <= ?";
    $params[] = $date_fin;
}

$whereSQL = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

try {
    // Requête pour les données des crédits avec informations des membres
    $query = "
        SELECT 
            c.*,
            m.nom,
            m.prenom,
            m.telephone,
            m.date_inscription,
            m.statut as statut_membre,
            CONCAT(m.nom, ' ', m.prenom) as nom_complet,
            ROUND(c.montant * (c.taux_interet/100) * (c.duree_mois/12), 2) as interet_total,
            ROUND(c.montant + (c.montant * (c.taux_interet/100) * (c.duree_mois/12)), 2) as montant_total_rembourser
        FROM credit c
        INNER JOIN membre m ON c.id_membre = m.id_membre
        $whereSQL
        ORDER BY c.date_emprunt DESC, c.id_credit DESC
        LIMIT $limit OFFSET $offset
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $credits = $stmt->fetchAll();

    // Requête pour le total
    $countQuery = "
        SELECT COUNT(*) as total
        FROM credit c
        INNER JOIN membre m ON c.id_membre = m.id_membre
        $whereSQL
    ";

    $countStmt = $pdo->prepare($countQuery);
    $countStmt->execute($params);
    $totalResult = $countStmt->fetch();
    $totalCredits = $totalResult['total'];
    $totalPages = ceil($totalCredits / $limit);

    // Calculer les statistiques
    $statsQuery = "
        SELECT 
            COUNT(*) as total_credits,
            SUM(montant) as montant_total,
            AVG(montant) as moyenne_montant,
            SUM(montant_restant) as total_restant,
            MIN(date_emprunt) as plus_ancien,
            MAX(date_emprunt) as plus_recent,
            COUNT(CASE WHEN statut = 'en_cours' THEN 1 END) as credits_en_cours,
            COUNT(CASE WHEN statut = 'en_retard' THEN 1 END) as credits_en_retard,
            COUNT(CASE WHEN statut = 'rembourse' THEN 1 END) as credits_rembourses
        FROM credit
    ";
    $stats = $pdo->query($statsQuery)->fetch();

    // Récupérer les membres ayant le plus de crédits
    $topMembresQuery = "
        SELECT 
            m.id_membre,
            m.nom,
            m.prenom,
            COUNT(c.id_credit) as nb_credits,
            SUM(c.montant) as total_emprunte
        FROM credit c
        INNER JOIN membre m ON c.id_membre = m.id_membre
        GROUP BY c.id_membre
        ORDER BY nb_credits DESC, total_emprunte DESC
        LIMIT 5
    ";
    $topMembres = $pdo->query($topMembresQuery)->fetchAll();

    // Récupérer la répartition par statut
    $repartitionQuery = "
        SELECT 
            statut,
            COUNT(*) as nombre,
            SUM(montant) as montant_total
        FROM credit
        GROUP BY statut
        ORDER BY nombre DESC
    ";
    $repartition = $pdo->query($repartitionQuery)->fetchAll();
} catch (PDOException $e) {
    $error = "Erreur lors de la récupération des données: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Crédits | Gestion de Tontine</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet"> 
    <style>
        * {
            font-family: 'Inter', sans-serif;
        }

        body {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
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
            border-bottom: 1px solid #bae6fd;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
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

        .pagination-btn {
            padding: 8px 16px;
            border-radius: 8px;
            transition: all 0.2s ease;
        }

        .pagination-btn:hover:not(.disabled) {
            background: #0ea5e9;
            color: white;
        }

        .pagination-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .filter-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
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

        .montant-cell {
            font-weight: 700;
            font-size: 15px;
        }
    </style>
</head>

<body class="p-4 md:p-6">
    <div class="max-w-7xl mx-auto">
        <!-- Header -->
        <div class="mb-8">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <h1 class="text-3xl font-bold text-gray-800 mb-2">
                        <i class="fas fa-credit-card text-blue-600 mr-2"></i>
                        Gestion des Crédits
                    </h1>
                    <p class="text-gray-600">Suivi des emprunts et crédits des membres</p>
                </div>

                <div class="flex items-center gap-3">
                    <a href="dashboard.php" class="px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-lg font-medium flex items-center gap-2">
                        <i class="fas fa-arrow-left"></i>
                        Retour
                    </a>
                    <div class="bg-gradient-to-r from-blue-600 to-blue-700 text-white px-4 py-2 rounded-lg shadow-md">
                        <span class="text-xs uppercase font-semibold">Admin</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistiques -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="stat-card bg-white p-6 rounded-xl border-l-4 border-blue-500">
                <div class="flex items-center justify-between mb-4">
                    <div class="p-3 bg-blue-100 rounded-lg">
                        <i class="fas fa-wallet text-blue-600 text-2xl"></i>
                    </div>
                    <span class="text-2xl font-bold text-blue-600"><?php echo $stats['total_credits'] ?? 0; ?></span>
                </div>
                <h3 class="font-bold text-gray-800 mb-1">Total Crédits</h3>
                <p class="text-sm text-gray-600">Emprunts actifs</p>
            </div>

            <div class="stat-card bg-white p-6 rounded-xl border-l-4 border-green-500">
                <div class="flex items-center justify-between mb-4">
                    <div class="p-3 bg-green-100 rounded-lg">
                        <i class="fas fa-money-bill-wave text-green-600 text-2xl"></i>
                    </div>
                    <span class="text-2xl font-bold text-green-600">
                        <?php echo number_format($stats['montant_total'] ?? 0, 0, ',', ' '); ?> FCFA
                    </span>
                </div>
                <h3 class="font-bold text-gray-800 mb-1">Montant Total</h3>
                <p class="text-sm text-gray-600">Somme empruntée</p>
            </div>

            <div class="stat-card bg-white p-6 rounded-xl border-l-4 border-yellow-500">
                <div class="flex items-center justify-between mb-4">
                    <div class="p-3 bg-yellow-100 rounded-lg">
                        <i class="fas fa-hourglass-half text-yellow-600 text-2xl"></i>
                    </div>
                    <span class="text-2xl font-bold text-yellow-600">
                        <?php echo number_format($stats['montant_restant'] ?? 0, 0, ',', ' '); ?> FCFA
                    </span>
                </div>
                <h3 class="font-bold text-gray-800 mb-1">Reste à Payer</h3>
                <p class="text-sm text-gray-600">Montant en attente</p>
            </div>

            <div class="stat-card bg-white p-6 rounded-xl border-l-4 border-red-500">
                <div class="flex items-center justify-between mb-4">
                    <div class="p-3 bg-red-100 rounded-lg">
                        <i class="fas fa-exclamation-triangle text-red-600 text-2xl"></i>
                    </div>
                    <span class="text-2xl font-bold text-red-600">
                        <?php echo $stats['credits_en_retard'] ?? 0; ?>
                    </span>
                </div>
                <h3 class="font-bold text-gray-800 mb-1">En Retard</h3>
                <p class="text-sm text-gray-600">Crédits impayés</p>
            </div>
        </div>

        <!-- Filtres -->
        <div class="glass-card p-6 mb-6">
            <form method="GET" action="" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <!-- Recherche -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-search mr-1"></i> Recherche
                        </label>
                        <input type="text" name="search" placeholder="Nom, prénom ou statut..."
                            value="<?php echo htmlspecialchars($search); ?>"
                            class="w-full px-4 py-2 rounded-lg border-2 border-gray-200 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200">
                    </div>

                    <!-- Statut -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-filter mr-1"></i> Statut
                        </label>
                        <select name="statut" class="w-full px-4 py-2 rounded-lg border-2 border-gray-200 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200">
                            <option value="">Tous les statuts</option>
                            <option value="en_cours" <?php echo $statut_filter == 'en_cours' ? 'selected' : ''; ?>>En cours</option>
                            <option value="en_retard" <?php echo $statut_filter == 'en_retard' ? 'selected' : ''; ?>>En retard</option>
                            <option value="rembourse" <?php echo $statut_filter == 'rembourse' ? 'selected' : ''; ?>>Remboursé</option>
                        </select>
                    </div>

                    <!-- Date début -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-calendar-alt mr-1"></i> Date début
                        </label>
                        <input type="date" name="date_debut"
                            value="<?php echo htmlspecialchars($date_debut); ?>"
                            class="w-full px-4 py-2 rounded-lg border-2 border-gray-200 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200">
                    </div>

                    <!-- Date fin -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-calendar-alt mr-1"></i> Date fin
                        </label>
                        <input type="date" name="date_fin"
                            value="<?php echo htmlspecialchars($date_fin); ?>"
                            class="w-full px-4 py-2 rounded-lg border-2 border-gray-200 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200">
                    </div>
                </div>

                <div class="flex flex-col md:flex-row gap-4 justify-between items-center pt-4 border-t border-gray-200">
                    <div class="flex gap-2">
                        <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium flex items-center gap-2">
                            <i class="fas fa-filter"></i>
                            Appliquer les filtres
                        </button>
                        <a href="credit.php" class="px-6 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-lg font-medium flex items-center gap-2">
                            <i class="fas fa-redo"></i>
                            Réinitialiser
                        </a>
                    </div>

                    <div class="flex gap-2">
                        <button type="button" onclick="exportToExcel()" class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium flex items-center gap-2">
                            <i class="fas fa-file-excel"></i>
                            Exporter Excel
                        </button>
                        <button type="button" onclick="printPage()" class="px-6 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg font-medium flex items-center gap-2">
                            <i class="fas fa-print"></i>
                            Imprimer
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Tableau des crédits -->
        <div class="glass-card overflow-hidden mb-8">
            <div class="p-6 border-b border-gray-200">
                <h3 class="text-xl font-bold text-gray-800 flex items-center">
                    <i class="fas fa-list mr-2"></i>
                    Liste des Crédits
                    <span class="ml-2 text-sm font-normal text-gray-600">
                        (<?php echo $totalCredits; ?> crédit<?php echo $totalCredits > 1 ? 's' : ''; ?>)
                    </span>
                </h3>
            </div>

            <?php if (isset($error)): ?>
                <div class="m-6 bg-red-50 border-l-4 border-red-500 p-4 rounded-r-lg">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-circle text-red-500"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-red-700"><?php echo htmlspecialchars($error); ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Membre</th>
                            <th>Montant</th>
                            <th>Taux</th>
                            <th>Durée</th>
                            <th>Date Emprunt</th>
                            <th>Reste à Payer</th>
                            <th>Statut</th>
                            <th>Progression</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($credits)): ?>
                            <?php foreach ($credits as $credit): ?>
                                <?php
                                $pourcentage_paye = $credit['montant'] > 0 ?
                                    (($credit['montant'] - $credit['montant_restant']) / $credit['montant']) * 100 : 0;
                                ?>
                                <tr>
                                    <td class="font-mono text-sm text-gray-500">#<?php echo $credit['id_credit']; ?></td>
                                    <td>
                                        <div class="font-semibold text-gray-800"><?php echo htmlspecialchars($credit['nom_complet']); ?></div>
                                        <div class="text-xs text-gray-500"><?php echo $credit['telephone']; ?></div>
                                    </td>
                                    <td class="montant-cell text-blue-600">
                                        <?php echo number_format($credit['montant'], 0, ',', ' '); ?> FCFA
                                    </td>
                                    <td>
                                        <div class="font-medium"><?php echo $credit['taux_interet']; ?>%</div>
                                        <div class="text-xs text-gray-500">Intérêt: <?php echo number_format($credit['interet_total'], 0, ',', ' '); ?> FCFA</div>
                                    </td>
                                    <td>
                                        <div class="font-medium"><?php echo $credit['duree_mois']; ?> mois</div>
                                        <div class="text-xs text-gray-500">Total: <?php echo number_format($credit['montant_total_rembourser'], 0, ',', ' '); ?> FCFA</div>
                                    </td>
                                    <td>
                                        <div class="text-sm font-medium text-gray-800">
                                            <?php echo date('d/m/Y', strtotime($credit['date_emprunt'])); ?>
                                        </div>
                                    </td>
                                    <td class="montant-cell <?php echo $credit['montant_restant'] > 0 ? 'text-red-600' : 'text-green-600'; ?>">
                                        <?php echo number_format($credit['montant_restant'], 0, ',', ' '); ?> FCFA
                                    </td>
                                    <td>
                                        <?php
                                        $status_class = '';
                                        $status_text = '';
                                        switch ($credit['statut']) {
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
                                            default:
                                                $status_class = 'status-en_cours';
                                                $status_text = 'En cours';
                                        }
                                        ?>
                                        <span class="status-badge <?php echo $status_class; ?>">
                                            <?php echo $status_text; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="w-full">
                                            <div class="flex justify-between text-xs mb-1">
                                                <span class="text-gray-600">Progression</span>
                                                <span class="font-semibold"><?php echo round($pourcentage_paye, 1); ?>%</span>
                                            </div>
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?php echo $pourcentage_paye; ?>%"></div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="text-center py-12">
                                    <div class="text-gray-400">
                                        <i class="fas fa-credit-card text-4xl mb-4 opacity-50"></i>
                                        <p class="font-semibold">Aucun crédit trouvé</p>
                                        <p class="text-sm mt-2">Utilisez les filtres pour affiner votre recherche</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="p-6 border-t border-gray-200">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                        <div class="text-sm text-gray-600">
                            Page <?php echo $page; ?> sur <?php echo $totalPages; ?>
                            • <?php echo $totalCredits; ?> crédit<?php echo $totalCredits > 1 ? 's' : ''; ?>
                        </div>

                        <div class="flex gap-2">
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>"
                                class="pagination-btn <?php echo $page == 1 ? 'disabled' : 'hover:bg-blue-100'; ?>">
                                <i class="fas fa-angle-double-left"></i>
                            </a>

                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => max(1, $page - 1)])); ?>"
                                class="pagination-btn <?php echo $page == 1 ? 'disabled' : 'hover:bg-blue-100'; ?>">
                                <i class="fas fa-angle-left"></i>
                            </a>

                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);

                            for ($i = $startPage; $i <= $endPage; $i++):
                            ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"
                                    class="pagination-btn <?php echo $i == $page ? 'bg-blue-600 text-white' : 'hover:bg-blue-100'; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>

                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => min($totalPages, $page + 1)])); ?>"
                                class="pagination-btn <?php echo $page == $totalPages ? 'disabled' : 'hover:bg-blue-100'; ?>">
                                <i class="fas fa-angle-right"></i>
                            </a>

                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $totalPages])); ?>"
                                class="pagination-btn <?php echo $page == $totalPages ? 'disabled' : 'hover:bg-blue-100'; ?>">
                                <i class="fas fa-angle-double-right"></i>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Statistiques détaillées -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Répartition par statut -->
            <div class="glass-card p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
                    <i class="fas fa-chart-pie text-blue-600 mr-2"></i>
                    Répartition par Statut
                </h3>
                <div class="space-y-4">
                    <?php if (!empty($repartition)): ?>
                        <?php foreach ($repartition as $rep): ?>
                            <div class="space-y-2">
                                <div class="flex justify-between items-center">
                                    <div class="flex items-center gap-2">
                                        <?php
                                        $dot_color = '';
                                        switch ($rep['statut']) {
                                            case 'en_cours':
                                                $dot_color = 'bg-green-500';
                                                $statut_text = 'En cours';
                                                break;
                                            case 'en_retard':
                                                $dot_color = 'bg-red-500';
                                                $statut_text = 'En retard';
                                                break;
                                            case 'rembourse':
                                                $dot_color = 'bg-blue-500';
                                                $statut_text = 'Remboursé';
                                                break;
                                            default:
                                                $dot_color = 'bg-gray-500';
                                                $statut_text = $rep['statut'];
                                        }
                                        ?>
                                        <div class="w-3 h-3 <?php echo $dot_color; ?> rounded-full"></div>
                                        <span class="text-sm font-medium text-gray-700"><?php echo $statut_text; ?></span>
                                    </div>
                                    <span class="text-sm font-semibold"><?php echo $rep['nombre']; ?></span>
                                </div>
                                <div class="text-xs text-gray-600">
                                    <?php echo number_format($rep['montant_total'], 0, ',', ' '); ?> FCFA
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-4 text-gray-400">
                            <i class="fas fa-chart-pie text-2xl mb-2 opacity-50"></i>
                            <p>Aucune donnée disponible</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Top membres avec crédits -->
            <div class="glass-card p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
                    <i class="fas fa-chart-bar text-green-600 mr-2"></i>
                    Top 5 des Emprunteurs
                </h3>
                <div class="space-y-4">
                    <?php if (!empty($topMembres)): ?>
                        <?php foreach ($topMembres as $membre): ?>
                            <div class="flex items-center justify-between p-3 bg-gradient-to-r from-blue-50 to-indigo-50 rounded-lg border border-blue-100">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 bg-blue-600 rounded-full flex items-center justify-center text-white text-sm font-bold">
                                        <?php echo strtoupper(substr($membre['prenom'], 0, 1)); ?>
                                    </div>
                                    <div>
                                        <div class="font-semibold text-gray-800 text-sm">
                                            <?php echo htmlspecialchars($membre['prenom'] . ' ' . substr($membre['nom'], 0, 1)); ?>.
                                        </div>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="text-sm font-bold text-blue-600">
                                        <?php echo number_format($membre['total_emprunte'], 0, ',', ' '); ?> F
                                    </div>
                                    <div class="text-xs text-gray-600">
                                        <?php echo $membre['nb_credits']; ?> crédit<?php echo $membre['nb_credits'] > 1 ? 's' : ''; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-4 text-gray-400">
                            <i class="fas fa-chart-bar text-2xl mb-2 opacity-50"></i>
                            <p>Aucune donnée disponible</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Informations système -->
            <div class="glass-card p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
                    <i class="fas fa-info-circle text-purple-600 mr-2"></i>
                    Informations sur les Crédits
                </h3>
                <div class="space-y-4">
                    <div class="p-3 bg-blue-50 rounded-lg">
                        <h4 class="font-bold text-blue-800 mb-2 text-sm">📊 Statistiques Temporelles</h4>
                        <div class="space-y-1 text-xs">
                            <div class="flex justify-between">
                                <span class="text-gray-600">Plus ancien :</span>
                                <span class="font-medium">
                                    <?php echo $stats['plus_ancien'] ? date('d/m/Y', strtotime($stats['plus_ancien'])) : 'N/A'; ?>
                                </span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Plus récent :</span>
                                <span class="font-medium">
                                    <?php echo $stats['plus_recent'] ? date('d/m/Y', strtotime($stats['plus_recent'])) : 'N/A'; ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="p-3 bg-green-50 rounded-lg">
                        <h4 class="font-bold text-green-800 mb-2 text-sm">💰 Taux d'Intérêt</h4>
                        <p class="text-xs text-green-700">
                            <i class="fas fa-percentage mr-1"></i>
                            Les taux varient de 4.5% à 7% selon la durée et le profil du membre.
                        </p>
                    </div>

                    <div class="p-3 bg-purple-50 rounded-lg">
                        <h4 class="font-bold text-purple-800 mb-2 text-sm">⚡ Remboursement</h4>
                        <ul class="text-xs text-purple-700 space-y-1">
                            <li><i class="fas fa-check-circle mr-1"></i> Mensualités fixes</li>
                            <li><i class="fas fa-check-circle mr-1"></i> Remboursement anticipé possible</li>
                            <li><i class="fas fa-check-circle mr-1"></i> Pénalités de retard appliquées</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Fonctions pour les actions
        function exportToExcel() {
            // Collecter les paramètres de filtre actuels
            const params = new URLSearchParams(window.location.search);

            // Ouvrir une nouvelle page pour l'export
            window.open(`./fonctions/export_credits.php?${params.toString()}`, '_blank');
        }

        function printPage() {
            window.print();
        }

        // Mettre à jour les dates par défaut dans les filtres
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date().toISOString().split('T')[0];
            const dateFinInput = document.querySelector('input[name="date_fin"]');
            if (dateFinInput && !dateFinInput.value) {
                dateFinInput.value = today;
            }

            // Calculer automatiquement la date de début (3 mois avant)
            const dateDebutInput = document.querySelector('input[name="date_debut"]');
            if (dateDebutInput && !dateDebutInput.value) {
                const threeMonthsAgo = new Date();
                threeMonthsAgo.setMonth(threeMonthsAgo.getMonth() - 3);
                dateDebutInput.value = threeMonthsAgo.toISOString().split('T')[0];
            }

            // Ajouter un effet de survol amélioré aux lignes du tableau
            const tableRows = document.querySelectorAll('tbody tr');
            tableRows.forEach(row => {
                row.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateX(4px)';
                });

                row.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateX(0)';
                });
            });
        });

        // Mettre à jour automatiquement les barres de progression
        function updateProgressBars() {
            const progressBars = document.querySelectorAll('.progress-fill');
            progressBars.forEach(bar => {
                const currentWidth = bar.style.width;
                bar.style.transition = 'none';
                bar.style.width = '0%';
                setTimeout(() => {
                    bar.style.transition = 'width 1.5s ease';
                    bar.style.width = currentWidth;
                }, 50);
            });
        }

        // Exécuter après le chargement
        setTimeout(updateProgressBars, 500);
    </script>
</body>

</html>