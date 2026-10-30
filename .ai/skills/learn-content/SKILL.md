---
name: learn-content
description: "Erstellt vollständige Lernpfade für die Lernplattform LEARN als hochladbare Content-JSON: Lektionen mit Blöcken, Zwischen-Checks, Pool-Fragen und Abschlussquiz, belegt aus dem Quellmaterial des Nutzers. Verwenden, wenn ein Lernpfad, Kurs oder Fragenpaket für LEARN entstehen soll, wenn Folien, Skripte oder PDFs zu Lerninhalten aufbereitet werden, oder wenn eine Content-JSON für den Lernpfad-Import geschrieben oder repariert wird. Nicht verwenden für Änderungen am Code der Plattform, für einzelne Lektionen bestehender Pfade (die laufen über den Admin-Editor) oder für Inhalte anderer Systeme."
license: MIT
metadata:
  author: Markus Kautz
---

# Lernpfade für LEARN erstellen

Ergebnis ist **eine** JSON-Datei mit einem vollständigen Lernpfad, die der
Admin-Upload von LEARN ohne Nacharbeit annimmt. Sichtbar für Lernende wird der
Pfad erst, nachdem eine Person ihn im Admin-Bereich geprüft, sichtbar
geschaltet und Gruppen zugewiesen hat. Diese Prüfung ersetzt der Skill nicht, er bereitet sie vor.

## Was nie verhandelbar ist

- **Jede Lektion und jede Frage ist belegt**, mit Fundstellen, die die Aussage
  tatsächlich tragen. Keine erfundenen Quellen, Seiten oder Abschnitte.
- **Inhalt kommt aus dem Material**, nicht aus dem Modellwissen. Fehlt etwas,
  wird die Lücke benannt, nicht still gefüllt.
- **Webrecherche nur nach Rückfrage**, in zwei Schritten: vor der Suche und vor
  der Verwendung einer gefundenen Quelle.
- **`status` ist überall `"draft"`.**

## Ablauf

Die Schritte der Reihe nach abarbeiten. Es gibt zwei Haltepunkte, an denen auf
den Nutzer gewartet wird; dazwischen ohne Rückfragen arbeiten, außer bei
Lücken und Webrecherche.

### 1. Rahmen klären

Fragen, was nicht schon bekannt ist, in einer Nachricht:

- **Thema** und gewünschter Zuschnitt
- **Zielgruppe:** wer lernt damit, welches Vorwissen ist da
- **Ziel:** Vorbereitung auf eine Prüfung (welche Form: schriftlich, mündlich,
  praktisch), Praxis im Beruf oder Überblick
- **Sprache** (Voreinstellung Deutsch) und Ansprache
- **Umfang:** Richtwert 45 Minuten je Pfad; mehr Stoff heißt mehrere Pfade
- **Quellmaterial:** welche Dateien oder Seiten, und ob Webrecherche überhaupt
  in Frage kommt

Ohne Quellmaterial nicht weitermachen. Hat der Nutzer keins, gemeinsam
geeignete Quellen festlegen (siehe `references/sources.md`, Webrecherche).

### 2. Quellen erfassen

Das Material vollständig lesen. Für jede Quelle einen Eintrag für den
`sources`-Block anlegen und beim Lesen notieren, auf welcher Seite, Folie oder
unter welcher Überschrift welche Aussage steht. Diese Notizen sind die
Grundlage aller Belege.

→ `references/sources.md`

### 3. Struktur entwerfen: Haltepunkt 1

Dem Nutzer vorlegen, noch ohne ausformulierten Text:

- Titel, Lernziele, geschätzte Minuten
- Lektionen mit Thema, geplanten Blöcken (Typ und Zweck) und Belegen
- Zwischen-Checks je Lektion, Pool-Fragen mit dem jeweils geprüften Lernziel
- **Lückenliste:** was fehlt, wie es eingeordnet ist, was vorgeschlagen wird

Auf Freigabe oder Korrekturen warten.

→ `references/didactics.md`

### 4. Inhalt schreiben

Direkt in die JSON-Datei schreiben, nach dem Muster von
`references/example.json`. Lektion für Lektion: Blöcke, dann Zwischen-Checks.
Danach Pool-Fragen und Abschlussquiz. Den Wortlaut selbst schreiben und nicht
an Subagents abgeben; sie dürfen beim Nachschlagen in Quellen helfen.

Zeigt sich beim Schreiben eine neue Lücke, anhalten und nachfragen statt zu
überbrücken.

→ `references/format.md`, `references/didactics.md`, `references/style.md`

### 5. Validieren

Im Projektverzeichnis von LEARN:

```bash
php artisan lapp:content:validate --file=pfad/zur/datei.json
```

Der Befehl prüft wie der Upload und listet alle Probleme mit Feldpfad. Befunde
beheben und erneut prüfen, bis die Datei sauber ist. Steht kein LEARN-Projekt
zur Verfügung, das dem Nutzer sagen: Dann prüft erst „Datei prüfen“ im
Upload-Dialog.

### 6. Selbst durchsehen

Den gesamten Lernendentext gegen `references/style.md` und die Abschnitte zu
Fragen und Erklärungen in `references/didactics.md` lesen. Jeden Beleg noch
einmal daraufhin prüfen, ob die Fundstelle die Aussage trägt.

### 7. Übergeben: Haltepunkt 2

Dem Nutzer liefern:

- den Pfad zur Datei und eine Zusammenfassung (Lektionen, Fragen, Minuten)
- die aktualisierte Lückenliste und was bewusst weggelassen wurde
- die nächsten Schritte: in LEARN unter „Lernpfad importieren“ hochladen,
  Belege in der Freigabeansicht prüfen, freigeben, sichtbar schalten, Gruppen
  zuweisen

## Referenzen

| Datei | Laden, wenn |
| --- | --- |
| `references/format.md` | die JSON geschrieben oder ein Validierungsfehler verstanden werden muss |
| `references/example.json` | eine Vorlage für Aufbau und Felder gebraucht wird |
| `references/sources.md` | Quellen erfasst, Belege gesetzt, Lücken eingeordnet oder Webquellen gesucht werden |
| `references/didactics.md` | Struktur, Blöcke, Fragen oder Erklärungen entworfen werden |
| `references/style.md` | Lernendentext geschrieben oder durchgesehen wird |
