<?php

declare(strict_types=1);

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/funktionen.php";
require_once __DIR__ . "/rechnung_funktionen.php";

$final = false; $auftrag = null; $auswahlIds = []; $modus = "gemeinsam"; $dokumente = []; $alleBezahlt = true;
try {
    $rechnungIds = geraete_auswahl_ids($_GET["rechnung_ids"] ?? ($_GET["rechnung_id"] ?? null));
    if ($rechnungIds !== []) {
        $final = true;
        foreach ($rechnungIds as $id) $dokumente[] = rechnung_laden($pdo, $id);
    } else {
        $auftragId = positive_id($_GET["auftrag_id"] ?? null, "Die Auftragsnummer");
        $modus = (string) ($_GET["modus"] ?? "gemeinsam");
        if (!in_array($modus, ["gemeinsam", "einzeln"], true)) throw new EingabeException("Die Rechnungsart ist ungültig.");
        $auftrag = auftrag_fuer_druck_laden($pdo, $auftragId);
        $auswahlIds = geraete_auswahl_ids($_GET["geraete"] ?? null);
        $geraete = ausgewaehlte_geraete($auftrag, $auswahlIds);
        foreach($geraete as $g){if(zahlungsstatus_fuer_betraege($g["preis"],$g["bereits_bezahlt"])!=="Bezahlt")$alleBezahlt=false;}
        $bestehende = rechnungen_fuer_geraete_laden($pdo, array_map("intval", array_column($geraete, "geraet_id")));
        if ($bestehende !== []) {
            if (count($geraete) === 1) {
                $final = true; $dokumente[] = rechnung_laden($pdo, (int) reset($bestehende)["id"]);
            } else {
                throw new EingabeException("Mindestens ein ausgewähltes Gerät wurde bereits abgerechnet. Öffnen Sie dessen vorhandene Rechnung oder ändern Sie die Auswahl.");
            }
        } else {
            $konfiguration = geschaeftsdaten_laden($pdo); $fehlend = fehlende_rechnungsangaben($konfiguration);
            $gruppen = $modus === "einzeln" ? array_map(static fn(array $g): array => [$g], $geraete) : [$geraete];
            foreach ($gruppen as $gruppe) $dokumente[] = ["geraete" => $gruppe, "summen" => rechnungsbetraege_berechnen($gruppe)];
        }
    }
} catch (EingabeException $e) {
    http_response_code(422); die(h($e->getMessage()));
}
$flash = flash_holen();
?>
<!DOCTYPE html>
<html lang="de"><head><link rel="icon" href="favicon.svg" type="image/svg+xml"><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $final ? "Rechnung" : "Rechnungsentwurf" ?></title><link rel="stylesheet" href="styles.css"><link rel="stylesheet" href="druck.css"><script src="app.js" defer></script></head>
<body class="print-preview-page">
<div class="print-toolbar no-print">
    <a class="button button--secondary" href="auftraege.php">Zurück</a>
    <button class="button button--secondary" type="button" data-print><?= $final ? "Erneut drucken" : "Entwurf drucken" ?></button>
    <?php if (!$final && $fehlend === [] && $alleBezahlt): ?>
        <form method="post" action="rechnung_erstellen.php"><?= csrf_feld() ?><input type="hidden" name="auftrag_id" value="<?= h($auftrag["id"]) ?>"><input type="hidden" name="modus" value="<?= h($modus) ?>"><?php foreach ($geraete as $g): ?><input type="hidden" name="geraete[]" value="<?= h($g["zuordnung_id"]) ?>"><?php endforeach; ?><button class="button button--primary" type="submit">Rechnung<?= count($dokumente) > 1 ? "en" : "" ?> verbindlich erstellen</button></form>
    <?php endif; ?>
</div>
<?php if ($flash): ?><div class="notice notice--<?= h($flash["typ"]) ?> no-print preview-notice" role="status"><?= h($flash["nachricht"]) ?></div><?php endif; ?>
<?php if (!$final && $fehlend !== []): ?><div class="notice notice--warning no-print preview-notice" role="alert"><strong>Endgültige Rechnung noch nicht möglich.</strong> Es fehlen: <?= h(implode(", ", $fehlend)) ?>. <?php if(aktuelle_benutzerrolle()==="administrator"):?><a href="einstellungen.php">Rechnungsdaten ergänzen</a>.<?php else:?>Bitte wenden Sie sich an einen Administrator.<?php endif;?></div><?php endif; ?>
<?php if(!$final&&!$alleBezahlt):?><div class="notice notice--warning no-print preview-notice" role="alert"><strong>Endgültige Rechnung noch nicht möglich.</strong><?php foreach($geraete as $g):?><?php if(zahlungsstatus_fuer_betraege($g["preis"],$g["bereits_bezahlt"])!=="Bezahlt"):?><p>Für dieses Gerät ist noch ein Betrag von <?= betrag_anzeigen($g["restbetrag"]) ?> offen. Erfassen Sie zuerst die vollständige Zahlung. <button class="button button--secondary button--compact" type="button" data-payment-trigger data-order-id="<?= h($auftrag["id"]) ?>" data-device-id="<?= h($g["zuordnung_id"]) ?>" data-device-label="<?= h($g["referenz"]." · ".$g["geraetetyp"]) ?>" data-device-price="<?= h($g["preis"]) ?>" data-device-open="<?= h($g["restbetrag"]) ?>">Als vollständig bezahlt markieren</button></p><?php endif;?><?php endforeach;?></div><?php endif;?>

<?php foreach ($dokumente as $dokumentIndex => $dokument): ?>
<?php
if ($final) {
    $rechnung = $dokument; $positionen = $rechnung["positionen"]; $auftragIdAnzeige = (int) $rechnung["auftragsnummer_snapshot"];
    $geschaeft = $rechnung; $geschaeft["telefon"] = $rechnung["geschaeft_telefon"]; $geschaeft["email"] = $rechnung["geschaeft_email"];
    $kunde = ["name"=>$rechnung["kunde_name"],"firmenname"=>$rechnung["kunde_firmenname"],"telefon"=>$rechnung["kunde_telefon"],"email"=>$rechnung["kunde_email"],"strasse"=>$rechnung["kunde_strasse"],"plz"=>$rechnung["kunde_plz"],"ort"=>$rechnung["kunde_ort"],"land"=>$rechnung["kunde_land"]];
    $rechnungsdatum=(new DateTimeImmutable($rechnung["rechnungsdatum"]))->format("d.m.Y"); $leistungsdatum=(new DateTimeImmutable($rechnung["leistungsdatum"]))->format("d.m.Y");
} else {
    $auftragIdAnzeige=(int)$auftrag["id"]; $geschaeft=$konfiguration; $kunde=$auftrag; $rechnungsdatum=$leistungsdatum=date("d.m.Y"); $positionen=[]; $bezahltCent=0;
    foreach($dokument["summen"]["positionen"] as $p){$g=$p["geraet"];$bezahltCent+=betrag_in_cent($g["bereits_bezahlt"]);$positionen[]=["positionsnummer"=>$p["positionsnummer"],"geraet_id_snapshot"=>$g["geraet_id"],"geraetetyp"=>$g["geraetetyp"],"hersteller"=>$g["hersteller"],"modell"=>$g["modell"],"seriennummer"=>$g["seriennummer"],"leistungsbeschreibung"=>$g["durchgefuehrte_arbeiten"],"netto"=>cent_als_betrag($p["netto_cent"]),"steuerbetrag"=>cent_als_betrag($p["steuer_cent"]),"brutto"=>cent_als_betrag($p["brutto_cent"])];}
    $rechnung=["rechnungsnummer"=>"Wird bei verbindlicher Erstellung vergeben","netto"=>cent_als_betrag($dokument["summen"]["netto_cent"]),"steuerbetrag"=>cent_als_betrag($dokument["summen"]["steuer_cent"]),"brutto"=>cent_als_betrag($dokument["summen"]["brutto_cent"]),"bereits_bezahlt"=>cent_als_betrag($bezahltCent),"restbetrag"=>cent_als_betrag($dokument["summen"]["brutto_cent"]-$bezahltCent),"zahlungsziel"=>$konfiguration["zahlungsziel"],"rechnungshinweise"=>$konfiguration["rechnungshinweise"],"bankverbindung"=>$konfiguration["bankverbindung"]];
}
?>
<main class="paper document-sheet invoice-sheet <?= !$final ? "is-draft" : "" ?> <?= $dokumentIndex > 0 ? "document-page" : "" ?>">
<?php if (!$final): ?><div class="draft-watermark"><?= $alleBezahlt ? "ENTWURF" : "ENTWURF – NOCH NICHT BEZAHLT" ?></div><?php endif; ?>
<header class="invoice-header"><div class="sender-block"><p class="document-brand"><?= h($geschaeft["geschaeftsname"]??"") ?></p><?php foreach (["inhaber","strasse"] as $f): ?><?php if(!empty($geschaeft[$f])):?><p><?= h($geschaeft[$f]) ?></p><?php endif; ?><?php endforeach; ?><p><?= h(trim(($geschaeft["plz"]??"")." ".($geschaeft["ort"]??""))) ?></p><?php if(!empty($geschaeft["telefon"])):?><p>Telefon: <?= h($geschaeft["telefon"]) ?></p><?php endif; ?><?php if(!empty($geschaeft["email"])):?><p>E-Mail: <?= h($geschaeft["email"]) ?></p><?php endif; ?></div><div><p class="document-type">Rechnung</p><?php if(!$final):?><p class="draft-label">ENTWURF</p><?php endif;?></div></header>
<div class="invoice-address-meta"><section class="recipient-block"><p class="small-label">Rechnungsempfänger</p><p><strong><?= h($kunde["name"]) ?></strong><?php if(!empty($kunde["firmenname"])):?><br><?= h($kunde["firmenname"]) ?><?php endif;?><?php foreach(adresse_zeilen($kunde) as $adresszeile):?><br><?= h($adresszeile) ?><?php endforeach;?><?php if(!empty($kunde["telefon"])):?><br><?= h($kunde["telefon"]) ?><?php endif;?><?php if(!empty($kunde["email"])):?><br><?= h($kunde["email"]) ?><?php endif;?></p></section><dl class="document-meta"><div><dt>Rechnungsnummer</dt><dd><?= h($rechnung["rechnungsnummer"]) ?></dd></div><div><dt>Rechnungsdatum</dt><dd><?= h($rechnungsdatum) ?></dd></div><div><dt>Leistungsdatum</dt><dd><?= h($leistungsdatum) ?></dd></div><div><dt>Auftrag</dt><dd>#<?= h($auftragIdAnzeige) ?></dd></div></dl></div>
<section class="document-section invoice-positions"><h1>Rechnung zu Ihrem Reparaturauftrag</h1><table><thead><tr><th>Pos.</th><th>Gerät / Leistung</th><th>Netto</th><th>19 % USt.</th><th>Brutto</th></tr></thead><tbody>
<?php foreach($positionen as $p):?><tr><td><?= h($p["positionsnummer"]) ?></td><td><strong><?= h(geraete_referenz($auftragIdAnzeige,(int)$p["geraet_id_snapshot"])) ?> · <?= h($p["geraetetyp"]) ?></strong><?php $z=implode(" · ",array_filter([$p["hersteller"]??null,$p["modell"]??null]));if($z!==""):?><br><?= h($z) ?><?php endif;?><?php if(!empty($p["seriennummer"])):?><br>Seriennummer: <?= h($p["seriennummer"]) ?><?php endif;?><?php if(!empty($p["leistungsbeschreibung"])):?><div class="line-description"><?= nl2br(h($p["leistungsbeschreibung"])) ?></div><?php endif;?></td><td><?= betrag_anzeigen($p["netto"]) ?></td><td><?= betrag_anzeigen($p["steuerbetrag"]) ?></td><td><?= betrag_anzeigen($p["brutto"]) ?></td></tr><?php endforeach;?>
</tbody></table></section>
<section class="invoice-summary"><dl><div><dt>Nettobetrag</dt><dd><?= betrag_anzeigen($rechnung["netto"]) ?></dd></div><div><dt>Umsatzsteuer 19 %</dt><dd><?= betrag_anzeigen($rechnung["steuerbetrag"]) ?></dd></div><div class="invoice-gross"><dt>Bruttobetrag</dt><dd><?= betrag_anzeigen($rechnung["brutto"]) ?></dd></div><div><dt>Zahlungsstatus</dt><dd><?= h($final?$rechnung["zahlungsstatus"]:($alleBezahlt?"Bezahlt":"Noch nicht bezahlt")) ?></dd></div><div><dt>Bezahlter Betrag</dt><dd><?= betrag_anzeigen($rechnung["bereits_bezahlt"]) ?></dd></div><?php if($final&&!empty($rechnung["zahlungsdatum_snapshot"])):?><div><dt>Zahlungsdatum</dt><dd><?= h($rechnung["zahlungsdatum_snapshot"]) ?></dd></div><?php endif;?><?php if($final&&!empty($rechnung["zahlungsart_snapshot"])):?><div><dt>Zahlungsart</dt><dd><?= h($rechnung["zahlungsart_snapshot"]) ?></dd></div><?php endif;?><div class="total-due"><dt>Restbetrag</dt><dd><?= betrag_anzeigen($rechnung["restbetrag"]) ?></dd></div></dl></section>
<footer class="invoice-footer"><?php if(!empty($rechnung["zahlungsziel"])):?><p><strong>Zahlungsziel:</strong> <?= h($rechnung["zahlungsziel"]) ?></p><?php endif;?><?php if(!empty($rechnung["rechnungshinweise"])):?><p><?= nl2br(h($rechnung["rechnungshinweise"])) ?></p><?php endif;?><?php if(!empty($rechnung["bankverbindung"])):?><p><strong>Bankverbindung:</strong><br><?= nl2br(h($rechnung["bankverbindung"])) ?></p><?php endif;?><p class="tax-details"><?php if(!empty($geschaeft["steuernummer"])):?>Steuernummer: <?= h($geschaeft["steuernummer"]) ?><?php endif;?><?php if(!empty($geschaeft["ust_id"])):?> · USt-ID: <?= h($geschaeft["ust_id"]) ?><?php endif;?></p></footer>
</main>
<?php endforeach; ?>
<?php require __DIR__."/zahlung_dialog.php"; ?>
</body></html>
