---
paths:
  - 'app/Actions/Content/**'
---

# Content

## Import: nur anlegen, Kopie bei vorhandenem Slug
`ImportLearningPath::create()` ist der einzige Import (Admin-Upload). Er legt bei vorhandenem Slug (auch soft-gelöscht) eine Kopie an (Slug + `-` + 4 Zeichen `[a-z0-9]`, Titel + „(Kopie)“), überschreibt oder belebt nie einen bestehenden Pfad, erzwingt `draft`/`unreviewed` und nimmt den Riegel `ImportLearningPath::ORDER_LOCK` — wer ihn schon hält, darf `create()` nicht aufrufen, Locks sind nicht wiedereintrittsfähig. `review_status` bleibt immer außen vor (Grounding-Gate).

`ValidateContentFile` ist zweistufig: `handle($file, $registry)` prüft Struktur und Erdung, `handleContents($contents, $label, null)` nur die Struktur plus genau einen Locator je Quellenangabe. Ohne Registry wird die `source_id` nicht aufgelöst — das ist Absicht, die Produktion kennt das Quellenarchiv nicht, die Erdung läuft lokal über `lapp:content:validate`.

## Quellen zur Laufzeit aus content_sources, Schema-Version beachten
Laufzeitcode (Admin-Formulare, Freigabe, Upload, Export) prüft Quellen gegen `content_sources` über `LoadContentSourceRegistry` und `SourceReferences(..., metadataOnly: true)`, nie gegen `LoadSourceRegistry`: Das Quellenarchiv gehört nicht zum Repository und nicht ins Image. `LoadSourceRegistry` bleibt Autorenwerkzeug der lokalen Pipeline (`lapp:content:validate`, `lapp:content:upgrade`, `lapp:content:extract`) und liest unter `config('lapp.content_root')` (Vorgabe `doc/`). Öffentliche Tests lesen nie echten Content; inhaltliche Tests gehören in die private Suite unter dem Content-Root.

Content-JSON ist versioniert (`ValidateContentFile::SCHEMA_VERSION_CURRENT`, fehlend = 1). Version 2 trägt den Block `sources`, den der Import per `SyncContentSources` anlegt; eine abweichende vorhandene Quelle weist den Import ab. Eine Formatänderung, die ältere Leser falsch verstünden, braucht eine neue Version plus Fixture-Test; der Export schreibt immer die aktuelle.

## Bilder reisen im ZIP-Bündel, Abschluss-Quiz nur mit eigenen Pool-Fragen
Schema-Version 3 erlaubt den Block `media`. Ein Export mit hochgeladenen Bildern wird zum ZIP (`content.json` + `media/<sha256>.<ext>`, siehe `ContentBundle`). Geprüft wird immer die aufgelöste Form mit `/storage/lesson-media/<sha256>.<ext>`: Der Export validiert vor dem Umschreiben auf `media/…`, der Upload nach dem Auflösen durch `ReadContentBundle`. Bilder schreibt erst `StoreBundledImages`; scheitert der Import, löscht `discard()` nur die neu geschriebenen. Bild-Erkennung nur über `LocateLessonImages`, nicht nachbauen. Ein Abschluss-Quiz enthält nur Pool-Fragen des eigenen Pfades (`Quiz::questionsOutsidePoolOf()`, `Question::isPoolQuestionOf()`), genau das, was `question_positions` adressieren kann (DEV-15).
