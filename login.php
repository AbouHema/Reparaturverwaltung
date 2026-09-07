<?php
declare(strict_types=1);
define("OEFFENTLICHE_SEITE", true);
require_once __DIR__ . "/db.php"; require_once __DIR__ . "/funktionen.php"; require_once __DIR__ . "/auth_service.php";
$fehler=null;$kennung=trim((string)($_POST["kennung"]??""));$flash=flash_holen();
if($_SERVER["REQUEST_METHOD"]==="POST"){
 try{
  csrf_pruefen($_POST["csrf_token"]??null);$passwort=(string)($_POST["passwort"]??"");
  $schluessel=hash("sha256",mb_strtolower($kennung)."|".($_SERVER["REMOTE_ADDR"]??""));
  $stmt=$pdo->prepare("SELECT fehlversuche,gesperrt_bis FROM login_sperren WHERE schluessel_hash=?");$stmt->execute([$schluessel]);$sperre=$stmt->fetch();
  if($sperre&&$sperre["gesperrt_bis"]&&strtotime($sperre["gesperrt_bis"])>time())throw new EingabeException("Die Anmeldung ist vorübergehend gesperrt. Bitte versuchen Sie es später erneut.");
  $benutzer=benutzer_fuer_anmeldung($pdo,$kennung);$dummy='$2y$10$wH7qk4tW3F6b6K7VQ9XWke0g0xw9eG9L8Y2yMBfZQbQvO9wD9fTqG';
  $passwortKorrekt=password_verify($passwort,$benutzer["passwort_hash"]??$dummy);
  $freigegeben=$benutzer&&(int)$benutzer["aktiv"]===1&&$benutzer["kontostatus"]==="aktiv"&&in_array($benutzer["rolle"],["administrator","mitarbeiter"],true)&&(!$benutzer["gesperrt_bis"]||strtotime($benutzer["gesperrt_bis"])<=time());
  if(!$passwortKorrekt||!$freigegeben){
   $pdo->prepare("INSERT INTO login_sperren (schluessel_hash,fehlversuche,gesperrt_bis) VALUES (?,1,NULL) ON DUPLICATE KEY UPDATE fehlversuche=fehlversuche+1,gesperrt_bis=IF(fehlversuche>=5,DATE_ADD(NOW(),INTERVAL 15 MINUTE),gesperrt_bis)")->execute([$schluessel]);
   if($benutzer)$pdo->prepare("UPDATE benutzer SET fehlversuche=fehlversuche+1,gesperrt_bis=IF(fehlversuche>=5,DATE_ADD(NOW(),INTERVAL 15 MINUTE),gesperrt_bis) WHERE id=?")->execute([$benutzer["id"]]);
   audit_protokollieren($pdo,"anmeldung_fehlgeschlagen","benutzer",$benutzer["id"]??null,null,$benutzer?(int)$benutzer["id"]:null);
   if($passwortKorrekt&&$benutzer&&$benutzer["kontostatus"]==="wartend")throw new EingabeException("Das Konto wartet noch auf Freigabe.");
   throw new EingabeException("Benutzername/E-Mail-Adresse oder Passwort ist nicht korrekt.");
  }
  $pdo->prepare("DELETE FROM login_sperren WHERE schluessel_hash=?")->execute([$schluessel]);$pdo->prepare("UPDATE benutzer SET fehlversuche=0,gesperrt_bis=NULL WHERE id=?")->execute([$benutzer["id"]]);
  if(!session_regenerate_id(true)){error_log("Die Sitzungs-ID konnte nach der Anmeldung nicht erneuert werden.");throw new EingabeException("Die Anmeldung konnte momentan nicht abgeschlossen werden. Bitte versuchen Sie es erneut.");}unset($_SESSION["csrf_vorherige_tokens"]);$_SESSION["benutzer_id"]=(int)$benutzer["id"];$_SESSION["benutzername"]=$benutzer["benutzername"];$_SESSION["benutzer_rolle"]=$benutzer["rolle"];$_SESSION["sitzung_version"]=(int)$benutzer["sitzung_version"];$_SESSION["letzte_aktivitaet"]=time();$_SESSION["csrf_token"]=bin2hex(random_bytes(32));
  audit_protokollieren($pdo,"anmeldung_erfolgreich","benutzer",$benutzer["id"]);$weiter=(string)($_POST["weiter"]??"auftraege.php");if(preg_match('/^[a-zA-Z0-9_\-.]+(?:\?[a-zA-Z0-9_=&%\[\].-]*)?$/',$weiter)!==1)$weiter="auftraege.php";weiterleiten($weiter);
 }catch(EingabeException $e){$fehler=$e->getMessage();}
}
?>
<!DOCTYPE html><html lang="de"><head><link rel="icon" href="favicon.svg" type="image/svg+xml"><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Anmelden – Computerfachmann</title><link rel="stylesheet" href="styles.css"><script src="app.js" defer></script></head><body class="auth-page"><main class="glass-panel auth-card"><a class="auth-brand" href="login.php"><span class="brand-mark" aria-hidden="true">CF</span><span>Computerfachmann</span></a><h1>Anmelden</h1><?php if($flash):?><div class="notice notice--<?=h($flash["typ"])?>" role="status"><?=h($flash["nachricht"])?></div><?php endif;?><?php if($fehler):?><div class="notice notice--error" role="alert"><?=h($fehler)?></div><?php endif;?><form method="post" action="login.php" class="auth-form" data-validated-form novalidate><?=csrf_feld()?><input type="hidden" name="weiter" value="<?=h($_GET["weiter"]??"auftraege.php")?>"><div class="form-field"><label for="kennung">Benutzername oder E-Mail-Adresse</label><input id="kennung" name="kennung" value="<?=h($kennung)?>" autocomplete="username" required autofocus></div><div class="form-field"><label for="passwort">Passwort</label><input id="passwort" type="password" name="passwort" autocomplete="current-password" required></div><label class="password-toggle"><input type="checkbox" data-password-toggle> Passwort anzeigen</label><button class="button button--primary auth-submit" type="submit">Anmelden</button></form><nav class="auth-links" aria-label="Weitere Möglichkeiten"><a href="registrieren.php">Konto erstellen</a><a href="passwort_vergessen.php">Passwort vergessen?</a></nav></main></body></html>
