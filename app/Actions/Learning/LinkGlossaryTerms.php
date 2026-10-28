<?php

namespace App\Actions\Learning;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;
use Illuminate\Support\Facades\Blade;

/**
 * Verlinkt die Glossar-Begriffe einer Lektion im **bereits gerenderten**
 * Markdown-HTML (Sprint-4-Entscheidung 11) und hängt die Definition als
 * Tooltip daran.
 *
 * Bewusst nachgelagert statt im Markdown-Parser: so profitieren alle
 * markdown-tragenden Blocktypen (Fließtext, Aufklappbereich, Hinweisbox),
 * ohne dass die XSS-Härtung aus `x-lesson.markdown` dupliziert wird — der
 * Text ist hier schon gesäubert.
 *
 * Anders als die übrigen Actions ist diese **zustandsbehaftet**: „erste
 * Fundstelle je Begriff und Schritt" lässt sich nicht per Block entscheiden,
 * die Blöcke eines Schritts rendern aber als eigene Blade-Komponenten. Der
 * Reader legt deshalb je Render eine Instanz an und reicht sie durch; ein
 * verbrauchter Begriff verschwindet aus `$pending`.
 *
 * Verwendet ext-dom (Standard, keine neue Abhängigkeit) mit dem
 * HTML5-Parser aus PHP 8.4+: der bringt UTF-8 und Fragment-Handling von
 * Haus aus mit, wo das alte DOMDocument::loadHTML() beides verlangt.
 */
class LinkGlossaryTerms
{
    /**
     * Elemente, in denen nicht verlinkt wird: in Links, weil ein Tooltip im
     * Link die Navigation verdeckt; in Code, weil dort ein Bezeichner steht
     * und kein Fließtext; in Überschriften, weil sie die Gliederung tragen;
     * in Tabellen, weil unsichtbare Tooltip-Boxen deren Scrollbereich
     * künstlich verbreitern.
     */
    protected const SKIPPED_TAGS = ['a', 'code', 'pre', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'table'];

    /**
     * Bereits verlinkte Begriffe: In der Erklärung eines Begriffs wird nicht
     * erneut verlinkt, sonst schachtelten sich die Tooltips ineinander.
     */
    protected const SKIPPED_CLASS = 'lesson-glossary';

    /**
     * Noch nicht verlinkte Begriffe, längste zuerst.
     *
     * Die Reihenfolge ist fachlich nötig: stünde „Klasse" vor
     * „Klassenvariable", zerlegte der kürzere Begriff den längeren.
     *
     * @var array<string, string>
     */
    protected array $pending = [];

    /**
     * Load the terms to link, longest first.
     *
     * @param  array<string, string>  $terms  Begriff → Definition
     */
    public function forTerms(array $terms): static
    {
        uksort($terms, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        $this->pending = $terms;

        return $this;
    }

    /**
     * Whether any term is still waiting to be linked.
     */
    public function hasTerms(): bool
    {
        return $this->pending !== [];
    }

    /**
     * Link the first occurrence of each remaining term in the given HTML.
     */
    public function handle(string $html): string
    {
        if ($this->pending === [] || trim($html) === '') {
            return $html;
        }

        $document = HTMLDocument::createFromString(
            '<div id="lapp-glossary-root">'.$html.'</div>',
            LIBXML_NOERROR | LIBXML_HTML_NOIMPLIED,
        );

        $root = $document->getElementById('lapp-glossary-root');

        if ($root === null) {
            return $html;
        }

        $this->walk($root, $document);

        $linked = '';

        foreach ($root->childNodes as $child) {
            $linked .= $document->saveHtml($child);
        }

        return $linked;
    }

    /**
     * Walk the tree depth-first, linking inside text nodes only.
     */
    protected function walk(Node $node, HTMLDocument $document): void
    {
        // Momentaufnahme: processText() ersetzt Knoten während des Laufs.
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($this->pending === []) {
                return;
            }

            if ($child instanceof Text) {
                $this->processText($child, $document);

                continue;
            }

            if ($child instanceof Element
                && ! in_array(mb_strtolower($child->tagName), self::SKIPPED_TAGS, true)
                && ! $child->classList->contains(self::SKIPPED_CLASS)
            ) {
                $this->walk($child, $document);
            }
        }
    }

    /**
     * Replace the first matching term inside the text node with a tooltip.
     */
    protected function processText(Text $node, HTMLDocument $document): void
    {
        foreach ($this->pending as $term => $definition) {
            if (preg_match($this->pattern($term), $node->data, $matches, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            unset($this->pending[$term]);

            [$matched, $offset] = $matches[0];

            $head = substr($node->data, 0, $offset);
            $tail = substr($node->data, $offset + strlen($matched));

            $headNode = $head === '' ? null : $document->createTextNode($head);
            $tailNode = $tail === '' ? null : $document->createTextNode($tail);

            $fragment = $document->createDocumentFragment();

            if ($headNode !== null) {
                $fragment->appendChild($headNode);
            }

            $fragment->appendChild($this->tooltip($document, $matched, $definition));

            if ($tailNode !== null) {
                $fragment->appendChild($tailNode);
            }

            $node->parentNode?->replaceChild($fragment, $node);

            // Vor **und** hinter dem Treffer können weitere Begriffe stehen:
            // die Begriffe werden nach Länge abgearbeitet, nicht nach
            // Position, der längste Treffer liegt also nicht zwingend vorn.
            // Die Rekursion endet, weil jeder Treffer seinen Begriff aus
            // $pending nimmt.
            foreach ([$headNode, $tailNode] as $rest) {
                if ($rest !== null) {
                    $this->processText($rest, $document);
                }
            }

            return;
        }
    }

    /**
     * Build the tooltip node for the matched term.
     */
    protected function tooltip(HTMLDocument $document, string $matched, string $definition): Node
    {
        // Bewusst **kein** flux:tooltip: Der rendert seinen Inhalt als <div>,
        // und ein <div> ist innerhalb eines <p> nicht erlaubt. Der Browser
        // schließt das <p> dann vorzeitig, wirft das <div> aus dem Tooltip
        // heraus und lässt den Resttext als nackten Text stehen. Ergebnis:
        // ein Umbruch mitten im Satz und ein Tooltip, der nichts mehr zeigt.
        // Hier stehen deshalb nur Inline-Elemente, die in einem Absatz
        // erlaubt sind. tabindex macht die Erklärung auf Touch-Geräten
        // per Antippen erreichbar, wo es kein Hover gibt.
        $rendered = Blade::render(
            '<span class="lesson-glossary" data-test="glossary-term" tabindex="0">'
            .'<span class="lesson-glossary-term">{{ $matched }}</span>'
            .'<span class="lesson-glossary-definition" role="tooltip">{{ $definition }}</span>'
            .'</span>',
            ['matched' => $matched, 'definition' => $definition],
        );

        $parsed = HTMLDocument::createFromString(
            '<div>'.$rendered.'</div>',
            LIBXML_NOERROR | LIBXML_HTML_NOIMPLIED,
        );

        $fragment = $document->createDocumentFragment();

        foreach (iterator_to_array($parsed->documentElement->childNodes) as $child) {
            $fragment->appendChild($document->importNode($child, true));
        }

        return $fragment;
    }

    /**
     * Build a word-boundary pattern so a term never splits a longer word.
     */
    protected function pattern(string $term): string
    {
        return '/(?<![\p{L}\p{N}_])'.preg_quote(trim($term), '/').'(?![\p{L}\p{N}_])/ui';
    }
}
