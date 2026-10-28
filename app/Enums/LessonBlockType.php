<?php

namespace App\Enums;

enum LessonBlockType: string
{
    case Markdown = 'markdown';
    case Accordion = 'accordion';
    case Callout = 'callout';
    case CodeSnippet = 'code_snippet';
    case Diagram = 'diagram';
    case Media = 'media';
    case Glossary = 'glossary';
    case SectionBreak = 'section_break';

    /**
     * Get the display label for the block type.
     */
    public function label(): string
    {
        return match ($this) {
            self::Markdown => __('enums.lesson_block_type.markdown'),
            self::Accordion => __('enums.lesson_block_type.accordion'),
            self::Callout => __('enums.lesson_block_type.callout'),
            self::CodeSnippet => __('enums.lesson_block_type.code_snippet'),
            self::Diagram => __('enums.lesson_block_type.diagram'),
            self::Media => __('enums.lesson_block_type.media'),
            self::Glossary => __('enums.lesson_block_type.glossary'),
            self::SectionBreak => __('enums.lesson_block_type.section_break'),
        };
    }

    /**
     * Get the Heroicon name shown in the block-type picker.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Markdown => 'document-text',
            self::Accordion => 'chevron-up-down',
            self::Callout => 'information-circle',
            self::CodeSnippet => 'code-bracket',
            self::Diagram => 'share',
            self::Media => 'photo',
            self::Glossary => 'book-open',
            self::SectionBreak => 'minus',
        };
    }
}
