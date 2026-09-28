<?php



namespace OnPage\Services;



class Svg
{
    public const MIME_TYPE = 'image/svg+xml';

    private const SVG_NAMESPACE = 'http://www.w3.org/2000/svg';

    /**
     * SVG elements kept by the sanitizer (lowercase local names). Everything else is removed.
     *
     * An allowlist rather than a blocklist: an element nobody thought of (a new HTML element in
     * an XHTML namespace, for example) is dropped instead of slipping through. The list covers
     * shapes, text, paint servers, clipping/masking, filters and SMIL animation. `script`,
     * `style`, `foreignObject` and all HTML elements are absent on purpose.
     */
    private const ALLOWED_ELEMENTS = [
        'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'metadata', 'switch', 'view', 'a',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'image',
        'text', 'tspan', 'textpath', 'tref',
        'lineargradient', 'radialgradient', 'stop', 'pattern', 'meshgradient', 'meshrow', 'meshpatch',
        'hatch', 'hatchpath', 'solidcolor',
        'clippath', 'mask', 'marker',
        'filter', 'feblend', 'fecolormatrix', 'fecomponenttransfer', 'fecomposite', 'feconvolvematrix',
        'fediffuselighting', 'fedisplacementmap', 'fedistantlight', 'fedropshadow', 'feflood',
        'fefunca', 'fefuncb', 'fefuncg', 'fefuncr', 'fegaussianblur', 'feimage', 'femerge',
        'femergenode', 'femorphology', 'feoffset', 'fepointlight', 'fespecularlighting',
        'fespotlight', 'fetile', 'feturbulence',
        'animate', 'animatemotion', 'animatetransform', 'set', 'mpath',
    ];

    /** SMIL animation elements: they can write any attribute of their target at run time. */
    private const ANIMATION_ELEMENTS = ['animate', 'animatemotion', 'animatetransform', 'set'];

    private const BLOCKED_ATTRIBUTES = [
        'style',
        'href',
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

        if (
            !$loaded ||
            !$document->documentElement ||
            strtolower($document->documentElement->localName) !== 'svg' ||
            !self::isAllowedElement($document->documentElement)
        ) {
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
        return onpage_http_exception("$error_context :: $message", 500, 'request_failed');
    }

    /** Recursively removes risky SVG elements and attributes. */
    private static function sanitizeNode(\DOMNode $node): void
    {
        for ($child = $node->firstChild; $child !== null;) {
            $next = $child->nextSibling;

            if (
                $child instanceof \DOMProcessingInstruction ||
                ($child instanceof \DOMElement && !self::isAllowedElement($child))
            ) {
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

    /**
     * Whether an element may stay in the sanitized SVG.
     *
     * It must be in the SVG namespace (or in none) and on the allowlist. An animation element is
     * also dropped when it targets an attribute that would be stripped if written directly
     * (`href`, `xlink:href`, event handlers, `style`...): otherwise `<set attributeName="href"
     * to="javascript:...">` would put back at run time what the sanitizer removed.
     */
    private static function isAllowedElement(\DOMElement $element): bool
    {
        $namespace = $element->namespaceURI;
        if ($namespace !== null && $namespace !== '' && $namespace !== self::SVG_NAMESPACE) {
            return false;
        }

        $name = strtolower($element->localName);
        if (!in_array($name, self::ALLOWED_ELEMENTS, true)) {
            return false;
        }

        if (in_array($name, self::ANIMATION_ELEMENTS, true) && $element->hasAttribute('attributeName')) {
            return !self::isBlockedAttributeName(self::normalizeValue($element->getAttribute('attributeName')));
        }

        return true;
    }

    /** Whether an SVG attribute should be stripped before upload. */
    private static function isBlockedAttribute(\DOMAttr $attribute): bool
    {
        // `nodeName` keeps the prefix (`xlink:href`), `localName` drops it: both are checked, so
        // an href in any namespace (whatever prefix it is bound to) is caught.
        if (
            self::isBlockedAttributeName(strtolower((string) $attribute->nodeName)) ||
            self::isBlockedAttributeName(strtolower((string) $attribute->localName))
        ) {
            return true;
        }

        return self::isDangerousValue($attribute->value);
    }

    /** Whether an attribute name (lowercase, possibly prefixed) is one the sanitizer strips. */
    private static function isBlockedAttributeName(string $name): bool
    {
        $name = strtolower($name);
        $colon = strrpos($name, ':');
        $local = $colon === false ? $name : substr($name, $colon + 1);

        return str_starts_with($local, 'on') ||
            in_array($local, self::BLOCKED_ATTRIBUTES, true) ||
            in_array($name, self::BLOCKED_ATTRIBUTES, true);
    }

    /**
     * Whether an attribute value carries a script-capable scheme or an external `url()` reference.
     *
     * The DOM value is already entity-decoded, so `java&#9;script:` arrives as `java<TAB>script:`.
     * Browsers ignore ASCII whitespace and control characters inside a URL scheme, so they are
     * stripped before matching; a pattern like `javascript\s*:` alone would miss them.
     */
    private static function isDangerousValue(string $value): bool
    {
        $normalized = self::normalizeValue($value);

        return preg_match('/(?:javascript|vbscript|livescript|data):/i', $normalized) === 1 ||
            preg_match('/url\((?![\'"]?#)/i', $normalized) === 1;
    }

    /** Lowercase value with every ASCII whitespace/control character (0x00-0x20, 0x7F) removed. */
    private static function normalizeValue(string $value): string
    {
        return strtolower((string) preg_replace('/[\x00-\x20\x7F]+/', '', $value));
    }
}
