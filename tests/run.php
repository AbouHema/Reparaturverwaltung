<?php

declare(strict_types=1);

require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../funktionen.php";
require_once __DIR__ . "/../auftrags_service.php";
require_once __DIR__ . "/../auftraege_repository.php";
require_once __DIR__ . "/../rechnung_funktionen.php";
require_once __DIR__ . "/../zahlungs_service.php";
require_once __DIR__ . "/../rechnungsarchiv_repository.php";

$tests = 0;

function pruefen(bool $bedingung, string $meldung): void
{
    global $tests;
    $tests++;
    if (!$bedingung) {
        throw new RuntimeException("Test fehlgeschlagen: " . $meldung);
    }
}

function wirft(callable $funktion, string $klasse): bool
{
    try {
        $funktion();
    } catch (Throwable $e) {
        return $e instanceof $klasse;
    }
    return false;
}

pruefen(wirft(fn() => betrag_normalisieren("-0.01"), EingabeException::class), "Negativer Betrag wird im Backend abgelehnt");
pruefen(betrag_normalisieren("0") === "0.00", "Betrag 0 wird akzeptiert");
pruefen(betrag_normalisieren("12,34") === "12.34", "Positiver Betrag wird akzeptiert");
pruefen(wirft(fn() => betrag_normalisieren("", "Preis", true), EingabeException::class), "Ein leerer Pflichtpreis wird im Backend abgelehnt");
pruefen(wirft(fn() => betrag_normalisieren("12,"), EingabeException::class), "Unvollständige Beträge werden abgelehnt");
pruefen(wirft(fn() => betrag_normalisieren("NaN"), EingabeException::class), "NaN wird abgelehnt");
pruefen(wirft(fn() => betrag_normalisieren("INF"), EingabeException::class), "Unendlich große Werte werden abgelehnt");
[$nurName, $leeresTelefon, $leereFirma, $leereEmail] = stammdaten_validieren("Nur Pflichtfelder", "", "", "");
pruefen($nurName === "Nur Pflichtfelder" && $leeresTelefon === null && $leereFirma === null && $leereEmail === null, "Optionale leere Kundendaten werden akzeptiert");
pruefen(wirft(fn() => stammdaten_validieren("Test", "", "", "ungueltig"), EingabeException::class), "Eine ausgefüllte ungültige E-Mail-Adresse wird abgelehnt");
pruefen(wirft(fn() => stammdaten_validieren("Test", "abc", "", ""), EingabeException::class), "Ungültige Zeichen in einer ausgefüllten Telefonnummer werden abgelehnt");
pruefen(stammdaten_validieren("Test", "+49 (30) 123-45/67", "", "")[1] === "+49 (30) 123-45/67", "Übliche Telefonnummernzeichen werden akzeptiert");
$adresseLeer = stammdaten_validieren("Test", "", "", "", "", "", "", "");
pruefen(array_slice($adresseLeer,4) === [null,null,null,null], "Ein Auftrag kann ohne Adresse validiert werden");
$adresseVoll = stammdaten_validieren("Test", "", "", "", "Teststraße 12", "10115", "Berlin", "Deutschland");
pruefen(array_slice($adresseVoll,4) === ["Teststraße 12","10115","Berlin","Deutschland"], "Strukturierte optionale Adresse wird validiert");

$index = file_get_contents(__DIR__ . "/../index.php");
$bearbeiten = file_get_contents(__DIR__ . "/../bearbeiten.php");
$appJs = file_get_contents(__DIR__ . "/../app.js");
$styles = file_get_contents(__DIR__ . "/../styles.css");
$auftraegeSeite = file_get_contents(__DIR__ . "/../auftraege.php");
$auftraegeListe = file_get_contents(__DIR__ . "/../auftraege_liste.php");
$repository = file_get_contents(__DIR__ . "/../auftraege_repository.php");
$rechnungSeite = file_get_contents(__DIR__ . "/../rechnung.php");
$abholscheinSeite = file_get_contents(__DIR__ . "/../abholschein.php");
$auswahlSeite = file_get_contents(__DIR__ . "/../dokument_auswahl.php");
$druckCss = file_get_contents(__DIR__ . "/../druck.css");
$loginSeite = file_get_contents(__DIR__ . "/../login.php");
$authService = file_get_contents(__DIR__ . "/../auth_service.php");
$archivSeite = file_get_contents(__DIR__ . "/../rechnungsarchiv.php");
$funktionenSeite = file_get_contents(__DIR__ . "/../funktionen.php");
pruefen(str_contains($authService,'mb_strlen($passwort) < 8') && !str_contains($authService,'mb_strlen($passwort) < 12'), "Backend verlangt mindestens acht Passwortzeichen");
pruefen(substr_count(file_get_contents(__DIR__."/../registrieren.php"),'minlength="8"')===2, "Registrierung verlangt im Frontend acht Passwortzeichen");
pruefen(str_contains($funktionenSeite, '__DIR__ . "|" . $serverPort') && str_contains($funktionenSeite, 'SESSION_SAVE_PATH'), "Lokale Instanzen trennen Cookie-Namen und Sitzungsspeicher nach Port");
pruefen(str_contains($appJs, 'dataset.nativeSubmitting') && str_contains($appJs, 'event.preventDefault()'), "Normale Formulare verhindern versehentliche Mehrfachübermittlung");
pruefen(is_file(__DIR__ . "/../favicon.svg") && str_contains(file_get_contents(__DIR__ . "/../registrieren.php"), 'href="favicon.svg"'), "Das statische Favicon verhindert einen irrtümlichen PHP-Sitzungsaufruf");
pruefen(substr_count($index, 'data-amount-input') >= 2 && str_contains($appJs, '["-", "+", "e", "E", "ArrowUp", "ArrowDown"]'), "Frontend verhindert negative Beträge und unbeabsichtigte Pfeiltastenänderungen");
pruefen(str_contains($appJs, 'addEventListener("wheel"') && str_contains($appJs, "passive: false"), "Betragsfelder werden nicht durch das Mausrad verändert");
pruefen(substr_count($index, 'data-device-price') >= 2 && substr_count($bearbeiten, 'data-device-price') >= 2, "Jeder Gerätepreis ist als Live-Pflichtbetrag markiert");
pruefen(str_contains($appJs, "zahlungsuebersichtAktualisieren") && str_contains($appJs, "data-remaining-amount"), "Gesamt-, Zahlungs- und Restbetrag werden live aktualisiert");
pruefen(str_contains($index, "Gerät hinzufügen") && str_contains($bearbeiten, "Gerät hinzufügen"), "Geräte können in beiden Formularen hinzugefügt werden");
pruefen(str_contains($bearbeiten, "Möchten Sie dieses Gerät wirklich aus dem Reparaturauftrag löschen? Diese Aktion kann nicht rückgängig gemacht werden."), "Geräte-Löschdialog enthält den geforderten Text");
pruefen(str_contains($bearbeiten, "Möchten Sie diesen Reparaturauftrag wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden."), "Auftrags-Löschdialog enthält den geforderten Text");
pruefen(!str_contains($styles, "input:invalid:not(:placeholder-shown)"), "Unberührte Pflichtfelder werden nicht über :invalid rot markiert");
pruefen(str_contains($styles, "input.is-invalid") && str_contains($appJs, "dataset.touched"), "Fehlerdarstellung wird erst nach Interaktion aktiviert");
pruefen(str_contains($appJs, "275") && str_contains($appJs, "AbortController") && str_contains($appJs, "searchSequence"), "Live-Suche nutzt Debounce und schützt vor veralteten Antworten");
pruefen(!str_contains($auftraegeSeite, ">Suchen</button>") && !str_contains($auftraegeSeite, ">Alle anzeigen</a>"), "Suchbutton und Alle-anzeigen-Schaltfläche wurden entfernt");
pruefen(str_contains($repository, '"email"') && str_contains($repository, '"hersteller"') && str_contains($repository, '"seriennummer"'), "Optionale vorhandene Suchfelder werden schemaabhängig berücksichtigt");
pruefen(!str_contains($index, "Reparaturstatus") && !str_contains($index, "data-remove-unsaved-device"), "Beim Anlegen sind Status und Gerät-entfernen-Schaltfläche vollständig ausgeblendet");
pruefen(str_contains($bearbeiten, "Reparaturstatus") && str_contains($bearbeiten, "Gerät entfernen"), "Beim Bearbeiten sind Status und Gerät-entfernen-Schaltfläche vorhanden");
pruefen(!str_contains($auftraegeListe, "Gesamtstatus") && str_contains($auftraegeListe, "Status:"), "Die Auftragsübersicht bezeichnet den abgeleiteten Status kompakt als Status");
pruefen(str_contains($repository, '"alle" => "Alle Kategorien"') && str_contains($auftraegeSeite, "data-search-category"), "Die Live-Suche bietet einen Kategorienfilter");
pruefen(str_contains($appJs, 'searchCategory?.addEventListener("change"') && str_contains($appJs, "URLSearchParams"), "Ein Kategorienwechsel aktualisiert die Live-Suche sofort");
pruefen(str_contains($appJs, "data-ajax-status") && str_contains($appJs, "data-ajax-delete-order"), "Statusänderung und Auftragslöschung erfolgen ohne Seitenneuladen");
pruefen(str_contains($appJs, "select.value = vorher"), "Ein fehlgeschlagener Statuswechsel stellt den vorherigen Status wieder her");
pruefen(str_contains($rechnungSeite, "ENTWURF") && str_contains($rechnungSeite, "einstellungen.php"), "Eine unvollständige Rechnung wird als Entwurf markiert und verweist auf die Konfiguration");
pruefen(str_contains($druckCss, "size: A4") && str_contains($druckCss, ".no-print") && str_contains($appJs, "window.print()"), "A4-Druckansichten öffnen den normalen Browserdruckdialog und blenden Bedienelemente aus");
pruefen(str_contains($abholscheinSeite, 'foreach($gruppe as') && str_contains($rechnungSeite, 'foreach($positionen as'), "Abholschein und Rechnung geben ausgewählte Geräte getrennt aus");
pruefen(str_contains($auftraegeSeite, "document-select-dialog") && str_contains($auftraegeListe, "data-document-select"), "Mehrgeräte-Aufträge öffnen die zentrale Dokumentauswahl");
pruefen(str_contains($auswahlSeite, "data-selection-count") && str_contains($auswahlSeite, "data-selection-total"), "Die Auswahl zeigt Anzahl und Gesamtbetrag live an");
pruefen(str_contains($auswahlSeite, "rechnungsnummer") && str_contains($auswahlSeite, "disabled"), "Bereits abgerechnete Geräte werden mit vorhandener Rechnung gesperrt angezeigt");
pruefen(str_contains($abholscheinSeite, "Das Drucken ändert den Abholstatus nicht") && str_contains($abholscheinSeite, "data-pickup-confirm"), "Drucken und explizite Abholbestätigung sind getrennt");
pruefen(str_contains($rechnungSeite, "geraete_referenz") && str_contains($abholscheinSeite, "referenz"), "Gerätereferenzen erscheinen auf Rechnungen und Abholscheinen");
pruefen(str_contains($druckCss, "break-before: page") && str_contains($abholscheinSeite, '$modus === "einzeln"'), "Getrennte Dokumente beginnen auf einer neuen A4-Seite");
pruefen(!str_contains($bearbeiten,"Noch nicht zugeordnete Zahlung")&&!str_contains($bearbeiten,"Ordnen Sie Zahlungen möglichst direkt"),"Das alte technische Zahlungsfeld erscheint nicht mehr im normalen Arbeitsablauf");
pruefen(str_contains($loginSeite,"password_verify")&&str_contains($authService,"password_hash"),"Passwörter werden ausschließlich sicher gehasht und geprüft");
pruefen(str_contains($loginSeite,"session_regenerate_id")&&str_contains($loginSeite,"anmeldung_fehlgeschlagen"),"Anmeldung erneuert die Sitzung und protokolliert Fehlversuche");
pruefen(str_contains($archivSeite,"CSV exportieren")&&str_contains($archivSeite,"Rechnungsarchiv"),"Rechnungsarchiv bietet Zeitraumfilter und CSV-Export");
pruefen(str_contains($styles, ".order-card:nth-of-type(even)") && str_contains($styles, "#eef7ff"), "Sichtbare Aufträge erhalten abwechselnd weiße und hellblaue Hintergründe");
pruefen(str_contains($index, 'name="firmenname"') && str_contains($index, 'name="email"'), "Optionale Kundendaten sind im Neuanlageformular vorhanden");
pruefen(str_contains($bearbeiten, 'name="firmenname"') && str_contains($bearbeiten, 'name="email"'), "Optionale Kundendaten sind im Bearbeitungsformular vorhanden");
pruefen(substr_count($index, '[hersteller]') >= 2 && substr_count($index, '[seriennummer]') >= 2, "Jedes neue Gerät besitzt Hersteller und Seriennummer");
pruefen(substr_count($bearbeiten, '[hersteller]') >= 2 && substr_count($bearbeiten, '[seriennummer]') >= 2, "Gerätedaten können vollständig bearbeitet werden");
pruefen(!preg_match('/<input[^>]+name="telefon"[^>]+required/', $index . $bearbeiten), "Die Telefonnummer ist in beiden Formularen optional");
pruefen(substr_count($index, '[modell]') === 2, "Das Modellfeld ist je Gerätekarte genau einmal vorhanden");
pruefen(str_contains($repository, '"hersteller_modell" => "Hersteller / Modell"'), "Der Suchfilter heißt Hersteller / Modell");
foreach ([
    "Jedes Gerät erhält eigene Fehler-, Preis- und Statusangaben.",
    "Ein Kunde, ein gemeinsamer Auftrag und beliebig viele Geräte mit eigenem Reparaturstatus.",
    "Kunden, Geräte, eigener Bearbeitungsstand und Kosten auf einen Blick.",
] as $entfernterText) {
    pruefen(!str_contains($index . $bearbeiten . $auftraegeSeite . $auftraegeListe, $entfernterText), "Beschreibungstext wurde entfernt: " . $entfernterText);
}

foreach (["firmenname", "email"] as $spalte) {
    pruefen((bool) $pdo->query("SHOW COLUMNS FROM kunden LIKE " . $pdo->quote($spalte))->fetch(), "Kundenspalte $spalte ist vorhanden");
}
foreach (["hersteller", "seriennummer"] as $spalte) {
    pruefen((bool) $pdo->query("SHOW COLUMNS FROM geraete LIKE " . $pdo->quote($spalte))->fetch(), "Gerätespalte $spalte ist vorhanden");
}
$telefonSpalte = $pdo->query("SHOW COLUMNS FROM kunden LIKE 'telefon'")->fetch();
pruefen(($telefonSpalte["Null"] ?? "NO") === "YES", "Die Telefonnummer ist in der Datenbank optional");
$preisSpalte = $pdo->query("SHOW COLUMNS FROM reparaturauftrag_geraete LIKE 'preis'")->fetch();
pruefen(($preisSpalte["Null"] ?? "YES") === "NO", "Der Gerätepreis ist auch in der Datenbank ein Pflichtfeld");
foreach ([
    ["reparaturauftraege", "bereits_bezahlt"],
    ["reparaturauftrag_geraete", "durchgefuehrte_arbeiten"],
    ["rechnungen", "auftragsnummer_snapshot"],
] as [$tabelle, $spalte]) {
    pruefen((bool) $pdo->query("SHOW COLUMNS FROM `$tabelle` LIKE " . $pdo->quote($spalte))->fetch(), "Schemaspalte $tabelle.$spalte ist vorhanden");
}
foreach ([
    ["reparaturauftraege", "nicht_zugeordnet_bezahlt"],
    ["reparaturauftrag_geraete", "bereits_bezahlt"],
    ["reparaturauftrag_geraete", "abgeholt_am"],
    ["reparaturauftrag_geraete", "abgeholt_von"],
    ["rechnungen", "rechnungsart"],
    ["rechnungen", "kunde_strasse"],
    ["rechnungen", "zahlungsdatum_snapshot"],
    ["rechnungen", "zahlungsart_snapshot"],
] as [$tabelle, $spalte]) {
    pruefen((bool) $pdo->query("SHOW COLUMNS FROM `$tabelle` LIKE " . $pdo->quote($spalte))->fetch(), "Neue Schemaspalte $tabelle.$spalte ist vorhanden");
}
pruefen((bool) $pdo->query("SHOW TABLES LIKE 'rechnung_geraete'")->fetch(), "Unveränderliche Rechnung-Gerät-Zuordnung ist vorhanden");
foreach(["benutzer","login_sperren","audit_protokoll","geraete_zahlungen"] as $tabelle)pruefen((bool)$pdo->query("SHOW TABLES LIKE ".$pdo->quote($tabelle))->fetch(),"Sicherheitstabelle $tabelle ist vorhanden");
foreach(["strasse","plz","ort","land"] as $spalte)pruefen((bool)$pdo->query("SHOW COLUMNS FROM kunden LIKE ".$pdo->quote($spalte))->fetch(),"Adressspalte kunden.$spalte ist vorhanden");
pruefen(wirft(fn() => geraete_validieren([["geraetetyp"=>"X","fehlerbeschreibung"=>"Y","preis"=>"10","bereits_bezahlt"=>"10.01"]], status_ids_laden($pdo)), EingabeException::class), "Eine Gerätezahlung über dem Gerätepreis wird im Backend abgelehnt");
pruefen(zahlungszuordnung_validieren([["preis"=>"10.00","bereits_bezahlt"=>"4.00"]], "1.00")["gesamt"] === "5.00", "Zugeordnete und nicht zugeordnete Zahlungen werden centgenau summiert");
foreach (["geschaeftskonfiguration", "rechnungsnummern", "rechnungen", "rechnungspositionen"] as $tabelle) {
    pruefen((bool) $pdo->query("SHOW TABLES LIKE " . $pdo->quote($tabelle))->fetch(), "Tabelle $tabelle ist vorhanden");
}

$steuerTest = rechnungsbetraege_berechnen([
    ["preis" => "119.00"],
    ["preis" => "10.00"],
]);
pruefen($steuerTest["brutto_cent"] === 12900, "Bruttosumme wird centgenau berechnet");
pruefen($steuerTest["netto_cent"] + $steuerTest["steuer_cent"] === $steuerTest["brutto_cent"], "Netto plus 19 Prozent Umsatzsteuer entspricht exakt dem Brutto");
pruefen($steuerTest["positionen"][0]["netto_cent"] === 10000 && $steuerTest["positionen"][0]["steuer_cent"] === 1900, "119,00 Euro brutto werden korrekt in 100,00 Euro netto und 19,00 Euro Steuer zerlegt");

$status = status_ids_laden($pdo);
$statusIds = array_column($status, "id");
$angenommenStatusId = status_id_nach_bezeichnung($status, "Angenommen");
pruefen(count($statusIds) >= 2, "Mindestens zwei Statuswerte sind für den Unabhängigkeitstest vorhanden");
pruefen($angenommenStatusId > 0, "Der Startstatus Angenommen ist eingerichtet");
$alternativerStatusId = (int) current(array_values(array_filter(
    $statusIds,
    static fn(mixed $id): bool => (int) $id !== $angenommenStatusId
)));

$auftragId = null;
try {
    $suchToken = "Suchtest" . bin2hex(random_bytes(4));
    $firma = $suchToken . " Firma";
    $email = strtolower($suchToken) . "@example.de";
    $strasse=$suchToken." Straße 17";$plz="13579";$ort=$suchToken." Ort";$land="Deutschland";
    [$name, $telefon, $firma, $email, $strasse, $plz, $ort, $land] = stammdaten_validieren(
        "Automatischer " . $suchToken,
        "(030) 123-45/67",
        $firma,
        $email,$strasse,$plz,$ort,$land
    );
    $geraete = geraete_validieren([
        [
            "geraetetyp" => $suchToken . " Gerät A",
            "hersteller" => $suchToken . " Hersteller A",
            "modell" => $suchToken . " Modell A",
            "seriennummer" => $suchToken . "-SN-A",
            "fehlerbeschreibung" => "Testfehler A",
            "durchgefuehrte_arbeiten" => "Arbeit A",
            "preis" => "0",
            "status_id" => $alternativerStatusId,
        ],
        [
            "geraetetyp" => $suchToken . " Gerät B",
            "hersteller" => $suchToken . " Hersteller B",
            "modell" => $suchToken . " Modell B",
            "seriennummer" => $suchToken . "-SN-B",
            "fehlerbeschreibung" => "Testfehler B",
            "durchgefuehrte_arbeiten" => "Arbeit B",
            "preis" => "25.50",
            "status_id" => 999999,
        ],
    ], $status, $angenommenStatusId);

    pruefen(
        array_unique(array_column($geraete, "status_id")) === [$angenommenStatusId],
        "Der Backend-Startstatus Angenommen überschreibt Statuswerte für alle neuen Geräte"
    );

    $bereitsBezahlt = zahlung_validieren($geraete, "10,00");
    pruefen($bereitsBezahlt === "10.00", "Deutscher Dezimalwert für Bereits bezahlt wird exakt normalisiert");
    pruefen(wirft(fn() => zahlung_validieren($geraete, "25,51"), EingabeException::class), "Bereits bezahlt darf den Gesamtbetrag nicht überschreiten");
    $auftragId = auftrag_anlegen($pdo, $name, $telefon, $firma, $email, $bereitsBezahlt, $geraete, null, $strasse, $plz, $ort, $land);
    $stmt = $pdo->prepare("SELECT id, geraet_id, status_id FROM reparaturauftrag_geraete WHERE auftrag_id = ? ORDER BY id");
    $stmt->execute([$auftragId]);
    $zuordnungen = $stmt->fetchAll();
    pruefen(count($zuordnungen) === 2, "Mehrere Geräte werden demselben Auftrag zugeordnet");
    pruefen(
        array_unique(array_map("intval", array_column($zuordnungen, "status_id"))) === [$angenommenStatusId],
        "Der Service speichert jedes neue Gerät unabhängig von übermittelten Werten als Angenommen"
    );

    $stmtOptionen = $pdo->prepare(
        "SELECT k.firmenname, k.telefon, k.email, k.strasse, k.plz, k.ort, k.land, ra.bereits_bezahlt,
                g.hersteller, g.modell, g.seriennummer, rag.durchgefuehrte_arbeiten
         FROM reparaturauftraege ra
         INNER JOIN kunden k ON k.id = ra.kunde_id
         INNER JOIN reparaturauftrag_geraete rag ON rag.auftrag_id = ra.id
         INNER JOIN geraete g ON g.id = rag.geraet_id
         WHERE ra.id = ? ORDER BY rag.id"
    );
    $stmtOptionen->execute([$auftragId]);
    $gespeicherteOptionen = $stmtOptionen->fetchAll();
    pruefen($gespeicherteOptionen[0]["firmenname"] === $firma && $gespeicherteOptionen[0]["email"] === $email, "Optionale Kundendaten werden gespeichert");
    pruefen($gespeicherteOptionen[0]["strasse"]===$strasse&&$gespeicherteOptionen[0]["plz"]===$plz&&$gespeicherteOptionen[0]["ort"]===$ort, "Die eingegebene Adresse wird gespeichert und erneut geladen");
    pruefen($gespeicherteOptionen[0]["seriennummer"] !== $gespeicherteOptionen[1]["seriennummer"], "Mehrere Geräte behalten jeweils ihre eigenen Angaben");
    pruefen($gespeicherteOptionen[0]["bereits_bezahlt"] === "10.00", "Bereits bezahlt wird dauerhaft gespeichert und wieder geladen");
    pruefen($gespeicherteOptionen[0]["durchgefuehrte_arbeiten"] === "Arbeit A" && $gespeicherteOptionen[1]["durchgefuehrte_arbeiten"] === "Arbeit B", "Leistungsbeschreibungen bleiben dem richtigen Gerät zugeordnet");

    $suchergebnis = auftraege_suchen($pdo, "  " . mb_strtoupper($suchToken) . "  ");
    pruefen(in_array($auftragId, array_map("intval", array_column($suchergebnis["auftraege"], "id")), true), "Namen werden ohne Beachtung der Groß-/Kleinschreibung und Rand-Leerzeichen gefunden");
    $suchergebnis = auftraege_suchen($pdo, $name, "kundenname");
    pruefen(in_array($auftragId, array_map("intval", array_column($suchergebnis["auftraege"], "id")), true), "Der Namensfilter durchsucht den Kundennamen");
    $suchergebnis = auftraege_suchen($pdo, $firma, "firmenname");
    pruefen(in_array($auftragId, array_map("intval", array_column($suchergebnis["auftraege"], "id")), true), "Der Firmenfilter durchsucht den Firmennamen");
    $suchergebnis = auftraege_suchen($pdo, $email, "email");
    pruefen(in_array($auftragId, array_map("intval", array_column($suchergebnis["auftraege"], "id")), true), "Der E-Mail-Filter durchsucht die E-Mail-Adresse");
    foreach([$strasse,"17",$plz,$ort] as $adressSuche){$suchergebnis=auftraege_suchen($pdo,$adressSuche,"adresse");pruefen(in_array($auftragId,array_map("intval",array_column($suchergebnis["auftraege"],"id")),true),"Adressfilter findet Straße, Hausnummer, Postleitzahl oder Ort");}
    $suchergebnis=auftraege_suchen($pdo,$ort,"alle");pruefen(in_array($auftragId,array_map("intval",array_column($suchergebnis["auftraege"],"id")),true),"Alle Kategorien berücksichtigt die Kundenadresse");
    $suchergebnis = auftraege_suchen($pdo, "030 123/45-67");
    pruefen(in_array($auftragId, array_map("intval", array_column($suchergebnis["auftraege"], "id")), true), "Telefonnummern werden unabhängig von Formatierungszeichen gefunden");
    $suchergebnis = auftraege_suchen($pdo, "+49 30 123-45/67", "telefon");
    pruefen(in_array($auftragId, array_map("intval", array_column($suchergebnis["auftraege"], "id")), true), "Telefonnummern werden auch mit deutscher Ländervorwahl gefunden");
    $suchergebnis = auftraege_suchen($pdo, $suchToken . " Gerät B");
    pruefen(in_array($auftragId, array_map("intval", array_column($suchergebnis["auftraege"], "id")), true), "Ein Auftrag wird über sein zweites Gerät gefunden");
    $suchergebnis = auftraege_suchen($pdo, $suchToken . " Gerät B", "geraetetyp");
    pruefen(in_array($auftragId, array_map("intval", array_column($suchergebnis["auftraege"], "id")), true), "Der Gerätefilter berücksichtigt auch das zweite Gerät");
    $suchergebnis = auftraege_suchen($pdo, $suchToken . " Hersteller B", "hersteller_modell");
    pruefen(in_array($auftragId, array_map("intval", array_column($suchergebnis["auftraege"], "id")), true), "Der Hersteller-/Modellfilter durchsucht Hersteller aller Geräte");
    $suchergebnis = auftraege_suchen($pdo, $suchToken . " Modell A", "hersteller_modell");
    pruefen(in_array($auftragId, array_map("intval", array_column($suchergebnis["auftraege"], "id")), true), "Der Hersteller-/Modellfilter durchsucht auch Modelle");
    $suchergebnis = auftraege_suchen($pdo, $suchToken . "-SN-B", "seriennummer");
    pruefen(in_array($auftragId, array_map("intval", array_column($suchergebnis["auftraege"], "id")), true), "Der Seriennummernfilter berücksichtigt jedes Gerät");
    $suchergebnis = auftraege_suchen($pdo, "Testfehler B", "fehlerbeschreibung");
    pruefen(in_array($auftragId, array_map("intval", array_column($suchergebnis["auftraege"], "id")), true), "Der Fehlerfilter berücksichtigt jedes Gerät");
    $suchergebnis = auftraege_suchen($pdo, $suchToken . "-SN-A", "alle");
    pruefen(in_array($auftragId, array_map("intval", array_column($suchergebnis["auftraege"], "id")), true), "Alle Kategorien durchsucht auch neue optionale Gerätedaten");
    $suchergebnis = auftraege_suchen($pdo, $suchToken . " Gerät B", "kundenname");
    pruefen(!in_array($auftragId, array_map("intval", array_column($suchergebnis["auftraege"], "id")), true), "Eine einzelne Suchkategorie durchsucht keine fremde Kategorie");
    $suchergebnis = auftraege_suchen($pdo, "#" . $auftragId, "auftragsnummer");
    pruefen(in_array($auftragId, array_map("intval", array_column($suchergebnis["auftraege"], "id")), true), "Auftragsnummern werden gefunden");
    $suchergebnis = auftraege_suchen($pdo, (string) $status[0]["bezeichnung"], "status");
    pruefen(in_array($auftragId, array_map("intval", array_column($suchergebnis["auftraege"], "id")), true), "Gerätestatus werden gefunden");

    $geraeteBearbeitet = geraete_validieren([
        [
            "id" => $zuordnungen[0]["geraet_id"],
            "geraetetyp" => $suchToken . " Gerät A",
            "hersteller" => $suchToken . " Hersteller A geändert",
            "modell" => $suchToken . " Modell A",
            "seriennummer" => $suchToken . "-SN-A",
            "fehlerbeschreibung" => "Testfehler A",
            "durchgefuehrte_arbeiten" => "Arbeit A geändert",
            "preis" => "0",
        ],
        [
            "id" => $zuordnungen[1]["geraet_id"],
            "geraetetyp" => $suchToken . " Gerät B",
            "hersteller" => $suchToken . " Hersteller B",
            "modell" => $suchToken . " Modell B geändert",
            "seriennummer" => $suchToken . "-SN-B",
            "fehlerbeschreibung" => "Testfehler B",
            "durchgefuehrte_arbeiten" => "Arbeit B geändert",
            "preis" => "25.50",
        ],
    ], $status);
    auftrag_aktualisieren($pdo, $auftragId, $name, null, $firma . " geändert", $email, "5.00", $geraeteBearbeitet, $strasse." geändert", $plz, $ort." geändert", $land);
    $stmtOptionen->execute([$auftragId]);
    $bearbeiteteOptionen = $stmtOptionen->fetchAll();
    pruefen($bearbeiteteOptionen[0]["telefon"] === null && $bearbeiteteOptionen[0]["firmenname"] === $firma . " geändert", "Optionale Kundendaten können geändert und geleert werden");
    pruefen(str_ends_with($bearbeiteteOptionen[0]["strasse"],"geändert")&&str_ends_with($bearbeiteteOptionen[0]["ort"],"geändert"),"Die Adresse lässt sich beim Bearbeiten ändern und erneut laden");
    pruefen(str_ends_with($bearbeiteteOptionen[0]["hersteller"], "geändert") && str_ends_with($bearbeiteteOptionen[1]["modell"], "geändert"), "Optionale Gerätedaten werden pro Gerät unabhängig bearbeitet");

    geraetestatus_aendern($pdo, $auftragId, (int) $zuordnungen[0]["id"], $alternativerStatusId);
    $stmt->execute([$auftragId]);
    $statusNachAenderung = $stmt->fetchAll();
    pruefen((int) $statusNachAenderung[0]["status_id"] === $alternativerStatusId, "Status des ersten Geräts wird geändert");
    pruefen((int) $statusNachAenderung[1]["status_id"] === $angenommenStatusId, "Status des zweiten Geräts bleibt unverändert");

    pruefen(
        wirft(
            fn() => geraetestatus_aendern($pdo, $auftragId + 999999, (int) $zuordnungen[0]["id"], (int) $statusIds[0]),
            EingabeException::class
        ),
        "Fremde Auftrag-Gerät-Zuordnungen werden abgelehnt"
    );

    pruefen(
        wirft(
            function () use ($pdo, $zuordnungen): void {
                $stmt = $pdo->prepare("UPDATE reparaturauftrag_geraete SET preis = -1 WHERE id = ?");
                $stmt->execute([(int) $zuordnungen[0]["id"]]);
            },
            PDOException::class
        ),
        "Datenbank-Constraint lehnt negative direkte Schreibzugriffe ab"
    );

    pruefen(
        wirft(
            function () use ($pdo, $auftragId): void {
                $stmt = $pdo->prepare("UPDATE reparaturauftraege SET bereits_bezahlt = -1 WHERE id = ?");
                $stmt->execute([$auftragId]);
            },
            PDOException::class
        ),
        "Datenbank-Constraint lehnt negative Zahlungen bei direkten Schreibzugriffen ab"
    );

    pruefen(
        wirft(
            fn() => geraet_aus_auftrag_loeschen($pdo, $auftragId, (int) $zuordnungen[1]["id"]),
            EingabeException::class
        ),
        "Ein Gerät wird nicht gelöscht, wenn die Zahlung danach den Gesamtbetrag überschreiten würde"
    );

    geraet_aus_auftrag_loeschen($pdo, $auftragId, (int) $zuordnungen[0]["id"]);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reparaturauftrag_geraete WHERE auftrag_id = ?");
    $stmt->execute([$auftragId]);
    pruefen((int) $stmt->fetchColumn() === 1, "Ein einzelnes Gerät kann gelöscht werden, ohne den Auftrag zu löschen");

    pruefen(
        wirft(
            fn() => geraet_aus_auftrag_loeschen($pdo, $auftragId, (int) $zuordnungen[1]["id"]),
            LetztesGeraetException::class
        ),
        "Das letzte Gerät kann nicht unbemerkt gelöscht werden"
    );

    auftrag_loeschen($pdo, $auftragId);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reparaturauftraege WHERE id = ?");
    $stmt->execute([$auftragId]);
    pruefen((int) $stmt->fetchColumn() === 0, "Ein kompletter Auftrag kann gelöscht werden");
    $auftragId = null;
} finally {
    if ($auftragId !== null) {
        try {
            auftrag_loeschen($pdo, $auftragId);
        } catch (Throwable) {
            // Der fehlgeschlagene Test soll die ursprüngliche Ursache melden.
        }
    }
}

$minimalAuftragId = null;
try {
    [$minimalName, $minimalTelefon, $minimalFirma, $minimalEmail] = stammdaten_validieren(
        "Minimalauftrag " . bin2hex(random_bytes(3)),
        "",
        "",
        ""
    );
    $minimalGeraete = geraete_validieren([[
        "geraetetyp" => "Testgerät",
        "fehlerbeschreibung" => "Testfehler",
        "preis" => "0",
    ]], $status, $angenommenStatusId);
    $minimalAuftragId = auftrag_anlegen(
        $pdo,
        $minimalName,
        $minimalTelefon,
        $minimalFirma,
        $minimalEmail,
        "0.00",
        $minimalGeraete
    );
    $stmt = $pdo->prepare(
        "SELECT k.telefon, k.firmenname, k.email, g.hersteller, g.modell, g.seriennummer
         FROM reparaturauftraege ra
         INNER JOIN kunden k ON k.id = ra.kunde_id
         INNER JOIN reparaturauftrag_geraete rag ON rag.auftrag_id = ra.id
         INNER JOIN geraete g ON g.id = rag.geraet_id
         WHERE ra.id = ?"
    );
    $stmt->execute([$minimalAuftragId]);
    $minimalDatensatz = $stmt->fetch();
    pruefen(
        $minimalDatensatz !== false
        && $minimalDatensatz["telefon"] === null
        && $minimalDatensatz["firmenname"] === null
        && $minimalDatensatz["email"] === null
        && $minimalDatensatz["hersteller"] === null
        && $minimalDatensatz["modell"] === null
        && $minimalDatensatz["seriennummer"] === null,
        "Ein Auftrag lässt sich mit Kundenname, Gerätetyp, Fehlerbeschreibung und Preis 0 speichern"
    );
    auftrag_loeschen($pdo, $minimalAuftragId);
    $minimalAuftragId = null;
} finally {
    if ($minimalAuftragId !== null) {
        try {
            auftrag_loeschen($pdo, $minimalAuftragId);
        } catch (Throwable) {
            // Der fehlgeschlagene Test soll die ursprüngliche Ursache melden.
        }
    }
}

$rechnungsTestAuftragId = null;
$rechnungsTestId = null;
$originalKonfiguration = geschaeftsdaten_laden($pdo);
$rechnungsjahr = (int) date("Y");
$stmt = $pdo->prepare("SELECT letzter_wert FROM rechnungsnummern WHERE jahr = ?");
$stmt->execute([$rechnungsjahr]);
$originalZaehler = $stmt->fetchColumn();
try {
    $stmt = $pdo->prepare(
        "UPDATE geschaeftskonfiguration SET geschaeftsname = 'Computerfachmann',
         inhaber = 'Automatischer Test', strasse = 'Testweg 1', plz = '10115', ort = 'Berlin',
         steuernummer = 'TEST-STEUER', ust_id = NULL WHERE id = 1"
    );
    $stmt->execute();
    $rechnungsGeraete = geraete_validieren([
        ["geraetetyp" => "Rechnungstest A", "fehlerbeschreibung" => "Fehler A", "durchgefuehrte_arbeiten" => "Leistung A", "preis" => "119,00", "bereits_bezahlt" => "119,00"],
        ["geraetetyp" => "Rechnungstest B", "fehlerbeschreibung" => "Fehler B", "durchgefuehrte_arbeiten" => "Leistung B", "preis" => "10,00", "bereits_bezahlt" => "10,00"],
    ], $status, $angenommenStatusId);
    $rechnungsTestAuftragId = auftrag_anlegen(
        $pdo,
        "Rechnungstest " . bin2hex(random_bytes(3)),
        null,
        null,
        null,
        "0.00",
        $rechnungsGeraete,
        null,"Snapshotstraße 9","12345","Snapshotstadt","Deutschland"
    );
    $rechnungsTestId = rechnung_endgueltig_erstellen($pdo, $rechnungsTestAuftragId);
    $rechnungSnapshot = rechnung_laden($pdo, $rechnungsTestId);
    pruefen((int) $rechnungSnapshot["auftragsnummer_snapshot"] === $rechnungsTestAuftragId, "Die Auftragsnummer wird in der endgültigen Rechnung gespeichert");
    pruefen(count($rechnungSnapshot["positionen"]) === 2, "Mehrere Geräte werden als getrennte Rechnungspositionen gespeichert");
    pruefen($rechnungSnapshot["positionen"][0]["leistungsbeschreibung"] === "Leistung A" && $rechnungSnapshot["positionen"][1]["leistungsbeschreibung"] === "Leistung B", "Leistungen bleiben in der Rechnung dem richtigen Gerät zugeordnet");
    pruefen($rechnungSnapshot["brutto"] === "129.00" && $rechnungSnapshot["bereits_bezahlt"] === "129.00" && $rechnungSnapshot["restbetrag"] === "0.00", "Brutto, vollständig bezahlt und Restbetrag 0 werden korrekt im Rechnungssnapshot gespeichert");
    pruefen($rechnungSnapshot["kunde_strasse"]==="Snapshotstraße 9"&&$rechnungSnapshot["kunde_ort"]==="Snapshotstadt","Die Kundenadresse wird unveränderlich in der Rechnung gespeichert");
    pruefen($rechnungSnapshot["zahlungsstatus"]==="Bezahlt"&&!empty($rechnungSnapshot["zahlungsdatum_snapshot"])&&!empty($rechnungSnapshot["zahlungsart_snapshot"]),"Endgültige Rechnung enthält Zahlungsstatus, Datum und Zahlungsart");
    $archivTreffer=archiv_filter($pdo,["suche"=>"Snapshotstraße","von"=>date("Y-01-01"),"bis"=>date("Y-12-31"),"status"=>"Bezahlt"]);
    pruefen(in_array($rechnungsTestId,array_map("intval",array_column($archivTreffer["rechnungen"],"id")),true),"Rechnung erscheint über Kundenadresse und Zeitraum im Archiv");
    pruefen(rechnung_endgueltig_erstellen($pdo, $rechnungsTestAuftragId) === $rechnungsTestId, "Erneutes Erstellen liefert dieselbe Rechnung ohne neue Rechnungsnummer");

    $stmt = $pdo->prepare("UPDATE kunden k INNER JOIN reparaturauftraege ra ON ra.kunde_id = k.id SET k.name = 'Nachträglich geändert',k.strasse='Andere Straße 1',k.ort='Anderer Ort' WHERE ra.id = ?");
    $stmt->execute([$rechnungsTestAuftragId]);
    $snapshotNachAenderung = rechnung_laden($pdo, $rechnungsTestId);
    pruefen($snapshotNachAenderung["kunde_name"] === $rechnungSnapshot["kunde_name"], "Spätere Auftragsänderungen verändern die endgültige Rechnung nicht");
    pruefen($snapshotNachAenderung["kunde_strasse"]==="Snapshotstraße 9"&&$snapshotNachAenderung["kunde_ort"]==="Snapshotstadt","Spätere Adressänderungen verändern die ausgestellte Rechnung nicht");

    auftrag_loeschen($pdo, $rechnungsTestAuftragId);
    $rechnungsTestAuftragId = null;
    $rechnungNachLoeschung = rechnung_laden($pdo, $rechnungsTestId);
    pruefen($rechnungNachLoeschung["auftrag_id"] === null && (int) $rechnungNachLoeschung["auftragsnummer_snapshot"] > 0, "Die Rechnung und ursprüngliche Auftragsnummer bleiben nach Auftragslöschung erhalten");
} finally {
    if ($rechnungsTestAuftragId !== null) {
        try { auftrag_loeschen($pdo, $rechnungsTestAuftragId); } catch (Throwable) {}
    }
    if ($rechnungsTestId !== null) {
        $stmt = $pdo->prepare("DELETE FROM rechnungen WHERE id = ?");
        $stmt->execute([$rechnungsTestId]);
    }
    $stmt = $pdo->prepare(
        "UPDATE geschaeftskonfiguration SET geschaeftsname = ?, inhaber = ?, strasse = ?, plz = ?, ort = ?,
         telefon = ?, email = ?, steuernummer = ?, ust_id = ?, bankverbindung = ?, zahlungsziel = ?, rechnungshinweise = ?
         WHERE id = 1"
    );
    $stmt->execute([
        $originalKonfiguration["geschaeftsname"], $originalKonfiguration["inhaber"], $originalKonfiguration["strasse"],
        $originalKonfiguration["plz"], $originalKonfiguration["ort"], $originalKonfiguration["telefon"],
        $originalKonfiguration["email"], $originalKonfiguration["steuernummer"], $originalKonfiguration["ust_id"],
        $originalKonfiguration["bankverbindung"], $originalKonfiguration["zahlungsziel"], $originalKonfiguration["rechnungshinweise"],
    ]);
    if ($originalZaehler === false) {
        $stmt = $pdo->prepare("DELETE FROM rechnungsnummern WHERE jahr = ?");
        $stmt->execute([$rechnungsjahr]);
    } else {
        $stmt = $pdo->prepare("UPDATE rechnungsnummern SET letzter_wert = ? WHERE jahr = ?");
        $stmt->execute([(int) $originalZaehler, $rechnungsjahr]);
    }
}

$teilAuftragId = null;
$teilRechnungIds = [];
$teilOriginalKonfiguration = geschaeftsdaten_laden($pdo);
$stmt = $pdo->prepare("SELECT letzter_wert FROM rechnungsnummern WHERE jahr = ?");
$stmt->execute([$rechnungsjahr]);
$teilOriginalZaehler = $stmt->fetchColumn();
try {
    $pdo->exec("UPDATE geschaeftskonfiguration SET geschaeftsname='Computerfachmann', inhaber='Teilrechnungstest', strasse='Testweg 2', plz='10115', ort='Berlin', steuernummer='TEST-TEIL' WHERE id=1");
    $teilGeraete = geraete_validieren([
        ["geraetetyp"=>"Teilgerät A","fehlerbeschreibung"=>"Fehler A","preis"=>"50.00","bereits_bezahlt"=>"10.00"],
        ["geraetetyp"=>"Teilgerät B","fehlerbeschreibung"=>"Fehler B","preis"=>"30.00","bereits_bezahlt"=>"5.00"],
        ["geraetetyp"=>"Teilgerät C","fehlerbeschreibung"=>"Fehler C","preis"=>"20.00","bereits_bezahlt"=>"0.00"],
    ], $status, $angenommenStatusId);
    $teilAuftragId = auftrag_anlegen($pdo, "Teilrechnung ".bin2hex(random_bytes(3)), null, null, null, "5.00", $teilGeraete);
    $teilAuftrag = auftrag_fuer_druck_laden($pdo, $teilAuftragId);
    pruefen($teilAuftrag["gesamtbetrag"] === "100.00" && $teilAuftrag["bereits_bezahlt"] === "20.00" && $teilAuftrag["restbetrag"] === "80.00", "Auftragssumme enthält Gerätezahlungen und bewahrt die nicht zugeordnete Altzahlung separat");
    $zuordnungIds = array_map("intval", array_column($teilAuftrag["geraete"], "zuordnung_id"));
    $geraetIds = array_map("intval", array_column($teilAuftrag["geraete"], "geraet_id"));
    pruefen(geraete_referenz($teilAuftragId, $geraetIds[0]) === "#{$teilAuftragId}-G{$geraetIds[0]}", "Die interne Gerätereferenz ist stabil aus Auftrag und Geräte-ID aufgebaut");
    pruefen(wirft(fn() => ausgewaehlte_geraete($teilAuftrag, [999999], false), EingabeException::class), "Fremde oder ungültige Gerätezuordnungen werden abgelehnt");
    pruefen(wirft(fn() => geraet_vollstaendig_bezahlen($pdo,$teilAuftragId,$zuordnungIds[0],date("Y-m-d"),"Barzahlung",null), EingabeException::class), "Eine alte nicht zugeordnete Zahlung muss vor neuer Vollzahlung verteilt werden");
    altzahlung_zuordnen($pdo,$teilAuftragId,[$zuordnungIds[0]=>"0.00",$zuordnungIds[1]=>"0.00",$zuordnungIds[2]=>"5.00"]);
    $nachZuordnung=auftrag_fuer_druck_laden($pdo,$teilAuftragId);
    pruefen($nachZuordnung["nicht_zugeordnet_bezahlt"] === "0.00" && $nachZuordnung["geraete"][2]["bereits_bezahlt"] === "5.00", "Alte Zahlung wird vollständig und verlustfrei einem Gerät zugeordnet");
    pruefen(wirft(fn() => rechnungen_endgueltig_erstellen($pdo,$teilAuftragId,[$zuordnungIds[0]],"gemeinsam"), EingabeException::class), "Eine unbezahlte Geräteposition kann keine endgültige Rechnung erhalten");

    geraet_als_abgeholt_markieren($pdo, $teilAuftragId, $zuordnungIds[0], "Testempfänger");
    $stmt = $pdo->prepare("SELECT id,abgeholt_am,abgeholt_von FROM reparaturauftrag_geraete WHERE auftrag_id=? ORDER BY id");
    $stmt->execute([$teilAuftragId]); $abholungen = $stmt->fetchAll();
    pruefen($abholungen[0]["abgeholt_am"] !== null && $abholungen[0]["abgeholt_von"] === "Testempfänger", "Ein Gerät kann ausdrücklich als abgeholt markiert werden");
    pruefen($abholungen[1]["abgeholt_am"] === null && $abholungen[2]["abgeholt_am"] === null, "Die Abholung eines Geräts beeinflusst andere Geräte nicht");
    pruefen(wirft(fn() => geraet_als_abgeholt_markieren($pdo, $teilAuftragId, 999999, null), EingabeException::class), "Abholung über eine fremde Zuordnung wird verhindert");

    foreach($zuordnungIds as $i=>$zuordnungId)geraet_vollstaendig_bezahlen($pdo,$teilAuftragId,$zuordnungId,date("Y-m-d"),$i===0?"Barzahlung":"EC-/Kartenzahlung","Testzahlung");
    $vollBezahlt=auftrag_fuer_druck_laden($pdo,$teilAuftragId);
    pruefen($vollBezahlt["bereits_bezahlt"] === "100.00" && $vollBezahlt["restbetrag"] === "0.00", "Vollzahlungen setzen Gesamtzahlung und Restbetrag exakt auf bezahlt und 0,00 Euro");
    pruefen(wirft(fn() => geraet_vollstaendig_bezahlen($pdo,$teilAuftragId,$zuordnungIds[0],date("Y-m-d"),"Barzahlung",null), EingabeException::class), "Ein vollständig bezahltes Gerät kann nicht doppelt bezahlt werden");

    $einzelIds = rechnungen_endgueltig_erstellen($pdo, $teilAuftragId, array_slice($zuordnungIds, 0, 2), "einzeln");
    array_push($teilRechnungIds, ...$einzelIds);
    pruefen(count($einzelIds) === 2 && $einzelIds[0] !== $einzelIds[1], "Getrennte Auswahl erzeugt je Gerät eine eigene Rechnung");
    $einzelA = rechnung_laden($pdo, $einzelIds[0]); $einzelB = rechnung_laden($pdo, $einzelIds[1]);
    pruefen(count($einzelA["positionen"]) === 1 && count($einzelB["positionen"]) === 1, "Jede Einzelrechnung enthält ausschließlich ihr ausgewähltes Gerät");
    pruefen($einzelA["rechnungsnummer"] !== $einzelB["rechnungsnummer"] && $einzelA["rechnungsart"] === "einzeln", "Einzelrechnungen erhalten eindeutige Nummern und die richtige Rechnungsart");
    pruefen($einzelA["bereits_bezahlt"] === "50.00" && $einzelB["bereits_bezahlt"] === "30.00", "Jede Rechnung verrechnet nur die vollständige Zahlung ihres Geräts");
    pruefen(wirft(fn() => rechnungen_endgueltig_erstellen($pdo, $teilAuftragId, [$zuordnungIds[0]], "gemeinsam"), EingabeException::class), "Doppelte Abrechnung desselben Geräts wird serverseitig verhindert");

    $gemeinsamIds = rechnungen_endgueltig_erstellen($pdo, $teilAuftragId, [$zuordnungIds[2]], "gemeinsam");
    array_push($teilRechnungIds, ...$gemeinsamIds);
    pruefen(count($gemeinsamIds) === 1 && rechnung_laden($pdo, $gemeinsamIds[0])["rechnungsart"] === "gemeinsam", "Eine gemeinsame Rechnung für die verbleibende Auswahl wird gespeichert");
    $mappingAnzahl = (int) $pdo->query("SELECT COUNT(*) FROM rechnung_geraete WHERE auftrag_id_snapshot=".(int)$teilAuftragId)->fetchColumn();
    pruefen($mappingAnzahl === 3, "Jedes abgerechnete Gerät besitzt genau eine unveränderliche Rechnungszuordnung");
    pruefen(wirft(fn() => geraet_aus_auftrag_loeschen($pdo, $teilAuftragId, $zuordnungIds[0]), EingabeException::class), "Ein bereits abgerechnetes Gerät kann nicht aus der Historie gelöscht werden");

    auftrag_loeschen($pdo, $teilAuftragId); $teilAuftragId = null;
    foreach ($teilRechnungIds as $id) pruefen(rechnung_laden($pdo, $id)["auftrag_id"] === null, "Teilrechnung bleibt nach Auftragslöschung unverändert erhalten");
} finally {
    if ($teilAuftragId !== null) { try { auftrag_loeschen($pdo, $teilAuftragId); } catch (Throwable) {} }
    foreach ($teilRechnungIds as $id) { $stmt=$pdo->prepare("DELETE FROM rechnungen WHERE id=?"); $stmt->execute([$id]); }
    $stmt=$pdo->prepare("UPDATE geschaeftskonfiguration SET geschaeftsname=?,inhaber=?,strasse=?,plz=?,ort=?,telefon=?,email=?,steuernummer=?,ust_id=?,bankverbindung=?,zahlungsziel=?,rechnungshinweise=? WHERE id=1");
    $stmt->execute([$teilOriginalKonfiguration["geschaeftsname"],$teilOriginalKonfiguration["inhaber"],$teilOriginalKonfiguration["strasse"],$teilOriginalKonfiguration["plz"],$teilOriginalKonfiguration["ort"],$teilOriginalKonfiguration["telefon"],$teilOriginalKonfiguration["email"],$teilOriginalKonfiguration["steuernummer"],$teilOriginalKonfiguration["ust_id"],$teilOriginalKonfiguration["bankverbindung"],$teilOriginalKonfiguration["zahlungsziel"],$teilOriginalKonfiguration["rechnungshinweise"]]);
    if($teilOriginalZaehler===false){$stmt=$pdo->prepare("DELETE FROM rechnungsnummern WHERE jahr=?");$stmt->execute([$rechnungsjahr]);}else{$stmt=$pdo->prepare("UPDATE rechnungsnummern SET letzter_wert=? WHERE jahr=?");$stmt->execute([(int)$teilOriginalZaehler,$rechnungsjahr]);}
}

$legacySpalte = $pdo->query("SHOW COLUMNS FROM reparaturauftraege LIKE 'geraet_id'")->fetch();
if ($legacySpalte) {
    $altauftraege = (int) $pdo->query("SELECT COUNT(*) FROM reparaturauftraege WHERE geraet_id IS NOT NULL")->fetchColumn();
    $migrierteAltauftraege = (int) $pdo->query(
        "SELECT COUNT(DISTINCT ra.id)
         FROM reparaturauftraege ra
         INNER JOIN reparaturauftrag_geraete rag
           ON rag.auftrag_id = ra.id AND rag.geraet_id = ra.geraet_id
         WHERE ra.geraet_id IS NOT NULL"
    )->fetchColumn();
    pruefen($altauftraege === $migrierteAltauftraege, "Alle bestehenden Ein-Gerät-Aufträge wurden verlustfrei zugeordnet");
} else {
    $leereAuftraege = (int) $pdo->query(
        "SELECT COUNT(*) FROM reparaturauftraege ra
         WHERE NOT EXISTS (SELECT 1 FROM reparaturauftrag_geraete rag WHERE rag.auftrag_id = ra.id)"
    )->fetchColumn();
    pruefen($leereAuftraege === 0, "Alle Aufträge besitzen mindestens eine Gerätezuordnung");
}

echo "OK - {$tests} Tests erfolgreich.\n";
