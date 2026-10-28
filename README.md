<p align="center">
  <img src="public/images/logo-learn.svg#gh-light-mode-only" alt="LEARN" height="64">
  <img src="public/images/logo-learn-dark.svg#gh-dark-mode-only" alt="LEARN" height="64">
</p>

<h1 align="center">LEARN</h1>

<p align="center">Lernplattform für geerdete Lernpfade und Quizze, entstanden für die Vorbereitung auf die Lehrabschlussprüfung.</p>

<p align="center">
  <a href="https://github.com/markusKautz/Pixelorum_LEARN/actions/workflows/tests.yml"><img src="https://github.com/markusKautz/Pixelorum_LEARN/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
  <a href="https://github.com/markusKautz/Pixelorum_LEARN/actions/workflows/lint.yml"><img src="https://github.com/markusKautz/Pixelorum_LEARN/actions/workflows/lint.yml/badge.svg" alt="Linter"></a>
  <img src="https://img.shields.io/badge/PHP-8.5-777BB4" alt="PHP 8.5">
  <img src="https://img.shields.io/badge/Laravel-13-FF2D20" alt="Laravel 13">
  <img src="https://img.shields.io/badge/Lizenz-AGPL--3.0--or--later-blue" alt="Lizenz: AGPL-3.0-or-later">
</p>

---

## Über das Projekt

**LEARN** ist eine schlanke Lernplattform für Ausbildungsgruppen und ihre
Ausbildner. Entstanden ist sie, damit sich Lehrlinge auf die Lehrabschlussprüfung
vorbereiten können. Sie bündelt Lernpfade und Quizze, deren Inhalte gegen Quellen
geprüft und freigegeben sind, und macht den Lernfortschritt für Lernende wie für die
Kursleitung sichtbar.

Die Plattform kennt zwei Rollen:

- **Lernende** gehören genau einer Gruppe an, arbeiten Lernpfade durch und
  absolvieren die zugehörigen Quizze.
- **Admins** arbeiten global über alle Gruppen hinweg, verwalten Inhalte, Gruppen
  und Mitgliedschaften und sehen den Fortschritt ihrer Gruppen.

LEARN soll beim Lernen unterstützen, nicht überwachen: Gespeichert werden
Lernergebnisse (abgeschlossene Lektionen, Quizversuche, Antworten), keine Klicks,
Seitenaufrufe oder Verweildauern.

`LAPP` ist der Codename; er bleibt in Code-Bezeichnern erhalten (zum Beispiel im
Artisan-Namespace `lapp:`). Nach außen heißt das Produkt **LEARN**.

## Funktionsumfang

- **Lernpfade** aus Lektionen mit typisierten Blöcken: Markdown-Text, Callouts,
  aufklappbare Aufgaben, Code mit Syntaxhervorhebung, Mermaid-Diagramme, Bilder und
  Videos, Glossare (Begriffe werden im Text als Tooltip verlinkt) und
  Abschnittstrenner.
- **Zwischen-Checks** in den Lektionen und ein **Abschlussquiz** je Lernpfad, mit
  Erklärungen zur richtigen Lösung.
- **Übungsquiz** aus zufälligen Fragen gewählter Pfade; die Antworten fließen in die
  Auswertung der eigenen Schwachstellen.
- **Quellenbelege** an jeder Lektion und jeder Frage, dazu eine Freigabeansicht, in
  der ein Admin die Belege prüft, bevor ein Pfad veröffentlicht wird.
- **Import und Export** von Lernpfaden als JSON, mit hochgeladenen Bildern als ZIP.
- **Fortschrittsverfolgung** und Auswertungen je Gruppe und je Person.
- **Gruppenverwaltung** mit Einladungen; ohne Mailserver lassen sich die
  Aktivierungslinks als CSV exportieren.
- **Papierkorb** für gelöschte Lernpfade.
- **Authentifizierung** über Laravel Fortify; Passkeys und Zwei-Faktor-Anmeldung
  lassen sich über `FORTIFY_PASSKEYS` und `FORTIFY_TWO_FACTOR` zuschalten.
- **Deutschsprachige Oberfläche** (`lang/de.json`).

## Tech-Stack

| Bereich       | Technologie                                            |
| ------------- | ------------------------------------------------------ |
| Sprache       | PHP 8.5                                                |
| Framework     | Laravel 13                                             |
| Frontend      | Livewire 4, Flux UI 2 (freie Fassung), Tailwind CSS 4, Vite 8 |
| Auth          | Laravel Fortify inkl. Passkeys                         |
| Datenbank     | PostgreSQL oder SQLite (siehe Datenbanken)             |
| Tests         | Pest 4                                                 |
| Codequalität  | Laravel Pint, Larastan (PHPStan)                       |

## Voraussetzungen

- PHP 8.5 mit den üblichen Laravel-Extensions
- Composer 2
- Node.js 22 samt npm
- PostgreSQL (getestet mit Version 18) oder SQLite ab 3.8

## Installation

```bash
git clone https://github.com/markusKautz/Pixelorum_LEARN.git learn
cd learn

# Abhängigkeiten, .env, App-Key, Migrationen und Frontend-Build in einem Schritt
composer setup
```

Ohne weitere Angaben läuft LEARN auf SQLite. Für PostgreSQL in `.env` die
Datenbankverbindung eintragen und die Migrationen erneut ausführen:

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

## Datenbanken

| Datenbank | Unterstützt | Hinweis |
| --- | --- | --- |
| PostgreSQL | ja | für den Betrieb empfohlen; die CI testet gegen Version 18 |
| SQLite | ja, ab 3.8 | Voreinstellung für Entwicklung und Tests |
| MySQL, MariaDB | nein | kennen keine partiellen Indizes, auf die sich mehrere Eindeutigkeitsregeln stützen |

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
  Actions/            Fachlogik als aufrufbare Einzelschritte (Content, Lernen,
                      Auswertung, Gruppen)
  Models/             Domänenmodelle: LearningPath, Lesson, LessonBlock, Quiz,
                      Question, QuizAttempt, Team, Membership, …
resources/views/pages/
                      Seiten als Livewire-Single-File-Components (⚡*.blade.php)
routes/
  web.php             öffentliche Seiten und Auth
  learn.php           Lernbereich der Lernenden
  admin.php           Adminbereich
  settings.php        Kontoeinstellungen
.ai/
  rules/              Projektregeln für KI-Agenten
  skills/learn-content/
                      Skill zum Erstellen von Lernpfaden (siehe unten)
docs/                 Dokumentation (siehe unten)
deploy/               Beispielkonfiguration für einen Betrieb mit Podman und Caddy;
                      wie LEARN betrieben wird, ist frei wählbar
```

## Lernpfade

Lernpfade sind JSON-Dateien: ein Lernpfad je Datei, mit Lektionen, Blöcken,
Zwischen-Checks, Pool-Fragen, Abschlussquiz und den Quellen, auf die sich jede
Lektion und jede Frage stützt. Ein Admin lädt sie unter *Lernpfade → Lernpfad
importieren* hoch; der Pfad entsteht als ungeprüfter Entwurf und wird erst nach
Freigabe und Veröffentlichung für Lernende sichtbar. Jeder Pfad lässt sich als
JSON (mit hochgeladenen Bildern als ZIP) wieder exportieren.

Das Format beschreibt [`format.md`](.ai/skills/learn-content/references/format.md);
[`example.json`](.ai/skills/learn-content/references/example.json) ist eine
vollständige, importierbare Vorlage.

```bash
# eine oder mehrere Dateien prüfen, wie es der Upload tut
php artisan lapp:content:validate --file=pfad/zur/datei.json

# Lernpfade aus der Datenbank exportieren (Standard: storage/app/private/content-export)
php artisan lapp:content:export --all
```

Optional gibt es eine lokale Pipeline, die Belege gegen ein Quellenarchiv prüft
(Seiten, Folien und Web-Schnappschüsse samt SHA256). Sie liest aus dem Verzeichnis
`config('lapp.content_root')` (Vorgabe `doc/`, nicht Teil des Repositorys) und
bricht ohne dieses Verzeichnis mit einer Meldung ab:
`lapp:content:validate` ohne `--file`, `lapp:content:extract` und
`lapp:content:upgrade`.

## Skill `learn-content`

Lernpfade lassen sich mit einem KI-Agenten erstellen. Der Skill
[`learn-content`](.ai/skills/learn-content/SKILL.md) leitet ihn dabei an: Er schreibt
aus dem Quellmaterial, das du mitbringst (Skripte, Foliensätze, PDFs oder
freigegebene Webseiten), einen vollständigen Lernpfad als hochladbare JSON-Datei.

Was den Skill ausmacht:

- **Belegt statt erfunden.** Jede Lektion und jede Frage trägt Fundstellen (Seite,
  Folie oder Überschrift), die die Aussage tatsächlich tragen. Fehlt etwas im
  Material, benennt der Agent die Lücke, statt sie aus seinem Modellwissen zu füllen.
- **Webrecherche nur nach Rückfrage**, und zwar zweimal: vor der Suche und vor der
  Verwendung einer gefundenen Quelle.
- **Zwei Haltepunkte.** Zuerst legt der Agent die Struktur vor (Lernziele, Lektionen
  mit geplanten Blöcken und Belegen, Fragen, Lückenliste) und wartet auf Freigabe.
  Am Ende übergibt er die geprüfte Datei mit Zusammenfassung und offener Lückenliste.
- **Didaktik und Sprache** sind festgelegt: passende Blocktypen je Inhalt,
  Zwischen-Checks und Pool-Fragen je Lernziel, Distraktoren aus typischen
  Fehlvorstellungen, Lernendentext ohne KI-Floskeln.
- **Geprüft wie beim Upload.** Der Agent validiert die Datei mit
  `php artisan lapp:content:validate --file=…`, bis keine Befunde mehr kommen.
  Die Datei bleibt ein Entwurf; freigeben und veröffentlichen muss eine Person im
  Adminbereich.

Ablauf in Kürze: Rahmen klären (Thema, Zielgruppe, Ziel, Umfang, Quellen) →
Quellen erfassen → Struktur vorlegen → Inhalt schreiben → validieren → selbst
durchsehen → übergeben.

| Datei | Inhalt |
| --- | --- |
| [`SKILL.md`](.ai/skills/learn-content/SKILL.md) | Ablauf und feste Regeln |
| [`references/format.md`](.ai/skills/learn-content/references/format.md) | JSON-Format, Blocktypen, Fragetypen, erlaubte Werte |
| [`references/sources.md`](.ai/skills/learn-content/references/sources.md) | Quellen, Belege, Lücken, Webrecherche |
| [`references/didactics.md`](.ai/skills/learn-content/references/didactics.md) | Struktur, Blöcke, Fragen, Erklärungen |
| [`references/style.md`](.ai/skills/learn-content/references/style.md) | Sprache im Lernendentext |
| [`references/example.json`](.ai/skills/learn-content/references/example.json) | vollständiger Beispielpfad |

**Verwenden.** In diesem Repository steht der Skill Claude Code und anderen Agenten
automatisch zur Verfügung (`.claude/skills` und `.agents/skills` verlinken auf
`.ai/skills`). Ein Auftrag wie „Erstelle einen Lernpfad zu … aus diesen Folien“
genügt. Außerhalb des Repositorys lässt sich der Ordner `.ai/skills/learn-content`
in das Skill-Verzeichnis des eigenen Agenten kopieren, etwa `~/.claude/skills/`;
ohne LEARN-Projekt übernimmt dann „Datei prüfen“ im Upload-Dialog die Validierung.

> Die Verknüpfungen sind symbolische Links. Unter Windows braucht Git dafür
> `core.symlinks=true` und die nötigen Rechte; sonst den Ordner kopieren.

Tests in `tests/Feature/Content/LearnContentSkillTest.php` halten den Skill am Code
fest: Ändert sich das Format, schlägt ein Test an, statt dass die Referenz still
veraltet.

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
gegen PostgreSQL 18. Der Grund ist bewusst gewählt:
SQLite kennt weder einen echten Boolean-Typ noch die strenge GROUP-BY-Prüfung, eine ganze
Fehlerklasse ist dort schlicht nicht testbar. Ein grüner SQLite-Lauf allein ist deshalb
noch keine Freigabe.

Zwei GitHub-Workflows sichern `main` und jeden Pull Request ab:
[`tests.yml`](.github/workflows/tests.yml) und [`lint.yml`](.github/workflows/lint.yml).

## Dokumentation

| Seite | Für wen |
| --- | --- |
| [Admin-Handbuch](docs/admin-handbuch.md) | Admins: Gruppen, Einladungen, Import, Freigabe, Quizze, Auswertung |
| [Konfiguration](docs/konfiguration.md) | Betreiber: Umgebungsvariablen, Hintergrundprozesse, Artisan-Befehle |
| [Domänenmodell](docs/domaenenmodell.md) | Mitwirkende: Begriffe, Kerntabellen, Regeln im Code |

Wie Lernpfade entstehen, beschreibt der Skill [`learn-content`](.ai/skills/learn-content/SKILL.md).

## Lizenz

LEARN ist freie Software unter der
[GNU Affero General Public License, Version 3 oder später](LICENSE)
(AGPL-3.0-or-later). Wer eine geänderte Fassung im Netz betreibt, muss den
Nutzenden den Quelltext dieser Fassung anbieten.

LEARN setzt das Paket `livewire/flux` voraus. Es wird nicht mitgeliefert und steht
nicht unter der AGPL; `LICENSE` enthält dafür eine Zusatzerlaubnis nach § 7 AGPL.

Der Skill `learn-content` steht zusätzlich unter der MIT-Lizenz (siehe Kopf von
[`SKILL.md`](.ai/skills/learn-content/SKILL.md)), damit er sich frei in andere
Projekte übernehmen lässt.

Wer LEARN betreibt, trägt unter `LAPP_SOURCE_URL` die Adresse des eigenen Quelltexts
ein; die Seite „Über“ verlinkt sie.

Copyright (c) 2026 Markus Kautz.
