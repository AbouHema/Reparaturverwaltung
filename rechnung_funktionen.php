<?php

declare(strict_types=1);

require_once __DIR__ . "/funktionen.php";

function geschaeftsdaten_laden(PDO $pdo): array
{
    $daten = $pdo->query("SELECT * FROM geschaeftskonfiguration WHERE id = 1")->fetch();
    if (!$daten) throw new RuntimeException("Die zentrale Geschäftskonfiguration ist nicht vorhanden.");
    return $daten;
}

function fehlende_rechnungsangaben(array $daten): array
{
    $pflichtfelder = ["geschaeftsname" => "Geschäftsname", "inhaber" => "Inhaber / Unternehmensname",
        "strasse" => "Straße und Hausnummer", "plz" => "Postleitzahl", "ort" => "Ort"];
    $fehlend = [];
    foreach ($pflichtfelder as $feld => $bezeichnung) {
        if (trim((string) ($daten[$feld] ?? "")) === "") $fehlend[] = $bezeichnung;
    }
    if (trim((string) ($daten["steuernummer"] ?? "")) === "" && trim((string) ($daten["ust_id"] ?? "")) === "") {
        $fehlend[] = "Steuernummer oder Umsatzsteuer-ID";
    }
    return $fehlend;
}

function auftrag_fuer_druck_laden(PDO $pdo, int $auftragId): array
{
    $stmt = $pdo->prepare("SELECT ra.id, ra.erstellt_am, ra.bereits_bezahlt, ra.nicht_zugeordnet_bezahlt,
        k.name, k.firmenname, k.telefon, k.email, k.strasse, k.plz, k.ort, k.land FROM reparaturauftraege ra
        INNER JOIN kunden k ON k.id=ra.kunde_id WHERE ra.id=?");
    $stmt->execute([$auftragId]);
    $auftrag = $stmt->fetch();
    if (!$auftrag) throw new EingabeException("Reparaturauftrag wurde nicht gefunden.");
    $stmt = $pdo->prepare("SELECT rag.id AS zuordnung_id, rag.fehlerbeschreibung, rag.durchgefuehrte_arbeiten,
        rag.preis, rag.bereits_bezahlt, rag.abgeholt_am, rag.abgeholt_von, g.id AS geraet_id,
        g.geraetetyp, g.hersteller, g.modell, g.seriennummer, s.bezeichnung AS status,
        r.id AS rechnung_id, r.rechnungsnummer FROM reparaturauftrag_geraete rag
        INNER JOIN geraete g ON g.id=rag.geraet_id INNER JOIN status s ON s.id=rag.status_id
        LEFT JOIN rechnung_geraete rg ON rg.geraet_id_snapshot=g.id AND rg.storniert_am IS NULL LEFT JOIN rechnungen r ON r.id=rg.rechnung_id
        WHERE rag.auftrag_id=? ORDER BY rag.id");
    $stmt->execute([$auftragId]);
    $geraete = $stmt->fetchAll();
    if ($geraete === []) throw new EingabeException("Der Reparaturauftrag enthält kein Gerät.");
    $gesamtCent = $bezahltCent = 0;
    foreach ($geraete as &$geraet) {
        $preis = betrag_normalisieren($geraet["preis"], "Preis", true);
        $bezahlt = betrag_normalisieren($geraet["bereits_bezahlt"], "Gerätezahlung", true);
        if (betrag_in_cent($bezahlt) > betrag_in_cent($preis)) throw new EingabeException("Eine Gerätezahlung überschreitet den Gerätepreis.");
        $geraet["referenz"] = geraete_referenz($auftragId, (int) $geraet["geraet_id"]);
        $geraet["restbetrag"] = cent_als_betrag(betrag_in_cent($preis) - betrag_in_cent($bezahlt));
        $gesamtCent += betrag_in_cent($preis);
        $bezahltCent += betrag_in_cent($bezahlt);
    }
    unset($geraet);
    $bezahltCent += betrag_in_cent(betrag_normalisieren($auftrag["nicht_zugeordnet_bezahlt"], "Nicht zugeordnete Zahlung", true));
    if ($bezahltCent > $gesamtCent) throw new EingabeException("Die Zahlungen überschreiten den Gesamtbetrag des Auftrags.");
    $auftrag["gesamtbetrag"] = cent_als_betrag($gesamtCent);
    $auftrag["bereits_bezahlt"] = cent_als_betrag($bezahltCent);
    $auftrag["restbetrag"] = cent_als_betrag($gesamtCent - $bezahltCent);
    $auftrag["geraete"] = $geraete;
    return $auftrag;
}

function geraete_auswahl_ids(mixed $eingabe): array
{
    if ($eingabe === null || $eingabe === "") return [];
    if (!is_array($eingabe)) $eingabe = [$eingabe];
    $ids = [];
    foreach ($eingabe as $wert) $ids[] = positive_id($wert, "Die Geräteauswahl");
    return array_values(array_unique($ids));
}

function ausgewaehlte_geraete(array $auftrag, array $zuordnungIds, bool $einzelnesAutomatisch = true): array
{
    if ($zuordnungIds === [] && $einzelnesAutomatisch && count($auftrag["geraete"]) === 1) return [$auftrag["geraete"][0]];
    if ($zuordnungIds === []) throw new EingabeException("Wählen Sie mindestens ein Gerät aus.");
    $nachId = [];
    foreach ($auftrag["geraete"] as $geraet) $nachId[(int) $geraet["zuordnung_id"]] = $geraet;
    $auswahl = [];
    foreach ($zuordnungIds as $id) {
        if (!isset($nachId[$id])) throw new EingabeException("Ein ausgewähltes Gerät gehört nicht zu diesem Reparaturauftrag.");
        $auswahl[] = $nachId[$id];
    }
    return $auswahl;
}

function rechnungsbetraege_berechnen(array $geraete): array
{
    $positionen = []; $nettoGesamt = $steuerGesamt = $bruttoGesamt = 0;
    foreach (array_values($geraete) as $index => $geraet) {
        $brutto = betrag_in_cent(betrag_normalisieren($geraet["preis"], "Preis", true));
        $netto = intdiv(($brutto * 100) + 59, 119); $steuer = $brutto - $netto;
        $positionen[] = ["positionsnummer" => $index + 1, "geraet" => $geraet,
            "brutto_cent" => $brutto, "netto_cent" => $netto, "steuer_cent" => $steuer];
        $bruttoGesamt += $brutto; $nettoGesamt += $netto; $steuerGesamt += $steuer;
    }
    return ["positionen" => $positionen, "brutto_cent" => $bruttoGesamt,
        "netto_cent" => $nettoGesamt, "steuer_cent" => $steuerGesamt];
}

function rechnung_nach_auftrag_laden(PDO $pdo, int $auftragId): ?array
{
    $stmt = $pdo->prepare("SELECT id FROM rechnungen WHERE auftrag_id=? ORDER BY id LIMIT 1");
    $stmt->execute([$auftragId]); $id = $stmt->fetchColumn();
    return $id === false ? null : rechnung_laden($pdo, (int) $id);
}

function rechnung_laden(PDO $pdo, int $rechnungId): array
{
    $stmt = $pdo->prepare("SELECT * FROM rechnungen WHERE id=?"); $stmt->execute([$rechnungId]);
    $rechnung = $stmt->fetch();
    if (!$rechnung) throw new EingabeException("Rechnung wurde nicht gefunden.");
    $stmt = $pdo->prepare("SELECT * FROM rechnungspositionen WHERE rechnung_id=? ORDER BY positionsnummer");
    $stmt->execute([$rechnungId]); $rechnung["positionen"] = $stmt->fetchAll();
    return $rechnung;
}

function rechnungen_fuer_geraete_laden(PDO $pdo, array $geraetIds): array
{
    if ($geraetIds === []) return [];
    $platzhalter = implode(",", array_fill(0, count($geraetIds), "?"));
    $stmt = $pdo->prepare("SELECT rg.geraet_id_snapshot,r.id,r.rechnungsnummer FROM rechnung_geraete rg
        INNER JOIN rechnungen r ON r.id=rg.rechnung_id WHERE rg.storniert_am IS NULL AND rg.geraet_id_snapshot IN ($platzhalter)");
    $stmt->execute($geraetIds); $resultat = [];
    foreach ($stmt->fetchAll() as $zeile) $resultat[(int) $zeile["geraet_id_snapshot"]] = $zeile;
    return $resultat;
}

function naechste_rechnungsnummer(PDO $pdo): string
{
    $jahr = (int) date("Y");
    $stmt = $pdo->prepare("INSERT INTO rechnungsnummern (jahr,letzter_wert) VALUES (?,0)
        ON DUPLICATE KEY UPDATE letzter_wert=letzter_wert"); $stmt->execute([$jahr]);
    $stmt = $pdo->prepare("SELECT letzter_wert FROM rechnungsnummern WHERE jahr=? FOR UPDATE");
    $stmt->execute([$jahr]); $wert = ((int) $stmt->fetchColumn()) + 1;
    $stmt = $pdo->prepare("UPDATE rechnungsnummern SET letzter_wert=? WHERE jahr=?"); $stmt->execute([$wert,$jahr]);
    return sprintf("%04d-%06d", $jahr, $wert);
}

function zahlungssnapshot_fuer_geraete(PDO $pdo, array $geraetIds): array
{
    if ($geraetIds === []) return ["datum" => null, "art" => null, "notiz" => null];
    $platzhalter = implode(",", array_fill(0, count($geraetIds), "?"));
    $stmt = $pdo->prepare("SELECT zahlungsdatum,zahlungsart,notiz FROM geraete_zahlungen z
        WHERE z.vorgang='zahlung' AND z.geraet_id_snapshot IN ($platzhalter)
          AND NOT EXISTS (SELECT 1 FROM geraete_zahlungen s WHERE s.bezugszahlung_id=z.id AND s.vorgang='storno')
        ORDER BY z.zahlungsdatum,z.id");
    $stmt->execute($geraetIds); $zeilen = $stmt->fetchAll();
    return [
        "datum" => $zeilen === [] ? null : implode(", ", array_unique(array_column($zeilen, "zahlungsdatum"))),
        "art" => $zeilen === [] ? null : implode(", ", array_unique(array_column($zeilen, "zahlungsart"))),
        "notiz" => $zeilen === [] ? null : (implode(" | ", array_filter(array_column($zeilen, "notiz"))) ?: null),
    ];
}

function eine_rechnung_speichern(PDO $pdo, array $auftrag, array $geraete, array $konfiguration, string $art): int
{
    $summen = rechnungsbetraege_berechnen($geraete); $bezahltCent = 0;
    foreach ($geraete as $geraet) $bezahltCent += betrag_in_cent((string) $geraet["bereits_bezahlt"]);
    if ($bezahltCent > $summen["brutto_cent"]) throw new EingabeException("Die Gerätezahlungen überschreiten den Rechnungsbetrag.");
    $datum = date("Y-m-d"); $nummer = naechste_rechnungsnummer($pdo);
    $zahlungsSnapshot = zahlungssnapshot_fuer_geraete($pdo, array_map("intval", array_column($geraete, "geraet_id")));
    $stmt = $pdo->prepare("INSERT INTO rechnungen (auftrag_id,auftragsnummer_snapshot,rechnungsnummer,rechnungsart,
        rechnungsdatum,leistungsdatum,steuersatz,netto,steuerbetrag,brutto,bereits_bezahlt,restbetrag,zahlungsstatus,
        zahlungsdatum_snapshot,zahlungsart_snapshot,zahlungsnotiz_snapshot,
        kunde_name,kunde_firmenname,kunde_telefon,kunde_email,kunde_strasse,kunde_plz,kunde_ort,kunde_land,geschaeftsname,inhaber,strasse,plz,ort,
        geschaeft_telefon,geschaeft_email,steuernummer,ust_id,bankverbindung,zahlungsziel,rechnungshinweise)
        VALUES (:auftrag,:auftrag_snapshot,:nummer,:art,:datum,:leistung,19.00,:netto,:steuer,:brutto,:bezahlt,0.00,'Bezahlt',
        :zahlungsdatum,:zahlungsart,:zahlungsnotiz,:kunde_name,:kunde_firma,:kunde_telefon,:kunde_email,:kunde_strasse,:kunde_plz,:kunde_ort,:kunde_land,
        :geschaeftsname,:inhaber,:strasse,:plz,:ort,:geschaeft_telefon,:geschaeft_email,:steuernummer,:ust_id,:bank,:ziel,:hinweise)");
    $stmt->execute(["auftrag"=>$auftrag["id"],"auftrag_snapshot"=>$auftrag["id"],"nummer"=>$nummer,"art"=>$art,"datum"=>$datum,"leistung"=>$datum,
        "netto"=>cent_als_betrag($summen["netto_cent"]),"steuer"=>cent_als_betrag($summen["steuer_cent"]),"brutto"=>cent_als_betrag($summen["brutto_cent"]),"bezahlt"=>cent_als_betrag($bezahltCent),
        "zahlungsdatum"=>$zahlungsSnapshot["datum"],"zahlungsart"=>$zahlungsSnapshot["art"],"zahlungsnotiz"=>$zahlungsSnapshot["notiz"],
        "kunde_name"=>$auftrag["name"],"kunde_firma"=>$auftrag["firmenname"],"kunde_telefon"=>$auftrag["telefon"],"kunde_email"=>$auftrag["email"],
        "kunde_strasse"=>$auftrag["strasse"],"kunde_plz"=>$auftrag["plz"],"kunde_ort"=>$auftrag["ort"],"kunde_land"=>$auftrag["land"],
        "geschaeftsname"=>$konfiguration["geschaeftsname"],"inhaber"=>$konfiguration["inhaber"],"strasse"=>$konfiguration["strasse"],"plz"=>$konfiguration["plz"],"ort"=>$konfiguration["ort"],
        "geschaeft_telefon"=>$konfiguration["telefon"],"geschaeft_email"=>$konfiguration["email"],"steuernummer"=>$konfiguration["steuernummer"],"ust_id"=>$konfiguration["ust_id"],
        "bank"=>$konfiguration["bankverbindung"],"ziel"=>$konfiguration["zahlungsziel"],"hinweise"=>$konfiguration["rechnungshinweise"]]);
    $rechnungId = (int) $pdo->lastInsertId();
    $position = $pdo->prepare("INSERT INTO rechnungspositionen (rechnung_id,positionsnummer,geraet_id_snapshot,
        geraetetyp,hersteller,modell,seriennummer,leistungsbeschreibung,brutto,netto,steuerbetrag) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $mapping = $pdo->prepare("INSERT INTO rechnung_geraete (rechnung_id,auftrag_id_snapshot,geraet_id_snapshot) VALUES (?,?,?)");
    foreach ($summen["positionen"] as $pos) {
        $g = $pos["geraet"];
        $position->execute([$rechnungId,$pos["positionsnummer"],$g["geraet_id"],$g["geraetetyp"],$g["hersteller"],$g["modell"],$g["seriennummer"],
            trim((string)($g["durchgefuehrte_arbeiten"]??""))===""?null:$g["durchgefuehrte_arbeiten"],
            cent_als_betrag($pos["brutto_cent"]),cent_als_betrag($pos["netto_cent"]),cent_als_betrag($pos["steuer_cent"])]);
        $mapping->execute([$rechnungId,$auftrag["id"],$g["geraet_id"]]);
    }
    audit_protokollieren($pdo,"rechnung_erstellt","rechnung",$rechnungId,["rechnungsnummer"=>$nummer,"auftrag_id"=>(int)$auftrag["id"],"geraete"=>array_map("intval",array_column($geraete,"geraet_id"))]);
    return $rechnungId;
}

function rechnungen_endgueltig_erstellen(PDO $pdo, int $auftragId, array $zuordnungIds, string $modus): array
{
    if (!in_array($modus,["gemeinsam","einzeln"],true)) throw new EingabeException("Die Rechnungsart ist ungültig.");
    $zuordnungIds = array_values(array_unique(array_map("intval",$zuordnungIds)));
    if ($zuordnungIds===[]) throw new EingabeException("Wählen Sie mindestens ein Gerät aus.");
    $pdo->beginTransaction();
    try {
        $konfiguration=geschaeftsdaten_laden($pdo); $fehlend=fehlende_rechnungsangaben($konfiguration);
        if ($fehlend!==[]) throw new EingabeException("Die endgültige Rechnung kann erst erstellt werden, wenn folgende Geschäftsdaten gepflegt sind: ".implode(", ",$fehlend).".");
        $stmt=$pdo->prepare("SELECT ra.id,ra.erstellt_am,k.name,k.firmenname,k.telefon,k.email,k.strasse,k.plz,k.ort,k.land FROM reparaturauftraege ra
            INNER JOIN kunden k ON k.id=ra.kunde_id WHERE ra.id=? FOR UPDATE"); $stmt->execute([$auftragId]);
        $auftrag=$stmt->fetch(); if(!$auftrag) throw new EingabeException("Reparaturauftrag wurde nicht gefunden.");
        $platzhalter=implode(",",array_fill(0,count($zuordnungIds),"?"));
        $stmt=$pdo->prepare("SELECT rag.id AS zuordnung_id,rag.fehlerbeschreibung,rag.durchgefuehrte_arbeiten,
            rag.preis,rag.bereits_bezahlt,g.id AS geraet_id,g.geraetetyp,g.hersteller,g.modell,g.seriennummer
            FROM reparaturauftrag_geraete rag INNER JOIN geraete g ON g.id=rag.geraet_id
            WHERE rag.auftrag_id=? AND rag.id IN ($platzhalter) ORDER BY rag.id FOR UPDATE");
        $stmt->execute(array_merge([$auftragId],$zuordnungIds)); $geraete=$stmt->fetchAll();
        if(count($geraete)!==count($zuordnungIds)) throw new EingabeException("Ein ausgewähltes Gerät gehört nicht zu diesem Reparaturauftrag.");
        foreach($geraete as $geraet){
            $offen=betrag_in_cent((string)$geraet["preis"])-betrag_in_cent((string)$geraet["bereits_bezahlt"]);
            if($offen>0)throw new EingabeException("Für dieses Gerät ist noch ein Betrag von ".betrag_anzeigen(cent_als_betrag($offen))." offen. Erfassen Sie zuerst die vollständige Zahlung.");
        }
        $vorhanden=rechnungen_fuer_geraete_laden($pdo,array_map("intval",array_column($geraete,"geraet_id")));
        if($vorhanden!==[]){$erste=reset($vorhanden);throw new EingabeException("Mindestens ein ausgewähltes Gerät wurde bereits mit Rechnung ".$erste["rechnungsnummer"]." abgerechnet.");}
        $gruppen=$modus==="einzeln"?array_map(static fn(array $g):array=>[$g],$geraete):[$geraete]; $ids=[];
        foreach($gruppen as $gruppe)$ids[]=eine_rechnung_speichern($pdo,$auftrag,$gruppe,$konfiguration,$modus);
        $pdo->commit(); return $ids;
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();
        if($e instanceof PDOException && (int)($e->errorInfo[1]??0)===1062)throw new EingabeException("Mindestens ein Gerät wurde zwischenzeitlich bereits abgerechnet.");
        throw $e;}
}

function rechnung_endgueltig_erstellen(PDO $pdo, int $auftragId): int
{
    $auftrag=auftrag_fuer_druck_laden($pdo,$auftragId);
    $ids=array_map(static fn(array $g):int=>(int)$g["zuordnung_id"],array_filter($auftrag["geraete"],static fn(array $g):bool=>empty($g["rechnung_id"])));
    if($ids===[]){$rechnung=rechnung_nach_auftrag_laden($pdo,$auftragId);if($rechnung!==null)return(int)$rechnung["id"];throw new EingabeException("Alle Geräte dieses Auftrags sind bereits abgerechnet.");}
    return rechnungen_endgueltig_erstellen($pdo,$auftragId,$ids,"gemeinsam")[0];
}
