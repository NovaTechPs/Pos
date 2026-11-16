<?php

use Symfony\Component\Process\Process;

test('numbers and dates always use western digits whatever the arabic locale', function (): void {
    $script = <<<'JAVASCRIPT'
    const fs = require('node:fs');
    const assert = require('node:assert/strict');
    const { pathToFileURL } = require('node:url');
    assert.ok(fs.readFileSync('resources/js/app.js', 'utf8').startsWith("import './latin-digits.js';"));
    import(pathToFileURL('resources/js/latin-digits.js').href).then(({ toLatinDigits, withLatinNumbers }) => {
        const eastern = /[٠-٩۰-۹]/;
        const outputs = [
            (1234.5).toLocaleString('ar-EG'),
            (99).toLocaleString(),
            new Intl.NumberFormat('ar-EG', { style: 'currency', currency: 'ILS' }).format(12.5),
            new Intl.DateTimeFormat('ar-EG').format(new Date(Date.UTC(2026, 10, 17))),
            new Date(Date.UTC(2026, 10, 17, 13, 45)).toLocaleString('ar-EG'),
        ];
        outputs.forEach(output => assert.ok(!eastern.test(output), output));
        assert.ok(new Intl.NumberFormat('ar') instanceof Intl.NumberFormat);
        assert.equal(toLatinDigits('فاتورة ١٢٣ و ۴۵'), 'فاتورة 123 و 45');
        assert.deepEqual(withLatinNumbers('ar-u-nu-arab'), ['ar-u-nu-latn']);
        console.log('LATIN_DIGITS_OK');
    });
    JAVASCRIPT;

    $process = new Process(['node', '-e', $script], dirname(__DIR__, 2));
    $process->run();

    expect($process->getErrorOutput())->toBe('');
    expect($process->getOutput())->toContain('LATIN_DIGITS_OK');
});
