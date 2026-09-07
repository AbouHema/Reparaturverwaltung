<?php
declare(strict_types=1);
require_once __DIR__."/db.php";require_once __DIR__."/funktionen.php";require_once __DIR__."/auth_service.php";
if($_SERVER["REQUEST_METHOD"]!=="POST"){http_response_code(405);die("Ungültige Methode.");}
try{
 csrf_pruefen($_POST["csrf_token"]??null);berechtigung_pruefen(["administrator"]);$aktion=(string)($_POST["aktion"]??"");
 if($aktion==="anlegen"){
  $name=benutzername_validieren($_POST["benutzername"]??null);$email=konto_email_validieren($_POST["email"]??null);$pw=neues_passwort_validieren($_POST["passwort"]??null,$_POST["passwort_wiederholen"]??null);$rolle=(string)($_POST["rolle"]??"");if(!in_array($rolle,["administrator","mitarbeiter"],true))throw new EingabeException("Die Rolle ist ungültig.");
  try{$pdo->prepare("INSERT INTO benutzer (benutzername,email,passwort_hash,rolle,aktiv,kontostatus,freigegeben_am,freigegeben_von) VALUES (?,?,?, ?,1,'aktiv',NOW(),?)")->execute([$name,$email,password_hash($pw,PASSWORD_DEFAULT),$rolle,aktuelle_benutzer_id()]);}catch(PDOException $e){if($e->getCode()==="23000")throw new EingabeException("Benutzername oder E-Mail-Adresse wird bereits verwendet.");throw $e;}
  audit_protokollieren($pdo,"benutzer_angelegt","benutzer",$pdo->lastInsertId(),["rolle"=>$rolle]);flash_setzen("success","Benutzer wurde angelegt.");
 }elseif($aktion==="status"){
  $id=positive_id($_POST["benutzer_id"]??null,"Benutzer");$status=(string)($_POST["status"]??"");if($id===aktuelle_benutzer_id())throw new EingabeException("Der eigene Zugang kann nicht deaktiviert oder abgelehnt werden.");if(!in_array($status,["aktiv","abgelehnt","deaktiviert"],true))throw new EingabeException("Der Kontostatus ist ungültig.");$aktiv=$status==="aktiv"?1:0;
  $stmt=$pdo->prepare("UPDATE benutzer SET aktiv=?,kontostatus=?,freigegeben_am=IF(?='aktiv',NOW(),freigegeben_am),freigegeben_von=IF(?='aktiv',?,freigegeben_von),fehlversuche=IF(?='aktiv',0,fehlversuche),gesperrt_bis=IF(?='aktiv',NULL,gesperrt_bis),sitzung_version=sitzung_version+1 WHERE id=?");$stmt->execute([$aktiv,$status,$status,$status,aktuelle_benutzer_id(),$status,$status,$id]);if($stmt->rowCount()!==1)throw new EingabeException("Das Benutzerkonto wurde nicht gefunden.");audit_protokollieren($pdo,"benutzer_status_geaendert","benutzer",$id,["status"=>$status]);flash_setzen("success","Benutzerstatus wurde geändert.");
 }elseif($aktion==="passwort"){
  $id=positive_id($_POST["benutzer_id"]??null,"Benutzer");$pw=neues_passwort_validieren($_POST["passwort"]??null,$_POST["passwort_wiederholen"]??null);$stmt=$pdo->prepare("UPDATE benutzer SET passwort_hash=?,sitzung_version=sitzung_version+1,fehlversuche=0,gesperrt_bis=NULL WHERE id=?");$stmt->execute([password_hash($pw,PASSWORD_DEFAULT),$id]);if($stmt->rowCount()!==1)throw new EingabeException("Das Benutzerkonto wurde nicht gefunden.");$pdo->prepare("UPDATE passwort_reset_tokens SET verwendet_am=NOW() WHERE benutzer_id=? AND verwendet_am IS NULL")->execute([$id]);audit_protokollieren($pdo,"passwort_zurueckgesetzt","benutzer",$id,["weg"=>"administration"]);flash_setzen("success","Passwort wurde sicher zurückgesetzt. Bestehende Sitzungen wurden beendet.");
 }elseif($aktion==="registrierung"){
  $aktiv=(string)($_POST["aktiv"]??"")==="1"?1:0;$pdo->prepare("UPDATE anwendungseinstellungen SET selbstregistrierung_aktiv=? WHERE id=1")->execute([$aktiv]);audit_protokollieren($pdo,"selbstregistrierung_geaendert","einstellung",1,["aktiv"=>$aktiv]);flash_setzen("success","Die Selbstregistrierung wurde ".($aktiv?"aktiviert.":"deaktiviert."));
 }else throw new EingabeException("Unbekannte Aktion.");
}catch(Throwable $e){if(!($e instanceof EingabeException))error_log("Benutzeraktion: ".$e->getMessage());flash_setzen("error",$e instanceof EingabeException?$e->getMessage():"Die Benutzeraktion ist fehlgeschlagen.");}
weiterleiten("benutzer.php");
