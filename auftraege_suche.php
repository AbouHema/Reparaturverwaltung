<?php

declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/funktionen.php";
require_once __DIR__ . "/auftraege_repository.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

// Der Token wird vorab initialisiert; anschließend wird die Session-Sperre
// freigegeben, damit eine neuere Suchanfrage nicht auf eine ältere warten muss.
csrf_token();
session_write_close();

try {
    $daten = auftraege_suchen($pdo, $_GET["suche"] ?? "", $_GET["kategorie"] ?? "alle");
    $suche = $daten["suche"];
    $kategorie = $daten["kategorie"];
    $auftraege = $daten["auftraege"];
    $geraeteNachAuftrag = $daten["geraeteNachAuftrag"];
    $status = status_ids_laden($pdo);

    ob_start();
    require __DIR__ . "/auftraege_liste.php";
    $html = ob_get_clean();

    echo json_encode([
        "html" => $html,
        "anzahl" => count($auftraege),
        "suche" => $suche,
        "kategorie" => $kategorie,
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode([
        "fehler" => "Die Reparaturaufträge konnten nicht geladen werden. Bitte versuchen Sie es erneut.",
    ], JSON_UNESCAPED_UNICODE);
}
