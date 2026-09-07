<?php

declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/funktionen.php";
require_once __DIR__ . "/rechnung_funktionen.php";

header("Content-Type: application/json; charset=UTF-8");

try {
    $auftragId = positive_id($_GET["auftrag_id"] ?? null, "Die Auftragsnummer");
    $typ = (string) ($_GET["typ"] ?? "");
    if (!in_array($typ, ["abholschein", "rechnung"], true)) throw new EingabeException("Der Dokumenttyp ist ungültig.");
    $auftrag = auftrag_fuer_druck_laden($pdo, $auftragId);
    if (count($auftrag["geraete"]) < 2) throw new EingabeException("Für diesen Auftrag ist keine Geräteauswahl erforderlich.");
    ob_start();
    ?>
    <form class="document-selection-form" method="get" action="<?= $typ === "rechnung" ? "rechnung.php" : "abholschein.php" ?>" target="_blank" data-document-selection-form>
        <input type="hidden" name="auftrag_id" value="<?= h($auftragId) ?>">
        <div class="selection-tools">
            <label><input type="checkbox" data-select-all> Alle verfügbaren Geräte auswählen</label>
            <strong><span data-selection-count>0</span> ausgewählt · <span data-selection-total>0,00 €</span></strong>
        </div>
        <div class="document-device-list">
            <?php foreach ($auftrag["geraete"] as $geraet): ?>
                <?php $gesperrt = $typ === "rechnung" && !empty($geraet["rechnung_id"]); ?>
                <label class="document-device-option <?= $gesperrt ? "is-disabled" : "" ?>">
                    <input type="checkbox" name="geraete[]" value="<?= h($geraet["zuordnung_id"]) ?>" data-selection-device data-price-cent="<?= h(betrag_in_cent((string) $geraet["preis"])) ?>" <?= $gesperrt ? "disabled" : "" ?>>
                    <span class="document-device-copy">
                        <strong><?= h($geraet["referenz"]) ?> · <?= h($geraet["geraetetyp"]) ?></strong>
                        <span><?= h(implode(" · ", array_filter([$geraet["hersteller"], $geraet["modell"]]))) ?><?= $geraet["seriennummer"] ? " · SN " . h($geraet["seriennummer"]) : "" ?></span>
                        <span>Status: <?= h($geraet["status"]) ?> · Preis <?= betrag_anzeigen($geraet["preis"]) ?> · Bezahlt <?= betrag_anzeigen($geraet["bereits_bezahlt"]) ?> · Rest <?= betrag_anzeigen($geraet["restbetrag"]) ?></span>
                        <?php if ($gesperrt): ?><span class="device-invoice-link">Bereits abgerechnet: <a href="rechnung.php?rechnung_id=<?= h($geraet["rechnung_id"]) ?>" target="_blank">Rechnung <?= h($geraet["rechnungsnummer"]) ?> öffnen</a></span><?php endif; ?>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>
        <fieldset class="document-mode-choice">
            <legend>Ausgabe</legend>
            <label><input type="radio" name="modus" value="gemeinsam" checked> Gemeinsam in einem Dokument</label>
            <label><input type="radio" name="modus" value="einzeln"> Je Gerät eine eigene A4-Seite<?= $typ === "rechnung" ? " und Rechnungsnummer" : "" ?></label>
        </fieldset>
        <p class="field-error" data-selection-error hidden>Wählen Sie mindestens ein Gerät aus.</p>
        <div class="dialog-actions">
            <button class="button button--secondary" type="button" data-dialog-cancel>Abbrechen</button>
            <button class="button button--primary" type="submit" disabled data-selection-submit>Vorschau öffnen</button>
        </div>
    </form>
    <?php
    echo json_encode(["ok" => true, "titel" => $typ === "rechnung" ? "Geräte für Rechnung auswählen" : "Geräte für Abholschein auswählen", "html" => ob_get_clean()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (EingabeException $e) {
    http_response_code(422);
    echo json_encode(["ok" => false, "fehler" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log($e->getMessage()); http_response_code(500);
    echo json_encode(["ok" => false, "fehler" => "Die Geräteauswahl konnte nicht geladen werden."], JSON_UNESCAPED_UNICODE);
}
