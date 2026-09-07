<?php
declare(strict_types=1);
require_once __DIR__."/funktionen.php";

function zahlungsarten(): array { return ["Barzahlung","EC-/Kartenzahlung","Überweisung","Sonstige"]; }

function zahlungsdatum_validieren(mixed $wert): string
{
    $wert=trim((string)$wert);$datum=DateTimeImmutable::createFromFormat('!Y-m-d',$wert);
    if(!$datum||$datum->format('Y-m-d')!==$wert)throw new EingabeException("Bitte wählen Sie ein gültiges Zahlungsdatum.");
    if($datum>new DateTimeImmutable('today'))throw new EingabeException("Das Zahlungsdatum darf nicht in der Zukunft liegen.");
    return $wert;
}

function zahlungsart_validieren(mixed $wert): string
{
    $wert=(string)$wert;if(!in_array($wert,zahlungsarten(),true))throw new EingabeException("Bitte wählen Sie eine gültige Zahlungsart.");return $wert;
}

function geraet_vollstaendig_bezahlen(PDO $pdo,int $auftragId,int $zuordnungId,string $datum,string $art,?string $notiz): array
{
    $datum=zahlungsdatum_validieren($datum);$art=zahlungsart_validieren($art);$notiz=trim((string)$notiz);
    if(mb_strlen($notiz)>1000)throw new EingabeException("Die Zahlungsnotiz darf höchstens 1.000 Zeichen lang sein.");
    $pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("SELECT rag.geraet_id,rag.preis,rag.bereits_bezahlt,g.geraetetyp,ra.nicht_zugeordnet_bezahlt FROM reparaturauftrag_geraete rag INNER JOIN geraete g ON g.id=rag.geraet_id INNER JOIN reparaturauftraege ra ON ra.id=rag.auftrag_id WHERE rag.id=? AND rag.auftrag_id=? FOR UPDATE");$stmt->execute([$zuordnungId,$auftragId]);$g=$stmt->fetch();
        if(!$g)throw new EingabeException("Das Gerät gehört nicht zu diesem Reparaturauftrag.");
        if(betrag_in_cent((string)$g["nicht_zugeordnet_bezahlt"])>0)throw new EingabeException("Ordnen Sie zuerst die vorhandene ältere Zahlung den Geräten zu.");
        $preis=betrag_in_cent((string)$g["preis"]);$bezahlt=betrag_in_cent((string)$g["bereits_bezahlt"]);$offen=$preis-$bezahlt;
        if($offen<=0)throw new EingabeException("Dieses Gerät ist bereits vollständig bezahlt.");
        $stmt=$pdo->prepare("INSERT INTO geraete_zahlungen (auftrag_id,auftragsnummer_snapshot,zuordnung_id,geraet_id_snapshot,betrag,vorgang,zahlungsdatum,zahlungsart,notiz,benutzer_id) VALUES (?,?,?,?,?,'zahlung',?,?,?,?)");
        $stmt->execute([$auftragId,$auftragId,$zuordnungId,$g["geraet_id"],cent_als_betrag($offen),$datum,$art,$notiz===""?null:$notiz,aktuelle_benutzer_id()]);$zahlungId=(int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE reparaturauftrag_geraete SET bereits_bezahlt=preis WHERE id=? AND auftrag_id=?")->execute([$zuordnungId,$auftragId]);
        $pdo->prepare("UPDATE reparaturauftraege SET bereits_bezahlt=bereits_bezahlt+? WHERE id=?")->execute([cent_als_betrag($offen),$auftragId]);
        audit_protokollieren($pdo,"zahlung_erfasst","geraete_zahlung",$zahlungId,["auftrag_id"=>$auftragId,"geraet_id"=>(int)$g["geraet_id"],"betrag"=>cent_als_betrag($offen),"zahlungsart"=>$art]);
        $stmt=$pdo->prepare("SELECT COALESCE(SUM(rag.preis),0) gesamt,ra.bereits_bezahlt bezahlt FROM reparaturauftraege ra JOIN reparaturauftrag_geraete rag ON rag.auftrag_id=ra.id WHERE ra.id=? GROUP BY ra.id,ra.bereits_bezahlt");$stmt->execute([$auftragId]);$summe=$stmt->fetch();
        $pdo->commit();
        return ["betrag"=>cent_als_betrag($offen),"geraet_preis"=>cent_als_betrag($preis),"geraet_bezahlt"=>cent_als_betrag($preis),"geraet_rest"=>"0.00","zahlungsstatus"=>"Bezahlt","gesamt"=>(string)$summe["gesamt"],"bezahlt"=>(string)$summe["bezahlt"],"rest"=>cent_als_betrag(betrag_in_cent((string)$summe["gesamt"])-betrag_in_cent((string)$summe["bezahlt"]))];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function zahlung_stornieren(PDO $pdo,int $zahlungId,string $grund): void
{
    $grund=trim($grund);if($grund===""||mb_strlen($grund)>1000)throw new EingabeException("Geben Sie einen Stornogrund mit höchstens 1.000 Zeichen an.");
    $pdo->beginTransaction();try{
        $stmt=$pdo->prepare("SELECT * FROM geraete_zahlungen WHERE id=? FOR UPDATE");$stmt->execute([$zahlungId]);$z=$stmt->fetch();if(!$z||$z["vorgang"]!=="zahlung")throw new EingabeException("Die Zahlung wurde nicht gefunden.");
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM geraete_zahlungen WHERE bezugszahlung_id=? AND vorgang='storno'");$stmt->execute([$zahlungId]);if((int)$stmt->fetchColumn()>0)throw new EingabeException("Diese Zahlung wurde bereits storniert.");
        if($z["zuordnung_id"]===null||$z["auftrag_id"]===null)throw new EingabeException("Diese historische Zahlung kann nicht mehr am gelöschten Auftrag korrigiert werden.");
        $stmt=$pdo->prepare("SELECT bereits_bezahlt FROM reparaturauftrag_geraete WHERE id=? AND auftrag_id=? FOR UPDATE");$stmt->execute([$z["zuordnung_id"],$z["auftrag_id"]]);$aktuell=$stmt->fetchColumn();if($aktuell===false||betrag_in_cent((string)$aktuell)<betrag_in_cent((string)$z["betrag"]))throw new EingabeException("Die Zahlung kann nicht konsistent storniert werden.");
        $stmt=$pdo->prepare("SELECT rechnung_id FROM rechnung_geraete WHERE geraet_id_snapshot=? AND storniert_am IS NULL LIMIT 1 FOR UPDATE");$stmt->execute([$z["geraet_id_snapshot"]]);if($stmt->fetchColumn()!==false)throw new EingabeException("Stornieren Sie zuerst die zugehörige Rechnung im Rechnungsarchiv.");
        $pdo->prepare("INSERT INTO geraete_zahlungen (auftrag_id,auftragsnummer_snapshot,zuordnung_id,geraet_id_snapshot,betrag,vorgang,zahlungsdatum,zahlungsart,notiz,bezugszahlung_id,benutzer_id) VALUES (?,?,?,?,?,'storno',CURRENT_DATE,'Sonstige',?,?,?)")->execute([$z["auftrag_id"],$z["auftragsnummer_snapshot"],$z["zuordnung_id"],$z["geraet_id_snapshot"],$z["betrag"],$grund,$zahlungId,aktuelle_benutzer_id()]);$stornoId=(int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE reparaturauftrag_geraete SET bereits_bezahlt=bereits_bezahlt-? WHERE id=?")->execute([$z["betrag"],$z["zuordnung_id"]]);$pdo->prepare("UPDATE reparaturauftraege SET bereits_bezahlt=bereits_bezahlt-? WHERE id=?")->execute([$z["betrag"],$z["auftrag_id"]]);
        audit_protokollieren($pdo,"zahlung_storniert","geraete_zahlung",$stornoId,["bezugszahlung_id"=>$zahlungId,"grund"=>$grund]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function altzahlung_zuordnen(PDO $pdo,int $auftragId,array $verteilung): void
{
    $pdo->beginTransaction();try{
        $stmt=$pdo->prepare("SELECT nicht_zugeordnet_bezahlt,erstellt_am FROM reparaturauftraege WHERE id=? FOR UPDATE");$stmt->execute([$auftragId]);$auftrag=$stmt->fetch();if(!$auftrag)throw new EingabeException("Reparaturauftrag wurde nicht gefunden.");
        $alt=betrag_in_cent((string)$auftrag["nicht_zugeordnet_bezahlt"]);if($alt<=0)throw new EingabeException("Für diesen Auftrag ist keine ältere Zahlung mehr zuzuordnen.");
        $stmt=$pdo->prepare("SELECT id,geraet_id,preis,bereits_bezahlt FROM reparaturauftrag_geraete WHERE auftrag_id=? ORDER BY id FOR UPDATE");$stmt->execute([$auftragId]);$geraete=$stmt->fetchAll();$nachId=[];foreach($geraete as $g)$nachId[(int)$g["id"]]=$g;
        $summe=0;$normalisiert=[];foreach($verteilung as $id=>$betrag){$id=positive_id($id,"Gerät");if(!isset($nachId[$id]))throw new EingabeException("Ein Gerät gehört nicht zu diesem Auftrag.");$wert=betrag_normalisieren($betrag,"Zuordnungsbetrag",true);$cent=betrag_in_cent($wert);$offen=betrag_in_cent((string)$nachId[$id]["preis"])-betrag_in_cent((string)$nachId[$id]["bereits_bezahlt"]);if($cent>$offen)throw new EingabeException("Ein Zuordnungsbetrag überschreitet den offenen Gerätebetrag.");$summe+=$cent;$normalisiert[$id]=$wert;}
        if($summe!==$alt)throw new EingabeException("Verteilen Sie den vorhandenen Betrag vollständig und exakt auf die Geräte.");
        $insert=$pdo->prepare("INSERT INTO geraete_zahlungen (auftrag_id,auftragsnummer_snapshot,zuordnung_id,geraet_id_snapshot,betrag,vorgang,zahlungsdatum,zahlungsart,notiz,benutzer_id) VALUES (?,?,?,?,?,'zahlung',?,'Bestandsübernahme','Alte Gesamtzahlung ausdrücklich zugeordnet',?)");
        $update=$pdo->prepare("UPDATE reparaturauftrag_geraete SET bereits_bezahlt=bereits_bezahlt+? WHERE id=? AND auftrag_id=?");
        foreach($normalisiert as $id=>$wert){if(betrag_in_cent($wert)===0)continue;$g=$nachId[$id];$insert->execute([$auftragId,$auftragId,$id,$g["geraet_id"],$wert,date("Y-m-d",strtotime((string)$auftrag["erstellt_am"])),aktuelle_benutzer_id()]);$update->execute([$wert,$id,$auftragId]);}
        $pdo->prepare("UPDATE reparaturauftraege SET nicht_zugeordnet_bezahlt=0 WHERE id=?")->execute([$auftragId]);audit_protokollieren($pdo,"altzahlung_zugeordnet","reparaturauftrag",$auftragId,["betrag"=>cent_als_betrag($alt)]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
