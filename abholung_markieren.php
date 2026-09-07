<?php

declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/funktionen.php";
require_once __DIR__ . "/auftrags_service.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") { http_response_code(405); die("Diese Aktion ist nur aus dem Abholschein möglich."); }
$auftragId = 0;
try {
    csrf_pruefen($_POST["csrf_token"] ?? null);
    $auftragId = positive_id($_POST["auftrag_id"] ?? null, "Die Auftragsnummer");
    $zuordnungId = positive_id($_POST["zuordnung_id"] ?? null, "Die Gerätezuordnung");
    geraet_als_abgeholt_markieren($pdo, $auftragId, $zuordnungId, $_POST["empfaenger"] ?? null);
    flash_setzen("success", "Die Geräteabholung wurde erfolgreich bestätigt.");
} catch (EingabeException $e) { flash_setzen("error", $e->getMessage()); }
catch (Throwable $e) { error_log($e->getMessage()); flash_setzen("error", "Der Abholstatus konnte nicht gespeichert werden."); }
$rueckkehr = (string) ($_POST["rueckkehr"] ?? "");
if (!str_starts_with($rueckkehr, "abholschein.php?")) $rueckkehr = $auftragId > 0 ? "abholschein.php?auftrag_id=".$auftragId : "auftraege.php";
weiterleiten($rueckkehr);
