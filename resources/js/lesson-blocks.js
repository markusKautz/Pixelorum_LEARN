/*
 * Client-Renderer der Lesson-Blocks (Entscheidung 5): Mermaid-Diagramme und
 * Syntax-Highlighting. Läuft initial, nach wire:navigate und nach
 * abgeschlossenen Livewire-Morphs.
 */
// highlight.js (~60 kB) wird wie Mermaid erst geladen, wenn die Seite
// Code-Blöcke enthält (DEV-6); app.js hängt an jeder Seite.
let hljsPromise = null;

const loadHljs = () => (hljsPromise ??= import('./highlight').then((module) => module.default));

const highlightCode = async () => {
    const selector = 'pre code[class*="language-"]:not([data-highlighted="yes"])';

    if (document.querySelector(selector) === null) {
        return;
    }

    const hljs = await loadHljs();

    // Nach dem Laden neu abfragen: Ein Morph kann die Knoten inzwischen
    // ersetzt haben, und die neuen sollen ebenfalls gefärbt werden.
    document.querySelectorAll(selector).forEach((element) => hljs.highlightElement(element));
};

// Mermaid ist groß (~650 kB) und wird deshalb erst geladen, wenn die Seite
// tatsächlich ein Diagramm enthält.
let mermaidPromise = null;

const loadMermaid = () => {
    mermaidPromise ??= import('mermaid').then(({ default: mermaid }) => {
        mermaid.initialize({
            startOnLoad: false,
            securityLevel: 'strict',
            theme: 'neutral',
        });

        return mermaid;
    });

    return mermaidPromise;
};

const renderMermaid = async () => {
    const nodes = document.querySelectorAll('pre.mermaid:not([data-processed])');

    if (nodes.length === 0) {
        return;
    }

    const mermaid = await loadMermaid();

    for (const node of nodes) {
        try {
            await mermaid.run({ nodes: [node] });
        } catch {
            // Parse-Fehler: Fehlermeldung statt leerer Fläche (Entscheidung 5)
            const fallback = document.createElement('div');
            fallback.className = 'lesson-diagram-error';
            fallback.textContent = node.dataset.errorLabel || 'Das Diagramm konnte nicht gerendert werden.';
            node.replaceWith(fallback);
        }
    }
};

const render = () => {
    highlightCode();
    renderMermaid();
};

document.addEventListener('DOMContentLoaded', render);
document.addEventListener('livewire:navigated', render);
document.addEventListener('livewire:init', () => {
    Livewire.hook('morphed', render);
});

// Von den Block-Formularen nach Speichern/Vorschau-Updates dispatcht; das
// requestAnimationFrame stellt sicher, dass der Morph abgeschlossen ist.
window.addEventListener('lesson-blocks-updated', () => {
    requestAnimationFrame(render);
});
