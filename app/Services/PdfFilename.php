<?php

namespace App\Services;

class PdfFilename
{
    /**
     * Builds a download name such as "فاتورة مبيعات عيسى - A1-381.pdf".
     *
     * The document type comes first, then the party (customer or supplier) name, then the
     * document number. Characters that are not allowed in file names are replaced.
     */
    public static function forDocument(?string $partyName, ?string $documentNumber, string $fallbackPartyName, string $documentType = ''): string
    {
        $name = self::clean($partyName);
        $name = $name !== '' ? $name : self::clean($fallbackPartyName);
        $title = self::withType($documentType, $name);
        $number = self::clean($documentNumber);

        return ($number !== '' ? $title.' - '.$number : $title).'.pdf';
    }

    /**
     * Builds a report download name such as "كشف حساب عيسى 2026-11-01 - 2026-11-17.pdf".
     */
    public static function forReport(string $reportTitle, ?string $partyName, ?string $from, ?string $to): string
    {
        $title = self::withType($reportTitle, self::clean($partyName));
        $period = collect([self::clean($from), self::clean($to)])->filter()->implode(' - ');

        return ($period !== '' ? $title.' '.$period : $title).'.pdf';
    }

    private static function withType(string $documentType, string $name): string
    {
        $type = self::clean($documentType);

        if ($type === '' || str_contains($name, $type)) {
            return $name !== '' ? $name : $type;
        }

        return trim($type.' '.$name);
    }

    private static function clean(?string $value): string
    {
        $value = preg_replace('/[<>:"\/\\\\|?*\x00-\x1F]+/u', '-', trim((string) $value)) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return trim($value, ' .-_');
    }
}
