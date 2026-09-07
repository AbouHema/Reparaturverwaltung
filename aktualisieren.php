<?php

declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/funktionen.php";
require_once __DIR__ . "/auftrags_service.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    die("Diese Aktion ist nur über das Bearbeitungsformular möglich.");
}

$auftragId = 0;
try {
    csrf_pruefen($_POST["csrf_token"] ?? null);
    $auftragId = positive_id($_POST["auftrag_id"] ?? null, "Die Auftragsnummer");
    [$name, $telefon, $firmenname, $email, $strasse, $plz, $ort, $land] = stammdaten_validieren(
        $_POST["name"] ?? null,
        $_POST["telefon"] ?? null,
        $_POST["firmenname"] ?? null,
        $_POST["email"] ?? null,
        $_POST["strasse"] ?? null,
        $_POST["plz"] ?? null,
        $_POST["ort"] ?? null,
        $_POST["land"] ?? null
    );
    $status = status_ids_laden($pdo);
    $geraete = geraete_validieren($_POST["geraete"] ?? null, $status);
    $zahlung = zahlungszuordnung_validieren($geraete, $_POST["nicht_zugeordnet_bezahlt"] ?? null);

    auftrag_aktualisieren($pdo, $auftragId, $name, $telefon, $firmenname, $email, $zahlung["nicht_zugeordnet"], $geraete, $strasse, $plz, $ort, $land);
    flash_setzen("success", "Der Reparaturauftrag wurde erfolgreich aktualisiert.");
    weiterleiten("auftraege.php");
} catch (EingabeException $e) {
    http_response_code(422);
    flash_setzen("error", $e->getMessage());
    weiterleiten($auftragId > 0 ? "bearbeiten.php?id=" . $auftragId : "auftraege.php");
} catch (Throwable $e) {
    error_log($e->getMessage());
    http_response_code(500);
    flash_setzen("error", "Die Änderungen konnten nicht gespeichert werden. Bitte versuchen Sie es erneut.");
    weiterleiten($auftragId > 0 ? "bearbeiten.php?id=" . $auftragId : "auftraege.php");
}
