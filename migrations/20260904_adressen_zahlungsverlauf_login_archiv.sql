-- Migration: Kundenadressen, auditierbare Zahlungen, Rechnungsarchiv und Anmeldung
-- Vor Ausführung ist eine aktuelle und erfolgreich wiederhergestellte Sicherung Pflicht.

ALTER TABLE `kunden`
  ADD COLUMN IF NOT EXISTS `strasse` varchar(200) NULL AFTER `email`,
  ADD COLUMN IF NOT EXISTS `plz` varchar(20) NULL AFTER `strasse`,
  ADD COLUMN IF NOT EXISTS `ort` varchar(100) NULL AFTER `plz`,
  ADD COLUMN IF NOT EXISTS `land` varchar(100) NULL AFTER `ort`;

CREATE TABLE IF NOT EXISTS `benutzer` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `benutzername` varchar(100) NOT NULL,
  `passwort_hash` varchar(255) NOT NULL,
  `rolle` varchar(30) NOT NULL,
  `aktiv` tinyint(1) NOT NULL DEFAULT 1,
  `fehlversuche` smallint unsigned NOT NULL DEFAULT 0,
  `gesperrt_bis` datetime NULL,
  `erstellt_am` datetime NOT NULL DEFAULT current_timestamp(),
  `aktualisiert_am` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_benutzer_benutzername` (`benutzername`),
  CONSTRAINT `chk_benutzer_rolle` CHECK (`rolle` IN ('administrator', 'mitarbeiter'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `login_sperren` (
  `schluessel_hash` char(64) NOT NULL,
  `fehlversuche` smallint unsigned NOT NULL DEFAULT 0,
  `gesperrt_bis` datetime NULL,
  `aktualisiert_am` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`schluessel_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `audit_protokoll` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `benutzer_id` int unsigned NULL,
  `ereignis` varchar(80) NOT NULL,
  `objekttyp` varchar(50) NULL,
  `objekt_id` varchar(100) NULL,
  `details` text NULL,
  `ip_hash` char(64) NULL,
  `erstellt_am` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_ereignis_datum` (`ereignis`, `erstellt_am`),
  KEY `idx_audit_benutzer` (`benutzer_id`),
  CONSTRAINT `fk_audit_benutzer` FOREIGN KEY (`benutzer_id`) REFERENCES `benutzer` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `geraete_zahlungen` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `auftrag_id` int(11) NULL,
  `auftragsnummer_snapshot` int(11) NOT NULL,
  `zuordnung_id` int(11) NULL,
  `geraet_id_snapshot` int(11) NOT NULL,
  `betrag` decimal(10,2) NOT NULL,
  `vorgang` varchar(20) NOT NULL DEFAULT 'zahlung',
  `zahlungsdatum` date NOT NULL,
  `zahlungsart` varchar(50) NOT NULL,
  `notiz` varchar(1000) NULL,
  `bezugszahlung_id` bigint unsigned NULL,
  `benutzer_id` int unsigned NULL,
  `erstellt_am` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_zahlungen_auftrag` (`auftragsnummer_snapshot`),
  KEY `idx_zahlungen_geraet` (`geraet_id_snapshot`, `erstellt_am`),
  KEY `idx_zahlungen_zuordnung` (`zuordnung_id`),
  CONSTRAINT `fk_zahlungen_auftrag` FOREIGN KEY (`auftrag_id`) REFERENCES `reparaturauftraege` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_zahlungen_zuordnung` FOREIGN KEY (`zuordnung_id`) REFERENCES `reparaturauftrag_geraete` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_zahlungen_bezug` FOREIGN KEY (`bezugszahlung_id`) REFERENCES `geraete_zahlungen` (`id`),
  CONSTRAINT `fk_zahlungen_benutzer` FOREIGN KEY (`benutzer_id`) REFERENCES `benutzer` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_zahlungen_betrag` CHECK (`betrag` > 0),
  CONSTRAINT `chk_zahlungen_vorgang` CHECK (`vorgang` IN ('zahlung', 'storno'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Eindeutige Altzahlung bei einem einzelnen Gerät verlustfrei zuordnen.
UPDATE `reparaturauftrag_geraete` rag
INNER JOIN `reparaturauftraege` ra ON ra.`id` = rag.`auftrag_id`
INNER JOIN (
  SELECT `auftrag_id` FROM `reparaturauftrag_geraete` GROUP BY `auftrag_id` HAVING COUNT(*) = 1
) einzel ON einzel.`auftrag_id` = rag.`auftrag_id`
SET rag.`bereits_bezahlt` = rag.`bereits_bezahlt` + ra.`nicht_zugeordnet_bezahlt`,
    ra.`nicht_zugeordnet_bezahlt` = 0.00
WHERE ra.`nicht_zugeordnet_bezahlt` > 0
  AND rag.`bereits_bezahlt` + ra.`nicht_zugeordnet_bezahlt` <= rag.`preis`;

-- Sämtliche vorhandenen gerätebezogenen Zahlungen als unveränderliche Bestandsübernahme protokollieren.
INSERT INTO `geraete_zahlungen`
  (`auftrag_id`, `auftragsnummer_snapshot`, `zuordnung_id`, `geraet_id_snapshot`, `betrag`, `vorgang`, `zahlungsdatum`, `zahlungsart`, `notiz`)
SELECT rag.`auftrag_id`, rag.`auftrag_id`, rag.`id`, rag.`geraet_id`, rag.`bereits_bezahlt`, 'zahlung',
       DATE(COALESCE(ra.`erstellt_am`, CURRENT_TIMESTAMP)), 'Bestandsübernahme', 'Bei Migration verlustfrei übernommen'
FROM `reparaturauftrag_geraete` rag
INNER JOIN `reparaturauftraege` ra ON ra.`id` = rag.`auftrag_id`
WHERE rag.`bereits_bezahlt` > 0
  AND NOT EXISTS (
    SELECT 1 FROM `geraete_zahlungen` gz
    WHERE gz.`zuordnung_id` = rag.`id`
      AND gz.`geraet_id_snapshot` = rag.`geraet_id`
  );

ALTER TABLE `rechnungen`
  ADD COLUMN IF NOT EXISTS `kunde_strasse` varchar(200) NULL AFTER `kunde_email`,
  ADD COLUMN IF NOT EXISTS `kunde_plz` varchar(20) NULL AFTER `kunde_strasse`,
  ADD COLUMN IF NOT EXISTS `kunde_ort` varchar(100) NULL AFTER `kunde_plz`,
  ADD COLUMN IF NOT EXISTS `kunde_land` varchar(100) NULL AFTER `kunde_ort`,
  ADD COLUMN IF NOT EXISTS `zahlungsstatus` varchar(30) NOT NULL DEFAULT 'Bezahlt' AFTER `restbetrag`,
  ADD COLUMN IF NOT EXISTS `zahlungsdatum_snapshot` varchar(255) NULL AFTER `zahlungsstatus`,
  ADD COLUMN IF NOT EXISTS `zahlungsart_snapshot` varchar(500) NULL AFTER `zahlungsdatum_snapshot`,
  ADD COLUMN IF NOT EXISTS `zahlungsnotiz_snapshot` text NULL AFTER `zahlungsart_snapshot`,
  ADD COLUMN IF NOT EXISTS `storniert_am` datetime NULL AFTER `erstellt_am`,
  ADD COLUMN IF NOT EXISTS `storniert_von` int unsigned NULL AFTER `storniert_am`,
  ADD COLUMN IF NOT EXISTS `storno_grund` varchar(1000) NULL AFTER `storniert_von`;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE() AND table_name = 'rechnungen'
     AND constraint_name = 'fk_rechnungen_storniert_von') = 0,
  'ALTER TABLE `rechnungen` ADD CONSTRAINT `fk_rechnungen_storniert_von` FOREIGN KEY (`storniert_von`) REFERENCES `benutzer` (`id`) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE() AND table_name = 'rechnungen'
     AND constraint_name = 'chk_rechnungen_zahlungsstatus') = 0,
  'ALTER TABLE `rechnungen` ADD CONSTRAINT `chk_rechnungen_zahlungsstatus` CHECK (`zahlungsstatus` IN (''Bezahlt'', ''Teilweise bezahlt'', ''Offen''))',
  'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

ALTER TABLE `rechnung_geraete`
  ADD COLUMN IF NOT EXISTS `storniert_am` datetime NULL AFTER `geraet_id_snapshot`,
  ADD COLUMN IF NOT EXISTS `aktive_geraet_id` int(11)
    GENERATED ALWAYS AS (CASE WHEN `storniert_am` IS NULL THEN `geraet_id_snapshot` ELSE NULL END) STORED,
  DROP INDEX IF EXISTS `uq_abgerechnetes_geraet`;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.statistics
   WHERE table_schema = DATABASE() AND table_name = 'rechnung_geraete'
     AND index_name = 'uq_aktives_abgerechnetes_geraet') = 0,
  'ALTER TABLE `rechnung_geraete` ADD UNIQUE KEY `uq_aktives_abgerechnetes_geraet` (`aktive_geraet_id`)',
  'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

DELIMITER $$
CREATE PROCEDURE `migration_pruefe_adressen_zahlungen_login`()
BEGIN
  IF EXISTS (
    SELECT 1 FROM `reparaturauftraege` ra
    WHERE ra.`nicht_zugeordnet_bezahlt` < 0 OR ra.`nicht_zugeordnet_bezahlt` > ra.`bereits_bezahlt`
  ) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration abgebrochen: Nicht zugeordnete Zahlung ist ungültig.';
  END IF;
  IF EXISTS (
    SELECT 1 FROM `reparaturauftrag_geraete` rag
    WHERE rag.`bereits_bezahlt` < 0 OR rag.`bereits_bezahlt` > rag.`preis`
  ) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration abgebrochen: Gerätezahlung ist ungültig.';
  END IF;
  IF EXISTS (
    SELECT 1 FROM `reparaturauftrag_geraete` rag
    WHERE rag.`bereits_bezahlt` > 0 AND NOT EXISTS (
      SELECT 1 FROM `geraete_zahlungen` gz
      WHERE gz.`geraet_id_snapshot` = rag.`geraet_id` AND gz.`vorgang` = 'zahlung'
    )
  ) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration abgebrochen: Eine Bestandszahlung wurde nicht protokolliert.';
  END IF;
END$$
DELIMITER ;
CALL `migration_pruefe_adressen_zahlungen_login`();
DROP PROCEDURE `migration_pruefe_adressen_zahlungen_login`;
