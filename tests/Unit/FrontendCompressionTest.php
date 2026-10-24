<?php

test('production CSS and JavaScript have smaller gzip copies with identical content', function (): void {
    $root = dirname(__DIR__, 2);
    $manifest = json_decode(file_get_contents($root.'/public/build/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach (['resources/css/app.css', 'resources/js/app.js'] as $entry) {
        $file = $root.'/public/build/'.$manifest[$entry]['file'];
        expect(is_file($file.'.gz'))->toBeTrue()
            ->and(hash('sha256', gzdecode(file_get_contents($file.'.gz'))))->toBe(hash_file('sha256', $file))
            ->and(filesize($file.'.gz'))->toBeLessThan(filesize($file) / 2);
    }
});
