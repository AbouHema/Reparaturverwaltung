<?php

declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/funktionen.php";
require_once __DIR__ . "/auftrags_service.php";

$ajax = strtolower((string) ($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "")) === "xmlhttprequest";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    if ($ajax) {
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(["fehler" => "Diese Aktion ist nur über eine bestätigte Löschanfrage möglich."], JSON_UNESCAPED_UNICODE);
        exit;
    }
    die("Diese Aktion ist nur über eine bestätigte Löschanfrage möglich.");
}

try {
    csrf_pruefen($_POST["csrf_token"] ?? null);
    loeschberechtigung_pruefen();
    $auftragId = positive_id($_POST["auftrag_id"] ?? null, "Die Auftragsnummer");
    auftrag_loeschen($pdo, $auftragId);
    if ($ajax) {
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode([
            "ok" => true,
            "auftrag_id" => $auftragId,
            "nachricht" => "Reparaturauftrag erfolgreich gelöscht.",
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
    flash_setzen("success", "Reparaturauftrag erfolgreich gelöscht.");
} catch (EingabeException $e) {
    if ($ajax) {
        header("Content-Type: application/json; charset=utf-8");
        http_response_code(422);
        echo json_encode(["fehler" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
    flash_setzen("error", $e->getMessage());
} catch (Throwable $e) {
    error_log($e->getMessage());
    if ($ajax) {
        header("Content-Type: application/json; charset=utf-8");
        http_response_code(500);
        echo json_encode(["fehler" => "Der Reparaturauftrag konnte nicht gelöscht werden. Bitte versuchen Sie es erneut."], JSON_UNESCAPED_UNICODE);
        exit;
    }
    flash_setzen("error", "Der Reparaturauftrag konnte nicht gelöscht werden. Bitte versuchen Sie es erneut.");
}

weiterleiten("auftraege.php");
