<?php

declare(strict_types=1);

namespace App\Services\AiGrader;

class AiGraderHtmlTextExtractor
{
    public function toPlainText(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $text = preg_replace('/<\s*br\s*\/?>/i', "\n", $html) ?? $html;
        $text = preg_replace('/<\/\s*p\s*>/i', "\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        $text = trim($text);

        return $text === '' ? null : $text;
    }
}
