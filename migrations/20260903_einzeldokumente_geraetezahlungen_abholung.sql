-- Migration: Gerätebezogene Zahlungen, Abholung und Teilrechnungen
-- Zielsystem: MariaDB 10.4+
-- Nur nach einer aktuellen, erfolgreich wiederhergestellten Sicherung ausführen.

ALTER TABLE `reparaturauftraege`
  ADD COLUMN IF NOT EXISTS `nicht_zugeordnet_bezahlt` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `bereits_bezahlt`,
  ADD CONSTRAINT `chk_auftrag_nicht_zugeordnet_bezahlt`
    CHECK (`nicht_zugeordnet_bezahlt` >= 0 AND `nicht_zugeordnet_bezahlt` <= `bereits_bezahlt`);

ALTER TABLE `reparaturauftrag_geraete`
  ADD COLUMN IF NOT EXISTS `bereits_bezahlt` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `preis`,
  ADD COLUMN IF NOT EXISTS `abgeholt_am` datetime NULL AFTER `status_id`,
  ADD COLUMN IF NOT EXISTS `abgeholt_von` varchar(150) NULL AFTER `abgeholt_am`,
  ADD CONSTRAINT `chk_geraet_bereits_bezahlt`
    CHECK (`bereits_bezahlt` >= 0 AND `bereits_bezahlt` <= `preis`);

-- Alte Gesamtzahlungen zunächst vollständig als nicht zugeordnet erhalten.
UPDATE `reparaturauftraege`
SET `nicht_zugeordnet_bezahlt` = `bereits_bezahlt`;

-- Bei genau einem Gerät ist die Zuordnung eindeutig und verlustfrei.
UPDATE `reparaturauftrag_geraete` rag
INNER JOIN `reparaturauftraege` ra ON ra.`id` = rag.`auftrag_id`
INNER JOIN (
  SELECT `auftrag_id`
  FROM `reparaturauftrag_geraete`
  GROUP BY `auftrag_id`
  HAVING COUNT(*) = 1
) einzel ON einzel.`auftrag_id` = rag.`auftrag_id`
SET rag.`bereits_bezahlt` = ra.`bereits_bezahlt`
WHERE ra.`bereits_bezahlt` <= rag.`preis`;

UPDATE `reparaturauftraege` ra
INNER JOIN (
  SELECT rag.`auftrag_id`, COUNT(*) AS anzahl, MAX(rag.`bereits_bezahlt`) AS zugeordnet
  FROM `reparaturauftrag_geraete` rag
  GROUP BY rag.`auftrag_id`
) einzel ON einzel.`auftrag_id` = ra.`id` AND einzel.`anzahl` = 1
SET ra.`nicht_zugeordnet_bezahlt` = 0.00
WHERE einzel.`zugeordnet` = ra.`bereits_bezahlt`;

ALTER TABLE `rechnungen`
  ADD COLUMN IF NOT EXISTS `rechnungsart` varchar(20) NOT NULL DEFAULT 'gemeinsam' AFTER `rechnungsnummer`,
  ADD CONSTRAINT `chk_rechnungen_rechnungsart`
    CHECK (`rechnungsart` IN ('einzeln', 'gemeinsam'));

CREATE INDEX IF NOT EXISTS `idx_rechnungen_auftrag` ON `rechnungen` (`auftrag_id`);
ALTER TABLE `rechnungen` DROP INDEX `uq_rechnungen_auftrag`;

CREATE TABLE `rechnung_geraete` (
  `rechnung_id` bigint unsigned NOT NULL,
  `auftrag_id_snapshot` int(11) NOT NULL,
  `geraet_id_snapshot` int(11) NOT NULL,
  PRIMARY KEY (`rechnung_id`, `geraet_id_snapshot`),
  UNIQUE KEY `uq_abgerechnetes_geraet` (`geraet_id_snapshot`),
  KEY `idx_rechnung_geraete_auftrag` (`auftrag_id_snapshot`),
  CONSTRAINT `fk_rechnung_geraete_rechnung`
    FOREIGN KEY (`rechnung_id`) REFERENCES `rechnungen` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Vorhandene unveränderliche Rechnungen ihren bereits gespeicherten Geräten zuordnen.
INSERT INTO `rechnung_geraete` (`rechnung_id`, `auftrag_id_snapshot`, `geraet_id_snapshot`)
SELECT DISTINCT rp.`rechnung_id`, r.`auftragsnummer_snapshot`, rp.`geraet_id_snapshot`
FROM `rechnungspositionen` rp
INNER JOIN `rechnungen` r ON r.`id` = rp.`rechnung_id`
WHERE rp.`geraet_id_snapshot` IS NOT NULL;

DELIMITER $$
CREATE PROCEDURE `migration_pruefe_einzeldokumente`()
BEGIN
  IF EXISTS (
    SELECT 1
    FROM `reparaturauftraege` ra
    WHERE ra.`bereits_bezahlt` < 0
       OR ra.`nicht_zugeordnet_bezahlt` < 0
       OR ra.`nicht_zugeordnet_bezahlt` > ra.`bereits_bezahlt`
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Migration abgebrochen: Eine Auftragszahlung wurde ungültig übernommen.';
  END IF;

  IF EXISTS (
    SELECT 1
    FROM `reparaturauftrag_geraete` rag
    WHERE rag.`bereits_bezahlt` < 0 OR rag.`bereits_bezahlt` > rag.`preis`
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Migration abgebrochen: Eine Gerätezahlung ist ungültig.';
  END IF;

  IF (
    SELECT COUNT(DISTINCT rp.`geraet_id_snapshot`)
    FROM `rechnungspositionen` rp
    WHERE rp.`geraet_id_snapshot` IS NOT NULL
  ) <> (SELECT COUNT(*) FROM `rechnung_geraete`) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Migration abgebrochen: Vorhandene Rechnungspositionen konnten nicht eindeutig Geräten zugeordnet werden.';
  END IF;
END$$
DELIMITER ;

CALL `migration_pruefe_einzeldokumente`();
DROP PROCEDURE `migration_pruefe_einzeldokumente`;
