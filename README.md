# Reparaturverwaltung

## Lokale Konfiguration

Die Datenbankzugangsdaten liegen bewusst nicht im öffentlich erreichbaren PHP-Quellcode. Vor dem Start müssen sie als Umgebungsvariablen gesetzt werden:

```sh
export DB_HOST=127.0.0.1
export DB_PORT=3306
export DB_NAME=reparaturverwaltung
export DB_USER=nasim
export DB_PASSWORD='lokales-passwort'
php -S 127.0.0.1:8080
```

Die lokale Anwendung immer einheitlich über `http://127.0.0.1:8080` öffnen.
Formulare und Weiterleitungen verwenden relative Ziele. Der eingebaute
PHP-Server erhält pro Port automatisch einen eigenen Cookie-Namen und ein
eigenes Sitzungsverzeichnis, damit parallele Testinstanzen keine Sitzung
überschreiben. Optional können diese Werte über `SESSION_COOKIE_NAME` und
`SESSION_SAVE_PATH` ausdrücklich gesetzt werden.

Alternativ startet `./start_lokal.sh` die Anwendung interaktiv. Das Skript
fragt ein noch nicht gesetztes Datenbankpasswort verdeckt ab, prüft zuerst die
Datenbankverbindung und speichert das Passwort nicht in einer Datei.
Mit `./start_lokal.sh --open` wird nach erfolgreichem Start zusätzlich die
Registrierungsseite im Standardbrowser geöffnet. Die gleichnamige
`Reparaturverwaltung.command` auf dem Schreibtisch verwendet diesen Modus.

In Produktion müssen dieselben Variablen über die Server-/Secret-Konfiguration gesetzt und HTTPS erzwungen werden.

## Konten und Anmeldung

Nach den Login-Migrationen wird das erste Konto über die normale Seite
`/registrieren.php` transaktionsgeschützt als Administrator angelegt. Jedes
weitere selbst registrierte Konto ist ein Mitarbeiterkonto und wartet auf die
Freigabe in der Benutzerverwaltung. Die Selbstregistrierung kann dort zentral
deaktiviert werden. Passwörter müssen mindestens 8 Zeichen enthalten.

## Passwort-Reset per E-Mail

Für den Versand von Reset-Links werden folgende Umgebungsvariablen verwendet:

```sh
export APP_BASE_URL='https://example.invalid'
export SMTP_HOST='smtp.example.invalid'
export SMTP_PORT=587
export SMTP_USER='smtp-benutzer'
export SMTP_PASSWORD='smtp-passwort'
export SMTP_ENCRYPTION='tls'
export SMTP_FROM='reparatur@example.invalid'
export APP_HTTPS=1
```

Zulässige Werte für `SMTP_ENCRYPTION` sind `tls`, `ssl` und `none`. Ohne
vollständige SMTP-Konfiguration werden keine Reset-Links öffentlich angezeigt;
Administratoren können Mitarbeiterpasswörter weiterhin sicher in der
Benutzerverwaltung zurücksetzen.

## Rollen

- Administrator: Benutzer, Rechnungsarchiv/CSV, Geschäftsdaten, Stornos und sämtliche Verwaltungsfunktionen.
- Mitarbeiter: Kunden/Aufträge, Gerätestatus, Zahlungen, Abholscheine sowie Rechnungen anzeigen und erstellen.

Endgültige Rechnungen werden nur für vollständig bezahlte Geräte erstellt. Rechnungen und Zahlungen werden nicht überschrieben; Korrekturen erfolgen nachvollziehbar über Stornoaktionen.
