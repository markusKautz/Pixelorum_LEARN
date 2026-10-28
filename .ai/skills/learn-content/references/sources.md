# Quellen und Belege

LEARN zeigt Lernenden nur Inhalte, die sich auf eine Quelle stützen. Jede
Lektion und jede Frage trägt deshalb Belege, und eine Person im Admin-Bereich
prüft sie vor der Freigabe. Ein Beleg, der die Aussage nicht trägt, ist
schlechter als gar keiner: Er täuscht eine Prüfung vor, die nicht stattfand.

## Grundsätze

- **Aus dem Material schreiben, nicht aus dem Gedächtnis.** Jede fachliche
  Aussage muss sich in einer der erfassten Quellen finden lassen. Modellwissen
  dient nur dazu, das Material zu verstehen und gut zu erklären.
- **Fundstellen nachsehen, nicht raten.** Seite, Folie oder Abschnitt stammen
  aus dem tatsächlichen Material. Lässt sich eine Fundstelle nicht feststellen,
  ist das eine Lücke, kein Anlass für eine plausible Zahl.
- **Keine erfundenen Quellen.** Keine Titel, Autoren, URLs oder Abschnitte, die
  nicht vorliegen oder nicht geprüft wurden.

## Der `sources`-Block

Jede Quelle, auf die ein Beleg verweist, steht genau einmal im `sources`-Block.
Jeder Eintrag dort muss auch verwendet werden. Alle neun Felder stehen in jedem
Eintrag, nicht belegte mit `null`.

| Feld | Inhalt |
| --- | --- |
| `source_id` | Frei gewählter, sprechender Schlüssel: `kursskript-netzwerk`, `bsi-passwoerter` (Kleinbuchstaben, Ziffern, Bindestriche, höchstens 100 Zeichen) |
| `kind` | `pdf` (Dokument mit Seiten), `slides` (Foliensatz), `web` (Webseite) |
| `title` | Titel der Quelle |
| `publisher` | Herausgeber, Autor oder Organisation, sonst `null` |
| `url` | Pflicht bei `web`, sonst `null` oder eine Fundstelle im Netz |
| `version` | Auflage, Version, Stand, sonst `null` |
| `published_at` | Erscheinungsdatum `JJJJ-MM-TT` oder `null` |
| `accessed_at` | Abrufdatum `JJJJ-MM-TT`; bei Webquellen angeben |
| `content_sha256` | Prüfsumme der Datei, falls bekannt, sonst `null` |

Eine Installation kennt eine `source_id` nach dem ersten Import. Kommt sie in
einer späteren Datei mit anderen Metadaten vor, lehnt der Upload ab. Gleiche
Quelle heißt also: gleicher Schlüssel und gleiche Metadaten. Für eine andere
Auflage einen neuen Schlüssel wählen.

## `source_reference`

Eine nicht leere Liste. Jeder Eintrag nennt `source_id` und **genau einen**
Fundort, passend zur Art der Quelle:

| `kind` | Fundort | Beispiel |
| --- | --- | --- |
| `pdf` | `page` (Ganzzahl) | `{ "source_id": "kursskript-web", "page": 12 }` |
| `slides` | `slide` (Ganzzahl) | `{ "source_id": "folien-netzwerk", "slide": 4 }` |
| `web` | `section` (Überschrift der Seite, wörtlich) | `{ "source_id": "wikipedia-http", "section": "Funktionsweise" }` |

- `page` und `slide` zählen die Seiten der Datei ab 1 (das Deckblatt ist
  Seite 1), nicht die aufgedruckte Nummer. Nur so findet die prüfende Person
  die Stelle wieder.
- Jeder Foliensatz und jedes Dokument ist eine eigene Quelle. Keine
  Sammelquelle wie „Folien“ für mehrere Dateien.
- `section` ist der Wortlaut einer Überschrift der Webseite. Ohne passende
  Überschrift die nächstliegende übergeordnete nehmen.
- Mehrere Belege sind erlaubt und erwünscht, wenn eine Lektion oder Frage
  Aussagen aus mehreren Stellen verbindet.
- Eine Lektion belegt ihre zentralen Aussagen, eine Frage genau die Aussage,
  die ihre richtige Antwort trägt.

## Lücken

Beim Abgleich von geplantem Inhalt und Material zeigen sich Lücken. Sie werden
nie still gefüllt, sondern benannt und eingeordnet:

1. **Anders formuliert:** Der Inhalt steht im Material, nur mit anderen
   Begriffen oder in anderer Darstellung. Keine Lücke; Fundstelle angeben.
2. **Fehlt, aber verzichtbar:** Vertiefung oder Randaspekt. Weglassen und in
   der Lückenliste vermerken.
3. **Fehlt und wird gebraucht:** ohne den Inhalt ist das Thema für die
   Zielgruppe nicht verständlich oder das Ziel nicht erreichbar. Blockiert den
   betroffenen Teil. Der Nutzer entscheidet: weiteres Material liefern,
   Webrecherche freigeben oder den Teil streichen.

## Webrecherche

Webrecherche läuft nur nach ausdrücklicher Zustimmung und in zwei Schritten:

1. **Vor der Suche fragen:** welche Lücke, wonach gesucht würde, welche Art
   von Quelle in Frage kommt.
2. **Vor der Verwendung fragen:** gefundene Quelle mit Titel, Herausgeber, URL
   und der Stelle nennen, die die Lücke schließen soll. Erst nach Zustimmung in
   `sources` aufnehmen, mit `accessed_at` als Abrufdatum.

Geeignet sind Primärquellen und etablierte Fachstellen: Normen und Gesetze,
Behörden, Hersteller-Dokumentation, Fachverbände, Lehrbücher, anerkannte
Nachschlagewerke. Nicht geeignet sind Foren, Werbeseiten, KI-generierte Seiten
und Inhalte ohne erkennbaren Herausgeber.

Eine Webseite kann sich ändern. Die Belegstelle so wählen, dass sie in einer
Überschrift wiederzufinden ist, und das Abrufdatum setzen.
