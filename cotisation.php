<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Cotisations</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

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
            --danger: #ef4444;
            --warning: #f59e0b;
            --success: #10b981;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, var(--navy-blue) 0%, var(--medium-blue) 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .header {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            padding: 25px 30px;
            border-radius: var(--border-radius);
            box-shadow: 0 8px 32px var(--shadow-medium);
            margin-bottom: 30px;
        }

        .header h1 {
            color: var(--navy-blue);
            font-size: clamp(24px, 4vw, 32px);
            margin-bottom: 10px;
        }

        .header p {
            color: var(--text-light);
            font-size: clamp(14px, 2vw, 16px);
        }

        .selection-card {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            padding: 25px;
            border-radius: var(--border-radius);
            box-shadow: 0 8px 32px var(--shadow-medium);
            margin-bottom: 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            color: var(--text-dark);
            font-weight: 600;
            margin-bottom: 8px;
            font-size: clamp(14px, 2vw, 16px);
        }

        .form-group select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 16px;
            background: var(--pure-white);
            color: var(--text-dark);
            transition: var(--transition);
        }

        .form-group select:focus {
            outline: none;
            border-color: var(--light-blue);
            box-shadow: 0 0 0 3px rgba(58, 95, 192, 0.1);
        }

        .tontine-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
            padding: 20px;
            background: linear-gradient(135deg, var(--light-blue), var(--medium-blue));
            border-radius: 8px;
        }

        .info-item {
            text-align: center;
            color: var(--pure-white);
        }

        .info-item .label {
            font-size: 12px;
            opacity: 0.9;
            margin-bottom: 5px;
        }

        .info-item .value {
            font-size: clamp(18px, 3vw, 24px);
            font-weight: 700;
        }

        .seances-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .seance-card {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: 0 4px 16px var(--shadow-light);
            transition: var(--transition);
            cursor: pointer;
            border: 2px solid transparent;
            position: relative;
            overflow: hidden;
        }

        .seance-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--accent-gold), var(--accent-light));
        }

        .seance-card.en-retard::before {
            background: linear-gradient(90deg, var(--danger), var(--warning));
        }

        .seance-card.payee::before {
            background: linear-gradient(90deg, var(--success), #059669);
        }

        .seance-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 24px var(--shadow-medium);
            border-color: var(--light-blue);
        }

        .seance-card.selected {
            border-color: var(--accent-gold);
            box-shadow: 0 8px 24px rgba(212, 175, 55, 0.3);
        }

        .seance-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .seance-date {
            font-size: 18px;
            font-weight: 700;
            color: var(--navy-blue);
        }

        .badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge.payee {
            background: var(--success);
            color: var(--pure-white);
        }

        .badge.en-retard {
            background: var(--danger);
            color: var(--pure-white);
        }

        .badge.a-venir {
            background: var(--text-light);
            color: var(--pure-white);
        }

        .seance-details {
            margin-top: 15px;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            color: var(--text-light);
            font-weight: 500;
        }

        .detail-value {
            color: var(--text-dark);
            font-weight: 600;
        }

        .penalite-alert {
            background: #fef2f2;
            border-left: 4px solid var(--danger);
            padding: 12px;
            border-radius: 8px;
            margin-top: 15px;
        }

        .penalite-alert .title {
            color: var(--danger);
            font-weight: 700;
            font-size: 14px;
            margin-bottom: 5px;
        }

        .penalite-alert .amount {
            color: var(--text-dark);
            font-size: 20px;
            font-weight: 700;
        }

        .penalite-alert .calculation {
            color: var(--text-light);
            font-size: 12px;
            margin-top: 5px;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 30px;
            flex-wrap: wrap;
        }

        .btn {
            flex: 1;
            min-width: 150px;
            padding: 14px 24px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-align: center;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--light-blue), var(--medium-blue));
            color: var(--pure-white);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px var(--shadow-medium);
        }

        .btn-secondary {
            background: var(--pure-white);
            color: var(--navy-blue);
            border: 2px solid var(--navy-blue);
        }

        .btn-secondary:hover {
            background: var(--navy-blue);
            color: var(--pure-white);
        }

        .summary-card {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            padding: 25px;
            border-radius: var(--border-radius);
            box-shadow: 0 8px 32px var(--shadow-medium);
            margin-top: 30px;
        }

        .summary-title {
            color: var(--navy-blue);
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 20px;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }

        .summary-item {
            padding: 15px;
            background: linear-gradient(135deg, #f8fafc, #e2e8f0);
            border-radius: 8px;
            text-align: center;
        }

        .summary-item .label {
            color: var(--text-light);
            font-size: 14px;
            margin-bottom: 8px;
        }

        .summary-item .value {
            color: var(--navy-blue);
            font-size: 24px;
            font-weight: 700;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
        }

        .empty-state svg {
            width: 80px;
            height: 80px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        @media (max-width: 768px) {
            body {
                padding: 10px;
            }

            .header {
                padding: 20px;
            }

            .selection-card {
                padding: 20px;
            }

            .seances-grid {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: column;
            }

            .btn {
                min-width: 100%;
            }

            .tontine-info {
                grid-template-columns: 1fr;
            }

            .summary-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .seance-header {
                flex-direction: column;
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>💰 Gestion des Cotisations</h1>
            <p>Sélectionnez une tontine et payez vos cotisations</p>
        </div>

        <?php
        // Configuration de la base de données
        $host = 'localhost';
        $dbname = 'tontine_db';
        $username = 'root';
        $password = '';

        try {
            $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $e) {
            die("Erreur de connexion : " . $e->getMessage());
        }

        // ID du membre connecté (à remplacer par le système d'authentification)
        $id_membre = 1;

        // Récupération du membre
        $stmt_membre = $pdo->prepare("SELECT * FROM membre WHERE id_membre = ?");
        $stmt_membre->execute([$id_membre]);
        $membre = $stmt_membre->fetch(PDO::FETCH_ASSOC);

        // Traitement du formulaire de paiement
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payer_cotisation'])) {
            $id_seance = $_POST['id_seance'];
            $montant = $_POST['montant'];
            $penalite = $_POST['penalite'];
            
            try {
                $pdo->beginTransaction();
                
                // Insertion de la cotisation
                $stmt_insert = $pdo->prepare("
                    INSERT INTO cotisation (id_membre, id_seance, montant, date_paiement, statut) 
                    VALUES (?, ?, ?, CURDATE(), 'payé')
                ");
                $stmt_insert->execute([$id_membre, $id_seance, $montant]);
                $id_cotisation = $pdo->lastInsertId();
                
                // Si pénalité, l'insérer
                if ($penalite > 0) {
                    $stmt_penalite = $pdo->prepare("
                        INSERT INTO penalite (id_membre, montant, raison, date_penalite, statut_paiement, id_cotisation_penalite) 
                        VALUES (?, ?, 'Retard de paiement cotisation', CURDATE(), 'impayé', ?)
                    ");
                    $stmt_penalite->execute([$id_membre, $penalite, $id_cotisation]);
                }
                
                $pdo->commit();
                $message_success = "Cotisation payée avec succès !";
            } catch(Exception $e) {
                $pdo->rollBack();
                $message_error = "Erreur lors du paiement : " . $e->getMessage();
            }
        }

        // Récupération des tontines du membre
        $stmt_tontines = $pdo->prepare("
            SELECT DISTINCT t.* 
            FROM tontine t
            INNER JOIN participation_tontine pt ON t.id_tontine = pt.id_tontine
            WHERE pt.id_membre = ? AND pt.statut = 'active'
            ORDER BY t.nom_tontine
        ");
        $stmt_tontines->execute([$id_membre]);
        $tontines = $stmt_tontines->fetchAll(PDO::FETCH_ASSOC);

        $id_tontine_selectionnee = $_GET['tontine'] ?? ($tontines[0]['id_tontine'] ?? null);
        ?>

        <?php if (isset($message_success)): ?>
            <div style="background: #d1fae5; color: #065f46; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                ✓ <?= htmlspecialchars($message_success) ?>
            </div>
        <?php endif; ?>

        <?php if (isset($message_error)): ?>
            <div style="background: #fee2e2; color: #991b1b; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                ✗ <?= htmlspecialchars($message_error) ?>
            </div>
        <?php endif; ?>

        <div class="selection-card">
            <form method="GET">
                <div class="form-group">
                    <label for="tontine">📋 Sélectionnez une tontine</label>
                    <select name="tontine" id="tontine" onchange="this.form.submit()">
                        <option value="">-- Choisir une tontine --</option>
                        <?php foreach ($tontines as $tontine): ?>
                            <option value="<?= $tontine['id_tontine'] ?>" 
                                <?= $id_tontine_selectionnee == $tontine['id_tontine'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($tontine['nom_tontine']) ?> 
                                (<?= number_format($tontine['montant_cotisation'], 0, ',', ' ') ?> FCFA - <?= $tontine['frequence'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>

            <?php if ($id_tontine_selectionnee): ?>
                <?php
                $stmt_tontine = $pdo->prepare("SELECT * FROM tontine WHERE id_tontine = ?");
                $stmt_tontine->execute([$id_tontine_selectionnee]);
                $tontine_active = $stmt_tontine->fetch(PDO::FETCH_ASSOC);
                ?>
                <div class="tontine-info">
                    <div class="info-item">
                        <div class="label">Type</div>
                        <div class="value"><?= ucfirst($tontine_active['type_tontine']) ?></div>
                    </div>
                    <div class="info-item">
                        <div class="label">Montant</div>
                        <div class="value"><?= number_format($tontine_active['montant_cotisation'], 0, ',', ' ') ?> FCFA</div>
                    </div>
                    <div class="info-item">
                        <div class="label">Fréquence</div>
                        <div class="value"><?= ucfirst($tontine_active['frequence']) ?></div>
                    </div>
                    <div class="info-item">
                        <div class="label">Statut</div>
                        <div class="value"><?= ucfirst($tontine_active['statut']) ?></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($id_tontine_selectionnee): ?>
            <?php
            // Récupération des paramètres de pénalité
            $stmt_param = $pdo->query("SELECT * FROM parametres_penalites WHERE type_penalite = 'cotisation_retard_hebdo' AND actif = 1");
            $param_penalite = $stmt_param->fetch(PDO::FETCH_ASSOC);
            $taux_penalite = $param_penalite['taux_penalite'] ?? 10;
            $delai_jours = $param_penalite['delai_jours'] ?? 7;

            // Récupération des séances
            $stmt_seances = $pdo->prepare("
                SELECT s.*, 
                    c.id_cotisation,
                    c.statut as statut_cotisation,
                    c.date_paiement,
                    DATEDIFF(CURDATE(), s.date_seance) as jours_retard
                FROM seance s
                LEFT JOIN cotisation c ON s.id_seance = c.id_seance AND c.id_membre = ?
                WHERE s.id_tontine = ?
                ORDER BY s.date_seance DESC
            ");
            $stmt_seances->execute([$id_membre, $id_tontine_selectionnee]);
            $seances = $stmt_seances->fetchAll(PDO::FETCH_ASSOC);

            $total_paye = 0;
            $total_en_retard = 0;
            $total_a_venir = 0;
            $total_penalites = 0;
            ?>

            <div class="seances-grid">
                <?php foreach ($seances as $seance): ?>
                    <?php
                    $date_seance = new DateTime($seance['date_seance']);
                    $aujourd_hui = new DateTime();
                    $jours_retard = $seance['jours_retard'];
                    
                    // Déterminer le statut
                    if ($seance['statut_cotisation'] === 'payé') {
                        $statut = 'payee';
                        $statut_label = 'Payée';
                        $penalite = 0;
                        $total_paye++;
                    } elseif ($date_seance > $aujourd_hui) {
                        $statut = 'a-venir';
                        $statut_label = 'À venir';
                        $penalite = 0;
                        $total_a_venir++;
                    } else {
                        $statut = 'en-retard';
                        $statut_label = 'En retard';
                        // Calcul de la pénalité
                        $semaines_retard = floor($jours_retard / $delai_jours);
                        $penalite = ($tontine_active['montant_cotisation'] * $taux_penalite / 100) * $semaines_retard;
                        $total_en_retard++;
                        $total_penalites += $penalite;
                    }
                    ?>
                    
                    <div class="seance-card <?= $statut ?>" onclick="selectSeance(this, <?= $seance['id_seance'] ?>)">
                        <div class="seance-header">
                            <div class="seance-date">
                                📅 <?= $date_seance->format('d/m/Y') ?>
                            </div>
                            <span class="badge <?= $statut ?>"><?= $statut_label ?></span>
                        </div>

                        <div class="seance-details">
                            <div class="detail-row">
                                <span class="detail-label">Heure</span>
                                <span class="detail-value"><?= substr($seance['heure_debut'], 0, 5) ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Lieu</span>
                                <span class="detail-value"><?= htmlspecialchars($seance['lieu']) ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Montant</span>
                                <span class="detail-value"><?= number_format($tontine_active['montant_cotisation'], 0, ',', ' ') ?> FCFA</span>
                            </div>

                            <?php if ($statut === 'en-retard' && $penalite > 0): ?>
                                <div class="penalite-alert">
                                    <div class="title">⚠️ Pénalité de retard</div>
                                    <div class="amount"><?= number_format($penalite, 0, ',', ' ') ?> FCFA</div>
                                    <div class="calculation">
                                        <?= $jours_retard ?> jours de retard (<?= $semaines_retard ?> semaine<?= $semaines_retard > 1 ? 's' : '' ?>) 
                                        × <?= $taux_penalite ?>%
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($seance['statut_cotisation'] === 'payé'): ?>
                                <div style="background: #d1fae5; padding: 10px; border-radius: 6px; margin-top: 10px; text-align: center;">
                                    <span style="color: #065f46; font-weight: 600;">
                                        ✓ Payée le <?= date('d/m/Y', strtotime($seance['date_paiement'])) ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <?php if ($statut === 'en-retard' && !$seance['id_cotisation']): ?>
                                <form method="POST" style="margin-top: 15px;">
                                    <input type="hidden" name="id_seance" value="<?= $seance['id_seance'] ?>">
                                    <input type="hidden" name="montant" value="<?= $tontine_active['montant_cotisation'] ?>">
                                    <input type="hidden" name="penalite" value="<?= $penalite ?>">
                                    <button type="submit" name="payer_cotisation" class="btn btn-primary" style="width: 100%;">
                                        💳 Payer maintenant
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if (empty($seances)): ?>
                    <div class="empty-state" style="grid-column: 1/-1;">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <h3>Aucune séance disponible</h3>
                        <p>Aucune séance n'a été programmée pour cette tontine</p>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($seances)): ?>
                <div class="summary-card">
                    <h2 class="summary-title">📊 Récapitulatif</h2>
                    <div class="summary-grid">
                        <div class="summary-item">
                            <div class="label">Séances payées</div>
                            <div class="value" style="color: var(--success);"><?= $total_paye ?></div>
                        </div>
                        <div class="summary-item">
                            <div class="label">Séances en retard</div>
                            <div class="value" style="color: var(--danger);"><?= $total_en_retard ?></div>
                        </div>
                        <div class="summary-item">
                            <div class="label">Séances à venir</div>
                            <div class="value" style="color: var(--text-light);"><?= $total_a_venir ?></div>
                        </div>
                        <div class="summary-item">
                            <div class="label">Total pénalités</div>
                            <div class="value" style="color: var(--warning);"><?= number_format($total_penalites, 0, ',', ' ') ?> FCFA</div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
        function selectSeance(element, idSeance) {
            // Retirer la sélection de toutes les cartes
            document.querySelectorAll('.seance-card').forEach(card => {
                card.classList.remove('selected');
            });
            
            // Ajouter la sélection à la carte cliquée
            element.classList.add('selected');
        }
    </script>
</body>
</html>