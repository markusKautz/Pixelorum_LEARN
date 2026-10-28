@props(['block', 'glossary' => null])

<x-lesson.markdown :text="$block->content['body'] ?? ''" :glossary="$glossary" data-test="lesson-block-markdown" />
