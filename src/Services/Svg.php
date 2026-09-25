<?php



namespace OnPage\Services;



class Svg
{
    public const MIME_TYPE = 'image/svg+xml';

    private const BLOCKED_ELEMENTS = [
        'script',
        'foreignobject',
        'iframe',
        'object',
        'embed',
        'audio',
        'video',
        'source',
        'track',
        'canvas',
        'html',
        'body',
        'form',
        'input',
        'button',
        'textarea',
        'select',
        'option',
        'link',
        'meta',
        'style',
    ];

    private const BLOCKED_ATTRIBUTES = [
        'style',
        'href',
        'xlink:href',
        'src',
        'action',
        'formaction',
    ];



    /** Whether a filename points to an SVG file. */
    public static function isFilename(string $filename): bool
    {
        return strtolower((string) pathinfo($filename, \PATHINFO_EXTENSION)) === 'svg';
    }

    /** Throws when an SVG file is malformed or contains unsupported risky markup. */
    public static function sanitizeFile(string $file_path, string $error_context = 'SVG'): void
    {
        $contents = file_get_contents($file_path);
        if (!is_string($contents) || trim($contents) === '') {
            throw self::sanitizeFailed($error_context, 'SVG file is empty');
        }

        if (preg_match('/<\s*(!DOCTYPE|!ENTITY)\b/i', $contents) === 1) {
            throw self::sanitizeFailed($error_context, 'SVG doctype/entities are not allowed');
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($contents, \LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || !$document->documentElement || strtolower($document->documentElement->localName) !== 'svg') {
            throw self::sanitizeFailed($error_context, 'Invalid SVG file');
        }

        self::sanitizeNode($document->documentElement);

        $sanitized = $document->saveXML($document->documentElement);
        if (!is_string($sanitized) || file_put_contents($file_path, $sanitized) === false) {
            throw self::sanitizeFailed($error_context, 'Failed to sanitize SVG file');
        }
    }

    /** Builds a consistent sanitization failure. */
    private static function sanitizeFailed(string $error_context, string $message): \Throwable
    {
        return httpException("$error_context :: $message", 500, 'request_failed');
    }

    /** Recursively removes risky SVG elements and attributes. */
    private static function sanitizeNode(\DOMNode $node): void
    {
        for ($child = $node->firstChild; $child !== null;) {
            $next = $child->nextSibling;

            if ($child instanceof \DOMElement && in_array(strtolower($child->localName), self::BLOCKED_ELEMENTS, true)) {
                $node->removeChild($child);
                $child = $next;
                continue;
            }

            self::sanitizeNode($child);
            $child = $next;
        }

        if (!$node instanceof \DOMElement) {
            return;
        }

        for ($i = $node->attributes->length - 1; $i >= 0; $i--) {
            $attribute = $node->attributes->item($i);
            if (!$attribute instanceof \DOMAttr) continue;

            if (self::isBlockedAttribute($attribute)) {
                $node->removeAttributeNode($attribute);
            }
        }
    }

    /** Whether an SVG attribute should be stripped before upload. */
    private static function isBlockedAttribute(\DOMAttr $attribute): bool
    {
        $name = strtolower($attribute->name);
        $value = trim($attribute->value);

        return str_starts_with($name, 'on') ||
            in_array($name, self::BLOCKED_ATTRIBUTES, true) ||
            preg_match('/javascript\s*:|data\s*:/i', $value) === 1 ||
            preg_match('/url\s*\(\s*[\'"]?(?!#)/i', $value) === 1;
    }
}
