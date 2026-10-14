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
     * Percentage a purchase term may differ from the CA term it maps to. Covers how a billing cycle
     * converts to days (e.g. a 28-day February month, 12 x 30 days for a year).
     */
    public const TERM_TOLERANCE_PERCENT = 10;

    /**
     * Find the CA term within the tolerance of a purchase term. Never rounds a term up past the
     * tolerance, so a misconfigured term cannot buy a longer, costlier one.
     *
     * @param int[] $termDays CA terms in days, shortest first
     *
     * @return int|null The matching term, or null if none is within the tolerance
     */
    public static function matchTerm(int $validityDays, array $termDays): ?int
    {
        foreach ($termDays as $days) {
            if (abs($days - $validityDays) * 100 <= $days * self::TERM_TOLERANCE_PERCENT) {
                return $days;
            }
        }

        return null;
    }

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
