<?php

declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/funktionen.php";
require_once __DIR__ . "/rechnungsarchiv_repository.php";

function csv_zelle_sichern(mixed $wert): string
{
    $text = (string) $wert;
    return preg_match('/^[=+\-@]/u', $text) === 1 ? "'" . $text : $text;
}

try {
    berechtigung_pruefen(["administrator"]);
    $daten = archiv_filter($pdo, $_GET);
} catch (EingabeException $e) {
    http_response_code(403);
    die(h($e->getMessage()));
}

audit_protokollieren($pdo, "rechnungsarchiv_exportiert", "rechnungsarchiv", null, [
    "von" => $daten["von"],
    "bis" => $daten["bis"],
    "anzahl" => count($daten["rechnungen"]),
]);

header("Content-Type: text/csv; charset=UTF-8");
header('Content-Disposition: attachment; filename="rechnungsarchiv-' . date('Y-m-d') . '.csv"');
echo "\xEF\xBB\xBF";

$ausgabe = fopen("php://output", "wb");
fputcsv($ausgabe, [
    "Rechnungsnummer", "Rechnungsdatum", "Kunde", "Adresse", "Auftrag", "Geräte",
    "Netto", "USt", "Brutto", "Zahlungsstatus", "Zahlungsdatum", "Zahlungsart", "Storniert",
], ";", '"', "");

foreach ($daten["rechnungen"] as $rechnung) {
    $zeile = [
        $rechnung["rechnungsnummer"],
        $rechnung["rechnungsdatum"],
        $rechnung["kunde_name"],
        implode(", ", adresse_zeilen($rechnung, "kunde_")),
        $rechnung["auftragsnummer_snapshot"],
        $rechnung["geraete"],
        $rechnung["netto"],
        $rechnung["steuerbetrag"],
        $rechnung["brutto"],
        $rechnung["zahlungsstatus"],
        $rechnung["zahlungsdatum_snapshot"],
        $rechnung["zahlungsart_snapshot"],
        $rechnung["storniert_am"] ? "Ja" : "Nein",
    ];
    fputcsv($ausgabe, array_map("csv_zelle_sichern", $zeile), ";", '"', "");
}

fclose($ausgabe);
exit;
