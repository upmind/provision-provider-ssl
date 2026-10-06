<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SslCertificates\Helper;

use Carbon\Carbon;
use InvalidArgumentException;
use Throwable;

/**
 * Small conversion utilities shared by the certificate providers.
 */
class Utils
{
    /**
     * Convert a purchase term in days to calendar months.
     */
    public static function daysToMonths(int $days): int
    {
        return max(1, (int) round($days / 30.4375));
    }

    /**
     * Split a full name into first and last name for APIs that take them separately.
     *
     * The first word is the first name and the rest is the last name. A single word is both.
     *
     * @return array{0:string,1:string}
     */
    public static function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2) ?: [];
        $first = (string) ($parts[0] ?? '');

        return [$first, (string) ($parts[1] ?? $first)];
    }

    /**
     * Convert a PEM-encoded CSR or certificate to its DER (binary) encoding.
     */
    public static function pemToDer(string $pem): string
    {
        $base64 = (string) preg_replace('/-----[^-]+-----|\s+/', '', $pem);
        $der = base64_decode($base64, true);

        if ($der === false || $der === '') {
            throw new InvalidArgumentException('Invalid PEM data');
        }

        return $der;
    }

    /**
     * Parse a date/time string and format it as Y-m-d H:i:s in UTC.
     */
    public static function formatDate(?string $date): ?string
    {
        if ($date === null || trim($date) === '') {
            return null;
        }

        try {
            return Carbon::parse($date, 'UTC')->setTimezone('UTC')->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Format a unix timestamp as Y-m-d H:i:s in UTC.
     *
     * @param string|int|null $timestamp
     */
    public static function formatTimestamp($timestamp): ?string
    {
        if ($timestamp === null || !is_numeric($timestamp)) {
            return null;
        }

        return Carbon::createFromTimestampUTC((int) $timestamp)->format('Y-m-d H:i:s');
    }
}
