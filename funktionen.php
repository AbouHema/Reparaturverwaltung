<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $serverPort = (string) ($_SERVER["SERVER_PORT"] ?? "web");
    $direktesHttps = !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off";
    $httpsAktiv = $direktesHttps || (PHP_SAPI !== "cli-server" && getenv("APP_HTTPS") === "1");
    $standardSitzungsname = "REPARATURSESSID" . strtoupper(substr(hash("sha256", __DIR__ . "|" . $serverPort), 0, 8));
    $konfigurierterSitzungsname = trim((string) getenv("SESSION_COOKIE_NAME"));
    $sitzungsname = $konfigurierterSitzungsname !== ""
        ? $konfigurierterSitzungsname
        : $standardSitzungsname;
    if (preg_match('/^[A-Za-z][A-Za-z0-9]{7,63}$/D', $sitzungsname) !== 1) {
        error_log("SESSION_COOKIE_NAME ist ungültig; der sichere Standardname wird verwendet.");
        $sitzungsname = $standardSitzungsname;
    }
    $konfigurierterSitzungspfad = trim((string) getenv("SESSION_SAVE_PATH"));
    $sitzungspfad = $konfigurierterSitzungspfad !== ""
        ? $konfigurierterSitzungspfad
        : sys_get_temp_dir() . "/reparaturverwaltung-sessions-" . substr(hash("sha256", __DIR__ . "|" . $serverPort), 0, 12);
    if (str_contains($sitzungspfad, "\0") || str_contains($sitzungspfad, ";")) {
        error_log("SESSION_SAVE_PATH ist ungültig.");
        throw new RuntimeException("Der Sitzungsspeicher ist ungültig konfiguriert.");
    }
    if (!is_dir($sitzungspfad) && !mkdir($sitzungspfad, 0700, true) && !is_dir($sitzungspfad)) {
        error_log("Das Sitzungsverzeichnis konnte nicht erstellt werden.");
        throw new RuntimeException("Der Sitzungsspeicher konnte nicht vorbereitet werden.");
    }
    if (!is_writable($sitzungspfad)) {
        error_log("Das Sitzungsverzeichnis ist nicht beschreibbar.");
        throw new RuntimeException("Der Sitzungsspeicher ist nicht beschreibbar.");
    }
    ini_set("session.save_path", $sitzungspfad);
    session_name($sitzungsname);
    ini_set("session.use_strict_mode", "1");
    ini_set("session.use_only_cookies", "1");
    ini_set("session.gc_maxlifetime", "7200");
    session_set_cookie_params([
        "lifetime" => 0,
        "path" => "/",
        "secure" => $httpsAktiv,
        "httponly" => true,
        "samesite" => "Lax",
    ]);
    if (!session_start()) {
        error_log("Die PHP-Sitzung konnte nicht gestartet werden.");
        throw new RuntimeException("Die Sitzung konnte momentan nicht gestartet werden.");
    }
}

if (PHP_SAPI !== "cli" && !headers_sent()) {
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    header("Referrer-Policy: same-origin");
    header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    $antwortIstHttps = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off")
        || (PHP_SAPI !== "cli-server" && getenv("APP_HTTPS") === "1");
    if ($antwortIstHttps) {
        header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
    }
}

final class EingabeException extends RuntimeException
{
}

final class LetztesGeraetException extends RuntimeException
{
}

function h(mixed $wert): string
{
    return htmlspecialchars((string) $wert, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}

function csrf_token(): string
{
    if (
        !isset($_SESSION["csrf_token"])
        || !is_string($_SESSION["csrf_token"])
        || preg_match('/^[a-f0-9]{64}$/D', $_SESSION["csrf_token"]) !== 1
    ) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }

    return $_SESSION["csrf_token"];
}

function csrf_feld(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function csrf_token_erneuern(): string
{
    $vorherige = is_array($_SESSION["csrf_vorherige_tokens"] ?? null) ? $_SESSION["csrf_vorherige_tokens"] : [];
    if (isset($_SESSION["csrf_token"]) && is_string($_SESSION["csrf_token"])) {
        $vorherige[] = ["token" => $_SESSION["csrf_token"], "gueltig_bis" => time() + 7200];
    }
    $vorherige = array_values(array_filter($vorherige, static fn(mixed $eintrag): bool =>
        is_array($eintrag) && isset($eintrag["token"], $eintrag["gueltig_bis"])
        && is_string($eintrag["token"]) && (int) $eintrag["gueltig_bis"] >= time()
    ));
    $_SESSION["csrf_vorherige_tokens"] = array_slice($vorherige, -5);
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    return $_SESSION["csrf_token"];
}

function csrf_pruefen(mixed $token): void
{
    $gueltig = is_string($token) && hash_equals(csrf_token(), $token);
    if (!$gueltig && is_string($token)) {
        $vorherige = is_array($_SESSION["csrf_vorherige_tokens"] ?? null) ? $_SESSION["csrf_vorherige_tokens"] : [];
        foreach ($vorherige as $eintrag) {
            if (is_array($eintrag) && (int) ($eintrag["gueltig_bis"] ?? 0) >= time()
                && is_string($eintrag["token"] ?? null) && hash_equals($eintrag["token"], $token)) {
                $gueltig = true;
                break;
            }
        }
    }
    if (!$gueltig) {
        error_log("CSRF-Prüfung fehlgeschlagen: Sitzungscookie=" . (isset($_COOKIE[session_name()]) ? "vorhanden" : "fehlt")
            . ", Formularwert=" . (is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token) === 1 ? "formal_gueltig" : "ungueltig")
            . ", Serverport=" . (ctype_digit((string) ($_SERVER["SERVER_PORT"] ?? "")) ? (string) $_SERVER["SERVER_PORT"] : "unbekannt"));
        throw new EingabeException("Ihre Sitzung ist abgelaufen. Bitte versuchen Sie es erneut.");
    }
}

function flash_setzen(string $typ, string $nachricht): void
{
    $_SESSION["flash"] = ["typ" => $typ, "nachricht" => $nachricht];
}

function flash_holen(): ?array
{
    $flash = $_SESSION["flash"] ?? null;
    unset($_SESSION["flash"]);

    return is_array($flash) ? $flash : null;
}

function aktuelle_benutzerrolle(): string
{
    if (PHP_SAPI === "cli") return "administrator";
    $rolle = $_SESSION["benutzer_rolle"] ?? "leser";
    return is_string($rolle) ? $rolle : "leser";
}

function aktuelle_benutzer_id(): ?int
{
    $id = $_SESSION["benutzer_id"] ?? null;
    return is_int($id) || ctype_digit((string) $id) ? (int) $id : null;
}

function ist_angemeldet(): bool
{
    return aktuelle_benutzer_id() !== null && in_array(aktuelle_benutzerrolle(), ["administrator", "mitarbeiter"], true);
}

function sitzung_beenden(): void
{
    $_SESSION = [];
    if ((bool) ini_get("session.use_cookies")) {
        $parameter = session_get_cookie_params();
        setcookie(session_name(), "", [
            "expires" => time() - 42000,
            "path" => $parameter["path"],
            "domain" => $parameter["domain"],
            "secure" => $parameter["secure"],
            "httponly" => $parameter["httponly"],
            "samesite" => $parameter["samesite"] ?? "Lax",
        ]);
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
}

function berechtigung_pruefen(array $rollen): void
{
    if (!ist_angemeldet() || !in_array(aktuelle_benutzerrolle(), $rollen, true)) {
        throw new EingabeException("Sie sind für diese Aktion nicht berechtigt.");
    }
}

function audit_protokollieren(PDO $pdo, string $ereignis, ?string $objekttyp = null, string|int|null $objektId = null, ?array $details = null, ?int $benutzerId = null): void
{
    $ip = (string) ($_SERVER["REMOTE_ADDR"] ?? "cli");
    $stmt = $pdo->prepare("INSERT INTO audit_protokoll (benutzer_id,ereignis,objekttyp,objekt_id,details,ip_hash) VALUES (?,?,?,?,?,?)");
    $stmt->execute([$benutzerId ?? aktuelle_benutzer_id(), $ereignis, $objekttyp, $objektId === null ? null : (string) $objektId,
        $details === null ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), hash("sha256", $ip)]);
}

function anmeldung_erforderlich(): void
{
    if (PHP_SAPI === "cli" || defined("OEFFENTLICHE_SEITE")) return;
    global $pdo;
    $letzteAktivitaet = (int) ($_SESSION["letzte_aktivitaet"] ?? 0);
    $benutzer = null;
    $id = aktuelle_benutzer_id();
    if ($id !== null && isset($pdo) && $pdo instanceof PDO) {
        $stmt = $pdo->prepare("SELECT id,benutzername,rolle,aktiv,kontostatus,sitzung_version FROM benutzer WHERE id=? LIMIT 1");
        $stmt->execute([$id]);
        $benutzer = $stmt->fetch();
    }
    $version = (int) ($_SESSION["sitzung_version"] ?? -1);
    $gueltig = is_array($benutzer)
        && (int) $benutzer["aktiv"] === 1
        && $benutzer["kontostatus"] === "aktiv"
        && in_array($benutzer["rolle"], ["administrator", "mitarbeiter"], true)
        && (int) $benutzer["sitzung_version"] === $version;
    if (!$gueltig || ($letzteAktivitaet > 0 && time() - $letzteAktivitaet > 7200)) {
        sitzung_beenden();
        $weiter = rawurlencode((string) ($_SERVER["REQUEST_URI"] ?? "auftraege.php"));
        header("Location: login.php?weiter=" . $weiter, true, 303);
        exit;
    }
    $_SESSION["benutzername"] = $benutzer["benutzername"];
    $_SESSION["benutzer_rolle"] = $benutzer["rolle"];
    $_SESSION["letzte_aktivitaet"] = time();
}

function darf_loeschen(): bool
{
    return aktuelle_benutzerrolle() === "administrator";
}

function loeschberechtigung_pruefen(): void
{
    if (!darf_loeschen()) {
        throw new EingabeException("Sie sind nicht berechtigt, Reparaturaufträge oder Geräte zu löschen.");
    }
}

function positive_id(mixed $wert, string $feldname): int
{
    if (filter_var($wert, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]) === false) {
        throw new EingabeException($feldname . " ist ungültig.");
    }

    return (int) $wert;
}

function betrag_normalisieren(mixed $wert, string $feldname = "Betrag", bool $pflichtfeld = false): ?string
{
    if ($wert === null || trim((string) $wert) === "") {
        if ($pflichtfeld) {
            throw new EingabeException($feldname . " ist erforderlich.");
        }
        return null;
    }

    $normalisiert = str_replace(",", ".", trim((string) $wert));
    if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $normalisiert)) {
        throw new EingabeException($feldname . " muss eine Zahl ab 0 mit höchstens zwei Nachkommastellen sein.");
    }

    [$euro, $cent] = array_pad(explode(".", $normalisiert, 2), 2, "");
    $euro = ltrim($euro, "0");
    $euro = $euro === "" ? "0" : $euro;
    $cent = str_pad($cent, 2, "0");

    return $euro . "." . $cent;
}

function betrag_in_cent(string $betrag): int
{
    [$euro, $cent] = explode(".", $betrag, 2);
    return ((int) $euro * 100) + (int) $cent;
}

function cent_als_betrag(int $cent): string
{
    return intdiv($cent, 100) . "." . str_pad((string) ($cent % 100), 2, "0", STR_PAD_LEFT);
}

function zahlung_validieren(array $geraete, mixed $bereitsBezahlt): string
{
    $bezahlt = betrag_normalisieren($bereitsBezahlt, "Der bereits bezahlte Betrag") ?? "0.00";
    $gesamtCent = 0;
    foreach ($geraete as $geraet) {
        $gesamtCent += betrag_in_cent((string) $geraet["preis"]);
    }

    if (betrag_in_cent($bezahlt) > $gesamtCent) {
        throw new EingabeException("Der bereits bezahlte Betrag darf den Gesamtbetrag des Auftrags nicht überschreiten.");
    }

    return $bezahlt;
}

function zahlungszuordnung_validieren(array $geraete, mixed $nichtZugeordnet): array
{
    $nichtZugeordnet = betrag_normalisieren($nichtZugeordnet, "Die nicht zugeordnete Zahlung") ?? "0.00";
    $gesamtCent = 0;
    $zugeordnetCent = 0;

    foreach ($geraete as $index => $geraet) {
        $preis = (string) $geraet["preis"];
        $bezahlt = (string) ($geraet["bereits_bezahlt"] ?? "0.00");
        $preisCent = betrag_in_cent($preis);
        $bezahltCent = betrag_in_cent($bezahlt);
        if ($bezahltCent > $preisCent) {
            throw new EingabeException("Die Zahlung für Gerät " . ($index + 1) . " darf dessen Preis nicht überschreiten.");
        }
        $gesamtCent += $preisCent;
        $zugeordnetCent += $bezahltCent;
    }

    $gesamtBezahltCent = $zugeordnetCent + betrag_in_cent($nichtZugeordnet);
    if ($gesamtBezahltCent > $gesamtCent) {
        throw new EingabeException("Die Summe aller Zahlungen darf den Gesamtbetrag des Auftrags nicht überschreiten.");
    }

    return [
        "gesamt" => cent_als_betrag($gesamtBezahltCent),
        "nicht_zugeordnet" => $nichtZugeordnet,
    ];
}

function geraete_referenz(int $auftragId, int $geraetId): string
{
    return "#" . $auftragId . "-G" . $geraetId;
}

function status_ids_laden(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT id, bezeichnung FROM status ORDER BY id");
    $status = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($status === []) {
        throw new RuntimeException("Es sind keine Reparaturstatus eingerichtet.");
    }

    return $status;
}

function status_id_nach_bezeichnung(array $status, string $bezeichnung): int
{
    foreach ($status as $eintrag) {
        if (isset($eintrag["id"], $eintrag["bezeichnung"]) && $eintrag["bezeichnung"] === $bezeichnung) {
            return (int) $eintrag["id"];
        }
    }

    throw new RuntimeException('Der erforderliche Reparaturstatus „' . $bezeichnung . '“ ist nicht eingerichtet.');
}

function stammdaten_validieren(
    mixed $name,
    mixed $telefon,
    mixed $firmenname = null,
    mixed $email = null,
    mixed $strasse = null,
    mixed $plz = null,
    mixed $ort = null,
    mixed $land = null
): array
{
    $name = trim((string) $name);
    $telefon = trim((string) $telefon);
    $firmenname = trim((string) $firmenname);
    $email = trim((string) $email);
    $strasse = trim((string) $strasse);
    $plz = trim((string) $plz);
    $ort = trim((string) $ort);
    $land = trim((string) $land);

    if ($name === "") {
        throw new EingabeException("Der Kundenname ist erforderlich.");
    }
    if (mb_strlen($name) > 100) {
        throw new EingabeException("Der Kundenname darf höchstens 100 Zeichen lang sein.");
    }
    if (mb_strlen($firmenname) > 150) {
        throw new EingabeException("Der Firmenname darf höchstens 150 Zeichen lang sein.");
    }
    if (mb_strlen($telefon) > 30) {
        throw new EingabeException("Die Telefonnummer darf höchstens 30 Zeichen lang sein.");
    }
    if ($telefon !== "" && (!preg_match('/^[0-9+()\s\-\/]+$/u', $telefon) || !preg_match('/\d/', $telefon))) {
        throw new EingabeException("Die Telefonnummer enthält ungültige Zeichen.");
    }
    if (mb_strlen($email) > 254) {
        throw new EingabeException("Die E-Mail-Adresse darf höchstens 254 Zeichen lang sein.");
    }
    if ($email !== "" && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new EingabeException("Bitte geben Sie eine gültige E-Mail-Adresse ein.");
    }
    if (mb_strlen($strasse) > 200 || mb_strlen($plz) > 20 || mb_strlen($ort) > 100 || mb_strlen($land) > 100) {
        throw new EingabeException("Ein Bestandteil der Adresse ist zu lang.");
    }

    return [
        $name,
        $telefon === "" ? null : $telefon,
        $firmenname === "" ? null : $firmenname,
        $email === "" ? null : $email,
        $strasse === "" ? null : $strasse,
        $plz === "" ? null : $plz,
        $ort === "" ? null : $ort,
        $land === "" ? null : $land,
    ];
}

function adresse_zeilen(array $daten, string $prefix = ""): array
{
    $strasse = trim((string) ($daten[$prefix . "strasse"] ?? ""));
    $plz = trim((string) ($daten[$prefix . "plz"] ?? ""));
    $ort = trim((string) ($daten[$prefix . "ort"] ?? ""));
    $land = trim((string) ($daten[$prefix . "land"] ?? ""));
    $zeilen = [];
    if ($strasse !== "") $zeilen[] = $strasse;
    $ortZeile = trim($plz . " " . $ort);
    if ($ortZeile !== "") $zeilen[] = $ortZeile;
    if ($land !== "") $zeilen[] = $land;
    return $zeilen;
}

function zahlungsstatus_fuer_betraege(mixed $preis, mixed $bezahlt): string
{
    $preisCent = betrag_in_cent(betrag_normalisieren($preis, "Preis", true));
    $bezahltCent = betrag_in_cent(betrag_normalisieren($bezahlt, "Zahlung", true));
    if ($bezahltCent <= 0) return "Offen";
    return $bezahltCent >= $preisCent ? "Bezahlt" : "Teilweise bezahlt";
}

function geraete_validieren(mixed $eingaben, array $status, ?int $erzwungenerStatusId = null): array
{
    if (!is_array($eingaben) || $eingaben === []) {
        throw new EingabeException("Ein Reparaturauftrag muss mindestens ein Gerät enthalten.");
    }
    if (count($eingaben) > 100) {
        throw new EingabeException("Pro Auftrag können höchstens 100 Geräte auf einmal gespeichert werden.");
    }

    $gueltigeStatusIds = array_map(static fn(array $eintrag): int => (int) $eintrag["id"], $status);
    if ($erzwungenerStatusId !== null && !in_array($erzwungenerStatusId, $gueltigeStatusIds, true)) {
        throw new RuntimeException("Der vorgegebene Reparaturstatus ist nicht eingerichtet.");
    }
    $geraete = [];
    $verwendeteIds = [];

    foreach (array_values($eingaben) as $index => $eingabe) {
        if (!is_array($eingabe)) {
            throw new EingabeException("Die Daten für Gerät " . ($index + 1) . " sind ungültig.");
        }

        $nummer = $index + 1;
        $geraetId = null;
        if (($eingabe["id"] ?? "") !== "") {
            $geraetId = positive_id($eingabe["id"], "Die Geräte-ID");
            if (isset($verwendeteIds[$geraetId])) {
                throw new EingabeException("Ein Gerät wurde mehrfach übermittelt.");
            }
            $verwendeteIds[$geraetId] = true;
        }

        $geraetetyp = trim((string) ($eingabe["geraetetyp"] ?? ""));
        $hersteller = trim((string) ($eingabe["hersteller"] ?? ""));
        $modell = trim((string) ($eingabe["modell"] ?? ""));
        $seriennummer = trim((string) ($eingabe["seriennummer"] ?? ""));
        $fehlerbeschreibung = trim((string) ($eingabe["fehlerbeschreibung"] ?? ""));
        $durchgefuehrteArbeiten = trim((string) ($eingabe["durchgefuehrte_arbeiten"] ?? ""));
        $statusEingabe = $eingabe["status_id"] ?? "";
        $statusId = $erzwungenerStatusId;
        if ($statusId === null && trim((string) $statusEingabe) !== "") {
            $statusId = positive_id($statusEingabe, "Der Status von Gerät " . $nummer);
        }

        if ($geraetetyp === "" || $fehlerbeschreibung === "") {
            throw new EingabeException("Gerätetyp und Fehlerbeschreibung sind für Gerät " . $nummer . " erforderlich.");
        }
        if (mb_strlen($geraetetyp) > 50) {
            throw new EingabeException("Der Gerätetyp von Gerät " . $nummer . " darf höchstens 50 Zeichen lang sein.");
        }
        if (mb_strlen($hersteller) > 100 || mb_strlen($modell) > 100 || mb_strlen($seriennummer) > 100) {
            throw new EingabeException("Hersteller, Modell oder Seriennummer von Gerät " . $nummer . " ist zu lang.");
        }
        if (mb_strlen($durchgefuehrteArbeiten) > 5000) {
            throw new EingabeException("Die durchgeführten Arbeiten von Gerät " . $nummer . " dürfen höchstens 5.000 Zeichen lang sein.");
        }
        if ($statusId !== null && !in_array($statusId, $gueltigeStatusIds, true)) {
            throw new EingabeException("Der Status von Gerät " . $nummer . " ist ungültig.");
        }

        $preis = betrag_normalisieren($eingabe["preis"] ?? null, "Der Preis von Gerät " . $nummer, true);
        $bezahlt = betrag_normalisieren(
            $eingabe["bereits_bezahlt"] ?? null,
            "Die Zahlung für Gerät " . $nummer
        ) ?? "0.00";
        if (betrag_in_cent($bezahlt) > betrag_in_cent($preis)) {
            throw new EingabeException("Die Zahlung für Gerät " . $nummer . " darf dessen Preis nicht überschreiten.");
        }

        $geraete[] = [
            "id" => $geraetId,
            "geraetetyp" => $geraetetyp,
            "hersteller" => $hersteller === "" ? null : $hersteller,
            "modell" => $modell === "" ? null : $modell,
            "seriennummer" => $seriennummer === "" ? null : $seriennummer,
            "fehlerbeschreibung" => $fehlerbeschreibung,
            "durchgefuehrte_arbeiten" => $durchgefuehrteArbeiten === "" ? null : $durchgefuehrteArbeiten,
            "preis" => $preis,
            "bereits_bezahlt" => $bezahlt,
            "status_id" => $statusId,
        ];
    }

    return $geraete;
}

function status_klasse(string $bezeichnung): string
{
    return match ($bezeichnung) {
        "In Bearbeitung" => "status--progress",
        "Fertig" => "status--done",
        default => "status--accepted",
    };
}

function betrag_anzeigen(mixed $betrag): string
{
    if ($betrag === null || $betrag === "") {
        return "Noch offen";
    }

    $normalisiert = betrag_normalisieren($betrag) ?? "0.00";
    [$euro, $cent] = explode(".", $normalisiert, 2);
    return number_format((int) $euro, 0, ",", ".") . "," . $cent . " €";
}

function weiterleiten(string $ziel): never
{
    header("Location: " . $ziel);
    exit;
}

anmeldung_erforderlich();
