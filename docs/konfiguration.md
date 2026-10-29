# Konfiguration

LEARN ist eine Laravel-Anwendung und wird über Umgebungsvariablen in `.env`
eingestellt. Wie und wo du LEARN betreibst, bleibt dir überlassen; diese Seite nennt
nur, was für LEARN selbst wichtig ist. Alles Weitere steht in der
[Laravel-Dokumentation](https://laravel.com/docs/configuration).

## Einstellungen

| Variable | Vorgabe | Bedeutung |
| --- | --- | --- |
| `APP_URL` | `http://localhost` | Öffentliche Adresse samt Schema. Daraus entstehen die Aktivierungslinks in Einladungsmails und im CSV-Export; mit einer falschen Adresse sind sie unbrauchbar. |
| `APP_LOCALE` | `en` | Auf `de` setzen. Die Übersetzungen liegen in `lang/de.json`, ohne den Wert erscheint die Oberfläche auf Englisch. |
| `DB_CONNECTION` | `sqlite` | `pgsql` oder `sqlite`. MySQL und MariaDB werden nicht unterstützt, siehe README. |
| `LAPP_SOURCE_URL` | dieses Repository | Adresse des Quelltexts, verlinkt auf der Seite „Über“. Wer eine geänderte Fassung betreibt, trägt hier deren Quelltext ein (AGPL § 13). |
| `FORTIFY_PASSKEYS` | `false` | Anmeldung mit Passkeys zuschalten. |
| `PASSKEYS_USER_HANDLE_SECRET` | `APP_KEY` | Geheimnis für die Passkey-Kennungen. |
| `FORTIFY_TWO_FACTOR` | `false` | Zwei-Faktor-Anmeldung mit App zuschalten. |
| `MAIL_MAILER` | `log` | Versand der Einladungsmails. Mit `log` landen sie nur im Log. |
| `QUEUE_CONNECTION` | `database` | Einladungsmails laufen über die Queue. |
| `LAPP_CONTENT_ROOT` | `doc` | Nur für die optionale lokale Content-Pipeline, siehe unten. |

## Hintergrundprozesse

- **Queue-Worker:** Einladungsmails werden in die Queue gestellt. Ohne laufenden
  Worker (`php artisan queue:work`) gehen sie nicht hinaus. Die Einladung selbst und
  ihr Link entstehen trotzdem sofort.
- **Scheduler:** Einmal täglich löscht LEARN abgelaufene Einladungen. Dafür muss
  `php artisan schedule:run` jede Minute laufen, oder `php artisan schedule:work`
  dauerhaft.

## Betrieb ohne Mailserver

LEARN funktioniert vollständig ohne Mailserver. Jede Einladung schreibt ihren
Aktivierungslink zusätzlich in eine CSV-Datei der Gruppe
(`storage/app/private/invitations/`). Admins laden sie auf der Gruppenseite über
„Aktivierungslinks“ herunter. Die Datei enthält gültige Zugangslinks und gehört
nicht in ein unverschlüsseltes Backup.

## Bilder

Bilder in Lektionen speichert LEARN auf der Disk `public` unter
`storage/app/public/lesson-media`. Damit sie ausgeliefert werden, braucht es den
Link `php artisan storage:link` oder eine entsprechende Regel im Webserver.

## Artisan-Befehle von LEARN

| Befehl | Zweck |
| --- | --- |
| `lapp:make-admin` | Ersteinrichtung: ersten Admin anlegen; außerdem ein bestehendes Konto zum Admin machen. Weitere Admins entstehen im Adminbereich unter „Nutzer“ |
| `lapp:invitations:export` | CSV-Datei mit den Aktivierungslinks einer Gruppe neu schreiben |
| `lapp:content:validate --file=…` | Content-Datei prüfen wie der Upload |
| `lapp:content:export` | Lernpfade als JSON oder ZIP exportieren (`--all`, `--dir=`) |
| `lapp:content:validate` ohne `--file` | lokale Pipeline: alle Dateien samt Belegen prüfen |
| `lapp:content:extract` | lokale Pipeline: Text aus PDF- und Folienquellen ziehen (braucht Poppler) |
| `lapp:content:upgrade` | lokale Pipeline: ältere Content-Dateien auf die aktuelle Schema-Version heben |

Die drei Befehle der lokalen Pipeline lesen ein Quellenarchiv unter
`LAPP_CONTENT_ROOT`: `content/` mit den Lernpfad-JSONs und `ressource/` mit dem
Quellregister `sources.json`, Extrakten und Web-Schnappschüssen. Das Verzeichnis ist
nicht Teil des Repositorys. Fehlt es, brechen die Befehle mit einer Meldung ab.
