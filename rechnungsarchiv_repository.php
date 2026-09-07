<?php
declare(strict_types=1);

function archiv_filter(PDO $pdo,array $eingabe): array
{
    $suche=trim((string)($eingabe["suche"]??""));$von=trim((string)($eingabe["von"]??""));$bis=trim((string)($eingabe["bis"]??""));$status=(string)($eingabe["status"]??"");
    foreach([[&$von,"von"],[&$bis,"bis"]] as &$paar){if($paar[0]!==""&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$paar[0]))throw new EingabeException("Der Datumsfilter ist ungültig.");}unset($paar);
    if(!in_array($status,["","Bezahlt","Teilweise bezahlt","Offen","Storniert"],true))$status="";
    $where=[];$parameter=[];
    if($suche!==""){$m="%".str_replace(["\\","%","_"],["\\\\","\\%","\\_"],mb_strtolower($suche))."%";$where[]="(LOWER(r.rechnungsnummer) LIKE ? ESCAPE '\\\\' OR LOWER(r.kunde_name) LIKE ? ESCAPE '\\\\' OR LOWER(CONCAT_WS(' ',r.kunde_strasse,r.kunde_plz,r.kunde_ort,r.kunde_land)) LIKE ? ESCAPE '\\\\' OR CAST(r.auftragsnummer_snapshot AS CHAR) LIKE ? ESCAPE '\\\\' OR EXISTS(SELECT 1 FROM rechnungspositionen p WHERE p.rechnung_id=r.id AND LOWER(CONCAT('#',r.auftragsnummer_snapshot,'-G',p.geraet_id_snapshot)) LIKE ? ESCAPE '\\\\'))";array_push($parameter,$m,$m,$m,$m,$m);}
    if($von!==""){$where[]="r.rechnungsdatum>=?";$parameter[]=$von;}if($bis!==""){$where[]="r.rechnungsdatum<=?";$parameter[]=$bis;}
    if($status==="Storniert")$where[]="r.storniert_am IS NOT NULL";elseif($status!==""){$where[]="r.storniert_am IS NULL AND r.zahlungsstatus=?";$parameter[]=$status;}
    $sql="SELECT r.*,GROUP_CONCAT(CONCAT('#',r.auftragsnummer_snapshot,'-G',p.geraet_id_snapshot,' · ',p.geraetetyp) ORDER BY p.positionsnummer SEPARATOR ' | ') geraete FROM rechnungen r LEFT JOIN rechnungspositionen p ON p.rechnung_id=r.id".($where?" WHERE ".implode(" AND ",$where):"")." GROUP BY r.id ORDER BY r.rechnungsdatum DESC,r.id DESC";
    $stmt=$pdo->prepare($sql);$stmt->execute($parameter);return ["rechnungen"=>$stmt->fetchAll(),"suche"=>$suche,"von"=>$von,"bis"=>$bis,"status"=>$status];
}
