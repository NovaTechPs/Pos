param(
    [Parameter(Mandatory = $true)]
    [string]$Url
)

$ErrorActionPreference = 'Stop'
$localAddress = $null
if (-not [Uri]::TryCreate($Url.Trim(), [UriKind]::Absolute, [ref]$localAddress) -or
    $localAddress.Scheme -notin @('http', 'https') -or
    $Url.Contains('"') -or $Url.Contains("`r") -or $Url.Contains("`n")) {
    throw 'Enter the local branch POS http or https URL.'
}

$phpCommand = Get-Command php -ErrorAction Stop
$artisanPath = Join-Path $PSScriptRoot 'artisan'
$schedulerArguments = '"{0}" schedule:work --no-interaction' -f $artisanPath
$scheduler = Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" |
    Where-Object { $_.CommandLine -and $_.CommandLine.Contains($schedulerArguments) } |
    Select-Object -First 1

if (-not $scheduler) {
    Start-Process -FilePath $phpCommand.Source -ArgumentList $schedulerArguments -WorkingDirectory $PSScriptRoot -WindowStyle Hidden
}

Write-Host 'Automatic branch synchronization is running. The local web server and database must remain running.'
& (Join-Path $PSScriptRoot 'Start-POS-Silent.ps1') -Url $localAddress.AbsoluteUri
