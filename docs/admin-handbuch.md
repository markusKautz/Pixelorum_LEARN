# Admin-Handbuch

Dieses Handbuch beschreibt die Arbeit im Adminbereich von LEARN. Begriffe in
Anführungszeichen stehen so auf dem Bildschirm. Es gibt zwei Rollen: Admins und
Teilnehmer_innen.

## Rollen und Gruppen

- **Admins** sehen alle Gruppen und verwalten Inhalte, Gruppen und
  Nutzer. Den ersten Admin legst du auf der Kommandozeile an:
  `php artisan lapp:make-admin`. Weitere Admins legst du unter „Nutzer“ an
  (siehe [Nutzer verwalten](#nutzer-verwalten)).
- **Teilnehmer_innen** gehören genau einer Gruppe an. Sie sehen nur sichtbare und
  freigegebene Lernpfade, die ihrer Gruppe zugewiesen sind, und die Quizze, die
  ihrer Gruppe ausgegeben sind.

## Gruppe anlegen und Teilnehmer_innen einladen

Unter „Gruppen“ legst du mit „Neue Gruppe“ eine Gruppe an. Auf ihrer Seite
(„Gruppe verwalten“) lädst du Teilnehmer_innen ein:

- **„Teilnehmer_in einladen“** lädt eine einzelne E-Mail-Adresse ein.
- **„Liste importieren“** („Mehrere einladen“) lädt eine Adressliste als
  `.csv` oder `.txt`. Gelesen wird die erste Spalte; eine Kopfzeile und weitere
  Spalten, etwa aus Excel, stören nicht. „Liste prüfen“ zeigt zuerst, welche Zeilen
  eingeladen werden und welche nicht (ungültig, doppelt, schon Mitglied, schon
  eingeladen). Erst „Jetzt einladen“ legt die Einladungen an.

Jede Einladung verschickt eine Mail mit einem Aktivierungslink. Mit dem Link legt
die Person ihr Konto an und wählt ihren Namen selbst. Ein Link gilt **30 Tage**;
abgelaufene Einladungen löscht LEARN einmal täglich.

**Ohne Mailserver** lädst du mit **„Aktivierungslinks“** eine CSV-Datei mit allen
Links der Gruppe herunter und verteilst sie selbst. Die Datei enthält gültige
Zugangslinks: Behandle sie wie eine Passwortliste, gib allen nur ihren eigenen
Link und lösche Kopien und Ausdrucke, sobald alle aktiviert sind. Die Datei lässt
sich mit `php artisan lapp:invitations:export` neu schreiben.

Unter „Offene Einladungen“ ziehst du eine Einladung mit „Einladung zurückziehen“
zurück.

### Gruppe pflegen

- **Inaktivitäts-Schwelle:** Ab wie vielen Tagen ohne Aktivität die Auswertung
  Teilnehmer_innen als inaktiv meldet. Sie gilt je Gruppe.
- **„Gruppe archivieren“** nimmt eine abgeschlossene Gruppe aus den Kennzahlen des
  Dashboards. Gelöscht wird nichts, die Auswertung bleibt erreichbar, und
  „Gruppe wieder aufnehmen“ macht es rückgängig.
- **„Gruppe löschen“** verlangt die Eingabe des Gruppennamens. Die Konten der
  Teilnehmer_innen bleiben bestehen, außer du setzt ausdrücklich das Häkchen, sie
  mitzulöschen.

## Lernpfade importieren

Lernpfade entstehen als JSON-Datei, zum Beispiel mit dem Skill
[`learn-content`](../.ai/skills/learn-content/SKILL.md). So kommt eine Datei in
LEARN:

1. Unter „Lernpfade“ auf **„Lernpfad importieren“**.
2. Datei wählen (`.json` bis 2 MB oder ein ZIP aus einem Export mit Bildern) und
   **„Datei prüfen“**. Die Vorschau zeigt den Pfad oder die gefundenen Fehler; noch
   wird nichts angelegt.
3. **„Jetzt importieren“** legt den Pfad an.

Der Import legt **immer neu an**. Gibt es den Slug schon, auch im Papierkorb,
entsteht eine Kopie: Der Slug erhält vier zufällige Zeichen, der Titel den Zusatz
„(Kopie)“. Ein bestehender Pfad wird nie überschrieben. Ist die gewünschte Position
in der Reihenfolge besetzt, landet der Pfad am Ende.

Ein importierter Pfad ist immer **nicht sichtbar**, **nicht freigegeben** und
**keiner Gruppe zugewiesen**; auch sein Abschluss-Quiz ist nicht sichtbar.

## Prüfen, freigeben, sichtbar schalten

Teilnehmer_innen sehen einen Pfad nur, wenn er **freigegeben** und **sichtbar** ist und
**ihrer Gruppe zugewiesen** ist. Das sind drei getrennte Schritte:

1. **Freigabe:** In der Detailansicht des Pfades öffnest du die Freigabeansicht. Sie
   zeigt alle Lektionen und Fragen mit ihren Quellenbelegen (Quelle und Seite,
   Folie oder Abschnitt). Prüfe die Inhalte gegen die Quellen und bestätige mit
   **„Freigabe erteilen“**. Das geht nur, wenn jede Lektion und jede Frage einen
   Beleg hat und alle Quellen der Installation bekannt sind.
2. **Sichtbar schalten:** „Sichtbar schalten“ in der Liste oder in der
   Detailansicht. „Unsichtbar schalten“ nimmt den Pfad für alle wieder weg.
3. **Gruppen zuweisen:** in der Detailansicht unter „Gruppen“ (siehe
   [Lernpfade bearbeiten](#lernpfade-bearbeiten)). Ohne Zuweisung sieht den Pfad
   niemand, auch wenn er sichtbar ist.
4. **Abschluss-Quiz sichtbar schalten:** Das Abschluss-Quiz hat einen eigenen
   Status. Schalte es unter „Quizze“ sichtbar, sonst sehen die Teilnehmer_innen den Pfad
   ohne Quiz.

Ändert sich nach der Freigabe der Inhalt eines Pfades, erscheint das Badge **„Seit
Freigabe geändert“**. Die Freigabe bleibt bestehen; prüfe die Änderung und erteile
die Freigabe bei Bedarf neu. Reihenfolge, Sichtbarkeit und die Freigabe selbst
zählen nicht als Änderung. „Freigabe zurückziehen“ nimmt die Freigabe weg.

## Lernpfade bearbeiten

In der Detailansicht eines Pfades:

- **Pfad:** Titel, Slug, Kurzbeschreibung, Lernziele, Schwierigkeit, geschätzte
  Dauer, Bestehensgrenze. Der Slug ist Teil der Links, die Teilnehmer_innen teilen; eine
  Änderung macht bestehende Links ungültig.
- **Lektionen:** anlegen, umbenennen, per Ziehen sortieren, löschen. Die
  Zwischen-Checks einer gelöschten Lektion wandern in den Fragen-Pool des Pfades.
- **Blöcke** einer Lektion („Blöcke bearbeiten“): Markdown-Text, Callout, Aufgabe
  zum Aufklappen, Code, Diagramm (Mermaid), Bild oder Video, Glossar,
  Abschnittstrenner. Bilder lädst du im Blockformular hoch.
- **Quellenbelege** je Lektion und je Frage: Quelle und genau eine Fundstelle.
- **Gruppen:** Einen sichtbaren Pfad sehen nur die zugewiesenen Gruppen; ohne
  Zuweisung sieht ihn niemand. Die Liste der Gruppen liegt zugeklappt unter „N von M
  Gruppen zugewiesen“. Dort weist du einzelne Gruppen mit „Zuweisen“ zu und nimmst
  sie mit „Entfernen“ wieder heraus; „Allen Gruppen zuweisen“ weist alle laufenden
  Gruppen auf einmal zu, archivierte nicht. Die Änderung gilt sofort. Nimmst du eine
  Gruppe heraus, bleibt der Fortschritt ihrer Teilnehmer_innen erhalten und ist wieder da,
  sobald du sie erneut zuweist. Ist ein Pfad sichtbar, aber keiner Gruppe
  zugewiesen, weist ein Hinweis darauf hin. In der Liste „Lernpfade“ zeigt ein
  Badge, wie vielen Gruppen ein Pfad zugewiesen ist, oder „Keine Gruppe“; auf der
  Seite einer Gruppe stehen die freigegebenen Pfade, die ihr zugewiesen sind. Neue
  Gruppen bekommen nichts automatisch. Wird die einzige zugewiesene Gruppe
  gelöscht, sieht den Pfad niemand mehr. Zuweisungen gehören nicht zum Export.

Oben in der Detailansicht steht dauerhaft, was einen erneuten Upload des exportierten
Pfades verhindern würde, etwa eine fehlende Quellenangabe.

Unter „Fragen“ liegt der **Fragen-Pool**. Eine Frage mit Lektion ist ein
**Zwischen-Check** und erscheint in der Lektion; eine Frage ohne Lektion ist eine
**Pool-Frage** des Pfades. Fragetypen: eine richtige Antwort, mehrere richtige
Antworten, Wahr oder Falsch. Jede Frage braucht eine Begründung, die Teilnehmer_innen nach
einer falschen Antwort sehen.

Die Reihenfolge der Pfade änderst du in der Liste „Lernpfade“ per Ziehen.

## Quizze

Unter „Quizze“ gibt es zwei Arten:

- **Abschluss-Quiz:** gehört zu genau einem Pfad, höchstens eines je Pfad. Es nimmt
  nur **Pool-Fragen des eigenen Pfades** auf, keine Zwischen-Checks und keine Fragen
  anderer Pfade. Teilnehmer_innen sehen es, wenn sie den Pfad sehen und das Quiz sichtbar ist.
- **Freies Quiz:** stellt Fragen aus beliebigen Pfaden zusammen. Du schaltest es
  sichtbar und gibst es mit „An Gruppen ausgeben“ bestimmten Gruppen aus; Teilnehmer_innen finden es unter „Üben“.

Im Quiz-Builder fügst du Fragen aus dem Pool hinzu und ziehst sie in die
gewünschte Reihenfolge, oder du lässt sie mit „Fragen mischen“ je Versuch mischen.

Daneben stellen sich Teilnehmer_innen unter „Üben“ selbst **Übungsquizze** aus zufälligen
Fragen freigegebener Pfade zusammen. Die gehören ihnen allein und erscheinen nicht in
deiner Quizliste; ihre Antworten fließen aber in die Auswertung der Schwachstellen.

## Exportieren und Papierkorb

- **„Exportieren“** lädt einen Pfad als JSON herunter, mit hochgeladenen Bildern als
  ZIP. Gibt es Hindernisse für einen erneuten Upload, nennt LEARN sie vorher;
  herunterladen kannst du trotzdem („Trotzdem herunterladen“). Alle Pfade auf einmal
  exportiert `php artisan lapp:content:export --all`.
- **Löschen** verschiebt einen Pfad in den Papierkorb („Gelöschte Lernpfade“). Von
  dort holst du ihn mit „Wiederherstellen“ zurück, er landet am Ende der
  Reihenfolge. **„Endgültig löschen“** entfernt ihn samt Lektionen, Fragen, Quiz und
  den Versuchen und Antworten der Teilnehmer_innen; LEARN zeigt vorher, wie viel daran hängt.

## Auswertung

- **Dashboard:** die laufenden Gruppen auf einen Blick, mit der Zahl der Teilnehmer_innen,
  die in den letzten sieben Tagen aktiv waren. Archivierte Gruppen zählen nicht mit.
- **Auswertung einer Gruppe:** Lesefortschritt je Pfad (nur die Pfade, die die
  Gruppe sieht), Verteilung der Teilnehmer_innen nach Lesefortschritt, Abschluss-Quizze mit
  Bestehensquote, ausgegebene freie Quizze und inaktive Teilnehmer_innen. Die Bestehensquote zählt alle Mitglieder der
  Gruppe, nicht nur die, die angetreten sind.
- **Auswertung einzelner Teilnehmer_innen:** Fortschritt je Pfad, Quizversuche und
  Schwachstellen, also die Fragen, die die Person am häufigsten falsch beantwortet.

Alle Zahlen werden beim Aufruf aus den Lernergebnissen berechnet, ändern sich also
sofort mit den Inhalten. LEARN speichert nur Lernergebnisse: abgeschlossene
Lektionen, Quizversuche und Antworten. Klicks, Seitenaufrufe und Verweildauer
erfasst es nicht.

## Nutzer verwalten

Unter „Nutzer“ findest du alle Konten mit Gruppe und letzter Aktivität. Ein Konto
löschst du nach Eingabe des Namens endgültig, samt Fortschritt, Versuchen und
Passkeys. Inhalte, die die Person angelegt hat, bleiben erhalten. Zwei Konten lassen
sich hier nicht löschen: dein eigenes (das geht in den Kontoeinstellungen) und das
des letzten Admins, damit sich niemand aussperrt. Derselbe Schutz gilt in den
Kontoeinstellungen: Bist du der letzte Admin, siehst du dort statt „Account
löschen“ einen Hinweis.

Mehrere Teilnehmer_innen löschst du gemeinsam: Hake sie in der Liste an oder wähle über
das Kästchen im Tabellenkopf alle Teilnehmer_innen der aktuellen Seite, dann „Ausgewählte
löschen“. Der Dialog nennt bis zu zehn Namen und die Summe der erledigten Lektionen
und Quizversuche, bestätigt wird mit der Anzahl. Die Auswahl gilt nur für die
aktuelle Seite; Gruppe und Suche helfen beim Eingrenzen, und ein Filter- oder
Seitenwechsel leert sie. Admins lassen sich nicht anhaken, sie löschst du
einzeln.

Mit „Administrator erstellen“ legst du einen weiteren Admin an: Name, E-Mail
und ein Startpasswort, das du ihm mitteilst. Er meldet sich damit an und ändert es
in den Einstellungen. Das Formular legt nur neue Konten an; gehört die Adresse
schon einer anderen Person, lehnt es sie ab. Ein bestehendes Konto zum Admin machen
kann nur `php artisan lapp:make-admin` auf der Kommandozeile.
