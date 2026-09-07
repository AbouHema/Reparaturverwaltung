<?php

declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/funktionen.php";

$auftragId = positive_id($_GET["id"] ?? null, "Die Auftragsnummer");

$stmt = $pdo->prepare(
    "SELECT ra.id, ra.kunde_id, ra.erstellt_am, ra.bereits_bezahlt, ra.nicht_zugeordnet_bezahlt,
            k.name, k.firmenname, k.telefon, k.email, k.strasse, k.plz, k.ort, k.land
     FROM reparaturauftraege ra
     INNER JOIN kunden k ON k.id = ra.kunde_id
     WHERE ra.id = ?"
);
$stmt->execute([$auftragId]);
$auftrag = $stmt->fetch();
if (!$auftrag) {
    http_response_code(404);
    die("Reparaturauftrag wurde nicht gefunden.");
}

$stmt = $pdo->prepare(
    "SELECT rag.id AS zuordnung_id, g.id, g.geraetetyp, g.hersteller, g.modell, g.seriennummer,
            rag.fehlerbeschreibung, rag.durchgefuehrte_arbeiten, rag.preis, rag.bereits_bezahlt, rag.status_id,
            rag.abgeholt_am, rag.abgeholt_von, r.rechnungsnummer, r.id AS rechnung_id
     FROM reparaturauftrag_geraete rag
     INNER JOIN geraete g ON g.id = rag.geraet_id
     LEFT JOIN rechnung_geraete rg ON rg.geraet_id_snapshot = g.id AND rg.storniert_am IS NULL
     LEFT JOIN rechnungen r ON r.id = rg.rechnung_id
     WHERE rag.auftrag_id = ?
     ORDER BY rag.id"
);
$stmt->execute([$auftragId]);
$geraete = $stmt->fetchAll();
$stmt = $pdo->prepare("SELECT z.*,EXISTS(SELECT 1 FROM geraete_zahlungen s WHERE s.bezugszahlung_id=z.id AND s.vorgang='storno') AS ist_storniert FROM geraete_zahlungen z WHERE z.auftragsnummer_snapshot=? ORDER BY z.erstellt_am,z.id");
$stmt->execute([$auftragId]);$zahlungenNachGeraet=[];foreach($stmt->fetchAll() as $zahlung)$zahlungenNachGeraet[(int)$zahlung["geraet_id_snapshot"]][]=$zahlung;
$status = status_ids_laden($pdo);
$standardStatusId = status_id_nach_bezeichnung($status, "Angenommen");
$flash = flash_holen();
$anzahlGeraete = count($geraete);
?>
<!DOCTYPE html>
<html lang="de">
<head><link rel="icon" href="favicon.svg" type="image/svg+xml">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reparaturauftrag bearbeiten - Computerfachmann</title>
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
            <a class="nav-link" href="auftraege.php">Reparaturaufträge</a>
            <a class="nav-link" href="index.php">Neuer Auftrag</a>
            <?php if(aktuelle_benutzerrolle()==="administrator"):?><a class="nav-link" href="einstellungen.php">Rechnungsdaten</a><a class="nav-link" href="rechnungsarchiv.php">Rechnungsarchiv</a><a class="nav-link" href="benutzer.php">Benutzer</a><?php endif;?>
            <form method="post" action="abmelden.php" class="nav-form"><?= csrf_feld() ?><button class="nav-link" type="submit">Abmelden</button></form>
        </nav>
    </header>

    <main id="main-content">
        <?php if ($flash): ?>
            <div class="notice notice--<?= h($flash["typ"]) ?>" role="status"><?= h($flash["nachricht"]) ?></div>
        <?php endif; ?>

        <div class="page-heading form-card">
            <div>
                <p class="eyebrow">Auftrag #<?= h($auftrag["id"]) ?></p>
                <h1>Reparaturauftrag bearbeiten</h1>
                <p class="page-description">Kundendaten und jedes zugehörige Gerät unabhängig aktualisieren.</p>
            </div>
        </div>

        <form class="glass-panel form-card form-layout" method="post" action="aktualisieren.php" data-validated-form novalidate>
            <?= csrf_feld() ?>
            <input type="hidden" name="auftrag_id" value="<?= h($auftrag["id"]) ?>">

            <section class="form-section" aria-labelledby="customer-heading">
                <div class="section-heading">
                    <span class="section-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="7" r="4"/></svg>
                    </span>
                    <div>
                        <h2 id="customer-heading">Kundendaten</h2>
                        <p class="section-description">Kontaktperson und Erreichbarkeit für den gesamten Auftrag.</p>
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-field">
                        <label for="name">Kundenname <span class="required-indicator" aria-hidden="true">*</span></label>
                        <input id="name" type="text" name="name" value="<?= h($auftrag["name"]) ?>" autocomplete="name" maxlength="100" required>
                    </div>
                    <div class="form-field">
                        <label for="firmenname">Firmenname</label>
                        <input id="firmenname" type="text" name="firmenname" value="<?= h($auftrag["firmenname"] ?? "") ?>" autocomplete="organization" maxlength="150">
                    </div>
                    <div class="form-field">
                        <label for="telefon">Telefonnummer</label>
                        <input id="telefon" type="text" name="telefon" value="<?= h($auftrag["telefon"] ?? "") ?>" autocomplete="tel" inputmode="tel" maxlength="30" pattern="[0-9+\(\) \/\-]*" title="Erlaubt sind Ziffern, +, Leerzeichen, Klammern, Bindestriche und Schrägstriche.">
                    </div>
                    <div class="form-field">
                        <label for="email">E-Mail-Adresse</label>
                        <input id="email" type="email" name="email" value="<?= h($auftrag["email"] ?? "") ?>" autocomplete="email" maxlength="254">
                    </div>
                    <div class="form-field form-field--wide address-heading"><h3>Adresse</h3></div>
                    <div class="form-field form-field--wide"><label for="strasse">Straße und Hausnummer</label><input id="strasse" type="text" name="strasse" value="<?= h($auftrag["strasse"]??"") ?>" maxlength="200" autocomplete="street-address"></div>
                    <div class="form-field"><label for="plz">Postleitzahl</label><input id="plz" type="text" name="plz" value="<?= h($auftrag["plz"]??"") ?>" maxlength="20" autocomplete="postal-code"></div>
                    <div class="form-field"><label for="ort">Ort</label><input id="ort" type="text" name="ort" value="<?= h($auftrag["ort"]??"") ?>" maxlength="100" autocomplete="address-level2"></div>
                    <div class="form-field"><label for="land">Land</label><input id="land" type="text" name="land" value="<?= h($auftrag["land"]??"") ?>" maxlength="100" autocomplete="country-name"></div>
                </div>
            </section>

            <section class="form-section" id="geraete" aria-labelledby="devices-heading">
                <div class="section-heading section-heading--actions">
                    <span class="section-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
                    </span>
                    <div class="section-heading-copy">
                        <h2 id="devices-heading">Geräte und Reparaturen</h2>
                        <p class="section-description">Änderungen an einem Gerät beeinflussen die übrigen Geräte nicht.</p>
                    </div>
                    <button class="button button--secondary button--compact" type="button" data-add-device>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                        Gerät hinzufügen
                    </button>
                </div>

                <p class="field-error device-list-error" data-device-list-error <?= $geraete !== [] ? "hidden" : "" ?>>Ein Reparaturauftrag muss mindestens ein Gerät enthalten.</p>
                <div class="device-form-list" data-device-list data-next-index="<?= h($anzahlGeraete) ?>">
                    <?php foreach ($geraete as $index => $geraet): ?>
                        <article class="device-form-card" data-device-card>
                            <div class="device-card-heading">
                                <div>
                                    <p class="device-kicker">Gerät <span data-device-number><?= h($index + 1) ?></span> · <?= h(geraete_referenz($auftragId, (int) $geraet["id"])) ?></p>
                                    <h3><?= h($geraet["geraetetyp"]) ?><?= $geraet["modell"] ? " · " . h($geraet["modell"]) : "" ?></h3>
                                </div>
                                <?php if (darf_loeschen()): ?>
                                    <button
                                        class="button button--ghost-danger button--compact"
                                        type="button"
                                        data-confirm-form="geraet-loeschen-<?= h($geraet["zuordnung_id"]) ?>"
                                        data-confirm-message="Möchten Sie dieses Gerät wirklich aus dem Reparaturauftrag löschen? Diese Aktion kann nicht rückgängig gemacht werden."
                                        data-confirm-label="Gerät löschen"
                                        data-last-device="<?= $anzahlGeraete === 1 ? "true" : "false" ?>"
                                        data-order-delete-form="auftrag-loeschen-<?= h($auftragId) ?>"
                                        data-edit-url="bearbeiten.php?id=<?= h($auftragId) ?>#geraete"
                                    >Gerät entfernen</button>
                                <?php endif; ?>
                            </div>
                            <input type="hidden" name="geraete[<?= h($index) ?>][id]" value="<?= h($geraet["id"]) ?>">
                            <div class="form-grid">
                                <div class="form-field">
                                    <label for="geraetetyp-<?= h($index) ?>">Gerätetyp <span class="required-indicator" aria-hidden="true">*</span></label>
                                    <input id="geraetetyp-<?= h($index) ?>" type="text" name="geraete[<?= h($index) ?>][geraetetyp]" value="<?= h($geraet["geraetetyp"]) ?>" maxlength="50" required>
                                </div>
                                <div class="form-field">
                                    <label for="hersteller-<?= h($index) ?>">Hersteller</label>
                                    <input id="hersteller-<?= h($index) ?>" type="text" name="geraete[<?= h($index) ?>][hersteller]" value="<?= h($geraet["hersteller"] ?? "") ?>" maxlength="100">
                                </div>
                                <div class="form-field">
                                    <label for="modell-<?= h($index) ?>">Modell</label>
                                    <input id="modell-<?= h($index) ?>" type="text" name="geraete[<?= h($index) ?>][modell]" value="<?= h($geraet["modell"] ?? "") ?>" maxlength="100">
                                </div>
                                <div class="form-field">
                                    <label for="seriennummer-<?= h($index) ?>">Seriennummer</label>
                                    <input id="seriennummer-<?= h($index) ?>" type="text" name="geraete[<?= h($index) ?>][seriennummer]" value="<?= h($geraet["seriennummer"] ?? "") ?>" maxlength="100">
                                </div>
                                <div class="form-field form-field--wide">
                                    <label for="fehlerbeschreibung-<?= h($index) ?>">Fehlerbeschreibung <span class="required-indicator" aria-hidden="true">*</span></label>
                                    <textarea id="fehlerbeschreibung-<?= h($index) ?>" name="geraete[<?= h($index) ?>][fehlerbeschreibung]" required><?= h($geraet["fehlerbeschreibung"]) ?></textarea>
                                </div>
                                <div class="form-field form-field--wide">
                                    <label for="durchgefuehrte-arbeiten-<?= h($index) ?>">Durchgeführte Arbeiten / Leistungen</label>
                                    <textarea id="durchgefuehrte-arbeiten-<?= h($index) ?>" name="geraete[<?= h($index) ?>][durchgefuehrte_arbeiten]" maxlength="5000"><?= h($geraet["durchgefuehrte_arbeiten"] ?? "") ?></textarea>
                                </div>
                                <div class="form-field">
                                    <label for="preis-<?= h($index) ?>">Preis <span class="required-indicator" aria-hidden="true">*</span></label>
                                    <div class="input-suffix">
                                        <input id="preis-<?= h($index) ?>" type="text" name="geraete[<?= h($index) ?>][preis]" value="<?= h(str_replace(".", ",", (string) ($geraet["preis"] ?? ""))) ?>" inputmode="decimal" maxlength="11" pattern="[0-9]{1,8}([,.][0-9]{1,2})?" required data-amount-input data-device-price aria-describedby="preis-fehler-<?= h($index) ?>">
                                        <span aria-hidden="true">€</span>
                                    </div>
                                    <span class="field-error" id="preis-fehler-<?= h($index) ?>" data-amount-error hidden></span>
                                </div>
                                <div class="form-field">
                                    <label for="status-<?= h($index) ?>">Reparaturstatus</label>
                                    <select id="status-<?= h($index) ?>" name="geraete[<?= h($index) ?>][status_id]">
                                        <?php foreach ($status as $eintrag): ?>
                                            <option value="<?= h($eintrag["id"]) ?>" <?= (int) $eintrag["id"] === (int) $geraet["status_id"] ? "selected" : "" ?>><?= h($eintrag["bezeichnung"]) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-field">
                                    <label for="geraet-bezahlt-<?= h($index) ?>">Für dieses Gerät bezahlt</label>
                                    <div class="input-suffix">
                                        <input id="geraet-bezahlt-<?= h($index) ?>" type="text" name="geraete[<?= h($index) ?>][bereits_bezahlt]" value="<?= h(str_replace(".", ",", (string) $geraet["bereits_bezahlt"])) ?>" inputmode="decimal" readonly data-device-paid aria-describedby="geraet-bezahlt-fehler-<?= h($index) ?>">
                                        <span aria-hidden="true">€</span>
                                    </div>
                                    <span class="field-error" id="geraet-bezahlt-fehler-<?= h($index) ?>" data-amount-error hidden></span>
                                    <p class="field-hint" data-device-payment-state>Restbetrag: <span data-device-remaining><?= betrag_anzeigen(max(0,(float)$geraet["preis"]-(float)$geraet["bereits_bezahlt"])) ?></span> · <span data-device-payment-status><?= h(zahlungsstatus_fuer_betraege($geraet["preis"],$geraet["bereits_bezahlt"])) ?></span></p>
                                </div>
                                <div class="form-field form-field--wide device-state-note">
                                    <span><?= $geraet["abgeholt_am"] ? "Abgeholt am " . h((new DateTimeImmutable($geraet["abgeholt_am"]))->format("d.m.Y H:i")) : "Noch nicht abgeholt" ?></span>
                                    <span><?= $geraet["rechnung_id"] ? "Rechnung " . h($geraet["rechnungsnummer"]) : "Noch nicht abgerechnet" ?></span>
                                </div>
                                <?php if(zahlungsstatus_fuer_betraege($geraet["preis"],$geraet["bereits_bezahlt"])!=="Bezahlt"):?><div class="form-field form-field--wide"><button class="button button--secondary button--compact" type="button" data-payment-trigger data-order-id="<?= h($auftragId) ?>" data-device-id="<?= h($geraet["zuordnung_id"]) ?>" data-device-label="<?= h($geraet["geraetetyp"]) ?>" data-device-price="<?= h($geraet["preis"]) ?>" data-device-open="<?= h(max(0,(float)$geraet["preis"]-(float)$geraet["bereits_bezahlt"])) ?>">Als vollständig bezahlt markieren</button></div><?php endif;?>
                                <?php if(!empty($zahlungenNachGeraet[(int)$geraet["id"]])):?><div class="form-field form-field--wide payment-history"><h4>Zahlungsverlauf</h4><?php foreach($zahlungenNachGeraet[(int)$geraet["id"]] as $zahlung):?><div><span><?= h((new DateTimeImmutable($zahlung["zahlungsdatum"]))->format("d.m.Y")) ?> · <?= h($zahlung["zahlungsart"]) ?> · <?= $zahlung["vorgang"]==="storno"?"Storno ":"" ?><?= betrag_anzeigen($zahlung["betrag"]) ?><?= $zahlung["ist_storniert"]?" · storniert":"" ?></span><?php if(darf_loeschen()&&$zahlung["vorgang"]==="zahlung"&&!$zahlung["ist_storniert"]):?><form method="post" action="zahlung_stornieren.php" class="inline-form" onsubmit="return confirm('Möchten Sie diese Zahlung nachvollziehbar stornieren?');"><?= csrf_feld() ?><input type="hidden" name="zahlung_id" value="<?= h($zahlung["id"]) ?>"><input type="hidden" name="auftrag_id" value="<?= h($auftragId) ?>"><input name="grund" maxlength="1000" placeholder="Stornogrund" required><button class="button button--ghost-danger button--compact">Zahlung stornieren</button></form><?php endif;?></div><?php endforeach;?></div><?php endif;?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="form-section payment-section" aria-labelledby="payment-heading" data-payment-summary>
                <div class="section-heading">
                    <span class="section-icon" aria-hidden="true">€</span>
                    <div><h2 id="payment-heading">Zahlung</h2></div>
                </div>
                <div class="payment-layout">
                    <input type="hidden" name="nicht_zugeordnet_bezahlt" value="<?= h($auftrag["nicht_zugeordnet_bezahlt"]??"0.00") ?>" data-unassigned-paid>
                    <?php if((float)$auftrag["nicht_zugeordnet_bezahlt"]>0):?><div class="notice notice--warning"><strong>Vorhandene ältere Zahlung zuordnen:</strong> <?= betrag_anzeigen($auftrag["nicht_zugeordnet_bezahlt"]) ?>. <a href="zahlung_zuordnen.php?auftrag_id=<?= h($auftragId) ?>">Jetzt Geräten zuordnen</a></div><?php endif;?>
                    <dl class="amount-summary">
                        <div><dt>Gesamtbetrag</dt><dd data-total-amount>0,00 €</dd></div>
                        <div><dt>Insgesamt bezahlt</dt><dd data-paid-amount>0,00 €</dd></div>
                        <div class="amount-summary__rest"><dt>Gesamter Restbetrag</dt><dd data-remaining-amount>0,00 €</dd></div>
                    </dl>
                </div>
            </section>

            <div class="form-actions form-actions--split">
                <div>
                    <?php if (darf_loeschen()): ?>
                        <button
                            class="button button--danger"
                            type="button"
                            data-delete-order
                            data-confirm-form="auftrag-loeschen-<?= h($auftragId) ?>"
                            data-confirm-message="Möchten Sie diesen Reparaturauftrag wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden."
                            data-confirm-label="Auftrag löschen"
                        >Auftrag löschen</button>
                    <?php endif; ?>
                </div>
                <div class="action-group">
                    <a class="button button--secondary" href="auftraege.php">Abbrechen</a>
                    <button class="button button--primary" type="submit">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5 9.5 17 19 7.5"/></svg>
                        Änderungen speichern
                    </button>
                </div>
            </div>
        </form>
    </main>
</div>

<?php foreach ($geraete as $geraet): ?>
    <form id="geraet-loeschen-<?= h($geraet["zuordnung_id"]) ?>" method="post" action="geraet_loeschen.php" hidden>
        <?= csrf_feld() ?>
        <input type="hidden" name="auftrag_id" value="<?= h($auftragId) ?>">
        <input type="hidden" name="zuordnung_id" value="<?= h($geraet["zuordnung_id"]) ?>">
    </form>
<?php endforeach; ?>
<form id="auftrag-loeschen-<?= h($auftragId) ?>" method="post" action="auftrag_loeschen.php" hidden>
    <?= csrf_feld() ?>
    <input type="hidden" name="auftrag_id" value="<?= h($auftragId) ?>">
</form>

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
<?php require __DIR__."/zahlung_dialog.php"; ?>

<template id="device-template">
    <article class="device-form-card" data-device-card>
        <div class="device-card-heading">
            <div>
                <p class="device-kicker">Gerät <span data-device-number></span> · Neu</p>
                <h3>Neues Gerät</h3>
            </div>
            <button class="button button--ghost-danger button--compact" type="button" data-remove-unsaved-device>Gerät entfernen</button>
        </div>
        <input type="hidden" name="geraete[__INDEX__][id]" value="">
        <div class="form-grid">
            <div class="form-field">
                <label for="geraetetyp-__INDEX__">Gerätetyp <span class="required-indicator" aria-hidden="true">*</span></label>
                <input id="geraetetyp-__INDEX__" type="text" name="geraete[__INDEX__][geraetetyp]" maxlength="50" required>
            </div>
            <div class="form-field">
                <label for="hersteller-__INDEX__">Hersteller</label>
                <input id="hersteller-__INDEX__" type="text" name="geraete[__INDEX__][hersteller]" maxlength="100">
            </div>
            <div class="form-field">
                <label for="modell-__INDEX__">Modell</label>
                <input id="modell-__INDEX__" type="text" name="geraete[__INDEX__][modell]" maxlength="100">
            </div>
            <div class="form-field">
                <label for="seriennummer-__INDEX__">Seriennummer</label>
                <input id="seriennummer-__INDEX__" type="text" name="geraete[__INDEX__][seriennummer]" maxlength="100">
            </div>
            <div class="form-field form-field--wide">
                <label for="fehlerbeschreibung-__INDEX__">Fehlerbeschreibung <span class="required-indicator" aria-hidden="true">*</span></label>
                <textarea id="fehlerbeschreibung-__INDEX__" name="geraete[__INDEX__][fehlerbeschreibung]" required></textarea>
            </div>
            <div class="form-field form-field--wide">
                <label for="durchgefuehrte-arbeiten-__INDEX__">Durchgeführte Arbeiten / Leistungen</label>
                <textarea id="durchgefuehrte-arbeiten-__INDEX__" name="geraete[__INDEX__][durchgefuehrte_arbeiten]" maxlength="5000"></textarea>
            </div>
            <div class="form-field">
                <label for="preis-__INDEX__">Preis <span class="required-indicator" aria-hidden="true">*</span></label>
                <div class="input-suffix">
                    <input id="preis-__INDEX__" type="text" name="geraete[__INDEX__][preis]" inputmode="decimal" maxlength="11" pattern="[0-9]{1,8}([,.][0-9]{1,2})?" required data-amount-input data-device-price aria-describedby="preis-fehler-__INDEX__">
                    <span aria-hidden="true">€</span>
                </div>
                <span class="field-error" id="preis-fehler-__INDEX__" data-amount-error hidden></span>
            </div>
            <div class="form-field">
                <label for="status-__INDEX__">Reparaturstatus</label>
                <select id="status-__INDEX__" name="geraete[__INDEX__][status_id]">
                    <?php foreach ($status as $eintrag): ?>
                        <option value="<?= h($eintrag["id"]) ?>" <?= (int) $eintrag["id"] === $standardStatusId ? "selected" : "" ?>><?= h($eintrag["bezeichnung"]) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field">
                <label for="geraet-bezahlt-__INDEX__">Für dieses Gerät bezahlt</label>
                <div class="input-suffix">
                    <input id="geraet-bezahlt-__INDEX__" type="text" name="geraete[__INDEX__][bereits_bezahlt]" value="0,00" inputmode="decimal" readonly data-device-paid aria-describedby="geraet-bezahlt-fehler-__INDEX__">
                    <span aria-hidden="true">€</span>
                </div>
                <span class="field-error" id="geraet-bezahlt-fehler-__INDEX__" data-amount-error hidden></span>
                <p class="field-hint" data-device-payment-state>Restbetrag: <span data-device-remaining>–</span> · <span data-device-payment-status>Offen</span></p>
            </div>
        </div>
    </article>
</template>
</body>
</html>
