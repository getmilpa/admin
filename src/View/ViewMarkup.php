<?php

/**
 * This file is part of Milpa Admin — the administration panel of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/admin
 */

declare(strict_types=1);

namespace Milpa\Admin\View;

/**
 * The roots of a declared view's markup, one by one — what lets the panel compile a guest's tree NODE BY
 * NODE and contain a failure inside the node that caused it (greenhouse decisions/0211).
 *
 * `XhtmlComponentCompiler::compileFragment()` renders every root of a fragment in one call: one component
 * that throws while mounting takes the whole call — and, before this, the whole page — with it. The panel
 * splits the fragment here instead and compiles each root on its own, so a throwing component costs its
 * own region and nothing else. Splitting is the same XML parse the compiler does (the `milpa:` prefix
 * bound to the same namespace), and each root is serialized back to markup the compiler parses again: the
 * re-declared `xmlns:milpa` is the same prefix bound to the same URI, which XML allows.
 *
 * Containment is per ROOT of the declared view. A component nested INSIDE another root fails with that
 * root — the compiler renders children before their parent and this package cannot catch inside it — so a
 * view that wants a surface contained on its own declares it as a root of its tree, not as a child.
 */
final readonly class ViewMarkup
{
    /**
     * @param string $name   the component the root names — `desktop-conversation` for `<milpa:desktop-conversation/>`
     * @param string $markup that root alone, as markup the compiler can parse
     */
    private function __construct(
        public string $name,
        public string $markup,
    ) {
    }

    /**
     * The roots of `$markup`, in document order.
     *
     * @return list<self>
     *
     * @throws \RuntimeException when the markup does not parse, or carries no element at its root
     */
    public static function roots(string $markup): array
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            $wrapped = '<?xml version="1.0" encoding="UTF-8"?><root xmlns:milpa="urn:milpa:components">' . trim($markup) . '</root>';
            if (!$document->loadXML($wrapped) || $document->documentElement === null) {
                throw new \RuntimeException('The declared view\'s markup is not well-formed XHTML: every root must be a Milpa component element.');
            }

            $roots = [];
            foreach ($document->documentElement->childNodes as $child) {
                if (!$child instanceof \DOMElement) {
                    continue;
                }
                $serialized = $document->saveXML($child);
                if (!\is_string($serialized) || $serialized === '') {
                    continue;
                }
                $roots[] = new self(self::componentName($child), $serialized);
            }

            if ($roots === []) {
                throw new \RuntimeException('The declared view\'s markup carries no component: expected at least one <milpa:…> root.');
            }

            return $roots;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** The component a root names, or its tag when it is not a Milpa element — the compiler refuses that one. */
    private static function componentName(\DOMElement $node): string
    {
        if ($node->prefix === 'milpa') {
            return $node->localName;
        }
        if (str_starts_with($node->tagName, 'milpa-')) {
            return substr($node->tagName, \strlen('milpa-'));
        }

        return $node->tagName;
    }
}
