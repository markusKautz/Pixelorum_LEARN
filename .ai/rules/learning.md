---
paths:
  - app/Actions/Learning/LinkGlossaryTerms.php
---

# Learning

## Do not link glossary terms inside tables
Skip table descendants when inserting glossary tooltips. Hidden absolutely positioned tooltip boxes otherwise enlarge a markdown table's horizontal scroll area; link the term at its next occurrence in running text instead.
