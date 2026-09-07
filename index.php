<?php

declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/funktionen.php";

$flash = flash_holen();
$formularDaten = $_SESSION["formular_daten"] ?? [];
unset($_SESSION["formular_daten"]);
if (!is_array($formularDaten)) {
    $formularDaten = [];
}
$formularGeraete = $formularDaten["geraete"] ?? [[]];
if (!is_array($formularGeraete) || $formularGeraete === []) {
    $formularGeraete = [[]];
}
$formularGeraete = array_values(array_filter($formularGeraete, "is_array"));
if ($formularGeraete === []) {
    $formularGeraete = [[]];
}
$kundenAuswahl = $pdo->query("SELECT id,name,firmenname,telefon,email,strasse,plz,ort,land FROM kunden ORDER BY name,id")->fetchAll();
?>
<!DOCTYPE html>
<html lang="de">
<head><link rel="icon" href="favicon.svg" type="image/svg+xml">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Computerfachmann - Reparaturverwaltung</title>
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
            <a class="nav-link" href="index.php" aria-current="page">Neuer Auftrag</a>
            <?php if(aktuelle_benutzerrolle()==="administrator"):?><a class="nav-link" href="einstellungen.php">Rechnungsdaten</a><a class="nav-link" href="rechnungsarchiv.php">Rechnungsarchiv</a><a class="nav-link" href="benutzer.php">Benutzer</a><?php endif;?>
            <form method="post" action="abmelden.php" class="nav-form"><?= csrf_feld() ?><button class="nav-link" type="submit">Abmelden</button></form>
        </nav>
    </header>

    <main id="main-content">
        <?php if ($flash): ?>
            <div class="notice notice--<?= h($flash["typ"]) ?>" role="alert"><?= h($flash["nachricht"]) ?></div>
        <?php endif; ?>
        <div class="page-heading form-card">
            <div>
                <p class="eyebrow">Auftragserfassung</p>
                <h1>Reparaturauftrag anlegen</h1>
            </div>
        </div>

        <form class="glass-panel form-card form-layout" method="post" action="speichern.php" data-validated-form novalidate>
            <?= csrf_feld() ?>
            <section class="form-section" aria-labelledby="customer-heading">
                <div class="section-heading">
                    <span class="section-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="7" r="4"/></svg>
                    </span>
                    <div>
                        <h2 id="customer-heading">Kundendaten</h2>
                        <p class="section-description">Kontaktdaten für Rückfragen und Abholung.</p>
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-field form-field--wide">
                        <label for="bestehender-kunde">Bestehenden Kunden verwenden (optional)</label>
                        <select id="bestehender-kunde" name="bestehender_kunde_id" data-existing-customer>
                            <option value="">Neuen Kunden anlegen</option>
                            <?php foreach($kundenAuswahl as $kunde): ?><option value="<?= h($kunde["id"]) ?>" data-customer='<?= h(json_encode($kunde, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'><?= h($kunde["name"] . ($kunde["firmenname"] ? " · " . $kunde["firmenname"] : "")) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-field">
                        <label for="name">Kundenname <span class="required-indicator" aria-hidden="true">*</span></label>
                        <input id="name" type="text" name="name" value="<?= h($formularDaten["name"] ?? "") ?>" autocomplete="name" maxlength="100" required>
                    </div>
                    <div class="form-field">
                        <label for="firmenname">Firmenname</label>
                        <input id="firmenname" type="text" name="firmenname" value="<?= h($formularDaten["firmenname"] ?? "") ?>" autocomplete="organization" maxlength="150">
                    </div>
                    <div class="form-field">
                        <label for="telefon">Telefonnummer</label>
                        <input id="telefon" type="text" name="telefon" value="<?= h($formularDaten["telefon"] ?? "") ?>" autocomplete="tel" inputmode="tel" maxlength="30" pattern="[0-9+\(\) \/\-]*" title="Erlaubt sind Ziffern, +, Leerzeichen, Klammern, Bindestriche und Schrägstriche.">
                    </div>
                    <div class="form-field">
                        <label for="email">E-Mail-Adresse</label>
                        <input id="email" type="email" name="email" value="<?= h($formularDaten["email"] ?? "") ?>" autocomplete="email" maxlength="254">
                    </div>
                    <div class="form-field form-field--wide address-heading"><h3>Adresse</h3></div>
                    <div class="form-field form-field--wide"><label for="strasse">Straße und Hausnummer</label><input id="strasse" type="text" name="strasse" value="<?= h($formularDaten["strasse"]??"") ?>" maxlength="200" autocomplete="street-address"></div>
                    <div class="form-field"><label for="plz">Postleitzahl</label><input id="plz" type="text" name="plz" value="<?= h($formularDaten["plz"]??"") ?>" maxlength="20" autocomplete="postal-code"></div>
                    <div class="form-field"><label for="ort">Ort</label><input id="ort" type="text" name="ort" value="<?= h($formularDaten["ort"]??"") ?>" maxlength="100" autocomplete="address-level2"></div>
                    <div class="form-field"><label for="land">Land</label><input id="land" type="text" name="land" value="<?= h($formularDaten["land"]??"") ?>" maxlength="100" autocomplete="country-name"></div>
                </div>
            </section>

            <section class="form-section" id="geraete" aria-labelledby="devices-heading">
                <div class="section-heading section-heading--actions">
                    <span class="section-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
                    </span>
                    <div class="section-heading-copy">
                        <h2 id="devices-heading">Geräte und Reparaturen</h2>
                    </div>
                    <button class="button button--secondary button--compact" type="button" data-add-device>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                        Gerät hinzufügen
                    </button>
                </div>

                <p class="field-error device-list-error" data-device-list-error hidden></p>
                <div class="device-form-list" data-device-list data-next-index="<?= h(count($formularGeraete)) ?>">
                    <?php foreach ($formularGeraete as $index => $geraet): ?>
                    <article class="device-form-card" data-device-card>
                        <div class="device-card-heading">
                            <div>
                                <p class="device-kicker">Gerät <span data-device-number><?= h($index + 1) ?></span></p>
                                <h3>Gerätedaten</h3>
                            </div>
                        </div>
                        <input type="hidden" name="geraete[<?= h($index) ?>][id]" value="">
                        <div class="form-grid">
                            <div class="form-field">
                                <label for="geraetetyp-<?= h($index) ?>">Gerätetyp <span class="required-indicator" aria-hidden="true">*</span></label>
                                <input id="geraetetyp-<?= h($index) ?>" type="text" name="geraete[<?= h($index) ?>][geraetetyp]" value="<?= h($geraet["geraetetyp"] ?? "") ?>" maxlength="50" required>
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
                                <textarea id="fehlerbeschreibung-<?= h($index) ?>" name="geraete[<?= h($index) ?>][fehlerbeschreibung]" required><?= h($geraet["fehlerbeschreibung"] ?? "") ?></textarea>
                            </div>
                            <div class="form-field form-field--wide">
                                <label for="durchgefuehrte-arbeiten-<?= h($index) ?>">Durchgeführte Arbeiten / Leistungen</label>
                                <textarea id="durchgefuehrte-arbeiten-<?= h($index) ?>" name="geraete[<?= h($index) ?>][durchgefuehrte_arbeiten]" maxlength="5000"><?= h($geraet["durchgefuehrte_arbeiten"] ?? "") ?></textarea>
                            </div>
                            <div class="form-field">
                                <label for="preis-<?= h($index) ?>">Preis <span class="required-indicator" aria-hidden="true">*</span></label>
                                <div class="input-suffix">
                                    <input id="preis-<?= h($index) ?>" type="text" name="geraete[<?= h($index) ?>][preis]" value="<?= h($geraet["preis"] ?? "") ?>" inputmode="decimal" maxlength="11" pattern="[0-9]{1,8}([,.][0-9]{1,2})?" required data-amount-input data-device-price aria-describedby="preis-fehler-<?= h($index) ?>">
                                    <span aria-hidden="true">€</span>
                                </div>
                                <span class="field-error" id="preis-fehler-<?= h($index) ?>" data-amount-error hidden></span>
                            </div>
                            <div class="form-field">
                                <label for="geraet-bezahlt-<?= h($index) ?>">Für dieses Gerät bezahlt</label>
                                <div class="input-suffix">
                                    <input id="geraet-bezahlt-<?= h($index) ?>" type="text" name="geraete[<?= h($index) ?>][bereits_bezahlt]" value="0,00" inputmode="decimal" readonly data-device-paid aria-describedby="geraet-bezahlt-fehler-<?= h($index) ?>">
                                    <span aria-hidden="true">€</span>
                                </div>
                                <span class="field-error" id="geraet-bezahlt-fehler-<?= h($index) ?>" data-amount-error hidden></span>
                                <p class="field-hint" data-device-payment-state>Restbetrag: <span data-device-remaining>–</span> · <span data-device-payment-status>Offen</span></p>
                            </div>
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
                    <input type="hidden" name="nicht_zugeordnet_bezahlt" value="0,00" data-unassigned-paid>
                    <dl class="amount-summary">
                        <div><dt>Gesamtbetrag</dt><dd data-total-amount>0,00 €</dd></div>
                        <div><dt>Insgesamt bezahlt</dt><dd data-paid-amount>0,00 €</dd></div>
                        <div class="amount-summary__rest"><dt>Gesamter Restbetrag</dt><dd data-remaining-amount>0,00 €</dd></div>
                    </dl>
                </div>
            </section>

            <div class="form-actions">
                <a class="button button--secondary" href="auftraege.php">Zur Übersicht</a>
                <button class="button button--primary" type="submit">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                    Reparaturauftrag speichern
                </button>
            </div>
        </form>
    </main>
</div>

<template id="device-template">
    <article class="device-form-card" data-device-card>
        <div class="device-card-heading">
            <div>
                <p class="device-kicker">Gerät <span data-device-number></span></p>
                <h3>Gerätedaten</h3>
            </div>
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
