#!/bin/zsh

set -eu

cd -- "$(dirname -- "$0")"

task_browser_oeffnen=0
if [[ "${1:-}" == "--open" ]]; then
    task_browser_oeffnen=1
    shift
fi
if (( $# > 0 )); then
    echo "Unbekannte Startoption." >&2
    exit 1
fi

task_app_url="http://127.0.0.1:8080/registrieren.php"
if (( task_browser_oeffnen == 1 )) && curl -fs -o /dev/null "$task_app_url"; then
    open "$task_app_url"
    exit 0
fi

task_db_host="${DB_HOST:-127.0.0.1}"
task_db_port="${DB_PORT:-3306}"
task_db_name="${DB_NAME:-reparaturverwaltung}"
task_db_user="${DB_USER:-nasim}"
task_db_password="${DB_PASSWORD:-}"

if [[ -z "$task_db_password" ]]; then
    read -rs "task_db_password?Datenbankpasswort für ${task_db_user}: "
    echo
fi

if [[ -z "$task_db_password" ]]; then
    echo "Es wurde kein Datenbankpasswort eingegeben." >&2
    exit 1
fi

DB_HOST="$task_db_host" DB_PORT="$task_db_port" DB_NAME="$task_db_name" \
DB_USER="$task_db_user" DB_PASSWORD="$task_db_password" php -r '
try {
    new PDO(
        "mysql:host=" . getenv("DB_HOST") . ";port=" . getenv("DB_PORT") . ";dbname=" . getenv("DB_NAME") . ";charset=utf8mb4",
        getenv("DB_USER"),
        getenv("DB_PASSWORD"),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "Datenbankverbindung erfolgreich.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Datenbankanmeldung fehlgeschlagen. Bitte Benutzername und Passwort prüfen.\n");
    exit(1);
}
'

export DB_HOST="$task_db_host"
export DB_PORT="$task_db_port"
export DB_NAME="$task_db_name"
export DB_USER="$task_db_user"
export DB_PASSWORD="$task_db_password"

if (( task_browser_oeffnen == 0 )); then
    exec php -S 127.0.0.1:8080
fi

php -S 127.0.0.1:8080 &
task_server_pid=$!
trap 'kill "$task_server_pid" 2>/dev/null || true' INT TERM EXIT

for task_versuch in {1..50}; do
    if curl -fs -o /dev/null "$task_app_url"; then
        open "$task_app_url"
        wait "$task_server_pid"
        task_status=$?
        trap - INT TERM EXIT
        exit "$task_status"
    fi
    if ! kill -0 "$task_server_pid" 2>/dev/null; then
        wait "$task_server_pid"
        exit $?
    fi
    sleep 0.1
done

echo "Der lokale Webserver konnte nicht rechtzeitig gestartet werden." >&2
exit 1
