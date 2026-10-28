import {
    defineConfig
} from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from "@tailwindcss/vite";

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/passkeys.js',
            ],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
    ],
    build: {
        // Groesster Chunk ist mit ~660 kB der Langium/Chevrotain-Parser von Mermaid.
        // Er wird ausschliesslich per dynamischem Import geladen (siehe loadMermaid()
        // in resources/js/lesson-blocks.js) und faellt nur bei Lektionen mit Diagramm
        // an. Das Limit ist deshalb bewusst angehoben statt das Splitting zu aendern.
        // Wer hier erneut anhebt: vorher pruefen, ob der Chunk wirklich async ist.
        chunkSizeWarningLimit: 700,
    },
    server: {
        cors: true,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
