---
paths:
  - 'resources/views/**'
---

# Views

## Flux-Pro-Ersatz im Clean-Room nachbauen
Beim Ersetzen der Flux-Pro-Komponenten (accordion, popover, file-upload, file-item) gilt: niemals in die Quelldateien von Flux Pro schauen (vendor/livewire/flux-pro/**, auch keine Stubs, JS oder CSS daraus). Grundlage sind nur das gewünschte Verhalten, die bestehenden Aufrufstellen in der App und allgemeine Webstandards. Grund: Der Ersatz darf nicht aus dem proprietären Code abgeleitet sein (Auftraggeber-Vorgabe 2026-10-02).
