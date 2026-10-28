<p align="center">
  <img src="public/images/logo-learn.svg#gh-light-mode-only" alt="LEARN" height="64">
  <img src="public/images/logo-learn-dark.svg#gh-dark-mode-only" alt="LEARN" height="64">
</p>

<h1 align="center">LEARN</h1>

<p align="center">Lernplattform zur Vorbereitung auf die Lehrabschlussprüfung.</p>

<p align="center">
  <a href="https://github.com/markusKautz/Pixelorum_LEARN/actions/workflows/tests.yml"><img src="https://github.com/markusKautz/Pixelorum_LEARN/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
  <a href="https://github.com/markusKautz/Pixelorum_LEARN/actions/workflows/lint.yml"><img src="https://github.com/markusKautz/Pixelorum_LEARN/actions/workflows/lint.yml/badge.svg" alt="Linter"></a>
  <img src="https://img.shields.io/badge/PHP-8.5-777BB4" alt="PHP 8.5">
  <img src="https://img.shields.io/badge/Laravel-13-FF2D20" alt="Laravel 13">
  <img src="https://img.shields.io/badge/Lizenz-AGPL--3.0--or--later-blue" alt="Lizenz: AGPL-3.0-or-later">
</p>

---

## Über das Projekt

**LEARN** ist eine Lernplattform, mit der sich Lehrlinge auf die Lehrabschlussprüfung
vorbereiten. Sie bündelt geerdete Lernpfade und Quizze an einem Ort und macht den
Lernfortschritt für Lehrlinge wie für die Kursleitung sichtbar.

Die Plattform kennt zwei Rollen:

- **Lehrling** — gehört genau einer Gruppe an, arbeitet Lernpfade durch und
  absolviert die zugehörigen Quizze.
- **Admin** — global über alle Gruppen hinweg, verwaltet Inhalte, Gruppen und
  Mitgliedschaften und sieht den Fortschritt der Lehrlinge.

`LAPP` ist der Repo- und Codename; er bleibt in Code-Bezeichnern erhalten
(zum Beispiel im Artisan-Namespace `lapp:`). Nach außen heißt das Produkt **LEARN**.

## Funktionsumfang

- **Lernpfade** aus Lektionen, die aus typisierten Lektionsblöcken bestehen
  (Text, Code mit Syntaxhervorhebung, Mermaid-Diagramme, Callouts, Abschnitts-Trenner).
- **Quizze** je Lernpfad, mit Fragen, Antwortoptionen und Erklärungen zur richtigen Lösung.
- **Übungsquiz** zum wiederholten Üben ohne Wertung des Versuchs.
- **Fortschrittsverfolgung** über abgeschlossene Lektionen und Quizversuche.
- **Gruppenverwaltung** mit Einladungen und Mitgliedschaften.
- **Adminbereich** für Lernpfade, Quizze und die Auswertung des Jahrgangs.
- **Authentifizierung** über Laravel Fortify inklusive Passkeys.
- **Deutschsprachige Oberfläche** (`lang/de.json`).

## Tech-Stack

| Bereich       | Technologie                                            |
| ------------- | ------------------------------------------------------ |
| Sprache       | PHP 8.5                                                |
| Framework     | Laravel 13                                             |
| Frontend      | Livewire 4, Flux UI 2, Tailwind CSS 4, Vite 8           |
| Auth          | Laravel Fortify inkl. Passkeys                         |
| Datenbank     | PostgreSQL 18 (produktiv), SQLite (schnelle Testläufe)  |
| Tests         | Pest 4                                                 |
| Codequalität  | Laravel Pint, Larastan (PHPStan)                       |

## Voraussetzungen

- PHP 8.5 mit den üblichen Laravel-Extensions
- Composer 2
- Node.js 20 oder neuer samt npm
- PostgreSQL 18 für produktionsnahe Läufe

## Installation

```bash
git clone https://github.com/markusKautz/Pixelorum_LEARN.git learn
cd learn

# Abhängigkeiten, .env, App-Key, Migrationen und Frontend-Build in einem Schritt
composer setup
```

Anschließend in `.env` die Datenbankverbindung eintragen und die Migrationen bei Bedarf
erneut ausführen:

```bash
php artisan migrate
```

Einen ersten Admin anlegen:

```bash
php artisan lapp:make-admin
```

`lapp:make-admin` befördert einen bestehenden Account oder legt interaktiv einen neuen an.
Nicht interaktiv geht es ebenso:

```bash
php artisan lapp:make-admin admin@example.test --name="Vorname Nachname" --password="…"
```

## Entwicklung

```bash
composer dev
```

Startet Webserver, Queue-Worker, Log-Ausgabe (Pail) und den Vite-Dev-Server zusammen.
Wer nur das Frontend braucht, nutzt `npm run dev`; für einen Produktionsbuild `npm run build`.

> Bleibt eine Frontend-Änderung im Browser unsichtbar, fehlt in aller Regel der Build:
> `npm run dev` läuft nicht oder `npm run build` wurde nach der Änderung nicht ausgeführt.

Für die lokale Entwicklung (`APP_ENV=local`) legt `php artisan db:seed` Dev-Zugänge
(`admin@example.com`, zwei Lernende, Passwort `password`) und einen Demo-Lernpfad an.
Der Lauf ist wiederholbar; außerhalb von `local` legt der Seeder nichts an.

## Projektstruktur

Die Verzeichnisse folgen den Laravel-Konventionen. Erklärungsbedürftig sind vor allem:

```
app/
  Actions/            Fachlogik als aufrufbare Einzelschritte (u. a. Content-Pipeline)
  Livewire/           Livewire-Komponenten der Oberfläche
  Models/             Domänenmodelle: LearningPath, Lesson, LessonBlock, Quiz,
                      Question, QuizAttempt, Team, Membership, …
routes/
  web.php             öffentliche Seiten und Auth
  learn.php           Lernbereich der Lehrlinge
  admin.php           Adminbereich
  settings.php        Kontoeinstellungen
.ai/skills/
  learn-content/      Skill zum Erstellen von Lernpfaden samt Formatreferenz
docs/                 Dokumentation
```

## Lernpfade

Lernpfade sind JSON-Dateien: ein Lernpfad je Datei, mit Lektionen, Blöcken,
Zwischen-Checks, Pool-Fragen, Abschlussquiz und den Quellen, auf die sich jede
Lektion und jede Frage stützt. Ein Admin lädt sie unter *Lernpfade → Lernpfad
importieren* hoch; der Pfad entsteht als ungeprüfter Entwurf und wird erst nach
Freigabe und Veröffentlichung für Lernende sichtbar. Jeder Pfad lässt sich als
JSON (mit hochgeladenen Bildern als ZIP) wieder exportieren.

Format, Didaktik und Ablauf beschreibt der Skill
[`learn-content`](.ai/skills/learn-content/SKILL.md);
[`example.json`](.ai/skills/learn-content/references/example.json) ist eine
vollständige, importierbare Vorlage.

```bash
# eine oder mehrere Dateien prüfen, wie es der Upload tut
php artisan lapp:content:validate --file=pfad/zur/datei.json

# Lernpfade aus der Datenbank exportieren
php artisan lapp:content:export --all
```

Optional gibt es eine lokale Pipeline, die Belege gegen ein Quellenarchiv prüft
(Seiten, Folien und Web-Schnappschüsse samt SHA256). Sie liest aus dem Verzeichnis
`config('lapp.content_root')` (Vorgabe `doc/`, nicht Teil des Repositorys) und
bricht ohne dieses Verzeichnis mit einer Meldung ab:
`lapp:content:validate` ohne `--file`, `lapp:content:extract` und
`lapp:content:upgrade`.

## Tests und Codequalität

```bash
composer test        # config:clear + Pint-Check + PHPStan + Pest
composer lint        # Pint anwenden
composer lint:check  # Pint nur prüfen
composer types:check # PHPStan (Larastan)
```

Einzelne Tests laufen gezielt und damit deutlich schneller:

```bash
php artisan test --compact --filter=QuizBuilder
```

Die Suite läuft in der CI **zweimal**: zuerst gegen SQLite (in-memory, schnell), danach
gegen PostgreSQL 18, die produktiv eingesetzte Engine. Der Grund ist bewusst gewählt:
SQLite kennt weder einen echten Boolean-Typ noch die strenge GROUP-BY-Prüfung, eine ganze
Fehlerklasse ist dort schlicht nicht testbar. Ein grüner SQLite-Lauf allein ist deshalb
noch keine Freigabe.

Zwei GitHub-Workflows sichern `main` und jeden Pull Request ab:
[`tests.yml`](.github/workflows/tests.yml) und [`lint.yml`](.github/workflows/lint.yml).

## Lizenz

LEARN ist freie Software unter der
[GNU Affero General Public License, Version 3 oder später](LICENSE)
(AGPL-3.0-or-later). Wer eine geänderte Fassung im Netz betreibt, muss den
Nutzenden den Quelltext dieser Fassung anbieten.

LEARN setzt das Paket `livewire/flux` voraus. Es wird nicht mitgeliefert und steht
nicht unter der AGPL; `LICENSE` enthält dafür eine Zusatzerlaubnis nach § 7 AGPL.

Copyright (c) 2026 Markus Kautz.
