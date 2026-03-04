<?php
require_once 'fonctions/config.php';

echo "<h2>Initialisation des participations aux tontines</h2>";

// 1. Vérifier les paramètres de pénalité
$stmt_param = $pdo->query("SELECT * FROM parametres_penalites WHERE type_penalite = 'cotisation_retard_hebdo'");
if (!$stmt_param->fetch()) {
    $pdo->prepare("
        INSERT INTO parametres_penalites 
        (type_penalite, taux_penalite, delai_jours, montant_minimum, actif) 
        VALUES ('cotisation_retard_hebdo', 10.00, 7, 500.00, 1)
    ")->execute();
    echo "✓ Paramètres de pénalité créés<br>";
} else {
    echo "✓ Paramètres de pénalité existent déjà<br>";
}

// 2. Inscrire automatiquement tous les membres actifs aux tontines obligatoires
$membres = $pdo->query("SELECT id_membre FROM membre WHERE statut = 'actif'")->fetchAll();
$tontines = $pdo->query("SELECT id_tontine FROM tontine WHERE statut = 'active' AND type_tontine = 'obligatoire'")->fetchAll();

$inscriptions = 0;
foreach ($membres as $membre) {
    foreach ($tontines as $tontine) {
        $check = $pdo->prepare("SELECT id_participation FROM participation_tontine WHERE id_membre = ? AND id_tontine = ?");
        $check->execute([$membre['id_membre'], $tontine['id_tontine']]);
        
        if (!$check->fetch()) {
            $pdo->prepare("
                INSERT INTO participation_tontine 
                (id_membre, id_tontine, date_participation, statut) 
                VALUES (?, ?, CURDATE(), 'active')
            ")->execute([$membre['id_membre'], $tontine['id_tontine']]);
            $inscriptions++;
        }
    }
}

echo "✓ $inscriptions inscriptions créées<br>";

// 3. Créer un trigger pour les nouveaux membres
$pdo->exec("
    DROP TRIGGER IF EXISTS after_membre_insert;
    
    CREATE TRIGGER after_membre_insert 
    AFTER INSERT ON membre
    FOR EACH ROW
    BEGIN
        IF NEW.statut = 'actif' THEN
            INSERT INTO participation_tontine (id_membre, id_tontine, date_participation, statut)
            SELECT NEW.id_membre, id_tontine, CURDATE(), 'active'
            FROM tontine 
            WHERE statut = 'active' AND type_tontine = 'obligatoire';
        END IF;
    END;
");

echo "✓ Trigger pour nouveaux membres créé<br>";

// 4. Créer un trigger pour les nouvelles tontines obligatoires
$pdo->exec("
    DROP TRIGGER IF EXISTS after_tontine_insert;
    
    CREATE TRIGGER after_tontine_insert 
    AFTER INSERT ON tontine
    FOR EACH ROW
    BEGIN
        IF NEW.statut = 'active' AND NEW.type_tontine = 'obligatoire' THEN
            INSERT INTO participation_tontine (id_membre, id_tontine, date_participation, statut)
            SELECT id_membre, NEW.id_tontine, CURDATE(), 'active'
            FROM membre 
            WHERE statut = 'actif';
        END IF;
    END;
");

echo "✓ Trigger pour nouvelles tontines créé<br>";

// 5. Vérifier les données
echo "<h3>Vérification des données :</h3>";
echo "Membres actifs : " . $pdo->query("SELECT COUNT(*) FROM membre WHERE statut = 'actif'")->fetchColumn() . "<br>";
echo "Tontines obligatoires actives : " . $pdo->query("SELECT COUNT(*) FROM tontine WHERE statut = 'active' AND type_tontine = 'obligatoire'")->fetchColumn() . "<br>";
echo "Participations totales : " . $pdo->query("SELECT COUNT(*) FROM participation_tontine")->fetchColumn() . "<br>";

echo "<h3 style='color: green;'>✓ Initialisation terminée avec succès !</h3>";
?>