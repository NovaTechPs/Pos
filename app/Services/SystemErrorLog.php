<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Throwable;

class SystemErrorLog
{
    private const MAX_BYTES = 262144;

    /** @return list<string> */
    public function files(): array
    {
        $files = glob(storage_path('logs/{laravel,diagnostics}*.log'), GLOB_BRACE) ?: [];
        $files = array_filter($files, fn (string $file): bool => ! is_link($file) && is_file($file) && is_readable($file)
            && preg_match('/^(laravel|diagnostics)(-\d{4}-\d{2}-\d{2})?\.log$/D', basename($file)) === 1);
        usort($files, fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return array_map(basename(...), array_slice($files, 0, 14));
    }

    /** @return list<array{time: string, level: string, details: string}> */
    public function entries(string $file, string $level = 'error'): array
    {
        if (! in_array($file, $this->files(), true)) {
            return [];
        }

        $handle = @fopen(storage_path('logs/'.$file), 'rb');
        if ($handle === false) {
            return [];
        }
        try {
            $stat = fstat($handle);
            if ($stat === false) {
                return [];
            }
            $size = $stat['size'];
            $offset = max(0, $size - self::MAX_BYTES);
            fseek($handle, $offset);
            $text = stream_get_contents($handle, self::MAX_BYTES);
        } finally {
            fclose($handle);
        }
        // Discard the incomplete entry when only the end of a large file was read.
        if ($offset > 0) {
            $start = strpos($text, "\n[");
            $text = $start === false ? '' : substr($text, $start + 1);
        }
        preg_match_all('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] [^\r\n]*?\.(DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY): (.*?)(?=^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]|\z)/ms', $text, $matches, PREG_SET_ORDER);
        $entries = [];
        foreach (array_reverse($matches) as $match) {
            if (! in_array($match[2], $level === 'warning' ? ['WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'] : ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'], true)) {
                continue;
            }
            $entries[] = ['time' => $match[1], 'level' => $match[2], 'details' => $this->safeDetails($match[3])];
            if (count($entries) === 50) {
                break;
            }
        }

        return $entries;
    }

    public function record(Throwable $exception): void
    {
        // Omit request bodies, identities, SQL, bindings and arbitrary exception messages.
        $details = $this->safeDetails($exception::class.': '.$exception->getMessage()
            .' at '.$exception->getFile().':'.$exception->getLine());
        $trace = $exception->getTrace();
        usort($trace, fn (array $a, array $b): int => (int) ! str_contains(str_replace('\\', '/', $a['file'] ?? ''), '/app/')
            <=> (int) ! str_contains(str_replace('\\', '/', $b['file'] ?? ''), '/app/'));
        foreach (array_slice($trace, 0, 16) as $frame) {
            if (isset($frame['file'], $frame['line'])) {
                $details .= "\n".$this->safeDetails('at '.$frame['file'].':'.$frame['line']);
            }
        }
        try {
            Log::channel('diagnostics')->error($details);
        } catch (Throwable) {
            // Diagnostic logging must never replace the original application error.
        }
    }

    public function safeDetails(string $text): string
    {
        $details = [];
        $exceptionText = str_replace('\\\\', '\\', $text);
        if (preg_match_all('/(?:^|\[object\] \()((?:[A-Z][A-Za-z0-9_]*\\\\)*(?:[A-Z][A-Za-z0-9_]*(?:Exception|Error)|PDOException|TypeError|Error))\b/m', $exceptionText, $types)) {
            $details = array_slice(array_unique($types[1]), 0, 5);
        }
        if (preg_match('/SQLSTATE\[([A-Z0-9]{5})\]/', $text, $state)) {
            $details[] = 'SQLSTATE['.$state[1].']';
        }
        if (preg_match('/(?:violates check constraint |constraint=)"?([a-z][a-z0-9_]{0,62})\b/', $text, $constraint)) {
            $details[] = 'constraint='.$constraint[1];
        }
        // Keep only static source locations. Never expose absolute server paths.
        if (preg_match_all('~(?:^|/)((?:app|routes|vendor|bootstrap)/[A-Za-z0-9_./-]+\.php)(?::|\()([0-9]+)~m', str_replace('\\', '/', $text), $locations, PREG_SET_ORDER)) {
            foreach (array_slice($locations, 0, 16) as $location) {
                $details[] = $location[1].':'.$location[2];
            }
        }

        return implode("\n", array_unique($details)) ?: 'تفاصيل الرسالة محجوبة لحماية بيانات العملاء. راجع سجل الخادم الأصلي.';
    }
}
