-- Migration: Zahlungen, Leistungsbeschreibungen und revisionssichere Rechnungen
-- Zielsystem: MariaDB 10.4+
-- Nur nach einer aktuellen, erfolgreich geprüften Sicherung ausführen.

ALTER TABLE `reparaturauftraege`
  ADD COLUMN IF NOT EXISTS `bereits_bezahlt` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `erstellt_am`,
  ADD CONSTRAINT `chk_auftrag_bereits_bezahlt_nicht_negativ`
    CHECK (`bereits_bezahlt` >= 0);

ALTER TABLE `reparaturauftrag_geraete`
  ADD COLUMN IF NOT EXISTS `durchgefuehrte_arbeiten` text NULL AFTER `fehlerbeschreibung`,
  MODIFY `preis` decimal(10,2) NOT NULL;

CREATE TABLE IF NOT EXISTS `geschaeftskonfiguration` (
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

INSERT IGNORE INTO `geschaeftskonfiguration` (`id`, `geschaeftsname`)
VALUES (1, 'Computerfachmann');

CREATE TABLE IF NOT EXISTS `rechnungsnummern` (
  `jahr` smallint unsigned NOT NULL,
  `letzter_wert` int unsigned NOT NULL,
  PRIMARY KEY (`jahr`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `rechnungen` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `auftrag_id` int(11) DEFAULT NULL,
  `auftragsnummer_snapshot` int(11) NOT NULL,
  `rechnungsnummer` varchar(30) NOT NULL,
  `rechnungsdatum` date NOT NULL,
  `leistungsdatum` date NOT NULL,
  `steuersatz` decimal(5,2) NOT NULL DEFAULT 19.00,
  `netto` decimal(10,2) NOT NULL,
  `steuerbetrag` decimal(10,2) NOT NULL,
  `brutto` decimal(10,2) NOT NULL,
  `bereits_bezahlt` decimal(10,2) NOT NULL DEFAULT 0.00,
  `restbetrag` decimal(10,2) NOT NULL,
  `kunde_name` varchar(100) NOT NULL,
  `kunde_firmenname` varchar(150) DEFAULT NULL,
  `kunde_telefon` varchar(30) DEFAULT NULL,
  `kunde_email` varchar(254) DEFAULT NULL,
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rechnungen_nummer` (`rechnungsnummer`),
  UNIQUE KEY `uq_rechnungen_auftrag` (`auftrag_id`),
  CONSTRAINT `fk_rechnungen_auftrag`
    FOREIGN KEY (`auftrag_id`) REFERENCES `reparaturauftraege` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_rechnungen_betraege`
    CHECK (`netto` >= 0 AND `steuerbetrag` >= 0 AND `brutto` >= 0
           AND `bereits_bezahlt` >= 0 AND `restbetrag` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `rechnungspositionen` (
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
  CONSTRAINT `fk_rechnungsposition_rechnung`
    FOREIGN KEY (`rechnung_id`) REFERENCES `rechnungen` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_rechnungsposition_betraege`
    CHECK (`brutto` >= 0 AND `netto` >= 0 AND `steuerbetrag` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
