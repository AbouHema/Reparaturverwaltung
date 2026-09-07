<?php

declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/funktionen.php";
try { berechtigung_pruefen(["administrator"]); } catch (EingabeException $e) { http_response_code(403); die(h($e->getMessage())); }
require_once __DIR__ . "/rechnung_funktionen.php";

$daten = geschaeftsdaten_laden($pdo);
$fehlend = fehlende_rechnungsangaben($daten);
$flash = flash_holen();

$felder = [
    "geschaeftsname" => ["Geschäftsname", 150, "text"],
    "inhaber" => ["Inhaber / vollständiger Unternehmensname", 200, "text"],
    "strasse" => ["Straße und Hausnummer", 200, "text"],
    "plz" => ["Postleitzahl", 20, "text"],
    "ort" => ["Ort", 100, "text"],
    "telefon" => ["Telefonnummer", 50, "tel"],
    "email" => ["E-Mail-Adresse", 254, "email"],
    "steuernummer" => ["Steuernummer", 100, "text"],
    "ust_id" => ["Umsatzsteuer-Identifikationsnummer", 100, "text"],
];
?>
<!DOCTYPE html>
<html lang="de">
<head><link rel="icon" href="favicon.svg" type="image/svg+xml">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rechnungsdaten – Computerfachmann</title>
    <link rel="stylesheet" href="styles.css">
    <script src="app.js" defer></script>
</head>
<body>
<a class="skip-link" href="#main-content">Zum Inhalt springen</a>
<div class="app-shell">
    <header class="app-header">
        <a class="brand" href="index.php"><span class="brand-mark" aria-hidden="true">CF</span><span>Computerfachmann</span></a>
        <nav class="primary-nav" aria-label="Hauptnavigation">
            <a class="nav-link" href="auftraege.php">Reparaturaufträge</a>
            <a class="nav-link" href="index.php">Neuer Auftrag</a>
            <a class="nav-link" href="einstellungen.php" aria-current="page">Rechnungsdaten</a>
        </nav>
    </header>
    <main id="main-content">
        <?php if ($flash): ?><div class="notice notice--<?= h($flash["typ"]) ?>" role="status"><?= h($flash["nachricht"]) ?></div><?php endif; ?>
        <div class="page-heading form-card"><div><p class="eyebrow">Zentrale Konfiguration</p><h1>Geschäfts- und Rechnungsdaten</h1><p class="page-description">Diese Daten werden beim verbindlichen Erstellen als unveränderlicher Rechnungssnapshot gespeichert.</p></div></div>
        <?php if ($fehlend !== []): ?>
            <div class="notice notice--warning" role="status"><strong>Noch einzutragen:</strong> <?= h(implode(", ", $fehlend)) ?>. Bis dahin sind nur deutlich markierte Rechnungsentwürfe möglich.</div>
        <?php else: ?>
            <div class="notice notice--success" role="status">Alle Pflichtangaben für endgültige Rechnungen sind gepflegt.</div>
        <?php endif; ?>
        <form class="glass-panel form-card form-layout" method="post" action="einstellungen_speichern.php" data-validated-form novalidate>
            <?= csrf_feld() ?>
            <section class="form-section">
                <div class="section-heading"><span class="section-icon" aria-hidden="true">§</span><div><h2>Absender und Steuerdaten</h2><p class="section-description">Felder mit dem Hinweis „für finale Rechnung erforderlich“ müssen vor der verbindlichen Erstellung gefüllt sein.</p></div></div>
                <div class="form-grid">
                    <?php foreach ($felder as $name => [$label, $max, $typ]): ?>
                        <?php $finalPflicht = in_array($name, ["inhaber", "strasse", "plz", "ort"], true); ?>
                        <div class="form-field">
                            <label for="<?= h($name) ?>"><?= h($label) ?><?= $finalPflicht ? " (für finale Rechnung erforderlich)" : "" ?><?= $name === "geschaeftsname" ? ' <span class="required-indicator" aria-hidden="true">*</span>' : "" ?></label>
                            <input id="<?= h($name) ?>" type="<?= h($typ) ?>" name="<?= h($name) ?>" value="<?= h($daten[$name] ?? "") ?>" maxlength="<?= h($max) ?>" <?= $name === "geschaeftsname" ? "required" : "" ?>>
                        </div>
                    <?php endforeach; ?>
                    <div class="form-field form-field--wide"><p class="field-hint">Für eine finale Rechnung ist mindestens Steuernummer oder Umsatzsteuer-ID erforderlich.</p></div>
                    <div class="form-field form-field--wide"><label for="bankverbindung">Bankverbindung</label><textarea id="bankverbindung" name="bankverbindung" maxlength="2000"><?= h($daten["bankverbindung"] ?? "") ?></textarea></div>
                    <div class="form-field form-field--wide"><label for="zahlungsziel">Zahlungsziel</label><input id="zahlungsziel" type="text" name="zahlungsziel" value="<?= h($daten["zahlungsziel"] ?? "") ?>" maxlength="200"></div>
                    <div class="form-field form-field--wide"><label for="rechnungshinweise">Rechnungshinweise</label><textarea id="rechnungshinweise" name="rechnungshinweise" maxlength="5000"><?= h($daten["rechnungshinweise"] ?? "") ?></textarea></div>
                </div>
            </section>
            <div class="form-actions"><a class="button button--secondary" href="auftraege.php">Abbrechen</a><button class="button button--primary" type="submit">Rechnungsdaten speichern</button></div>
        </form>
    </main>
</div>
</body>
</html>
