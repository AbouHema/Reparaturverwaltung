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
        echo json_encode(["fehler" => "Diese Aktion ist nur über die Auftragsübersicht möglich."], JSON_UNESCAPED_UNICODE);
        exit;
    }
    die("Diese Aktion ist nur über die Auftragsübersicht möglich.");
}

try {
    csrf_pruefen($_POST["csrf_token"] ?? null);
    $auftragId = positive_id($_POST["auftrag_id"] ?? null, "Die Auftragsnummer");
    $zuordnungId = positive_id($_POST["zuordnung_id"] ?? null, "Die Gerätezuordnung");
    $statusId = positive_id($_POST["status_id"] ?? null, "Der Reparaturstatus");
    $pdo->beginTransaction();
    geraetestatus_aendern($pdo, $auftragId, $zuordnungId, $statusId);
    $gesamtstatus = auftragsstatus_ermitteln($pdo, $auftragId);
    $stmt = $pdo->prepare("SELECT bezeichnung FROM status WHERE id = ?");
    $stmt->execute([$statusId]);
    $geraetestatus = (string) $stmt->fetchColumn();
    $pdo->commit();
    if ($ajax) {
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode([
            "ok" => true,
            "nachricht" => "Status erfolgreich aktualisiert.",
            "geraetestatus" => $geraetestatus,
            "status_klasse" => status_klasse($geraetestatus),
            "gesamtstatus" => $gesamtstatus,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
    flash_setzen("success", "Status erfolgreich aktualisiert.");
} catch (EingabeException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($ajax) {
        header("Content-Type: application/json; charset=utf-8");
        http_response_code(422);
        echo json_encode(["fehler" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
    flash_setzen("error", $e->getMessage());
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log($e->getMessage());
    if ($ajax) {
        header("Content-Type: application/json; charset=utf-8");
        http_response_code(500);
        echo json_encode(["fehler" => "Der Gerätestatus konnte nicht geändert werden. Bitte versuchen Sie es erneut."], JSON_UNESCAPED_UNICODE);
        exit;
    }
    flash_setzen("error", "Der Gerätestatus konnte nicht geändert werden. Bitte versuchen Sie es erneut.");
}

weiterleiten("auftraege.php");
