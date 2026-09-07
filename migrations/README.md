# Datenbankmigration

1. Vorher die Datenbank `reparaturverwaltung` vollständig sichern.
2. `20260903_mehrere_geraete_pro_auftrag.sql` genau einmal auf der bisherigen Datenbank ausführen.
3. Danach kontrollieren, dass die Anzahl in `reparaturauftraege` der Anzahl in `reparaturauftrag_geraete` entspricht.

Die bisherigen Spalten am Auftrag bleiben zunächst als nullable Legacy-Spalten erhalten. Dadurch ist ein Rollback der Anwendung möglich. Neue und migrierte Gerätedaten liegen in `reparaturauftrag_geraete`. Negative Altpreise werden auf 0,00 € normalisiert; ihr ursprünglicher Wert bleibt in `migration_betragskorrekturen.originalwert` dokumentiert.

Für die optionalen Kunden- und Gerätedaten anschließend
`20260903_optionale_kunden_und_geraetedaten.sql` ausführen. Die Migration ergänzt
nur fehlende Spalten und macht die vorhandene Telefonnummer nullable; bestehende
Werte und Datensätze bleiben unverändert.

Für Registrierung, Kontofreigabe und sichere Passwort-Reset-Tokens danach
`20260905_registrierung_passwort_reset.sql` ausführen. Diese Migration ist
wiederholt ausführbar und ergänzt ausschließlich Authentifizierungsstrukturen.
