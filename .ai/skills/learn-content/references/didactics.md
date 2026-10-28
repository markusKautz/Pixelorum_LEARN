# Didaktik

Die Regeln hier richten sich nach der Zielgruppe, die zu Beginn erfragt wurde.
Wo eine Regel von der Zielgruppe abhängt, steht das dabei.

## Pfad

- **Umfang:** 30 bis 60 Minuten Bearbeitungszeit inklusive Zwischen-Checks und
  Abschlussquiz, Richtwert 45. Wird es mehr, zuerst verzichtbaren Stoff
  streichen; reicht das nicht, den Pfad teilen. `estimated_minutes` ehrlich
  schätzen.
- **Lektionen:** drei bis sieben, jede mit einem klaren Thema. Eine Lektion ist
  in fünf bis zehn Minuten lesbar.
- **Lernziele:** überprüfbar formuliert, was jemand danach kann („Du kannst
  Statuscodes ihren Klassen zuordnen“), nicht was behandelt wird. Jedes
  Lernziel wird von mindestens einer Lektion und einer Pool-Frage abgedeckt.
- **Reihenfolge:** vom Grundbegriff zur Anwendung. Ein Begriff wird
  eingeführt, bevor er verwendet wird.

## Ausrichtung an der Zielgruppe

- **Vorwissen:** Was die Zielgruppe schon kann, wird nicht erklärt, sondern
  vorausgesetzt oder kurz eingeordnet. Bei Einsteigern Begriffe sorgfältig
  aufbauen, bei Fortgeschrittenen Zusammenhänge und Abgrenzungen betonen.
- **Ziel:** Bereitet der Pfad auf eine mündliche Prüfung oder ein
  Fachgespräch vor, zählen Erklären, Begründen und Vergleichen; Rechenwege nur,
  wo die Prüfung rechnet. Bei Praxisbezug zählen Abläufe, Entscheidungen und
  typische Fehler. Bei einem Überblick reicht Einordnen und Wiedererkennen.
- **Ansprache:** Du-Form, sofern die Zielgruppe nichts anderes nahelegt.
- **Beispiele** aus dem Umfeld der Zielgruppe, nicht aus Lehrbuchwelten.

## Blöcke

Für **jede** Lektion die ganze Blockpalette durchgehen und fragen, welcher Typ
den Inhalt am besten trägt. Einen Typ nicht pauschal für den ganzen Pfad
ausschließen.

| Inhalt | Block |
| --- | --- |
| Erklärung, Zusammenhang, Vergleichstabelle | `markdown` |
| Ablauf, Aufbau, Beziehungen, Schichten | `diagram` (Mermaid) |
| Kernaussage zum Behalten | `callout` `highlight` |
| Typischer Fehler, Verwechslung | `callout` `warning` |
| Besonders prüfungsrelevant | `callout` `exam` |
| Echte Gefahr für Mensch oder Daten | `callout` `danger` |
| Ergänzung, Einordnung | `callout` `info` |
| Übungsaufgabe mit Lösung | `accordion` |
| Befehle, Quelltext, Konfiguration | `code_snippet` |
| Fachbegriffe des Abschnitts | `glossary` |
| Längere Lektion in Teile gliedern | `section_break` mit Titel |

- **Text in Häppchen:** ein `markdown`-Block trägt einen Gedanken. Lange Blöcke
  teilen und Diagramme, Callouts oder Aufgaben dazwischen setzen.
- **Callouts sparsam:** höchstens zwei oder drei je Lektion, sonst verlieren
  sie ihre Wirkung.
- **Aufgaben im Accordion:** Die vollständige Aufgabe einschließlich aller
  nötigen Angaben steht im `heading`, das immer sichtbar ist. Der `body`
  enthält nur die Lösung. Keine bloße Themenüberschrift als `heading`, keine
  vorweggenommene Antwort.
- **Glossar am Abschnittsende:** unmittelbar vor dem nächsten `section_break`
  oder am Ende der Lektion. Jeder Begriff kommt vorher wörtlich im sichtbaren
  Fließtext vor, nicht nur in Überschriften, Code oder Aufklappteilen.
- **Diagramme** zeigen Struktur, die Text schlecht trägt. Kurze Beschriftungen,
  wenige Knoten, eine Bildunterschrift, die sagt, was man sieht.

## Fragen

- **Menge:** je Lektion ein bis zwei Zwischen-Checks; acht bis fünfzehn
  Pool-Fragen je Pfad, verteilt über alle Lernziele. Das Abschlussquiz nimmt
  alle oder eine ausgewogene Auswahl der Pool-Fragen.
- **Zwischen-Checks** sind eigenständig verständliche Fragen zum Inhalt der
  Lektion. Sie setzen keine Beispielfiguren, Beispieldaten oder Namen aus der
  Lektion voraus, weil sie später auch in Übungsquizzen ohne die Lektion
  erscheinen.
- **Pool-Fragen** prüfen Verständnis, nicht Wortlaut. Eine Frage, die sich nur
  durch Wiedererkennen eines Satzes aus der Lektion lösen lässt, ist zu leicht.
- **Fragetyp passend wählen:** `single` für eine eindeutige Antwort,
  `multiple` wenn mehrere Aussagen zutreffen können (im `prompt` erkennbar
  machen, dass mehrere richtig sein können), `boolean` für eine klare Aussage
  ohne Grauzone.
- **Distraktoren** stammen aus typischen Fehlvorstellungen und Verwechslungen
  der Zielgruppe. Sie sind plausibel, ähnlich lang wie die richtige Antwort und
  eindeutig falsch. Keine Scherzoptionen, kein „Alle genannten“, keine
  doppelte Verneinung.
- **Position der richtigen Antwort** über die Fragen hinweg variieren.

## Erklärungen

- Die Erklärung beschreibt nur den **richtigen Lösungsweg** und schreibt die
  richtige Antwort aus.
- Sie ist **ohne Frage und Optionen verständlich**, weil sie auch einzeln
  angezeigt wird.
- **Keine Distraktor-Analyse:** nicht erklären, warum einzelne falsche
  Optionen falsch sind.
- Zwei bis vier Sätze. Bei `boolean` mit „Die Aussage ist wahr.“ oder „Die
  Aussage ist falsch.“ beginnen und dann begründen.
