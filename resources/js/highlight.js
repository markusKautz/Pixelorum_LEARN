/*
 * highlight.js samt registrierten Sprachen (DEV-6). Eigenes Modul, damit
 * lesson-blocks.js es erst nachlädt, wenn eine Seite Code-Blöcke enthält.
 * Das Theme liegt bewusst in resources/css/app.css: zur Laufzeit injiziert,
 * würden Code-Blöcke kurz ungefärbt aufblitzen. Die Sprachliste muss zu
 * LessonBlockContent::CODE_LANGUAGES passen.
 */
import hljs from 'highlight.js/lib/core';
import php from 'highlight.js/lib/languages/php';
import bash from 'highlight.js/lib/languages/bash';
import javascript from 'highlight.js/lib/languages/javascript';
import xml from 'highlight.js/lib/languages/xml';
import css from 'highlight.js/lib/languages/css';
import sql from 'highlight.js/lib/languages/sql';
import json from 'highlight.js/lib/languages/json';
import yaml from 'highlight.js/lib/languages/yaml';
import csharp from 'highlight.js/lib/languages/csharp';
import plaintext from 'highlight.js/lib/languages/plaintext';

hljs.registerLanguage('php', php);
hljs.registerLanguage('bash', bash);
hljs.registerLanguage('javascript', javascript);
hljs.registerLanguage('xml', xml);
hljs.registerLanguage('css', css);
hljs.registerLanguage('sql', sql);
hljs.registerLanguage('json', json);
hljs.registerLanguage('yaml', yaml);
hljs.registerLanguage('csharp', csharp);
hljs.registerLanguage('plaintext', plaintext);
hljs.registerAliases(['js'], { languageName: 'javascript' });
hljs.registerAliases(['html'], { languageName: 'xml' });
hljs.registerAliases(['text'], { languageName: 'plaintext' });

export default hljs;
