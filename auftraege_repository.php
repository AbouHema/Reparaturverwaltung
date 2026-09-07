<?php

declare(strict_types=1);

function suchbegriff_normalisieren(mixed $suche): string
{
    return trim((string) $suche);
}

function like_muster(string $suche): string
{
    $escaped = str_replace(["=", "%", "_"], ["==", "=%", "=_"], $suche);
    return "%" . $escaped . "%";
}

function telefon_suchwert(string $suche): string
{
    return preg_replace('/[^0-9]/', '', $suche) ?? "";
}

function telefon_suchvarianten(string $suche): array
{
    $ziffern = telefon_suchwert($suche);
    if ($ziffern === "") {
        return [];
    }

    $varianten = [$ziffern];
    if (str_starts_with($ziffern, "0049") && strlen($ziffern) > 4) {
        $varianten[] = "49" . substr($ziffern, 4);
        $varianten[] = "0" . substr($ziffern, 4);
    } elseif (str_starts_with($ziffern, "49") && strlen($ziffern) > 2) {
        $varianten[] = "0049" . substr($ziffern, 2);
        $varianten[] = "0" . substr($ziffern, 2);
    } elseif (str_starts_with($ziffern, "0") && strlen($ziffern) > 1) {
        $varianten[] = "49" . substr($ziffern, 1);
        $varianten[] = "0049" . substr($ziffern, 1);
    }

    return array_values(array_unique($varianten));
}

function suchkategorien(): array
{
    return [
        "alle" => "Alle Kategorien",
        "kundenname" => "Kundenname",
        "firmenname" => "Firmenname",
        "telefon" => "Telefonnummer",
        "email" => "E-Mail-Adresse",
        "adresse" => "Adresse",
        "auftragsnummer" => "Auftragsnummer",
        "geraetetyp" => "Gerätetyp",
        "hersteller_modell" => "Hersteller / Modell",
        "seriennummer" => "Seriennummer",
        "status" => "Status",
        "fehlerbeschreibung" => "Fehlerbeschreibung",
    ];
}

function suchkategorie_normalisieren(mixed $kategorie): string
{
    $kategorie = trim((string) $kategorie);
    return array_key_exists($kategorie, suchkategorien()) ? $kategorie : "alle";
}

function vorhandene_optionale_suchspalten(PDO $pdo, string $tabelle, array $kandidaten): array
{
    static $cache = [];
    $cacheKey = $tabelle . ":" . implode(",", $kandidaten);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $stmt = $pdo->prepare(
        "SELECT COLUMN_NAME
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->execute([$tabelle]);
    $vorhanden = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

    return $cache[$cacheKey] = array_values(array_filter(
        $kandidaten,
        static fn(string $spalte): bool => isset($vorhanden[$spalte])
    ));
}

function auftraege_suchen(PDO $pdo, mixed $eingabe, mixed $kategorieEingabe = "alle"): array
{
    $suche = suchbegriff_normalisieren($eingabe);
    $kategorie = suchkategorie_normalisieren($kategorieEingabe);
    $bedingungen = [];
    $parameter = [];

    if ($suche !== "") {
        $muster = like_muster(mb_strtolower($suche, "UTF-8"));
        $alle = $kategorie === "alle";

        if ($alle || $kategorie === "auftragsnummer") {
            $bedingungen[] = "CAST(ra.id AS CHAR) LIKE ? ESCAPE '='";
            $parameter[] = like_muster(ltrim($suche, "#"));
        }
        if ($alle || $kategorie === "kundenname") {
            $bedingungen[] = "LOWER(k.name) LIKE ? ESCAPE '='";
            $parameter[] = $muster;
        }
        if ($alle || $kategorie === "telefon") {
            $bedingungen[] = "LOWER(k.telefon) LIKE ? ESCAPE '='";
            $parameter[] = $muster;
            if (!str_starts_with($suche, "#")) {
                foreach (telefon_suchvarianten($suche) as $telefon) {
                    $bedingungen[] = "
                        REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(k.telefon,
                            ' ', ''), '-', ''), '/', ''), '(', ''), ')', ''), '+', '') LIKE ? ESCAPE '='";
                    $parameter[] = like_muster($telefon);
                }
            }
        }
        if ($alle || $kategorie === "firmenname") {
            foreach (vorhandene_optionale_suchspalten($pdo, "kunden", ["firma", "firmenname"]) as $spalte) {
                $bedingungen[] = "LOWER(k.`$spalte`) LIKE ? ESCAPE '='";
                $parameter[] = $muster;
            }
        }
        if ($alle || $kategorie === "email") {
            foreach (vorhandene_optionale_suchspalten($pdo, "kunden", ["email", "email_adresse"]) as $spalte) {
                $bedingungen[] = "LOWER(k.`$spalte`) LIKE ? ESCAPE '='";
                $parameter[] = $muster;
            }
        }
        if ($alle || $kategorie === "adresse") {
            foreach (["strasse", "plz", "ort", "land"] as $spalte) {
                $bedingungen[] = "LOWER(COALESCE(k.`$spalte`, '')) LIKE ? ESCAPE '='";
                $parameter[] = $muster;
            }
        }

        $geraeteBedingungen = [];
        $geraeteParameter = [];
        if ($alle || $kategorie === "geraetetyp") {
            $geraeteBedingungen[] = "LOWER(suche_g.geraetetyp) LIKE ? ESCAPE '='";
            $geraeteParameter[] = $muster;
        }
        if ($alle || $kategorie === "hersteller_modell") {
            $geraeteBedingungen[] = "LOWER(suche_g.modell) LIKE ? ESCAPE '='";
            $geraeteParameter[] = $muster;
            foreach (vorhandene_optionale_suchspalten($pdo, "geraete", ["hersteller"]) as $spalte) {
                $geraeteBedingungen[] = "LOWER(suche_g.`$spalte`) LIKE ? ESCAPE '='";
                $geraeteParameter[] = $muster;
            }
        }
        if ($alle || $kategorie === "seriennummer") {
            foreach (vorhandene_optionale_suchspalten($pdo, "geraete", ["seriennummer", "serial_number"]) as $spalte) {
                $geraeteBedingungen[] = "LOWER(suche_g.`$spalte`) LIKE ? ESCAPE '='";
                $geraeteParameter[] = $muster;
            }
        }
        if ($alle || $kategorie === "status") {
            $geraeteBedingungen[] = "LOWER(suche_s.bezeichnung) LIKE ? ESCAPE '='";
            $geraeteParameter[] = $muster;
        }
        if ($alle || $kategorie === "fehlerbeschreibung") {
            $geraeteBedingungen[] = "LOWER(suche_rag.fehlerbeschreibung) LIKE ? ESCAPE '='";
            $geraeteParameter[] = $muster;
        }

        if ($geraeteBedingungen !== []) {
            $bedingungen[] = "EXISTS (
                SELECT 1
                FROM reparaturauftrag_geraete suche_rag
                INNER JOIN geraete suche_g ON suche_g.id = suche_rag.geraet_id
                INNER JOIN status suche_s ON suche_s.id = suche_rag.status_id
                WHERE suche_rag.auftrag_id = ra.id
                  AND (" . implode(" OR ", $geraeteBedingungen) . ")
            )";
            array_push($parameter, ...$geraeteParameter);
        }
    }

    $where = $bedingungen === []
        ? ($suche === "" ? "" : "WHERE 1 = 0")
        : "WHERE " . implode(" OR ", $bedingungen);
    $sql = "
        SELECT ra.id, ra.erstellt_am, ra.bereits_bezahlt, ra.nicht_zugeordnet_bezahlt,
               k.name, k.firmenname, k.telefon, k.email, k.strasse, k.plz, k.ort, k.land,
               COUNT(rag.id) AS anzahl_geraete,
               COALESCE(SUM(CASE WHEN rag.preis >= 0 THEN rag.preis ELSE 0 END), 0) AS gesamtpreis,
               GREATEST(
                   COALESCE(SUM(CASE WHEN rag.preis >= 0 THEN rag.preis ELSE 0 END), 0) - ra.bereits_bezahlt,
                   0
               ) AS restbetrag,
               EXISTS(SELECT 1 FROM rechnungen r WHERE r.auftrag_id = ra.id) AS hat_rechnung,
               CASE
                 WHEN COUNT(rag.id) = 0 THEN 'Ohne Gerät'
                 WHEN COUNT(DISTINCT rag.status_id) = 1 THEN MAX(s.bezeichnung)
                 ELSE 'Mehrere Status'
               END AS gesamtstatus
        FROM reparaturauftraege ra
        INNER JOIN kunden k ON k.id = ra.kunde_id
        LEFT JOIN reparaturauftrag_geraete rag ON rag.auftrag_id = ra.id
        LEFT JOIN status s ON s.id = rag.status_id
        $where
        GROUP BY ra.id, ra.erstellt_am, ra.bereits_bezahlt, ra.nicht_zugeordnet_bezahlt, k.name, k.firmenname, k.telefon, k.email, k.strasse, k.plz, k.ort, k.land
        ORDER BY ra.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($parameter);
    $auftraege = $stmt->fetchAll();

    $geraeteNachAuftrag = [];
    if ($auftraege !== []) {
        $auftragIds = array_map(static fn(array $auftrag): int => (int) $auftrag["id"], $auftraege);
        $platzhalter = implode(",", array_fill(0, count($auftragIds), "?"));
        $stmt = $pdo->prepare(
            "SELECT rag.id AS zuordnung_id, rag.auftrag_id, rag.fehlerbeschreibung,
                    rag.durchgefuehrte_arbeiten, rag.preis, rag.bereits_bezahlt,
                    GREATEST(rag.preis - rag.bereits_bezahlt, 0) AS restbetrag,
                    rag.abgeholt_am, rag.abgeholt_von,
                    g.id, g.geraetetyp, g.hersteller, g.modell, g.seriennummer,
                    s.id AS status_id, s.bezeichnung AS status,
                    r.id AS rechnung_id, r.rechnungsnummer
             FROM reparaturauftrag_geraete rag
             INNER JOIN geraete g ON g.id = rag.geraet_id
             INNER JOIN status s ON s.id = rag.status_id
             LEFT JOIN rechnung_geraete rg ON rg.geraet_id_snapshot = g.id AND rg.storniert_am IS NULL
             LEFT JOIN rechnungen r ON r.id = rg.rechnung_id
             WHERE rag.auftrag_id IN ($platzhalter)
             ORDER BY rag.auftrag_id DESC, rag.id"
        );
        $stmt->execute($auftragIds);
        foreach ($stmt->fetchAll() as $geraet) {
            $geraeteNachAuftrag[(int) $geraet["auftrag_id"]][] = $geraet;
        }
    }

    return [
        "suche" => $suche,
        "kategorie" => $kategorie,
        "auftraege" => $auftraege,
        "geraeteNachAuftrag" => $geraeteNachAuftrag,
    ];
}
