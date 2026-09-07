-- Neuinstallation der Reparaturverwaltung (MariaDB 10.4+)
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";
SET NAMES utf8mb4;

CREATE TABLE `kunden` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `firmenname` varchar(150) DEFAULT NULL,
  `telefon` varchar(30) DEFAULT NULL,
  `email` varchar(254) DEFAULT NULL,
  `strasse` varchar(200) DEFAULT NULL,
  `plz` varchar(20) DEFAULT NULL,
  `ort` varchar(100) DEFAULT NULL,
  `land` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `status` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bezeichnung` varchar(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `geraete` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `kunde_id` int(11) NOT NULL,
  `geraetetyp` varchar(50) NOT NULL,
  `hersteller` varchar(100) DEFAULT NULL,
  `modell` varchar(100) DEFAULT NULL,
  `seriennummer` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_geraete_kunde_id` (`kunde_id`),
  CONSTRAINT `fk_geraete_kunde`
    FOREIGN KEY (`kunde_id`) REFERENCES `kunden` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `reparaturauftraege` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `kunde_id` int(11) NOT NULL,
  `erstellt_am` datetime NOT NULL DEFAULT current_timestamp(),
  `bereits_bezahlt` decimal(10,2) NOT NULL DEFAULT 0.00,
  `nicht_zugeordnet_bezahlt` decimal(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_reparaturauftraege_kunde_id` (`kunde_id`),
  CONSTRAINT `fk_reparaturauftraege_kunde`
    FOREIGN KEY (`kunde_id`) REFERENCES `kunden` (`id`),
  CONSTRAINT `chk_auftrag_bereits_bezahlt_nicht_negativ`
    CHECK (`bereits_bezahlt` >= 0),
  CONSTRAINT `chk_auftrag_nicht_zugeordnet_bezahlt`
    CHECK (`nicht_zugeordnet_bezahlt` >= 0 AND `nicht_zugeordnet_bezahlt` <= `bereits_bezahlt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `reparaturauftrag_geraete` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `auftrag_id` int(11) NOT NULL,
  `geraet_id` int(11) NOT NULL,
  `fehlerbeschreibung` text NOT NULL,
  `durchgefuehrte_arbeiten` text DEFAULT NULL,
  `preis` decimal(10,2) NOT NULL,
  `bereits_bezahlt` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status_id` int(11) NOT NULL,
  `abgeholt_am` datetime DEFAULT NULL,
  `abgeholt_von` varchar(150) DEFAULT NULL,
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
    CHECK (`preis` >= 0),
  CONSTRAINT `chk_geraet_bereits_bezahlt`
    CHECK (`bereits_bezahlt` >= 0 AND `bereits_bezahlt` <= `preis`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `geschaeftskonfiguration` (
  `id` tinyint unsigned NOT NULL,
  `geschaeftsname` varchar(150) NOT NULL,
  `inhaber` varchar(200) DEFAULT NULL,
  `strasse` varchar(200) DEFAULT NULL,
  `plz` varchar(20) DEFAULT NULL,
  `ort` varchar(100) DEFAULT NULL,
  `telefon` varchar(50) DEFAULT NULL,
  `email` varchar(254) DEFAULT NULL,
  `steuernummer` varchar(100) DEFAULT NULL,
  `ust_id` varchar(100) DEFAULT NULL,
  `bankverbindung` text DEFAULT NULL,
  `zahlungsziel` varchar(200) DEFAULT NULL,
  `rechnungshinweise` text DEFAULT NULL,
  `aktualisiert_am` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  CONSTRAINT `chk_geschaeftskonfiguration_eintrag` CHECK (`id` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `rechnungsnummern` (
  `jahr` smallint unsigned NOT NULL,
  `letzter_wert` int unsigned NOT NULL,
  PRIMARY KEY (`jahr`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `benutzer` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `benutzername` varchar(100) NOT NULL,
  `email` varchar(254) DEFAULT NULL,
  `passwort_hash` varchar(255) NOT NULL,
  `rolle` varchar(30) NOT NULL,
  `aktiv` tinyint(1) NOT NULL DEFAULT 1,
  `kontostatus` varchar(20) NOT NULL DEFAULT 'wartend',
  `sitzung_version` int unsigned NOT NULL DEFAULT 0,
  `fehlversuche` smallint unsigned NOT NULL DEFAULT 0,
  `gesperrt_bis` datetime DEFAULT NULL,
  `freigegeben_am` datetime DEFAULT NULL,
  `freigegeben_von` int unsigned DEFAULT NULL,
  `erstellt_am` datetime NOT NULL DEFAULT current_timestamp(),
  `aktualisiert_am` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_benutzer_benutzername` (`benutzername`), UNIQUE KEY `uq_benutzer_email` (`email`),
  CONSTRAINT `chk_benutzer_rolle` CHECK (`rolle` IN ('administrator','mitarbeiter'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `login_sperren` (`schluessel_hash` char(64) NOT NULL,`fehlversuche` smallint unsigned NOT NULL DEFAULT 0,`gesperrt_bis` datetime DEFAULT NULL,`aktualisiert_am` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),PRIMARY KEY (`schluessel_hash`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `anwendungseinstellungen` (`id` tinyint unsigned NOT NULL,`selbstregistrierung_aktiv` tinyint(1) NOT NULL DEFAULT 1,`aktualisiert_am` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),PRIMARY KEY (`id`),CONSTRAINT `chk_anwendungseinstellungen_id` CHECK (`id` = 1)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
INSERT INTO `anwendungseinstellungen` (`id`,`selbstregistrierung_aktiv`) VALUES (1,1);

CREATE TABLE `passwort_reset_tokens` (`id` bigint unsigned NOT NULL AUTO_INCREMENT,`benutzer_id` int unsigned NOT NULL,`token_hash` char(64) NOT NULL,`gueltig_bis` datetime NOT NULL,`verwendet_am` datetime DEFAULT NULL,`erstellt_am` datetime NOT NULL DEFAULT current_timestamp(),PRIMARY KEY (`id`),UNIQUE KEY `uq_passwort_reset_token` (`token_hash`),KEY `idx_passwort_reset_benutzer` (`benutzer_id`,`verwendet_am`,`gueltig_bis`),CONSTRAINT `fk_passwort_reset_benutzer` FOREIGN KEY (`benutzer_id`) REFERENCES `benutzer` (`id`) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `passwort_reset_limits` (`schluessel_hash` char(64) NOT NULL,`anzahl` smallint unsigned NOT NULL DEFAULT 1,`fenster_start` datetime NOT NULL DEFAULT current_timestamp(),`aktualisiert_am` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),PRIMARY KEY (`schluessel_hash`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `audit_protokoll` (`id` bigint unsigned NOT NULL AUTO_INCREMENT,`benutzer_id` int unsigned DEFAULT NULL,`ereignis` varchar(80) NOT NULL,`objekttyp` varchar(50) DEFAULT NULL,`objekt_id` varchar(100) DEFAULT NULL,`details` text DEFAULT NULL,`ip_hash` char(64) DEFAULT NULL,`erstellt_am` datetime NOT NULL DEFAULT current_timestamp(),PRIMARY KEY (`id`),KEY `idx_audit_ereignis_datum` (`ereignis`,`erstellt_am`),CONSTRAINT `fk_audit_benutzer` FOREIGN KEY (`benutzer_id`) REFERENCES `benutzer` (`id`) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `rechnungen` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `auftrag_id` int(11) DEFAULT NULL,
  `auftragsnummer_snapshot` int(11) NOT NULL,
  `rechnungsnummer` varchar(30) NOT NULL,
  `rechnungsart` varchar(20) NOT NULL DEFAULT 'gemeinsam',
  `rechnungsdatum` date NOT NULL,
  `leistungsdatum` date NOT NULL,
  `steuersatz` decimal(5,2) NOT NULL DEFAULT 19.00,
  `netto` decimal(10,2) NOT NULL,
  `steuerbetrag` decimal(10,2) NOT NULL,
  `brutto` decimal(10,2) NOT NULL,
  `bereits_bezahlt` decimal(10,2) NOT NULL DEFAULT 0.00,
  `restbetrag` decimal(10,2) NOT NULL,
  `zahlungsstatus` varchar(30) NOT NULL DEFAULT 'Bezahlt',
  `zahlungsdatum_snapshot` varchar(255) DEFAULT NULL,
  `zahlungsart_snapshot` varchar(500) DEFAULT NULL,
  `zahlungsnotiz_snapshot` text DEFAULT NULL,
  `kunde_name` varchar(100) NOT NULL,
  `kunde_firmenname` varchar(150) DEFAULT NULL,
  `kunde_telefon` varchar(30) DEFAULT NULL,
  `kunde_email` varchar(254) DEFAULT NULL,
  `kunde_strasse` varchar(200) DEFAULT NULL,
  `kunde_plz` varchar(20) DEFAULT NULL,
  `kunde_ort` varchar(100) DEFAULT NULL,
  `kunde_land` varchar(100) DEFAULT NULL,
  `geschaeftsname` varchar(150) NOT NULL,
  `inhaber` varchar(200) NOT NULL,
  `strasse` varchar(200) NOT NULL,
  `plz` varchar(20) NOT NULL,
  `ort` varchar(100) NOT NULL,
  `geschaeft_telefon` varchar(50) DEFAULT NULL,
  `geschaeft_email` varchar(254) DEFAULT NULL,
  `steuernummer` varchar(100) DEFAULT NULL,
  `ust_id` varchar(100) DEFAULT NULL,
  `bankverbindung` text DEFAULT NULL,
  `zahlungsziel` varchar(200) DEFAULT NULL,
  `rechnungshinweise` text DEFAULT NULL,
  `erstellt_am` datetime NOT NULL DEFAULT current_timestamp(),
  `storniert_am` datetime DEFAULT NULL,
  `storniert_von` int unsigned DEFAULT NULL,
  `storno_grund` varchar(1000) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rechnungen_nummer` (`rechnungsnummer`),
  KEY `idx_rechnungen_auftrag` (`auftrag_id`),
  CONSTRAINT `fk_rechnungen_auftrag` FOREIGN KEY (`auftrag_id`) REFERENCES `reparaturauftraege` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_rechnungen_storniert_von` FOREIGN KEY (`storniert_von`) REFERENCES `benutzer` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_rechnungen_betraege` CHECK (`netto` >= 0 AND `steuerbetrag` >= 0 AND `brutto` >= 0 AND `bereits_bezahlt` >= 0 AND `restbetrag` >= 0),
  CONSTRAINT `chk_rechnungen_rechnungsart` CHECK (`rechnungsart` IN ('einzeln', 'gemeinsam')),
  CONSTRAINT `chk_rechnungen_zahlungsstatus` CHECK (`zahlungsstatus` IN ('Bezahlt', 'Teilweise bezahlt', 'Offen'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `rechnung_geraete` (
  `rechnung_id` bigint unsigned NOT NULL,
  `auftrag_id_snapshot` int(11) NOT NULL,
  `geraet_id_snapshot` int(11) NOT NULL,
  `storniert_am` datetime DEFAULT NULL,
  `aktive_geraet_id` int(11) GENERATED ALWAYS AS (CASE WHEN `storniert_am` IS NULL THEN `geraet_id_snapshot` ELSE NULL END) STORED,
  PRIMARY KEY (`rechnung_id`, `geraet_id_snapshot`),
  UNIQUE KEY `uq_aktives_abgerechnetes_geraet` (`aktive_geraet_id`),
  KEY `idx_rechnung_geraete_auftrag` (`auftrag_id_snapshot`),
  CONSTRAINT `fk_rechnung_geraete_rechnung`
    FOREIGN KEY (`rechnung_id`) REFERENCES `rechnungen` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `rechnungspositionen` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `rechnung_id` bigint unsigned NOT NULL,
  `positionsnummer` int unsigned NOT NULL,
  `geraet_id_snapshot` int(11) DEFAULT NULL,
  `geraetetyp` varchar(50) NOT NULL,
  `hersteller` varchar(100) DEFAULT NULL,
  `modell` varchar(100) DEFAULT NULL,
  `seriennummer` varchar(100) DEFAULT NULL,
  `leistungsbeschreibung` text DEFAULT NULL,
  `brutto` decimal(10,2) NOT NULL,
  `netto` decimal(10,2) NOT NULL,
  `steuerbetrag` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rechnungsposition` (`rechnung_id`, `positionsnummer`),
  CONSTRAINT `fk_rechnungsposition_rechnung` FOREIGN KEY (`rechnung_id`) REFERENCES `rechnungen` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_rechnungsposition_betraege` CHECK (`brutto` >= 0 AND `netto` >= 0 AND `steuerbetrag` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `geraete_zahlungen` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,`auftrag_id` int(11) DEFAULT NULL,`auftragsnummer_snapshot` int(11) NOT NULL,`zuordnung_id` int(11) DEFAULT NULL,`geraet_id_snapshot` int(11) NOT NULL,`betrag` decimal(10,2) NOT NULL,`vorgang` varchar(20) NOT NULL DEFAULT 'zahlung',`zahlungsdatum` date NOT NULL,`zahlungsart` varchar(50) NOT NULL,`notiz` varchar(1000) DEFAULT NULL,`bezugszahlung_id` bigint unsigned DEFAULT NULL,`benutzer_id` int unsigned DEFAULT NULL,`erstellt_am` datetime NOT NULL DEFAULT current_timestamp(),PRIMARY KEY (`id`),KEY `idx_zahlungen_auftrag` (`auftragsnummer_snapshot`),KEY `idx_zahlungen_geraet` (`geraet_id_snapshot`,`erstellt_am`),CONSTRAINT `fk_zahlungen_auftrag` FOREIGN KEY (`auftrag_id`) REFERENCES `reparaturauftraege` (`id`) ON DELETE SET NULL,CONSTRAINT `fk_zahlungen_zuordnung` FOREIGN KEY (`zuordnung_id`) REFERENCES `reparaturauftrag_geraete` (`id`) ON DELETE SET NULL,CONSTRAINT `fk_zahlungen_bezug` FOREIGN KEY (`bezugszahlung_id`) REFERENCES `geraete_zahlungen` (`id`),CONSTRAINT `fk_zahlungen_benutzer` FOREIGN KEY (`benutzer_id`) REFERENCES `benutzer` (`id`) ON DELETE SET NULL,CONSTRAINT `chk_zahlungen_betrag` CHECK (`betrag` > 0),CONSTRAINT `chk_zahlungen_vorgang` CHECK (`vorgang` IN ('zahlung','storno'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `status` (`id`, `bezeichnung`) VALUES
(1, 'Angenommen'),
(2, 'In Bearbeitung'),
(3, 'Fertig');

INSERT INTO `geschaeftskonfiguration` (`id`, `geschaeftsname`) VALUES (1, 'Computerfachmann');

INSERT INTO `kunden` (`id`, `name`, `telefon`) VALUES
(1, 'Max Mustermann', '0301234567'),
(2, 'Max Müller', '03023232'),
(3, 'Nasim', '015231'),
(4, 'Testkunde Tag6', '0300000000'),
(5, 'Testkunde Preis', '0301111111'),
(6, 'Testkunde Anleitung', '0302222222');

INSERT INTO `geraete` (`id`, `kunde_id`, `geraetetyp`, `modell`) VALUES
(1, 1, 'Handy', 'Samsung Galaxy S23'),
(2, 2, 'Laptop', 'Dell'),
(3, 3, 'Drucker', 'p1p4'),
(4, 4, 'Laptop', 'Testmodell'),
(5, 5, 'Handy', 'Testmodell'),
(6, 6, 'Laptop', 'Testgerät');

INSERT INTO `reparaturauftraege` (`id`, `kunde_id`, `erstellt_am`) VALUES
(1, 1, '2026-08-26 09:04:15'),
(2, 2, '2026-08-26 13:42:56'),
(3, 3, '2026-08-26 16:40:42'),
(4, 4, '2026-08-27 12:06:05'),
(5, 5, '2026-08-27 12:22:42'),
(6, 6, '2026-08-27 13:21:30');

INSERT INTO `reparaturauftrag_geraete`
  (`id`, `auftrag_id`, `geraet_id`, `fehlerbeschreibung`, `preis`, `status_id`, `erstellt_am`) VALUES
(1, 1, 1, 'Display beschädigt', 149.90, 1, '2026-08-26 09:04:15'),
(2, 2, 2, 'Tastatur', 200.00, 3, '2026-08-26 13:42:56'),
(3, 3, 3, 'laser', 70.00, 1, '2026-08-26 16:40:42'),
(4, 4, 4, 'Display und Tastatur defekt', 50.00, 3, '2026-08-27 12:06:05'),
(5, 5, 5, 'Test Preisgrenze', 0.00, 1, '2026-08-27 12:22:42'),
(6, 6, 6, 'Gerät startet nicht', 80.00, 2, '2026-08-27 13:21:30');

ALTER TABLE `kunden` AUTO_INCREMENT = 7;
ALTER TABLE `geraete` AUTO_INCREMENT = 7;
ALTER TABLE `reparaturauftraege` AUTO_INCREMENT = 7;
ALTER TABLE `reparaturauftrag_geraete` AUTO_INCREMENT = 7;
ALTER TABLE `status` AUTO_INCREMENT = 4;

COMMIT;
