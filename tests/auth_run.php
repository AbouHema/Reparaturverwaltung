<?php
declare(strict_types=1);

require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../funktionen.php";
require_once __DIR__ . "/../auth_service.php";

$tests=0;$fehler=[];
function pruefe_auth(bool $wert,string $name):void{global $tests,$fehler;$tests++;if(!$wert)$fehler[]=$name;}
function erwartet_eingabefehler(callable $aktion,string $text):bool{try{$aktion();return false;}catch(EingabeException $e){return str_contains($e->getMessage(),$text);}}

try{
 pruefe_auth(erwartet_eingabefehler(fn()=>neues_passwort_validieren("1234567","1234567"),"mindestens 8 Zeichen"),"Sieben Zeichen werden abgelehnt");
 pruefe_auth(neues_passwort_validieren("12345678","12345678")==="12345678","Genau acht Zeichen werden akzeptiert");
 pruefe_auth(neues_passwort_validieren("123456789","123456789")==="123456789","Längere Passwörter werden akzeptiert");
 pruefe_auth(erwartet_eingabefehler(fn()=>neues_passwort_validieren("12345678","87654321"),"stimmen nicht überein"),"Unterschiedliche Passwortfelder werden abgelehnt");
 $pdo->exec("SET FOREIGN_KEY_CHECKS=0;TRUNCATE TABLE passwort_reset_tokens;TRUNCATE TABLE passwort_reset_limits;TRUNCATE TABLE login_sperren;TRUNCATE TABLE audit_protokoll;TRUNCATE TABLE benutzer;SET FOREIGN_KEY_CHECKS=1");
 $pdo->exec("UPDATE anwendungseinstellungen SET selbstregistrierung_aktiv=1 WHERE id=1");
 pruefe_auth((int)$pdo->query("SELECT COUNT(*) FROM benutzer")->fetchColumn()===0,"Leere Benutzertabelle erkannt");
 $prozesse=[];
 foreach([["parallel_a","parallel-a@example.test"],["parallel_b","parallel-b@example.test"]] as [$parallelName,$parallelEmail]){
  $beschreibung=[0=>["pipe","r"],1=>["pipe","w"],2=>["pipe","w"]];$prozess=proc_open([PHP_BINARY,__DIR__."/register_worker.php",$parallelName,$parallelEmail],$beschreibung,$pipes,__DIR__);if(!is_resource($prozess))throw new RuntimeException("Paralleltest konnte nicht gestartet werden.");fclose($pipes[0]);$prozesse[]=[$prozess,$pipes];
 }
 $rollen=[];foreach($prozesse as [$prozess,$pipes]){$ausgabe=trim(stream_get_contents($pipes[1]));$err=trim(stream_get_contents($pipes[2]));fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($prozess);if($code!==0)throw new RuntimeException("Paralleltest fehlgeschlagen: ".$err);$rollen[]=$ausgabe;}
 sort($rollen);pruefe_auth($rollen===["administrator","mitarbeiter"]&& (int)$pdo->query("SELECT COUNT(*) FROM benutzer WHERE rolle='administrator'")->fetchColumn()===1,"Gleichzeitige Registrierungen erzeugen genau einen Administrator");
 $pdo->exec("SET FOREIGN_KEY_CHECKS=0;TRUNCATE TABLE audit_protokoll;TRUNCATE TABLE benutzer;SET FOREIGN_KEY_CHECKS=1");
 $erstes=konto_registrieren($pdo,"erstadmin","admin@example.test","SehrSicher123!","SehrSicher123!");
 pruefe_auth($erstes["rolle"]==="administrator"&&$erstes["kontostatus"]==="aktiv","Erstes Konto ist aktiver Administrator");
 $zweites=konto_registrieren($pdo,"mitarbeiter","team@example.test","NochSicherer456!","NochSicherer456!");
 pruefe_auth($zweites["rolle"]==="mitarbeiter"&&$zweites["kontostatus"]==="wartend","Weiteres Konto wartet als Mitarbeiter");
 pruefe_auth((int)$pdo->query("SELECT COUNT(*) FROM benutzer WHERE rolle='administrator'")->fetchColumn()===1,"Genau ein erster Administrator");
 pruefe_auth(erwartet_eingabefehler(fn()=>konto_registrieren($pdo,"erstadmin","neu@example.test","SehrSicher123!","SehrSicher123!"),"Dieser Benutzername wird bereits verwendet."),"Doppelter Benutzername abgelehnt");
 pruefe_auth(erwartet_eingabefehler(fn()=>konto_registrieren($pdo,"neuername","admin@example.test","SehrSicher123!","SehrSicher123!"),"Für diese E-Mail-Adresse besteht bereits ein Konto."),"Doppelte E-Mail abgelehnt");
 $perName=benutzer_fuer_anmeldung($pdo,"erstadmin");$perMail=benutzer_fuer_anmeldung($pdo,"ADMIN@EXAMPLE.TEST");
 pruefe_auth((int)$perName["id"]===(int)$perMail["id"],"Login-Suche per Benutzername und E-Mail");
 pruefe_auth(password_verify("SehrSicher123!",$perName["passwort_hash"])&&!hash_equals("SehrSicher123!",$perName["passwort_hash"]),"Passwort sicher gehasht");
 $reset1=reset_anfrage_erstellen($pdo,"admin@example.test","127.0.0.11");$reset2=reset_anfrage_erstellen($pdo,"admin@example.test","127.0.0.12");
 pruefe_auth($reset1!==null&&$reset2!==null&&reset_token_laden($pdo,$reset1["token"])===null,"Neuer Reset macht alten Token ungültig");
 $pdo->prepare("UPDATE passwort_reset_tokens SET gueltig_bis=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE token_hash=?")->execute([hash("sha256",$reset2["token"])]);
 pruefe_auth(reset_token_laden($pdo,$reset2["token"])===null,"Abgelaufener Token abgelehnt");
 $reset3=reset_anfrage_erstellen($pdo,"admin@example.test","127.0.0.13");$versionVor=(int)$perName["sitzung_version"];
 passwort_mit_token_aendern($pdo,$reset3["token"],"GanzNeu7890!","GanzNeu7890!");$danach=benutzer_fuer_anmeldung($pdo,"admin@example.test");
 pruefe_auth(!password_verify("SehrSicher123!",$danach["passwort_hash"])&&password_verify("GanzNeu7890!",$danach["passwort_hash"]),"Nur neues Passwort funktioniert");
 pruefe_auth((int)$danach["sitzung_version"]===$versionVor+1,"Bestehende Sitzungen invalidiert");
 pruefe_auth(reset_token_laden($pdo,$reset3["token"])===null,"Verwendeter Token abgelehnt");
 $_SESSION["csrf_token"]=str_repeat("a",64);csrf_pruefen(str_repeat("a",64));$alt=$_SESSION["csrf_token"];
 pruefe_auth(erwartet_eingabefehler(fn()=>csrf_pruefen("falsch"),"Ihre Sitzung ist abgelaufen"),"CSRF-Ablauf verständlich");
 pruefe_auth($_SESSION["csrf_token"]===$alt,"Ein fremder Token entwertet den gültigen Sitzungstoken nicht");
 csrf_token_erneuern();pruefe_auth($_SESSION["csrf_token"]!==$alt&&preg_match('/^[a-f0-9]{64}$/D',$_SESSION["csrf_token"])===1,"CSRF-Token kann nach erfolgreicher Aktion erneuert werden");
 csrf_pruefen($alt);pruefe_auth(true,"Bereits geöffneter Tab bleibt nach Token-Erneuerung gültig");
 $pdo->exec("UPDATE anwendungseinstellungen SET selbstregistrierung_aktiv=0 WHERE id=1");
 pruefe_auth(erwartet_eingabefehler(fn()=>konto_registrieren($pdo,"gesperrt","gesperrt@example.test","SehrSicher123!","SehrSicher123!"),"nicht verfügbar"),"Selbstregistrierung zentral abschaltbar");
 $pdo->exec("SET FOREIGN_KEY_CHECKS=0;TRUNCATE TABLE passwort_reset_tokens;TRUNCATE TABLE passwort_reset_limits;TRUNCATE TABLE login_sperren;TRUNCATE TABLE audit_protokoll;TRUNCATE TABLE benutzer;SET FOREIGN_KEY_CHECKS=1");
 $pdo->exec("UPDATE anwendungseinstellungen SET selbstregistrierung_aktiv=1 WHERE id=1");
}catch(Throwable $e){$fehler[]="Unerwarteter Fehler: ".$e->getMessage();}

if($fehler){fwrite(STDERR,implode("\n",$fehler)."\n");exit(1);}echo "Auth-Tests: {$tests} erfolgreich.\n";
