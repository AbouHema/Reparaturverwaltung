-- Migration: optionale Kunden- und Gerätedaten für Formulare und Suche
-- Zielsystem: MariaDB 10.4+
-- Vor der Ausführung eine vollständige Datenbanksicherung erstellen.

ALTER TABLE `kunden`
  ADD COLUMN IF NOT EXISTS `firmenname` varchar(150) NULL AFTER `name`,
  MODIFY COLUMN `telefon` varchar(30) NULL,
  ADD COLUMN IF NOT EXISTS `email` varchar(254) NULL AFTER `telefon`;

ALTER TABLE `geraete`
  ADD COLUMN IF NOT EXISTS `hersteller` varchar(100) NULL AFTER `geraetetyp`,
  ADD COLUMN IF NOT EXISTS `seriennummer` varchar(100) NULL AFTER `modell`;

