<?php

declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/funktionen.php";
try { berechtigung_pruefen(["administrator"]); } catch (EingabeException $e) { http_response_code(403); die(h($e->getMessage())); }

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    die("Diese Aktion ist nur über die Rechnungsdaten möglich.");
}

try {
    csrf_pruefen($_POST["csrf_token"] ?? null);
    $laengen = [
        "geschaeftsname" => 150, "inhaber" => 200, "strasse" => 200, "plz" => 20,
        "ort" => 100, "telefon" => 50, "email" => 254, "steuernummer" => 100,
        "ust_id" => 100, "bankverbindung" => 2000, "zahlungsziel" => 200, "rechnungshinweise" => 5000,
    ];
    $werte = [];
    foreach ($laengen as $feld => $maximal) {
        $wert = trim((string) ($_POST[$feld] ?? ""));
        if (mb_strlen($wert) > $maximal) {
            throw new EingabeException("Das Feld „" . $feld . "“ ist zu lang.");
        }
        $werte[$feld] = $wert === "" ? null : $wert;
    }
    if ($werte["geschaeftsname"] === null) {
        throw new EingabeException("Der Geschäftsname ist erforderlich.");
    }
    if ($werte["email"] !== null && filter_var($werte["email"], FILTER_VALIDATE_EMAIL) === false) {
        throw new EingabeException("Bitte geben Sie eine gültige geschäftliche E-Mail-Adresse ein.");
    }

    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        "UPDATE geschaeftskonfiguration SET
         geschaeftsname = ?, inhaber = ?, strasse = ?, plz = ?, ort = ?, telefon = ?, email = ?,
         steuernummer = ?, ust_id = ?, bankverbindung = ?, zahlungsziel = ?, rechnungshinweise = ?
         WHERE id = 1"
    );
    $stmt->execute([
        $werte["geschaeftsname"], $werte["inhaber"], $werte["strasse"], $werte["plz"], $werte["ort"],
        $werte["telefon"], $werte["email"], $werte["steuernummer"], $werte["ust_id"],
        $werte["bankverbindung"], $werte["zahlungsziel"], $werte["rechnungshinweise"],
    ]);
    $pdo->commit();
    flash_setzen("success", "Die Rechnungsdaten wurden gespeichert.");
} catch (EingabeException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash_setzen("error", $e->getMessage());
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log($e->getMessage());
    flash_setzen("error", "Die Rechnungsdaten konnten nicht gespeichert werden. Bitte versuchen Sie es erneut.");
}

weiterleiten("einstellungen.php");
