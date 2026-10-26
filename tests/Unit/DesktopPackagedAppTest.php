<?php

use Symfony\Component\Process\Process;

test('bundled desktop opens the local login and retains its database across restarts', function (): void {
    $project = dirname(__DIR__, 2);
    $dataRoot = $project.'/storage/app/desktop-build/test-runs/'.bin2hex(random_bytes(8));
    $binary = getenv('WASLA_DESKTOP_EXE') ?: $project.'/node_modules/electron/dist/electron.exe';
    $arguments = getenv('WASLA_DESKTOP_EXE')
        ? [$binary, '--smoke-test']
        : [$binary, $project.'/resources/desktop/main.cjs', '--smoke-test'];
    $process = new Process($arguments, $project, ['WASLA_DESKTOP_TEST_DATA' => $dataRoot, 'ELECTRON_RUN_AS_NODE' => false]);
    $process->setTimeout(120);
    $process->run();
    $startupError = is_file($dataRoot.'/logs/startup-error.log') ? file_get_contents($dataRoot.'/logs/startup-error.log') : '';

    expect($process->getExitCode(), $startupError.$process->getErrorOutput())->toBe(0);
    $result = json_decode(file_get_contents($dataRoot.'/smoke-result.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($result['loginForm'])->toBeTrue()
        ->and($result['action'])->toStartWith('http://127.0.0.1:')
        ->and($result['styles'])->not->toBeEmpty()
        ->and($result['consoleErrors'])->toBe([])
        ->and($result['text'])->toContain('تسجيل دخول الموظف')->not->toContain('Sign up', 'Sign in with a passkey')
        ->and(is_file($dataRoot.'/database.sqlite'))->toBeTrue()
        ->and(is_file($dataRoot.'/smoke-login.png'))->toBeTrue();
    $key = file_get_contents($dataRoot.'/application-key');
    $databaseSize = filesize($dataRoot.'/database.sqlite');
    $process->run();
    expect($process->getExitCode(), $process->getErrorOutput())->toBe(0)
        ->and(file_get_contents($dataRoot.'/application-key'))->toBe($key)
        ->and(filesize($dataRoot.'/database.sqlite'))->toBeGreaterThanOrEqual($databaseSize);
})->skip(PHP_OS_FAMILY !== 'Windows' || ! is_file(dirname(__DIR__, 2).'/storage/app/desktop-build/stage/php/php.exe'), 'Build desktop resources on Windows before running this integration test.');
