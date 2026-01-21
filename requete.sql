-- 1. Liste de tous les membres avec leur rôle et statut
SELECT id_membre, CONCAT(nom, ' ', prenom) AS nom_complet, telephone, role, statut
FROM membre
ORDER BY nom, prenom;

-- 2. Nombre total de tontines par type (obligatoire / optionnel)
SELECT type_tontine, COUNT(*) AS nombre
FROM tontine
GROUP BY type_tontine;

-- 3. Montant total des cotisations payées par un membre donné (paramétrée)
SELECT m.nom, m.prenom, SUM(c.montant) AS total_cotise
FROM membre m
JOIN cotisation c ON m.id_membre = c.id_membre
WHERE m.id_membre = ? AND c.statut = 'payé'
GROUP BY m.id_membre, m.nom, m.prenom;

-- 4. Liste des séances à venir avec nom de la tontine et nombre de participants attendus
SELECT s.id_seance, t.nom_tontine, s.date_seance, s.lieu, 
       (SELECT COUNT(*) FROM cotisation WHERE id_seance = s.id_seance AND statut = 'payé') AS participants_payes
FROM seance s
JOIN tontine t ON s.id_tontine = t.id_tontine
WHERE s.date_seance >= CURDATE()
ORDER BY s.date_seance;

-- 5. Bénéficiaires d'une tontine donnée avec montant gagné
SELECT b.id_beneficiaire, CONCAT(m.nom, ' ', m.prenom) AS beneficiaire, 
       t.nom_tontine, b.montant_gagne, b.date_gain
FROM beneficiaire b
JOIN membre m ON b.id_membre = m.id_membre
JOIN tontine t ON b.id_tontine = t.id_tontine
WHERE t.id_tontine = ?;

-- 6. Crédits en cours ou en retard avec intérêts estimés
SELECT c.id_credit, CONCAT(m.nom, ' ', m.prenom) AS emprunteur,
       c.montant, c.taux_interet, c.duree_mois,
       ROUND(c.montant * (c.taux_interet/100) * (c.duree_mois/12), 2) AS interet_total,
       c.montant_restant, c.statut
FROM credit c
JOIN membre m ON c.id_membre = m.id_membre
WHERE c.statut IN ('en_cours', 'en_retard');

-- 7. Total des pénalités par membre
SELECT m.id_membre, CONCAT(m.nom, ' ', m.prenom) AS nom_complet,
       SUM(p.montant) AS total_penalites
FROM penalite p
JOIN membre m ON p.id_membre = m.id_membre
GROUP BY m.id_membre
HAVING total_penalites > 0
ORDER BY total_penalites DESC;

-- 8. Tontines auxquelles un membre participe activement
SELECT t.id_tontine, t.nom_tontine, t.type_tontine, t.montant_cotisation,
       pt.date_participation
FROM participation_tontine pt
JOIN tontine t ON pt.id_tontine = t.id_tontine
WHERE pt.id_membre = ? AND pt.statut = 'active';

-- 9. Séances où il y a des cotisations en retard ou impayées
SELECT s.id_seance, t.nom_tontine, s.date_seance,
       COUNT(CASE WHEN c.statut IN ('impayé', 'retard') THEN 1 END) AS cotisations_en_attente
FROM seance s
JOIN tontine t ON s.id_tontine = t.id_tontine
LEFT JOIN cotisation c ON s.id_seance = c.id_seance
WHERE s.date_seance < CURDATE()
GROUP BY s.id_seance
HAVING cotisations_en_attente > 0;

-- 10. Montant total collecté par tontine (toutes cotisations payées)
SELECT t.id_tontine, t.nom_tontine,
       SUM(c.montant) AS total_collecte
FROM tontine t
JOIN seance s ON t.id_tontine = s.id_tontine
JOIN cotisation c ON s.id_seance = c.id_seance
WHERE c.statut = 'payé'
GROUP BY t.id_tontine;

-- 11. Membre qui a le plus de pénalités
SELECT m.id_membre, CONCAT(m.nom, ' ', m.prenom) AS nom_complet,
       COUNT(p.id_penalite) AS nombre_penalites,
       SUM(p.montant) AS montant_total_penalites
FROM membre m
LEFT JOIN penalite p ON m.id_membre = p.id_membre
GROUP BY m.id_membre
ORDER BY montant_total_penalites DESC
LIMIT 1;

-- 12. Mise à jour du statut d'une cotisation en retard (exemple action)
UPDATE cotisation
SET statut = 'retard'
WHERE id_seance = ?
  AND statut = 'impayé'
  AND date_paiement IS NULL
  AND (SELECT date_seance FROM seance WHERE id_seance = ?) < DATE_SUB(CURDATE(), INTERVAL 7 DAY);

-- 13. Insertion d'une nouvelle cotisation (exemple paramétrée)
INSERT INTO cotisation (id_membre, id_seance, montant, date_paiement, statut)
VALUES (?, ?, ?, CURDATE(), 'payé');

-- 14. Suppression d'une pénalité payée (exemple action)
DELETE FROM penalite
WHERE id_penalite = ?
  AND montant = (SELECT montant FROM cotisation WHERE id_cotisation = ?);  -- exemple de contrôle

-- 15. Synthèse financière globale (total cotisations payées vs total pénalités)
SELECT 
    (SELECT COALESCE(SUM(montant), 0) FROM cotisation WHERE statut = 'payé') AS total_cotisations_payees,
    (SELECT COALESCE(SUM(montant), 0) FROM penalite) AS total_penalites,
    (SELECT COALESCE(SUM(montant), 0) FROM beneficiaire) AS total_gains_distribues;