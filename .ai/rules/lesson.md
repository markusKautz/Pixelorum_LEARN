---
paths:
  - 'resources/views/components/lesson/**'
---

# Lesson

## Markdown maskiert rohes HTML statt es zu entfernen
`x-lesson.markdown` rendert mit `html_input => escape`, nicht `strip` (Abweichung von Entscheidung 5, Auftraggeber 2026-10-27). commonmark ab 2.10.4 liest C#-Generics wie `List<T>` als HTML-Tag; mit `strip` verschwand `<T>` stillschweigend aus Lektionen und Erklärungen. `escape` ist gegen XSS gleich sicher. Nicht auf `strip` zurückstellen; Generics im Content brauchen keine Backticks.
