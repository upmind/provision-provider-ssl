<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SslCertificates\Helper;

use InvalidArgumentException;

/**
 * Rebuilds PEM values whose line breaks were lost or collapsed to spaces, as some configuration
 * forms do. The base64 body survives, so each block can be wrapped again.
 */
class PemHelper
{
    public const LABELS = [
        'CERTIFICATE',
        'PRIVATE KEY',
        'RSA PRIVATE KEY',
        'EC PRIVATE KEY',
        'ENCRYPTED PRIVATE KEY',
    ];

    /**
     * Normalise every PEM block in a value: base64 body wrapped at 64 characters, `\n` line
     * endings and a trailing newline. Keeps the blocks in order and drops text between them.
     *
     * A block with RFC 1421 headers (legacy `Proc-Type`/`DEK-Info` encryption) cannot be rebuilt
     * once its line breaks are gone, so it is kept as-is when well-formed and rejected otherwise.
     *
     * @throws InvalidArgumentException With a message that names the block type, never its content
     */
    public static function normalize(string $pem): string
    {
        if (!preg_match_all('/-----BEGIN ([A-Z0-9 ]+)-----(.*?)-----END \1-----/s', $pem, $matches, PREG_SET_ORDER)) {
            throw new InvalidArgumentException('No PEM block found');
        }

        $blocks = [];

        foreach ($matches as [$block, $label, $body]) {
            if (!in_array($label, self::LABELS, true)) {
                throw new InvalidArgumentException(sprintf('Unsupported PEM block type: %s', $label));
            }

            $blocks[] = strpos($body, ':') !== false
                ? self::legacyEncryptedBlock($block, $label, $body)
                : self::wrap($label, $body);
        }

        return implode("\n", $blocks) . "\n";
    }

    protected static function wrap(string $label, string $body): string
    {
        $base64 = (string) preg_replace('/\s+/', '', $body);

        if (!preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $base64)) {
            throw new InvalidArgumentException(sprintf('The %s PEM block is not valid base64', $label));
        }

        return sprintf("-----BEGIN %s-----\n%s\n-----END %1\$s-----", $label, rtrim(chunk_split($base64, 64, "\n")));
    }

    /**
     * Keep a well-formed block with RFC 1421 headers unchanged, apart from its line endings.
     */
    protected static function legacyEncryptedBlock(string $block, string $label, string $body): string
    {
        // header lines, a blank line, then the base64 lines
        $wellFormed = '/\A\r?\n(?:[A-Za-z-]+:[^\r\n]*\r?\n)+\r?\n(?:[A-Za-z0-9+\/=]+\r?\n)+\z/';

        if (!preg_match($wellFormed, $body)) {
            throw new InvalidArgumentException(sprintf(
                'The %s has legacy encryption headers and its line breaks were lost. Convert it to'
                    . ' PKCS#8 (openssl pkcs8 -topk8) or enter it with its original line breaks',
                strpos($label, 'PRIVATE KEY') !== false ? 'private key' : $label . ' block'
            ));
        }

        return str_replace("\r\n", "\n", $block);
    }
}
