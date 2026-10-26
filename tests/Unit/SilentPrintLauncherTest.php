<?php

use Symfony\Component\Process\Process;

test('silent print launcher starts an isolated Chrome session and rejects non web addresses', function (): void {
    $launcher = str_replace("'", "''", dirname(__DIR__, 2).'/Start-POS-Silent.ps1');
    $script = <<<'POWERSHELL'
    $ErrorActionPreference = 'Stop'
    function Start-Process {
        param($FilePath, $ArgumentList, $WindowStyle)
        $script:CapturedLaunch = @{ FilePath = $FilePath; Arguments = $ArgumentList; WindowStyle = $WindowStyle }
    }
    . '__LAUNCHER__' -Url 'https://pos.example.test/dashboard/pos'
    if ($script:CapturedLaunch.Arguments -notcontains '--kiosk-printing') { throw 'Silent printing is missing.' }
    if (-not ($script:CapturedLaunch.Arguments | Where-Object { $_ -like '--user-data-dir=*ChromeSilentPrint*' })) { throw 'The isolated profile is missing.' }
    if ($script:CapturedLaunch.Arguments -notcontains '--app="https://pos.example.test/dashboard/pos"') { throw 'The POS address is incorrect.' }
    if ($script:CapturedLaunch.FilePath -notlike '*chrome.exe') { throw 'Chrome was not selected.' }
    foreach ($address in @('file:///C:/Windows', 'javascript:alert(1)', 'https://example.test/"invalid')) {
        $script:CapturedLaunch = $null
        $rejected = $false
        try { . '__LAUNCHER__' -Url $address } catch { $rejected = $true }
        if (-not $rejected -or $script:CapturedLaunch) { throw 'An invalid address was allowed.' }
    }
    Write-Output 'SILENT_PRINT_LAUNCHER_OK'
    POWERSHELL;
    $process = new Process(['powershell.exe', '-NoProfile', '-NonInteractive', '-Command', str_replace('__LAUNCHER__', $launcher, $script)]);
    $process->run();

    expect($process->getErrorOutput())->toBe('');
    expect($process->isSuccessful())->toBeTrue();
    expect($process->getOutput())->toContain('SILENT_PRINT_LAUNCHER_OK');
})->skip(PHP_OS_FAMILY !== 'Windows', 'This launcher is intended for Windows cashier devices.');

test('offline launcher starts the synchronization scheduler once and opens the local POS', function (): void {
    $launcher = str_replace("'", "''", dirname(__DIR__, 2).'/Start-POS-Offline.ps1');
    $script = <<<'POWERSHELL'
    $ErrorActionPreference = 'Stop'
    $global:OfflineStarts = @()
    $global:OfflineSchedulerRunning = $false
    function Get-Command { param($Name, $ErrorAction) [pscustomobject]@{ Source = 'C:\php\php.exe' } }
    function Get-CimInstance {
        param($ClassName, $Filter)
        if ($global:OfflineSchedulerRunning) { [pscustomobject]@{ CommandLine = $global:OfflineSchedulerArguments } }
    }
    function Start-Process {
        param($FilePath, $ArgumentList, $WorkingDirectory, $WindowStyle)
        $global:OfflineStarts += [pscustomobject]@{ FilePath = $FilePath; Arguments = $ArgumentList; WindowStyle = $WindowStyle }
        if ($FilePath -like '*php.exe') { $global:OfflineSchedulerArguments = $ArgumentList; $global:OfflineSchedulerRunning = $true }
    }
    . '__LAUNCHER__' -Url 'http://127.0.0.1/dashboard/pos'
    . '__LAUNCHER__' -Url 'http://127.0.0.1/dashboard/pos'
    $workers = @($global:OfflineStarts | Where-Object { $_.FilePath -like '*php.exe' })
    if ($workers.Count -ne 1) { throw 'Scheduler was duplicated.' }
    if ($workers[0].WindowStyle -ne 'Hidden') { throw 'Scheduler must run hidden.' }
    if ($workers[0].Arguments -notlike '*schedule:work --no-interaction') { throw 'Wrong scheduler command.' }
    if (@($global:OfflineStarts | Where-Object { $_.FilePath -like '*chrome.exe' }).Count -ne 2) { throw 'POS did not open.' }
    Write-Output 'OFFLINE_LAUNCHER_OK'
    POWERSHELL;
    $process = new Process(['powershell.exe', '-NoProfile', '-NonInteractive', '-Command', str_replace('__LAUNCHER__', $launcher, $script)]);
    $process->run();

    expect($process->getErrorOutput())->toBe('');
    expect($process->isSuccessful())->toBeTrue();
    expect($process->getOutput())->toContain('OFFLINE_LAUNCHER_OK');
})->skip(PHP_OS_FAMILY !== 'Windows', 'This launcher is intended for Windows branch servers.');
