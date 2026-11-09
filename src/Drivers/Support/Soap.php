<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Drivers\Support;

use RuntimeException;
use SimpleXMLElement;

/**
 * Minimal SOAP over HTTP without ext-soap: builds envelopes and reads responses.
 */
final class Soap
{
    public const V11 = 'http://schemas.xmlsoap.org/soap/envelope/';

    public const V12 = 'http://www.w3.org/2003/05/soap-envelope';

    /**
     * @param  string  $body  The XML inside <soap:Body>, already escaped.
     * @param  array<string, string>  $namespaces  Prefix => namespace URI, declared on the envelope.
     * @param  string  $header  The XML inside <soap:Header>, if any.
     */
    public static function envelope(string $body, array $namespaces = [], string $version = self::V11, string $header = ''): string
    {
        $declarations = '';
        foreach ($namespaces as $prefix => $uri) {
            $declarations .= sprintf(' xmlns:%s="%s"', $prefix, htmlspecialchars($uri, ENT_XML1 | ENT_QUOTES));
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soap:Envelope xmlns:soap="'.$version.'"'.$declarations.'>'
            .($header === '' ? '<soap:Header/>' : '<soap:Header>'.$header.'</soap:Header>')
            .'<soap:Body>'.$body.'</soap:Body>'
            .'</soap:Envelope>';
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * Parses a SOAP response, also when it is wrapped in an MTOM/XOP multipart body.
     *
     * @throws RuntimeException When no envelope can be parsed.
     */
    public static function parse(string $response): SimpleXMLElement
    {
        $start = stripos($response, '<?xml');
        if ($start === false && preg_match('/<([A-Za-z0-9_]+:)?Envelope[\s>]/', $response, $match, PREG_OFFSET_CAPTURE) === 1) {
            $start = $match[0][1];
        }
        if ($start === false) {
            throw new RuntimeException('The response does not contain a SOAP envelope.');
        }

        $xml = substr($response, $start);
        $end = strripos($xml, 'Envelope>');
        if ($end !== false) {
            $xml = substr($xml, 0, $end + strlen('Envelope>'));
        }

        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($document === false) {
            throw new RuntimeException('The SOAP envelope is not valid XML.');
        }

        return $document;
    }

    /**
     * The fault string of a SOAP fault, or null when the response is not a fault.
     */
    public static function fault(SimpleXMLElement $envelope): ?string
    {
        foreach (['//*[local-name()="Fault"]/*[local-name()="faultstring"]', '//*[local-name()="Fault"]//*[local-name()="Text"]'] as $path) {
            $nodes = $envelope->xpath($path);
            if (is_array($nodes) && isset($nodes[0])) {
                return trim((string) $nodes[0]);
            }
        }

        $faults = $envelope->xpath('//*[local-name()="Fault"]');

        return is_array($faults) && $faults !== [] ? 'SOAP fault' : null;
    }

    /**
     * Text of the first element with the given local name, ignoring namespaces.
     */
    public static function value(SimpleXMLElement $node, string $localName): ?string
    {
        $found = $node->xpath('.//*[local-name()="'.$localName.'"]');
        if (! is_array($found) || ! isset($found[0])) {
            return null;
        }

        return Data::clean((string) $found[0]);
    }

    /**
     * All elements with the given local name, ignoring namespaces.
     *
     * @return list<SimpleXMLElement>
     */
    public static function all(SimpleXMLElement $node, string $localName): array
    {
        $found = $node->xpath('.//*[local-name()="'.$localName.'"]');

        return is_array($found) ? array_values($found) : [];
    }
}
