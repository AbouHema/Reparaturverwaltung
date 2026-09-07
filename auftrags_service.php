<?php

declare(strict_types=1);

require_once __DIR__ . "/funktionen.php";

function auftrag_anlegen(
    PDO $pdo,
    string $name,
    ?string $telefon,
    ?string $firmenname,
    ?string $email,
    string $nichtZugeordnetBezahlt,
    array $geraete,
    ?int $bestehenderKundeId = null,
    ?string $strasse = null,
    ?string $plz = null,
    ?string $ort = null,
    ?string $land = null
): int
{
    $angenommenStatusId = status_id_nach_bezeichnung(status_ids_laden($pdo), "Angenommen");
    $pdo->beginTransaction();
    try {
        if ($bestehenderKundeId !== null) {
            $stmt = $pdo->prepare("SELECT id FROM kunden WHERE id=? FOR UPDATE"); $stmt->execute([$bestehenderKundeId]);
            if ($stmt->fetchColumn() === false) throw new EingabeException("Der ausgewählte bestehende Kunde wurde nicht gefunden.");
            $kundeId = $bestehenderKundeId;
            $stmt = $pdo->prepare("UPDATE kunden SET name=?,firmenname=?,telefon=?,email=?,strasse=?,plz=?,ort=?,land=? WHERE id=?");
            $stmt->execute([$name,$firmenname,$telefon,$email,$strasse,$plz,$ort,$land,$kundeId]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO kunden (name,firmenname,telefon,email,strasse,plz,ort,land) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([$name,$firmenname,$telefon,$email,$strasse,$plz,$ort,$land]);
            $kundeId = (int) $pdo->lastInsertId();
        }

        $zahlung = zahlungszuordnung_validieren($geraete, $nichtZugeordnetBezahlt);
        $stmt = $pdo->prepare("INSERT INTO reparaturauftraege (kunde_id, bereits_bezahlt, nicht_zugeordnet_bezahlt) VALUES (?, ?, ?)");
        $stmt->execute([$kundeId, $zahlung["gesamt"], $zahlung["nicht_zugeordnet"]]);
        $auftragId = (int) $pdo->lastInsertId();

        $geraetStmt = $pdo->prepare(
            "INSERT INTO geraete (kunde_id, geraetetyp, hersteller, modell, seriennummer)
             VALUES (?, ?, ?, ?, ?)"
        );
        $zuordnungStmt = $pdo->prepare(
            "INSERT INTO reparaturauftrag_geraete
                (auftrag_id, geraet_id, fehlerbeschreibung, durchgefuehrte_arbeiten, preis, bereits_bezahlt, status_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $zahlungsStmt = $pdo->prepare("INSERT INTO geraete_zahlungen (auftrag_id,auftragsnummer_snapshot,zuordnung_id,geraet_id_snapshot,betrag,vorgang,zahlungsdatum,zahlungsart,notiz,benutzer_id) VALUES (?,?,?,?,?,'zahlung',CURRENT_DATE,'Bestandsübernahme','Bei Auftragserfassung übernommen',?)");

        foreach ($geraete as $geraet) {
            $geraetStmt->execute([
                $kundeId,
                $geraet["geraetetyp"],
                $geraet["hersteller"],
                $geraet["modell"],
                $geraet["seriennummer"],
            ]);
            $geraetId = (int) $pdo->lastInsertId();
            $zuordnungStmt->execute([
                $auftragId,
                $geraetId,
                $geraet["fehlerbeschreibung"],
                $geraet["durchgefuehrte_arbeiten"],
                $geraet["preis"],
                $geraet["bereits_bezahlt"],
                $angenommenStatusId,
            ]);
            if (betrag_in_cent((string) $geraet["bereits_bezahlt"]) > 0) {
                $zahlungsStmt->execute([$auftragId,$auftragId,(int)$pdo->lastInsertId(),$geraetId,$geraet["bereits_bezahlt"],aktuelle_benutzer_id()]);
            }
        }

        $pdo->commit();
        return $auftragId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function auftrag_aktualisieren(
    PDO $pdo,
    int $auftragId,
    string $name,
    ?string $telefon,
    ?string $firmenname,
    ?string $email,
    string $nichtZugeordnetBezahlt,
    array $geraete,
    ?string $strasse = null,
    ?string $plz = null,
    ?string $ort = null,
    ?string $land = null
): void
{
    $angenommenStatusId = status_id_nach_bezeichnung(status_ids_laden($pdo), "Angenommen");
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT kunde_id FROM reparaturauftraege WHERE id = ? FOR UPDATE");
        $stmt->execute([$auftragId]);
        $kundeId = $stmt->fetchColumn();
        if ($kundeId === false) {
            throw new EingabeException("Reparaturauftrag wurde nicht gefunden.");
        }
        $kundeId = (int) $kundeId;

        $stmt = $pdo->prepare(
            "SELECT rag.id AS zuordnung_id, rag.geraet_id, rag.status_id, rag.bereits_bezahlt
             FROM reparaturauftrag_geraete rag
             WHERE rag.auftrag_id = ?
             FOR UPDATE"
        );
        $stmt->execute([$auftragId]);
        $zuordnungen = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $zeile) {
            $zuordnungen[(int) $zeile["geraet_id"]] = [
                "id" => (int) $zeile["zuordnung_id"],
                "status_id" => (int) $zeile["status_id"],
                "bereits_bezahlt" => (string) $zeile["bereits_bezahlt"],
            ];
        }

        $uebermittelteBestehendeIds = array_map(
            "intval",
            array_column(array_filter($geraete, static fn(array $geraet): bool => $geraet["id"] !== null), "id")
        );
        sort($uebermittelteBestehendeIds);
        $gespeicherteIds = array_keys($zuordnungen);
        sort($gespeicherteIds);
        if ($uebermittelteBestehendeIds !== $gespeicherteIds) {
            throw new EingabeException("Die übermittelten Gerätedaten sind unvollständig oder gehören nicht vollständig zu diesem Reparaturauftrag.");
        }
        foreach ($geraete as &$geraet) {
            if ($geraet["id"] !== null) {
                $geraet["bereits_bezahlt"] = $zuordnungen[(int)$geraet["id"]]["bereits_bezahlt"];
            } elseif (betrag_in_cent((string)$geraet["bereits_bezahlt"]) > 0) {
                throw new EingabeException("Zahlungen für neue Geräte erfassen Sie nach dem Speichern über die Zahlungsaktion.");
            }
        }
        unset($geraet);

        $zahlung = zahlungszuordnung_validieren($geraete, $nichtZugeordnetBezahlt);
        $stmt = $pdo->prepare(
            "UPDATE kunden SET name = ?, firmenname = ?, telefon = ?, email = ?, strasse=?, plz=?, ort=?, land=? WHERE id = ?"
        );
        $stmt->execute([$name, $firmenname, $telefon, $email, $strasse, $plz, $ort, $land, $kundeId]);
        $stmt = $pdo->prepare("UPDATE reparaturauftraege SET bereits_bezahlt = ?, nicht_zugeordnet_bezahlt = ? WHERE id = ?");
        $stmt->execute([$zahlung["gesamt"], $zahlung["nicht_zugeordnet"], $auftragId]);

        $geraetUpdate = $pdo->prepare(
            "UPDATE geraete
             SET kunde_id = ?, geraetetyp = ?, hersteller = ?, modell = ?, seriennummer = ?
             WHERE id = ?"
        );
        $zuordnungUpdate = $pdo->prepare(
            "UPDATE reparaturauftrag_geraete
             SET fehlerbeschreibung = ?, durchgefuehrte_arbeiten = ?, preis = ?, bereits_bezahlt = ?, status_id = ?
             WHERE id = ? AND auftrag_id = ? AND geraet_id = ?"
        );
        $geraetInsert = $pdo->prepare(
            "INSERT INTO geraete (kunde_id, geraetetyp, hersteller, modell, seriennummer)
             VALUES (?, ?, ?, ?, ?)"
        );
        $zuordnungInsert = $pdo->prepare(
            "INSERT INTO reparaturauftrag_geraete
                (auftrag_id, geraet_id, fehlerbeschreibung, durchgefuehrte_arbeiten, preis, bereits_bezahlt, status_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );

        foreach ($geraete as $geraet) {
            if ($geraet["id"] !== null) {
                $geraetId = (int) $geraet["id"];
                if (!isset($zuordnungen[$geraetId])) {
                    throw new EingabeException("Ein übermitteltes Gerät gehört nicht zu diesem Reparaturauftrag.");
                }

                $geraetUpdate->execute([
                    $kundeId,
                    $geraet["geraetetyp"],
                    $geraet["hersteller"],
                    $geraet["modell"],
                    $geraet["seriennummer"],
                    $geraetId,
                ]);
                $zuordnungUpdate->execute([
                    $geraet["fehlerbeschreibung"],
                    $geraet["durchgefuehrte_arbeiten"],
                    $geraet["preis"],
                    $geraet["bereits_bezahlt"],
                    $geraet["status_id"] ?? $zuordnungen[$geraetId]["status_id"],
                    $zuordnungen[$geraetId]["id"],
                    $auftragId,
                    $geraetId,
                ]);
            } else {
                $geraetInsert->execute([
                    $kundeId,
                    $geraet["geraetetyp"],
                    $geraet["hersteller"],
                    $geraet["modell"],
                    $geraet["seriennummer"],
                ]);
                $geraetId = (int) $pdo->lastInsertId();
                $zuordnungInsert->execute([
                    $auftragId,
                    $geraetId,
                    $geraet["fehlerbeschreibung"],
                    $geraet["durchgefuehrte_arbeiten"],
                    $geraet["preis"],
                    $geraet["bereits_bezahlt"],
                    $geraet["status_id"] ?? $angenommenStatusId,
                ]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function geraetestatus_aendern(PDO $pdo, int $auftragId, int $zuordnungId, int $statusId): void
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM status WHERE id = ?");
    $stmt->execute([$statusId]);
    if ((int) $stmt->fetchColumn() !== 1) {
        throw new EingabeException("Der ausgewählte Reparaturstatus ist ungültig.");
    }

    $stmt = $pdo->prepare(
        "UPDATE reparaturauftrag_geraete SET status_id = ? WHERE id = ? AND auftrag_id = ?"
    );
    $stmt->execute([$statusId, $zuordnungId, $auftragId]);
    if ($stmt->rowCount() !== 1) {
        $pruefung = $pdo->prepare(
            "SELECT COUNT(*) FROM reparaturauftrag_geraete WHERE id = ? AND auftrag_id = ? AND status_id = ?"
        );
        $pruefung->execute([$zuordnungId, $auftragId, $statusId]);
        if ((int) $pruefung->fetchColumn() !== 1) {
            throw new EingabeException("Das Gerät gehört nicht zu diesem Reparaturauftrag.");
        }
    }
}

function auftragsstatus_ermitteln(PDO $pdo, int $auftragId): string
{
    $stmt = $pdo->prepare(
        "SELECT CASE
                   WHEN COUNT(rag.id) = 0 THEN 'Ohne Gerät'
                   WHEN COUNT(DISTINCT rag.status_id) = 1 THEN MAX(s.bezeichnung)
                   ELSE 'Mehrere Status'
                END
         FROM reparaturauftraege ra
         LEFT JOIN reparaturauftrag_geraete rag ON rag.auftrag_id = ra.id
         LEFT JOIN status s ON s.id = rag.status_id
         WHERE ra.id = ?
         GROUP BY ra.id"
    );
    $stmt->execute([$auftragId]);
    $status = $stmt->fetchColumn();
    if ($status === false) {
        throw new EingabeException("Reparaturauftrag wurde nicht gefunden.");
    }

    return (string) $status;
}

function geraet_aus_auftrag_loeschen(PDO $pdo, int $auftragId, int $zuordnungId): void
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT geraet_id, bereits_bezahlt FROM reparaturauftrag_geraete
             WHERE id = ? AND auftrag_id = ? FOR UPDATE"
        );
        $stmt->execute([$zuordnungId, $auftragId]);
        $zuordnung = $stmt->fetch();
        if (!$zuordnung) {
            throw new EingabeException("Das Gerät gehört nicht zu diesem Reparaturauftrag oder wurde bereits gelöscht.");
        }
        $geraetId = (int) $zuordnung["geraet_id"];
        if (betrag_in_cent((string) $zuordnung["bereits_bezahlt"]) > 0) {
            throw new EingabeException("Das Gerät kann nicht gelöscht werden, solange ihm eine Zahlung zugeordnet ist. Setzen oder verteilen Sie die Gerätezahlung zuerst neu.");
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM rechnung_geraete WHERE geraet_id_snapshot = ?");
        $stmt->execute([$geraetId]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new EingabeException("Das Gerät ist bereits abgerechnet und kann aus Gründen der Nachvollziehbarkeit nicht gelöscht werden.");
        }

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM reparaturauftrag_geraete WHERE auftrag_id = ? FOR UPDATE"
        );
        $stmt->execute([$auftragId]);
        if ((int) $stmt->fetchColumn() <= 1) {
            throw new LetztesGeraetException(
                "Das letzte Gerät kann nicht einzeln gelöscht werden. Fügen Sie zuerst ein neues Gerät hinzu oder löschen Sie den gesamten Auftrag."
            );
        }

        $stmt = $pdo->prepare(
            "SELECT ra.bereits_bezahlt,
                    COALESCE(SUM(CASE WHEN rag.id <> ? THEN rag.preis ELSE 0 END), 0) AS neuer_gesamtbetrag
             FROM reparaturauftraege ra
             INNER JOIN reparaturauftrag_geraete rag ON rag.auftrag_id = ra.id
             WHERE ra.id = ?
             GROUP BY ra.id, ra.bereits_bezahlt
             FOR UPDATE"
        );
        $stmt->execute([$zuordnungId, $auftragId]);
        $zahlung = $stmt->fetch();
        if (!$zahlung || betrag_in_cent((string) $zahlung["bereits_bezahlt"]) > betrag_in_cent((string) $zahlung["neuer_gesamtbetrag"])) {
            throw new EingabeException(
                "Das Gerät kann nicht gelöscht werden, weil der bereits bezahlte Betrag danach den neuen Gesamtbetrag überschreiten würde. Passen Sie zuerst die Zahlung an."
            );
        }

        $stmt = $pdo->prepare("DELETE FROM reparaturauftrag_geraete WHERE id = ? AND auftrag_id = ?");
        $stmt->execute([$zuordnungId, $auftragId]);

        $stmt = $pdo->prepare(
            "DELETE FROM geraete
             WHERE id = ? AND NOT EXISTS (
                 SELECT 1 FROM reparaturauftrag_geraete WHERE geraet_id = ?
             )"
        );
        $stmt->execute([$geraetId, $geraetId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function geraet_als_abgeholt_markieren(PDO $pdo, int $auftragId, int $zuordnungId, ?string $empfaenger): void
{
    $empfaenger = trim((string) $empfaenger);
    if (mb_strlen($empfaenger) > 150) {
        throw new EingabeException("Der Name des Empfängers darf höchstens 150 Zeichen lang sein.");
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT abggeholt.id
             FROM reparaturauftrag_geraete abggeholt
             WHERE abggeholt.id = ? AND abggeholt.auftrag_id = ? FOR UPDATE"
        );
        $stmt->execute([$zuordnungId, $auftragId]);
        if ($stmt->fetchColumn() === false) {
            throw new EingabeException("Das Gerät gehört nicht zu diesem Reparaturauftrag.");
        }
        $stmt = $pdo->prepare(
            "UPDATE reparaturauftrag_geraete
             SET abgeholt_von = CASE WHEN abgeholt_am IS NULL THEN ? ELSE abgeholt_von END,
                 abgeholt_am = COALESCE(abgeholt_am, NOW())
             WHERE id = ? AND auftrag_id = ?"
        );
        $stmt->execute([$empfaenger === "" ? null : $empfaenger, $zuordnungId, $auftragId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function auftrag_loeschen(PDO $pdo, int $auftragId): void
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT kunde_id FROM reparaturauftraege WHERE id = ? FOR UPDATE");
        $stmt->execute([$auftragId]);
        $kundeId = $stmt->fetchColumn();
        if ($kundeId === false) {
            throw new EingabeException("Der Reparaturauftrag wurde nicht gefunden oder bereits gelöscht.");
        }

        $stmt = $pdo->prepare(
            "SELECT geraet_id FROM reparaturauftrag_geraete WHERE auftrag_id = ? FOR UPDATE"
        );
        $stmt->execute([$auftragId]);
        $geraetIds = array_map("intval", $stmt->fetchAll(PDO::FETCH_COLUMN));

        $stmt = $pdo->prepare("DELETE FROM reparaturauftraege WHERE id = ?");
        $stmt->execute([$auftragId]);

        if ($geraetIds !== []) {
            $platzhalter = implode(",", array_fill(0, count($geraetIds), "?"));
            $stmt = $pdo->prepare(
                "DELETE FROM geraete
                 WHERE id IN ($platzhalter)
                   AND NOT EXISTS (
                       SELECT 1 FROM reparaturauftrag_geraete rag WHERE rag.geraet_id = geraete.id
                   )"
            );
            $stmt->execute($geraetIds);
        }

        $stmt = $pdo->prepare(
            "DELETE FROM kunden
             WHERE id = ?
               AND NOT EXISTS (SELECT 1 FROM reparaturauftraege WHERE kunde_id = ?)
               AND NOT EXISTS (SELECT 1 FROM geraete WHERE kunde_id = ?)"
        );
        $stmt->execute([(int) $kundeId, (int) $kundeId, (int) $kundeId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
