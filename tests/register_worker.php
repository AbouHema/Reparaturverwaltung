<?php
declare(strict_types=1);
require_once __DIR__."/../db.php";require_once __DIR__."/../funktionen.php";require_once __DIR__."/../auth_service.php";
if(PHP_SAPI!=="cli"||$argc!==3)exit(2);
try{$konto=konto_registrieren($pdo,$argv[1],$argv[2],"NurFuerParallel123!","NurFuerParallel123!");echo $konto["rolle"];}catch(Throwable $e){fwrite(STDERR,get_class($e).": ".$e->getMessage());exit(1);}
