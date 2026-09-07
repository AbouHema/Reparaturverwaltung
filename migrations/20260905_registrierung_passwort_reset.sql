-- Sichere, wiederholt ausführbare Erweiterung für Registrierung und Passwort-Reset.
-- Vor Ausführung ist eine aktuelle, isoliert wiederhergestellte Sicherung Pflicht.

ALTER TABLE `benutzer`
  ADD COLUMN IF NOT EXISTS `email` varchar(254) NULL AFTER `benutzername`,
  ADD COLUMN IF NOT EXISTS `kontostatus` varchar(20) NOT NULL DEFAULT 'wartend' AFTER `aktiv`,
  ADD COLUMN IF NOT EXISTS `sitzung_version` int unsigned NOT NULL DEFAULT 0 AFTER `kontostatus`,
  ADD COLUMN IF NOT EXISTS `freigegeben_am` datetime NULL AFTER `gesperrt_bis`,
  ADD COLUMN IF NOT EXISTS `freigegeben_von` int unsigned NULL AFTER `freigegeben_am`;

UPDATE `benutzer`
SET `kontostatus` = CASE WHEN `aktiv` = 1 THEN 'aktiv' ELSE 'deaktiviert' END
WHERE `kontostatus` = 'wartend' AND `email` IS NULL;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.statistics
   WHERE table_schema=DATABASE() AND table_name='benutzer' AND index_name='uq_benutzer_email') = 0,
  'ALTER TABLE `benutzer` ADD UNIQUE KEY `uq_benutzer_email` (`email`)',
  'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

CREATE TABLE IF NOT EXISTS `anwendungseinstellungen` (
  `id` tinyint unsigned NOT NULL,
  `selbstregistrierung_aktiv` tinyint(1) NOT NULL DEFAULT 1,
  `aktualisiert_am` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  CONSTRAINT `chk_anwendungseinstellungen_id` CHECK (`id` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `anwendungseinstellungen` (`id`,`selbstregistrierung_aktiv`) VALUES (1,1);

CREATE TABLE IF NOT EXISTS `passwort_reset_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `benutzer_id` int unsigned NOT NULL,
  `token_hash` char(64) NOT NULL,
  `gueltig_bis` datetime NOT NULL,
  `verwendet_am` datetime NULL,
  `erstellt_am` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_passwort_reset_token` (`token_hash`),
  KEY `idx_passwort_reset_benutzer` (`benutzer_id`,`verwendet_am`,`gueltig_bis`),
  CONSTRAINT `fk_passwort_reset_benutzer` FOREIGN KEY (`benutzer_id`) REFERENCES `benutzer` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `passwort_reset_limits` (
  `schluessel_hash` char(64) NOT NULL,
  `anzahl` smallint unsigned NOT NULL DEFAULT 1,
  `fenster_start` datetime NOT NULL DEFAULT current_timestamp(),
  `aktualisiert_am` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`schluessel_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
