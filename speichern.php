<?php

declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/funktionen.php";
require_once __DIR__ . "/auftrags_service.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    die("Diese Aktion ist nur über das Auftragsformular möglich.");
}

try {
    csrf_pruefen($_POST["csrf_token"] ?? null);
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
    $angenommenStatusId = status_id_nach_bezeichnung($status, "Angenommen");
    $geraete = geraete_validieren($_POST["geraete"] ?? null, $status, $angenommenStatusId);
    $zahlung = zahlungszuordnung_validieren($geraete, $_POST["nicht_zugeordnet_bezahlt"] ?? null);

    $bestehenderKundeId = trim((string) ($_POST["bestehender_kunde_id"] ?? "")) === "" ? null : positive_id($_POST["bestehender_kunde_id"], "Der bestehende Kunde");
    auftrag_anlegen($pdo, $name, $telefon, $firmenname, $email, $zahlung["nicht_zugeordnet"], $geraete, $bestehenderKundeId, $strasse, $plz, $ort, $land);
    unset($_SESSION["formular_daten"]);
    flash_setzen("success", "Reparaturauftrag erfolgreich gespeichert.");
    weiterleiten("auftraege.php");
} catch (EingabeException $e) {
    $_SESSION["formular_daten"] = array_diff_key($_POST, ["csrf_token" => true]);
    flash_setzen("error", $e->getMessage());
    weiterleiten("index.php");
} catch (Throwable $e) {
    error_log($e->getMessage());
    $_SESSION["formular_daten"] = array_diff_key($_POST, ["csrf_token" => true]);
    flash_setzen("error", "Der Reparaturauftrag konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.");
    weiterleiten("index.php");
}
