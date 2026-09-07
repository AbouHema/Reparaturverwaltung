<?php

declare(strict_types=1);

require_once __DIR__ . "/funktionen.php";

function benutzername_validieren(mixed $wert): string
{
    $name = trim((string) $wert);
    if (preg_match('/^[\pL\pN_.-]{3,100}$/u', $name) !== 1) {
        throw new EingabeException("Der Benutzername muss 3 bis 100 Zeichen enthalten. Erlaubt sind Buchstaben, Zahlen, Punkt, Bindestrich und Unterstrich.");
    }
    return $name;
}

function konto_email_validieren(mixed $wert): string
{
    $email = mb_strtolower(trim((string) $wert));
    if (mb_strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new EingabeException("Bitte geben Sie eine gültige E-Mail-Adresse ein.");
    }
    return $email;
}

function neues_passwort_validieren(mixed $passwort, mixed $wiederholung): string
{
    $passwort = (string) $passwort;
    if (mb_strlen($passwort) < 8) throw new EingabeException("Das Passwort muss mindestens 8 Zeichen enthalten.");
    if (!hash_equals($passwort, (string) $wiederholung)) throw new EingabeException("Die Passwörter stimmen nicht überein.");
    return $passwort;
}

function konto_duplikat_pruefen(PDO $pdo, string $name, string $email, bool $sperren = false): void
{
    $stmt = $pdo->prepare("SELECT benutzername,email FROM benutzer WHERE benutzername=? OR email=?" . ($sperren ? " FOR UPDATE" : ""));
    $stmt->execute([$name, $email]);
    foreach ($stmt->fetchAll() as $konto) {
        if ((string) $konto["benutzername"] === $name) throw new EingabeException("Dieser Benutzername wird bereits verwendet.");
        if (mb_strtolower((string) ($konto["email"] ?? "")) === $email) throw new EingabeException("Für diese E-Mail-Adresse besteht bereits ein Konto.");
    }
}

function konto_registrieren(PDO $pdo, mixed $name, mixed $email, mixed $passwort, mixed $wiederholung): array
{
    $name = benutzername_validieren($name);
    $email = konto_email_validieren($email);
    $passwort = neues_passwort_validieren($passwort, $wiederholung);
    try {
        $pdo->beginTransaction();
        $einstellung = $pdo->query("SELECT selbstregistrierung_aktiv FROM anwendungseinstellungen WHERE id=1 FOR UPDATE")->fetch();
        if (!is_array($einstellung)) throw new RuntimeException("Die zentrale Anwendungseinstellung fehlt.");
        $anzahl = (int) $pdo->query("SELECT COUNT(*) FROM benutzer FOR UPDATE")->fetchColumn();
        $erstesKonto = $anzahl === 0;
        if (!$erstesKonto && (int) ($einstellung["selbstregistrierung_aktiv"] ?? 0) !== 1) {
            throw new EingabeException("Die Kontoerstellung ist derzeit nicht verfügbar. Bitte wenden Sie sich an einen Administrator.");
        }
        konto_duplikat_pruefen($pdo, $name, $email, true);
        $rolle = $erstesKonto ? "administrator" : "mitarbeiter";
        $status = $erstesKonto ? "aktiv" : "wartend";
        $aktiv = $erstesKonto ? 1 : 0;
        $stmt = $pdo->prepare("INSERT INTO benutzer (benutzername,email,passwort_hash,rolle,aktiv,kontostatus,freigegeben_am) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([$name, $email, password_hash($passwort, PASSWORD_DEFAULT), $rolle, $aktiv, $status, $erstesKonto ? date("Y-m-d H:i:s") : null]);
        $id = (int) $pdo->lastInsertId();
        audit_protokollieren($pdo, "konto_registriert", "benutzer", $id, ["rolle" => $rolle, "status" => $status], $id);
        $pdo->commit();
        return ["id" => $id, "rolle" => $rolle, "kontostatus" => $status, "erstes_konto" => $erstesKonto];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof EingabeException) throw $e;
        if ($e instanceof PDOException && $e->getCode() === "23000") {
            konto_duplikat_pruefen($pdo, $name, $email);
            throw new EingabeException("Das Konto konnte wegen eines bereits verwendeten Werts nicht erstellt werden.");
        }
        throw $e;
    }
}

function benutzer_fuer_anmeldung(PDO $pdo, string $kennung): ?array
{
    $stmt = $pdo->prepare("SELECT id,benutzername,email,passwort_hash,rolle,aktiv,kontostatus,sitzung_version,fehlversuche,gesperrt_bis FROM benutzer WHERE benutzername=? OR email=? LIMIT 1");
    $stmt->execute([trim($kennung), mb_strtolower(trim($kennung))]);
    $benutzer = $stmt->fetch();
    return is_array($benutzer) ? $benutzer : null;
}

function smtp_konfiguration(): array
{
    $port = filter_var(getenv("SMTP_PORT") ?: "587", FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => 65535]]) ?: 587;
    $werte = [
        "host" => trim((string) getenv("SMTP_HOST")), "port" => (int) $port,
        "benutzer" => (string) getenv("SMTP_USER"), "passwort" => (string) getenv("SMTP_PASSWORD"),
        "verschluesselung" => strtolower(trim((string) (getenv("SMTP_ENCRYPTION") ?: "tls"))),
        "absender" => trim((string) getenv("SMTP_FROM")), "basis_url" => rtrim(trim((string) getenv("APP_BASE_URL")), "/"),
    ];
    $werte["konfiguriert"] = $werte["host"] !== "" && $werte["absender"] !== "" && $werte["basis_url"] !== ""
        && in_array($werte["verschluesselung"], ["tls", "ssl", "none"], true);
    return $werte;
}

function smtp_antwort($socket, array $erlaubt): string
{
    $antwort = "";
    do {
        $zeile = fgets($socket, 1000);
        if ($zeile === false) throw new RuntimeException("Keine Antwort vom E-Mail-Server.");
        $antwort .= $zeile;
    } while (isset($zeile[3]) && $zeile[3] === "-");
    if (!in_array((int) substr($antwort, 0, 3), $erlaubt, true)) throw new RuntimeException("Der E-Mail-Server hat die Nachricht abgelehnt.");
    return $antwort;
}

function smtp_befehl($socket, string $befehl, array $erlaubt): void
{
    if (fwrite($socket, $befehl . "\r\n") === false) throw new RuntimeException("E-Mail-Befehl konnte nicht gesendet werden.");
    smtp_antwort($socket, $erlaubt);
}

function reset_mail_senden(string $empfaenger, string $token): bool
{
    $cfg = smtp_konfiguration();
    if (!$cfg["konfiguriert"]) return false;
    $ziel = ($cfg["verschluesselung"] === "ssl" ? "ssl://" : "tcp://") . $cfg["host"] . ":" . $cfg["port"];
    $socket = @stream_socket_client($ziel, $nummer, $meldung, 10, STREAM_CLIENT_CONNECT);
    if (!is_resource($socket)) throw new RuntimeException("Verbindung zum E-Mail-Server fehlgeschlagen.");
    stream_set_timeout($socket, 10);
    smtp_antwort($socket, [220]);
    smtp_befehl($socket, "EHLO reparaturverwaltung", [250]);
    if ($cfg["verschluesselung"] === "tls") {
        smtp_befehl($socket, "STARTTLS", [220]);
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException("TLS für den E-Mail-Versand konnte nicht aktiviert werden.");
        smtp_befehl($socket, "EHLO reparaturverwaltung", [250]);
    }
    if ($cfg["benutzer"] !== "") {
        smtp_befehl($socket, "AUTH LOGIN", [334]);
        smtp_befehl($socket, base64_encode($cfg["benutzer"]), [334]);
        smtp_befehl($socket, base64_encode($cfg["passwort"]), [235]);
    }
    $link = $cfg["basis_url"] . "/passwort_zuruecksetzen.php?token=" . rawurlencode($token);
    $betreff = "Passwort für die Reparaturverwaltung zurücksetzen";
    $text = "Öffnen Sie innerhalb von 30 Minuten diesen Link, um Ihr Passwort zurückzusetzen:\r\n\r\n" . $link . "\r\n\r\nFalls Sie dies nicht angefordert haben, ignorieren Sie diese Nachricht.";
    smtp_befehl($socket, "MAIL FROM:<" . $cfg["absender"] . ">", [250]);
    smtp_befehl($socket, "RCPT TO:<" . $empfaenger . ">", [250, 251]);
    smtp_befehl($socket, "DATA", [354]);
    $kopf = "From: " . $cfg["absender"] . "\r\nTo: " . $empfaenger . "\r\nSubject: " . $betreff . "\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n";
    fwrite($socket, str_replace("\n.", "\n..", $kopf . $text) . "\r\n.\r\n");
    smtp_antwort($socket, [250]);
    smtp_befehl($socket, "QUIT", [221]);
    fclose($socket);
    return true;
}

function reset_anfrage_erstellen(PDO $pdo, string $email, string $ip): ?array
{
    $email = mb_strtolower(trim($email));
    $schluessel = hash("sha256", $email . "|" . $ip);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT anzahl,fenster_start FROM passwort_reset_limits WHERE schluessel_hash=? FOR UPDATE");
        $stmt->execute([$schluessel]); $limit = $stmt->fetch();
        $zuViele = false;
        if (!$limit || strtotime($limit["fenster_start"]) < time() - 3600) {
            $pdo->prepare("INSERT INTO passwort_reset_limits (schluessel_hash,anzahl,fenster_start) VALUES (?,1,NOW()) ON DUPLICATE KEY UPDATE anzahl=1,fenster_start=NOW()")->execute([$schluessel]);
        } else {
            $zuViele = (int) $limit["anzahl"] >= 5;
            if (!$zuViele) $pdo->prepare("UPDATE passwort_reset_limits SET anzahl=anzahl+1 WHERE schluessel_hash=?")->execute([$schluessel]);
        }
        $benutzer = null;
        if (!$zuViele && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $stmt = $pdo->prepare("SELECT id,email FROM benutzer WHERE email=? AND aktiv=1 AND kontostatus='aktiv' LIMIT 1");
            $stmt->execute([$email]); $benutzer = $stmt->fetch();
        }
        $resultat = null;
        if (is_array($benutzer)) {
            $pdo->prepare("UPDATE passwort_reset_tokens SET verwendet_am=NOW() WHERE benutzer_id=? AND verwendet_am IS NULL")->execute([$benutzer["id"]]);
            $token = bin2hex(random_bytes(32));
            $pdo->prepare("INSERT INTO passwort_reset_tokens (benutzer_id,token_hash,gueltig_bis) VALUES (?, ?, DATE_ADD(NOW(),INTERVAL 30 MINUTE))")
                ->execute([$benutzer["id"], hash("sha256", $token)]);
            $resultat = ["email" => $benutzer["email"], "token" => $token];
        }
        $pdo->commit();
        return $resultat;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function reset_token_laden(PDO $pdo, string $token, bool $sperren = false): ?array
{
    if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) return null;
    $sql = "SELECT prt.id,prt.benutzer_id,prt.gueltig_bis,prt.verwendet_am,b.aktiv,b.kontostatus FROM passwort_reset_tokens prt INNER JOIN benutzer b ON b.id=prt.benutzer_id WHERE prt.token_hash=? AND prt.gueltig_bis>=NOW() LIMIT 1" . ($sperren ? " FOR UPDATE" : "");
    $stmt = $pdo->prepare($sql); $stmt->execute([hash("sha256", $token)]); $zeile = $stmt->fetch();
    if (!is_array($zeile) || $zeile["verwendet_am"] !== null || (int)$zeile["aktiv"] !== 1 || $zeile["kontostatus"] !== "aktiv") return null;
    return $zeile;
}

function passwort_mit_token_aendern(PDO $pdo, string $token, mixed $passwort, mixed $wiederholung): int
{
    $passwort = neues_passwort_validieren($passwort, $wiederholung);
    $pdo->beginTransaction();
    try {
        $reset = reset_token_laden($pdo, $token, true);
        if ($reset === null) throw new EingabeException("Dieser Link ist ungültig oder abgelaufen.");
        $id = (int) $reset["benutzer_id"];
        $pdo->prepare("UPDATE benutzer SET passwort_hash=?,sitzung_version=sitzung_version+1,fehlversuche=0,gesperrt_bis=NULL WHERE id=?")
            ->execute([password_hash($passwort, PASSWORD_DEFAULT), $id]);
        $pdo->prepare("UPDATE passwort_reset_tokens SET verwendet_am=NOW() WHERE benutzer_id=? AND verwendet_am IS NULL")->execute([$id]);
        audit_protokollieren($pdo, "passwort_zurueckgesetzt", "benutzer", $id, ["weg" => "reset_link"], $id);
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
