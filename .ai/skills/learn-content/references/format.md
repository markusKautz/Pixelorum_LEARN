# Format der Content-Datei

Ein Lernpfad ist eine JSON-Datei. Die maßgeblichen Regeln stehen im Code
(`app/Actions/Content/ValidateContentFile.php`, `app/Rules/LessonBlockContent.php`,
`app/Rules/QuestionOptionsMatchType.php`); diese Seite beschreibt sie. Im
Zweifel entscheidet `php artisan lapp:content:validate --file=<datei>`.

`example.json` im selben Ordner ist eine vollständige, gültige Vorlage.

## Grundregeln

- Der Validator setzt keine stillen Defaults. Jedes Pflichtfeld steht explizit
  in der Datei. Optionale Felder dürfen fehlen; die Vorlage führt sie trotzdem
  auf, damit nichts vergessen wird.
- Unbekannte Felder werden abgelehnt, auf jeder Ebene.
- Alle Positionen, `page` und `slide` sind echte JSON-Ganzzahlen (`3`, nicht
  `"3"`), ab 1, eindeutig innerhalb ihrer Liste.
- `status` ist überall `"draft"` (in der Oberfläche „Nicht sichtbar“). Sichtbar
  schalten und Gruppen zuweisen sind Schritte im Admin-Bereich, nicht in der Datei.

## Wurzel

| Feld | Pflicht | Inhalt |
| --- | --- | --- |
| `schema_version` | ja | `3` |
| `slug` | ja | Kleinbuchstaben, Ziffern, Bindestriche: `http-grundlagen` |
| `title` | ja | Pfadtitel, höchstens 255 Zeichen |
| `summary` | nein | Ein bis zwei Sätze oder `null` |
| `objectives` | ja | Nicht leere Liste von Lernzielen (Strings) |
| `position` | ja | Gewünschter Platz in der Pfadreihenfolge; ist er belegt, landet der Pfad am Ende |
| `difficulty` | nein | `"einsteiger"`, `"fortgeschritten"`, `"experte"` oder `null`; andere Werte bietet der Admin-Editor nicht an |
| `estimated_minutes` | nein | Bearbeitungszeit inklusive Fragen, Ganzzahl oder `null` |
| `pass_threshold` | ja | Bestehensgrenze des Pfades in Prozent, 1 bis 100 |
| `status` | ja | `"draft"` |
| `sources` | ja | Alle verwendeten Quellen, siehe `sources.md` |
| `lessons` | ja | Nicht leere Liste der Lektionen |
| `questions` | ja | Nicht leere Liste der Pool-Fragen |
| `quiz` | ja | Das Abschlussquiz |

Der Block `media` existiert nur in exportierten ZIP-Bündeln mit Bildern. Eine
von Hand geschriebene Datei enthält ihn nicht.

## Lektion

| Feld | Pflicht | Inhalt |
| --- | --- | --- |
| `position` | ja | Reihenfolge im Pfad |
| `title` | ja | Lektionstitel |
| `source_reference` | ja | Nicht leere Liste von Belegen, siehe `sources.md` |
| `blocks` | ja | Nicht leere Liste von Blöcken |
| `questions` | ja | Zwischen-Checks der Lektion; die Liste darf leer sein |

## Blöcke

Jeder Block hat `position`, `type` und `content`. Der Inhalt von `content`
hängt vom Typ ab; andere Felder als die genannten sind nicht erlaubt.

| `type` | `content` | Hinweise |
| --- | --- | --- |
| `markdown` | `body` | Fließtext in Markdown, auch Tabellen und Listen |
| `callout` | `variant`, `body` | Hervorgehobener Kasten, `body` in Markdown |
| `accordion` | `items`: Liste aus `heading`, `body` | Aufklappbare Einträge; `heading` ist Klartext, `body` Markdown |
| `code_snippet` | `language`, `code`, optional `filename` | Quelltext mit Hervorhebung |
| `diagram` | `format`, `source`, optional `caption` | Mermaid-Quelltext oder Bild-URL |
| `media` | `kind`, `url`, optional `caption`, `alt` | Bild oder Video per URL |
| `glossary` | `terms`: Liste aus `term`, `definition` | Begriffserklärungen |
| `section_break` | optional `title` | Abschnittsgrenze mit Zwischenüberschrift |

Erlaubte Werte:

- `callout.variant`: `info`, `warning`, `danger`, `highlight`, `exam`
  - `info` (blau): Ergänzung, Einordnung
  - `warning` (gelb): typischer Fehler, Stolperstelle; nicht bestrafend gemeint
  - `danger` (rot): nur für echte Gefahren, etwa Stromschlag oder Datenverlust
  - `highlight` (pink): Kernaussage, die man behalten soll
  - `exam` (indigo): besonders prüfungsrelevant
- `code_snippet.language`: `php`, `bash`, `js`, `html`, `css`, `sql`, `json`,
  `yaml`, `csharp`, `python`, `text`
- `diagram.format`: `mermaid`, `image`
- `media.kind`: `video`, `image`

Darstellung:

- Mermaid läuft mit `securityLevel: strict` und dem Thema `neutral`. Kein
  HTML in Knotenbeschriftungen, keine Klick-Handler.
- `media` und `diagram` mit `image` brauchen eine `https`-URL. Videos werden nur
  als direkte Datei (`.mp4`, `.webm`, `.ogg`) eingebettet; Links auf
  Videoplattformen erscheinen als Knopf „Video öffnen“.
- Eigene Bilder lassen sich nicht in die Datei einbetten. Sie werden nach dem
  Import im Blockformular hochgeladen.
- Markdown-Felder: `body` von `markdown`, `callout` und `accordion` sowie die
  `explanation` von Fragen. Rohes HTML wird dort als Text angezeigt, nicht
  ausgeführt. Generische Typen wie `List<T>` funktionieren ohne Backticks.
- Alle anderen Textfelder (Titel, Fragen, Optionen, Glossar, Überschriften)
  erscheinen als Klartext; Markdown wirkt dort nicht.

## Fragen

Zwischen-Checks (`lessons[].questions`) und Pool-Fragen (`questions`) haben
dieselbe Form:

| Feld | Pflicht | Inhalt |
| --- | --- | --- |
| `position` | ja | Eindeutig innerhalb der Liste |
| `type` | ja | `single`, `multiple` oder `boolean` |
| `prompt` | ja | Die Frage |
| `explanation` | ja | Erklärung der richtigen Lösung, Markdown |
| `source_reference` | ja | Nicht leere Liste von Belegen |
| `options` | ja | Liste aus `position`, `label`, `is_correct` (JSON-Boolean) |

Regeln je Typ:

- `single`: mindestens zwei Optionen, genau eine richtig
- `multiple`: mindestens zwei Optionen, mindestens eine richtig
- `boolean`: genau zwei Optionen (`Wahr`, `Falsch`), genau eine richtig

**Zwischen-Checks** stehen in der Lektion und werden direkt mit Auflösung
gezeigt. Sie zählen nicht in die Quizwertung.

**Pool-Fragen** gehören zum Pfad und speisen Abschlussquiz und Übungsquizze.

## Abschlussquiz

| Feld | Pflicht | Inhalt |
| --- | --- | --- |
| `title` | ja | Quiztitel |
| `description` | nein | Ein Satz oder `null` |
| `pass_threshold` | ja | Bestehensgrenze in Prozent, 1 bis 100 |
| `status` | ja | `"draft"` |
| `question_positions` | ja | Nicht leere Liste von Positionen aus `questions` |

Das Quiz enthält ausschließlich Pool-Fragen dieses Pfades, adressiert über ihre
`position`. Zwischen-Checks gehören nie ins Quiz.

## Nach dem Upload

Ein Admin lädt die Datei in der Lernpfad-Übersicht über „Lernpfad importieren“
hoch; „Datei prüfen“ zeigt vorher eine Vorschau. Der
Pfad entsteht ungeprüft, nicht sichtbar und ohne Gruppe. Gibt es den `slug` schon, entsteht eine
Kopie mit Zufallsanhang und „(Kopie)“ im Titel. Sichtbar für Lernende wird der
Pfad erst nach Freigabe, Sichtbarschalten und Zuweisung an Gruppen im
Admin-Bereich.
