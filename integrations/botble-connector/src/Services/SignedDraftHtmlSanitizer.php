<?php

namespace Botble\DatapilotConnector\Services;

use DOMDocument;
use DOMNode;
use RuntimeException;

final class SignedDraftHtmlSanitizer
{
    private const TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's',
        'ul', 'ol', 'li', 'h2', 'h3', 'h4',
        'blockquote', 'a', 'img', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
    ];
    private const DROP_CONTENT = [
        'script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button',
        'video', 'audio', 'svg', 'math', 'noscript', 'link', 'meta', 'base',
    ];

    public function clean(?string $html): ?string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return null;
        }
        if (mb_strlen($html) > 60000) {
            throw new RuntimeException('HTML description exceeds the allowed length.');
        }
        if (!class_exists(DOMDocument::class)) {
            // Never save/render unreviewed HTML if the sanitizer is unavailable.
            throw new RuntimeException('HTML sanitizer is unavailable.');
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $old = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="utf-8" ?><html><body><div id="saas-html-root">'.$html.'</div></body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
            );
            if (!$loaded) {
                return null;
            }
            $root = $document->getElementById('saas-html-root');
            if (!$root) {
                return null;
            }
            foreach (iterator_to_array($root->childNodes) as $node) {
                $this->sanitizeNode($node);
            }
            $safe = '';
            foreach ($root->childNodes as $node) {
                $safe .= $document->saveHTML($node);
            }
            return trim($safe) ?: null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }
    }

    private function sanitizeNode(DOMNode $node): void
    {
        if ($node->nodeType === XML_COMMENT_NODE) {
            $node->parentNode?->removeChild($node);
            return;
        }
        if ($node->nodeType === XML_TEXT_NODE) {
            return;
        }
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            $node->parentNode?->removeChild($node);
            return;
        }

        $name = strtolower($node->nodeName);
        if (in_array($name, self::DROP_CONTENT, true)) {
            $node->parentNode?->removeChild($node);
            return;
        }
        if (!in_array($name, self::TAGS, true)) {
            foreach (iterator_to_array($node->childNodes) as $child) {
                $this->sanitizeNode($child);
            }
            // Preserve useful text within divs/spans, without the wrapper.
            while ($node->firstChild) {
                $node->parentNode?->insertBefore($node->firstChild, $node);
            }
            $node->parentNode?->removeChild($node);
            return;
        }

        $url = $name === 'img' ? $node->attributes?->getNamedItem('src')?->nodeValue
            : ($name === 'a' ? $node->attributes?->getNamedItem('href')?->nodeValue : null);
        $alt = $name === 'img' ? $node->attributes?->getNamedItem('alt')?->nodeValue : null;
        while ($node->attributes && $node->attributes->length) {
            $node->removeAttributeNode($node->attributes->item(0));
        }
        if ($name === 'img' && !$this->safeUrl($url)) {
            $node->parentNode?->removeChild($node);
            return;
        }
        if ($name === 'img') {
            $node->setAttribute('src', $url);
            $node->setAttribute('alt', mb_substr((string) $alt, 0, 160));
            $node->setAttribute('loading', 'lazy');
            $node->setAttribute('referrerpolicy', 'no-referrer');
        }
        if ($name === 'a' && $this->safeUrl($url)) {
            $node->setAttribute('href', $url);
            $node->setAttribute('rel', 'noopener noreferrer nofollow');
        }
        foreach (iterator_to_array($node->childNodes) as $child) {
            $this->sanitizeNode($child);
        }
    }

    public function safeUrl(?string $url): bool
    {
        return is_string($url)
            && strlen($url) <= 500
            && str_starts_with($url, 'https://')
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && !preg_match('/[\s\x00-\x1F]/', $url);
    }
}
