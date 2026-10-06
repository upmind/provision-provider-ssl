<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SslCertificates\Helper;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

/**
 * PSR-3 logger decorator which masks key material and credentials from logged messages.
 */
class RedactingLogger implements LoggerInterface
{
    use LoggerTrait;

    /**
     * Form/query field names whose values must never be logged.
     */
    protected const REDACT_FIELDS = [
        'csr',
        'loginPassword',
        'pass',
        'password',
        'auth_key',
    ];

    /**
     * JSON property names whose values must never be logged.
     */
    protected const REDACT_JSON_FIELDS = [
        'CSR',
        'csr',
        'csr_code',
        'AuthToken',
        'Password',
        'userPassword',
    ];

    /**
     * @var LoggerInterface
     */
    protected $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param mixed $level
     * @param string|\Stringable $message
     */
    public function log($level, $message, array $context = []): void
    {
        $this->logger->log($level, self::redact((string) $message), $context);
    }

    /**
     * Mask PEM blocks, sensitive form fields and sensitive JSON properties.
     */
    public static function redact(string $text): string
    {
        $text = (string) preg_replace(
            '/-----BEGIN ((?:[A-Z]+ )*?(?:PRIVATE KEY|CERTIFICATE REQUEST))-----.+?-----END \1-----/s',
            '[REDACTED \1]',
            $text
        );

        $fields = implode('|', array_map('preg_quote', self::REDACT_FIELDS));
        $text = (string) preg_replace(
            sprintf('/(?<=^|&|\?|\s)((?:%s)=)[^&\s]*/i', $fields),
            '$1[REDACTED]',
            $text
        );

        $jsonFields = implode('|', array_map('preg_quote', self::REDACT_JSON_FIELDS));
        return (string) preg_replace(
            sprintf('/("(?:%s)"\s*:\s*")(?:[^"\\\\]|\\\\.)*(")/', $jsonFields),
            '$1[REDACTED]$2',
            $text
        );
    }
}
