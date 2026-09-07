<?php
declare(strict_types=1);
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/funktionen.php";
if ($_SERVER["REQUEST_METHOD"] !== "POST") { http_response_code(405); die("Abmeldung nur über die Schaltfläche."); }
csrf_pruefen($_POST["csrf_token"] ?? null); audit_protokollieren($pdo,"abmeldung","benutzer",aktuelle_benutzer_id());
sitzung_beenden();
header("Location: login.php",true,303); exit;
