<?php

declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/funktionen.php";
require_once __DIR__ . "/rechnung_funktionen.php";

try {
    $auftragId = positive_id($_GET["auftrag_id"] ?? ($_GET["id"] ?? null), "Die Auftragsnummer");
    $modus = (string) ($_GET["modus"] ?? "gemeinsam");
    if (!in_array($modus, ["gemeinsam", "einzeln"], true)) throw new EingabeException("Die Ausgabeart ist ungültig.");
    $auftrag = auftrag_fuer_druck_laden($pdo, $auftragId);
    $geraete = ausgewaehlte_geraete($auftrag, geraete_auswahl_ids($_GET["geraete"] ?? null));
    $geschaeft = geschaeftsdaten_laden($pdo);
} catch (EingabeException $e) { http_response_code(422); die(h($e->getMessage())); }
$gruppen = $modus === "einzeln" ? array_map(static fn(array $g): array => [$g], $geraete) : [$geraete];
$annahmedatum = (new DateTimeImmutable((string) $auftrag["erstellt_am"]))->format("d.m.Y");
$rueckkehr = "abholschein.php?" . http_build_query(["auftrag_id"=>$auftragId,"geraete"=>array_column($geraete,"zuordnung_id"),"modus"=>$modus]);
$flash = flash_holen();
?>
<!DOCTYPE html><html lang="de"><head><link rel="icon" href="favicon.svg" type="image/svg+xml"><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Abholschein Auftrag #<?= h($auftragId) ?></title><link rel="stylesheet" href="styles.css"><link rel="stylesheet" href="druck.css"><script src="app.js" defer></script></head>
<body class="print-preview-page">
<div class="print-toolbar no-print"><a class="button button--secondary" href="auftraege.php">Zurück</a><button class="button button--primary" type="button" data-print>Drucken</button></div>
<?php if($flash):?><div class="notice notice--<?= h($flash["typ"]) ?> no-print preview-notice" role="status"><?= h($flash["nachricht"]) ?></div><?php endif;?>
<section class="pickup-controls no-print" aria-labelledby="pickup-heading"><h2 id="pickup-heading">Abholung bestätigen</h2><p>Das Drucken ändert den Abholstatus nicht. Bestätigen Sie die Übergabe je Gerät ausdrücklich.</p>
<?php foreach($geraete as $g):?><form method="post" action="abholung_markieren.php" data-pickup-confirm><?= csrf_feld() ?><input type="hidden" name="auftrag_id" value="<?= h($auftragId) ?>"><input type="hidden" name="zuordnung_id" value="<?= h($g["zuordnung_id"]) ?>"><input type="hidden" name="rueckkehr" value="<?= h($rueckkehr) ?>"><strong><?= h($g["referenz"]) ?> · <?= h($g["geraetetyp"]) ?></strong><?php if($g["abgeholt_am"]):?><span>Abgeholt am <?= h((new DateTimeImmutable($g["abgeholt_am"]))->format("d.m.Y H:i")) ?><?= $g["abgeholt_von"] ? " durch ".h($g["abgeholt_von"]) : "" ?></span><?php else:?><label>Empfänger (optional) <input type="text" name="empfaenger" maxlength="150"></label><button class="button button--secondary button--compact" type="submit">Als abgeholt markieren</button><?php endif;?></form><?php endforeach;?></section>

<?php foreach($gruppen as $gruppenIndex=>$gruppe):?>
<?php $preisCent=$bezahltCent=0;foreach($gruppe as $g){$preisCent+=betrag_in_cent($g["preis"]);$bezahltCent+=betrag_in_cent($g["bereits_bezahlt"]);} ?>
<main class="paper document-sheet <?= $gruppenIndex>0 ? "document-page" : "" ?>">
<header class="document-header"><div><p class="document-brand"><?= h($geschaeft["geschaeftsname"]?:"Computerfachmann") ?></p><p class="document-type">Abholschein</p></div><dl class="document-meta"><div><dt>Auftrag</dt><dd>#<?= h($auftragId) ?></dd></div><div><dt>Annahmedatum</dt><dd><?= h($annahmedatum) ?></dd></div></dl></header>
<section class="document-section document-customer"><h1>Abholung / Übergabe</h1><p><strong><?= h($auftrag["name"]) ?></strong><?php if(!empty($auftrag["firmenname"])):?><br><?= h($auftrag["firmenname"]) ?><?php endif;?><?php foreach(adresse_zeilen($auftrag) as $adresszeile):?><br><?= h($adresszeile) ?><?php endforeach;?></p><?php if(!empty($auftrag["telefon"])||!empty($auftrag["email"])):?><p><?php if(!empty($auftrag["telefon"])):?>Telefon: <?= h($auftrag["telefon"]) ?><?php endif;?><?php if(!empty($auftrag["email"])):?><br>E-Mail: <?= h($auftrag["email"]) ?><?php endif;?></p><?php endif;?></section>
<section class="document-section"><h2>Geräte</h2><div class="print-device-list">
<?php foreach($gruppe as $index=>$g):?><article class="print-device-card"><div class="print-device-heading"><h3><?= h($g["referenz"]) ?> · <?= h($g["geraetetyp"]) ?></h3><strong><?= betrag_anzeigen($g["preis"]) ?></strong></div><dl class="print-details"><?php if($g["hersteller"]):?><div><dt>Hersteller</dt><dd><?= h($g["hersteller"]) ?></dd></div><?php endif;?><?php if($g["modell"]):?><div><dt>Modell</dt><dd><?= h($g["modell"]) ?></dd></div><?php endif;?><?php if($g["seriennummer"]):?><div><dt>Seriennummer</dt><dd><?= h($g["seriennummer"]) ?></dd></div><?php endif;?><div><dt>Status</dt><dd><?= h($g["status"]) ?></dd></div><div><dt>Abholung</dt><dd><?= $g["abgeholt_am"] ? h((new DateTimeImmutable($g["abgeholt_am"]))->format("d.m.Y H:i")) : "Noch nicht bestätigt" ?></dd></div></dl><p><strong>Fehlerbeschreibung:</strong><br><?= nl2br(h($g["fehlerbeschreibung"])) ?></p><?php if(!empty($g["durchgefuehrte_arbeiten"])):?><p><strong>Durchgeführte Arbeiten:</strong><br><?= nl2br(h($g["durchgefuehrte_arbeiten"])) ?></p><?php endif;?><p><strong>Preis:</strong> <?= betrag_anzeigen($g["preis"]) ?> · <strong>Bezahlt:</strong> <?= betrag_anzeigen($g["bereits_bezahlt"]) ?> · <strong>Rest:</strong> <?= betrag_anzeigen($g["restbetrag"]) ?></p></article><?php endforeach;?>
</div></section>
<section class="document-section document-totals"><dl><div><dt>Auswahlbetrag</dt><dd><?= betrag_anzeigen(cent_als_betrag($preisCent)) ?></dd></div><div><dt>Gerätebezogen bezahlt</dt><dd><?= betrag_anzeigen(cent_als_betrag($bezahltCent)) ?></dd></div><div class="total-due"><dt>Restbetrag</dt><dd><?= betrag_anzeigen(cent_als_betrag($preisCent-$bezahltCent)) ?></dd></div></dl></section>
<section class="document-section handover-fields"><p><strong>Datum der Abholung / Übergabe:</strong> <span class="fill-line"></span></p><div class="notes-box"><strong>Bemerkungen</strong></div><div class="signature-grid"><div><span></span><p>Unterschrift Kunde / Empfänger</p></div><div><span></span><p>Übergabe durch Mitarbeiter</p></div></div></section>
</main><?php endforeach;?>
</body></html>
