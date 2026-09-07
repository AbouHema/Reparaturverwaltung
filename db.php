<?php

declare(strict_types=1);

$host = getenv("DB_HOST") ?: "localhost";
$dbname = getenv("DB_NAME") ?: "reparaturverwaltung";
$user = getenv("DB_USER");
$password = getenv("DB_PASSWORD");
$port = filter_var(getenv("DB_PORT") ?: "3306", FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => 65535]]);

if (!is_string($user) || $user === "" || !is_string($password) || $password === "" || $port === false) {
    error_log("DB_USER oder DB_PASSWORD ist nicht als Umgebungsvariable gesetzt.");
    http_response_code(503);
    die("Die Anwendung ist noch nicht vollständig konfiguriert.");
}

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(503);
    die("Die Datenbank ist momentan nicht erreichbar. Bitte versuchen Sie es später erneut.");
}
