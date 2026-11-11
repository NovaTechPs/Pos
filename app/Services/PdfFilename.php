<?php

namespace App\Services;

class PdfFilename
{
    /**
     * Builds a download name such as "محمد للتجارة - SAL-2026-381.pdf".
     *
     * The party (customer or supplier) name comes first, then the document number.
     * Characters that are not allowed in file names are replaced.
     */
    public static function forDocument(?string $partyName, ?string $documentNumber, string $fallbackPartyName): string
    {
        $name = self::clean($partyName);
        $name = $name !== '' ? $name : self::clean($fallbackPartyName);
        $number = self::clean($documentNumber);

        return ($number !== '' ? $name.' - '.$number : $name).'.pdf';
    }

    private static function clean(?string $value): string
    {
        $value = preg_replace('/[<>:"\/\\\\|?*\x00-\x1F]+/u', '-', trim((string) $value)) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return trim($value, ' .-_');
    }
}
