<?php

declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/funktionen.php";
require_once __DIR__ . "/auftrags_service.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    die("Diese Aktion ist nur über eine bestätigte Löschanfrage möglich.");
}

$auftragId = 0;
try {
    csrf_pruefen($_POST["csrf_token"] ?? null);
    loeschberechtigung_pruefen();
    $auftragId = positive_id($_POST["auftrag_id"] ?? null, "Die Auftragsnummer");
    $zuordnungId = positive_id($_POST["zuordnung_id"] ?? null, "Die Gerätezuordnung");
    geraet_aus_auftrag_loeschen($pdo, $auftragId, $zuordnungId);
    flash_setzen("success", "Das Gerät wurde erfolgreich aus dem Reparaturauftrag gelöscht.");
} catch (LetztesGeraetException | EingabeException $e) {
    flash_setzen("error", $e->getMessage());
} catch (Throwable $e) {
    error_log($e->getMessage());
    flash_setzen("error", "Das Gerät konnte nicht gelöscht werden. Bitte versuchen Sie es erneut.");
}

weiterleiten($auftragId > 0 ? "bearbeiten.php?id=" . $auftragId . "#geraete" : "auftraege.php");

