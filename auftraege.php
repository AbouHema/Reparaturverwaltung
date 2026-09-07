<?php

declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/funktionen.php";
require_once __DIR__ . "/auftraege_repository.php";

$suchkategorien = suchkategorien();
$daten = auftraege_suchen($pdo, $_GET["suche"] ?? "", $_GET["kategorie"] ?? "alle");
$suche = $daten["suche"];
$kategorie = $daten["kategorie"];
$auftraege = $daten["auftraege"];
$geraeteNachAuftrag = $daten["geraeteNachAuftrag"];
$status = status_ids_laden($pdo);
$flash = flash_holen();
?>
<!DOCTYPE html>
<html lang="de">
<head><link rel="icon" href="favicon.svg" type="image/svg+xml">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reparaturaufträge - Computerfachmann</title>
    <link rel="stylesheet" href="styles.css">
    <script src="app.js" defer></script>
</head>
<body>
<a class="skip-link" href="#main-content">Zum Inhalt springen</a>
<div class="app-shell">
    <header class="app-header">
        <a class="brand" href="index.php" aria-label="Computerfachmann Startseite">
            <span class="brand-mark" aria-hidden="true">CF</span>
            <span>Computerfachmann</span>
        </a>
        <nav class="primary-nav" aria-label="Hauptnavigation">
            <a class="nav-link" href="auftraege.php" aria-current="page">Reparaturaufträge</a>
            <a class="nav-link" href="index.php">Neuer Auftrag</a>
            <?php if(aktuelle_benutzerrolle()==="administrator"):?><a class="nav-link" href="einstellungen.php">Rechnungsdaten</a><a class="nav-link" href="rechnungsarchiv.php">Rechnungsarchiv</a><a class="nav-link" href="benutzer.php">Benutzer</a><?php endif;?>
            <form method="post" action="abmelden.php" class="nav-form"><?= csrf_feld() ?><button class="nav-link" type="submit">Abmelden</button></form>
        </nav>
    </header>

    <main id="main-content">
        <?php if ($flash): ?>
            <div class="notice notice--<?= h($flash["typ"]) ?>" role="status"><?= h($flash["nachricht"]) ?></div>
        <?php endif; ?>

        <div class="page-heading">
            <div>
                <p class="eyebrow">Auftragsverwaltung</p>
                <h1>Reparaturaufträge</h1>
            </div>
            <a class="button button--primary" href="index.php">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                Neuer Auftrag
            </a>
        </div>

        <form class="glass-panel toolbar toolbar--live-search" method="get" action="auftraege.php" role="search" data-live-search-form>
            <div class="search-controls">
                <div class="search-field">
                    <label class="visually-hidden" for="suche">Reparaturaufträge durchsuchen</label>
                    <span class="search-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
                    </span>
                    <input
                        id="suche"
                        type="search"
                        name="suche"
                        value="<?= h($suche) ?>"
                        placeholder="Suchbegriff eingeben"
                        autocomplete="off"
                        aria-controls="search-results"
                        aria-describedby="search-status"
                    >
                    <button class="search-clear" type="button" data-search-clear aria-label="Suche leeren" <?= $suche === "" ? "hidden" : "" ?>>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 7 10 10M17 7 7 17"/></svg>
                    </button>
                </div>
                <div class="search-category">
                    <label class="visually-hidden" for="kategorie">Suchkategorie</label>
                    <select id="kategorie" name="kategorie" aria-label="Suchkategorie auswählen" data-search-category>
                        <?php foreach ($suchkategorien as $wert => $bezeichnung): ?>
                            <option value="<?= h($wert) ?>" <?= $wert === $kategorie ? "selected" : "" ?>><?= h($bezeichnung) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <span class="search-status" id="search-status" aria-live="polite"></span>
        </form>

        <div id="search-results" aria-live="polite" aria-busy="false">
            <?php require __DIR__ . "/auftraege_liste.php"; ?>
        </div>
    </main>
</div>

<dialog class="confirm-dialog" id="confirm-dialog" aria-labelledby="confirm-title">
    <div class="dialog-content">
        <p class="eyebrow">Bitte bestätigen</p>
        <h2 id="confirm-title">Löschen bestätigen</h2>
        <p data-confirm-message></p>
        <div class="dialog-actions">
            <button class="button button--secondary" type="button" data-dialog-cancel>Abbrechen</button>
            <button class="button button--danger" type="button" data-confirm-submit>Löschen</button>
        </div>
    </div>
</dialog>

<dialog class="confirm-dialog" id="last-device-dialog" aria-labelledby="last-device-title">
    <div class="dialog-content">
        <p class="eyebrow">Letztes Gerät</p>
        <h2 id="last-device-title">Der Auftrag darf nicht leer bleiben</h2>
        <p>Das letzte Gerät wurde nicht gelöscht. Fügen Sie zuerst ein neues Gerät hinzu oder löschen Sie den gesamten Auftrag.</p>
        <div class="dialog-actions dialog-actions--stack-mobile">
            <button class="button button--secondary" type="button" data-dialog-cancel>Abbrechen</button>
            <button class="button button--secondary" type="button" data-add-last-device>Neues Gerät hinzufügen</button>
            <button class="button button--danger" type="button" data-delete-whole-order>Gesamten Auftrag löschen</button>
        </div>
    </div>
</dialog>
<dialog class="document-select-dialog" id="document-select-dialog" aria-labelledby="document-select-title">
    <div class="dialog-content dialog-content--wide">
        <p class="eyebrow">Dokument vorbereiten</p>
        <h2 id="document-select-title" data-document-select-title>Geräte auswählen</h2>
        <div data-document-select-content></div>
    </div>
</dialog>
<?php require __DIR__."/zahlung_dialog.php"; ?>
</body>
</html>
