<?php

namespace Appylogi\AppyCrud\Crud;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Sanitiza el HTML producido por el editor de texto enriquecido
 * (FieldType::RICHTEXT) antes de guardarlo, usando solo DOMDocument (sin
 * dependencias externas tipo HTMLPurifier). Estrategia: lista blanca de
 * etiquetas — cualquier etiqueta no permitida se elimina pero se conserva
 * su contenido de texto (no se pierde lo que el usuario escribio); dentro
 * de las etiquetas permitidas, solo sobrevive 'href' en '<a>', validado
 * contra un esquema permitido (http/https/mailto). Nunca se guarda HTML
 * crudo sin pasar por aqui: la superficie de riesgo es XSS almacenado,
 * porque esta version renderiza el valor de un campo richtext como HTML
 * sin escapar (ver TailwindRenderer::renderRichText()/renderView()).
 */
class HtmlSanitizer
{
    private const ALLOWED_TAGS = ['p', 'div', 'br', 'b', 'strong', 'i', 'em', 'u', 'ul', 'ol', 'li', 'a', 'h1', 'h2', 'h3'];
    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto'];
    /** Su contenido no es HTML de verdad (es codigo/CSS); se descarta entero, no solo la etiqueta, para no dejarlo como texto visible. */
    private const STRIP_ENTIRELY_TAGS = ['script', 'style'];
    /** Unica propiedad CSS permitida en 'style' (solo en p/div) — la genera execCommand('justify*') del editor avanzado. */
    private const ALLOWED_ALIGNMENTS = ['left', 'center', 'right', 'justify'];

    public static function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        // Se envuelve en <body> con meta UTF-8 explicito: sin esto, DOMDocument
        // interpreta el HTML como Latin-1 y corrompe cualquier caracter no-ASCII.
        $dom->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $body = $dom->getElementsByTagName('body')->item(0);

        if ($body === null) {
            return '';
        }

        self::sanitizeNode($dom, $body);

        $result = '';
        foreach ($body->childNodes as $child) {
            $result .= $dom->saveHTML($child);
        }

        return trim($result);
    }

    private static function sanitizeNode(DOMDocument $dom, DOMNode $node): void
    {
        $children = iterator_to_array($node->childNodes);

        foreach ($children as $child) {
            if (!($child instanceof DOMElement)) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::STRIP_ENTIRELY_TAGS, true)) {
                $node->removeChild($child);
                continue;
            }

            self::sanitizeNode($dom, $child);

            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                // Etiqueta no permitida: se reemplaza por sus hijos (se conserva el texto/formato interno).
                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            foreach (iterator_to_array($child->attributes) as $attribute) {
                if ($tag === 'a' && $attribute->name === 'href' && self::isSafeUrl($attribute->value)) {
                    continue;
                }

                if (($tag === 'p' || $tag === 'div') && $attribute->name === 'style') {
                    $alignment = self::extractTextAlign($attribute->value);

                    if ($alignment !== null) {
                        // Se reescribe entero (nunca se conserva el 'style' original tal cual)
                        // para que sea imposible colar otra propiedad CSS agazapada junto a text-align.
                        $child->setAttribute('style', 'text-align: ' . $alignment . ';');
                        continue;
                    }
                }

                $child->removeAttribute($attribute->name);
            }
        }
    }

    /** Extrae SOLO el valor de 'text-align' de un 'style' crudo, validado contra la lista blanca; null si no hay una propiedad reconocida. */
    private static function extractTextAlign(string $style): ?string
    {
        if (preg_match('/text-align\s*:\s*([a-z]+)/i', $style, $matches) !== 1) {
            return null;
        }

        $value = strtolower($matches[1]);

        return in_array($value, self::ALLOWED_ALIGNMENTS, true) ? $value : null;
    }

    /** Sin esquema (relativa, "#ancla", vacia) se considera segura — no puede ejecutar codigo. Con esquema, debe estar en la lista blanca. */
    private static function isSafeUrl(string $url): bool
    {
        $scheme = parse_url(trim($url), PHP_URL_SCHEME);

        return $scheme === null || $scheme === false || in_array(strtolower($scheme), self::ALLOWED_SCHEMES, true);
    }
}
