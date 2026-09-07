-- Migration: mehrere Geräte und eigener Status/Preis je Gerät
-- Zielsystem: MariaDB 10.4+
-- Vor der Ausführung eine vollständige Datenbanksicherung erstellen.

-- Die bestehende Datenbank enthält möglicherweise negative Altwerte. Diese
-- werden als unveränderlicher Text protokolliert und im produktiven Preisfeld
-- auf 0,00 normalisiert, bevor die CHECK-Constraints gesetzt werden.
CREATE TABLE IF NOT EXISTS `migration_betragskorrekturen` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tabelle` varchar(64) NOT NULL,
  `datensatz_id` int(11) NOT NULL,
  `spalte` varchar(64) NOT NULL,
  `originalwert` varchar(64) NOT NULL,
  `korrigierter_wert` varchar(64) NOT NULL,
  `grund` varchar(255) NOT NULL,
  `korrigiert_am` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_betragskorrektur` (`tabelle`, `datensatz_id`, `spalte`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `migration_betragskorrekturen`
  (`tabelle`, `datensatz_id`, `spalte`, `originalwert`, `korrigierter_wert`, `grund`)
SELECT
  'reparaturauftraege', `id`, 'preis', CAST(`preis` AS CHAR), '0.00',
  'Negativer Altwert; ab dieser Migration sind nur Beträge ab 0 zulässig.'
FROM `reparaturauftraege`
WHERE `preis` < 0;

UPDATE `reparaturauftraege`
SET `preis` = 0.00
WHERE `preis` < 0;

ALTER TABLE `reparaturauftraege`
  ADD COLUMN `kunde_id` int(11) NULL AFTER `id`;

UPDATE `reparaturauftraege` ra
INNER JOIN `geraete` g ON g.`id` = ra.`geraet_id`
SET ra.`kunde_id` = g.`kunde_id`
WHERE ra.`kunde_id` IS NULL;

-- Bricht bewusst ab, falls ein Altauftrag nicht verlustfrei einem Kunden
-- zugeordnet werden konnte.
DELIMITER $$
CREATE PROCEDURE `migration_pruefe_auftragskunden`()
BEGIN
  IF EXISTS (SELECT 1 FROM `reparaturauftraege` WHERE `kunde_id` IS NULL) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Migration abgebrochen: Mindestens ein Auftrag hat keine gültige Kunden-/Gerätezuordnung.';
  END IF;
END$$
DELIMITER ;
CALL `migration_pruefe_auftragskunden`();
DROP PROCEDURE `migration_pruefe_auftragskunden`;

ALTER TABLE `reparaturauftraege`
  MODIFY `kunde_id` int(11) NOT NULL,
  MODIFY `geraet_id` int(11) NULL,
  MODIFY `fehlerbeschreibung` text NULL,
  MODIFY `status_id` int(11) NULL,
  ADD KEY `idx_reparaturauftraege_kunde_id` (`kunde_id`),
  ADD CONSTRAINT `fk_reparaturauftraege_kunde`
    FOREIGN KEY (`kunde_id`) REFERENCES `kunden` (`id`),
  ADD CONSTRAINT `chk_reparaturauftraege_preis_nicht_negativ`
    CHECK (`preis` IS NULL OR `preis` >= 0);

CREATE TABLE `reparaturauftrag_geraete` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `auftrag_id` int(11) NOT NULL,
  `geraet_id` int(11) NOT NULL,
  `fehlerbeschreibung` text NOT NULL,
  `preis` decimal(10,2) DEFAULT NULL,
  `status_id` int(11) NOT NULL,
  `erstellt_am` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_auftrag_geraet` (`auftrag_id`, `geraet_id`),
  UNIQUE KEY `uq_rag_geraet_id` (`geraet_id`),
  KEY `idx_rag_status_id` (`status_id`),
  CONSTRAINT `fk_rag_auftrag`
    FOREIGN KEY (`auftrag_id`) REFERENCES `reparaturauftraege` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rag_geraet`
    FOREIGN KEY (`geraet_id`) REFERENCES `geraete` (`id`),
  CONSTRAINT `fk_rag_status`
    FOREIGN KEY (`status_id`) REFERENCES `status` (`id`),
  CONSTRAINT `chk_rag_preis_nicht_negativ`
    CHECK (`preis` IS NULL OR `preis` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `reparaturauftrag_geraete`
  (`auftrag_id`, `geraet_id`, `fehlerbeschreibung`, `preis`, `status_id`, `erstellt_am`)
SELECT
  `id`, `geraet_id`, `fehlerbeschreibung`, `preis`, `status_id`, COALESCE(`erstellt_am`, current_timestamp())
FROM `reparaturauftraege`;

-- Verlustfreiheitsprüfung: Jeder Altauftrag muss nun genau eine Zuordnung haben.
DELIMITER $$
CREATE PROCEDURE `migration_pruefe_geraete`()
BEGIN
  IF (SELECT COUNT(*) FROM `reparaturauftrag_geraete`) <>
     (SELECT COUNT(*) FROM `reparaturauftraege`) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Migration abgebrochen: Anzahl der Gerätezuordnungen stimmt nicht mit den Altaufträgen überein.';
  END IF;
END$$
DELIMITER ;
CALL `migration_pruefe_geraete`();
DROP PROCEDURE `migration_pruefe_geraete`;
