param(
    [string]$Url
)

$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($Url)) {
    $Url = Read-Host 'Enter the full Point of Sale URL'
}

$posAddress = $null
if (-not [Uri]::TryCreate($Url.Trim(), [UriKind]::Absolute, [ref]$posAddress) -or
    $posAddress.Scheme -notin @('http', 'https') -or
    $Url.Contains('"') -or $Url.Contains("`r") -or $Url.Contains("`n")) {
    throw 'Enter a valid http or https Point of Sale URL.'
}

$chromeCandidates = @(
    (Join-Path $env:ProgramFiles 'Google\Chrome\Application\chrome.exe'),
    (Join-Path ${env:ProgramFiles(x86)} 'Google\Chrome\Application\chrome.exe'),
    (Join-Path $env:LOCALAPPDATA 'Google\Chrome\Application\chrome.exe')
)
$chromePath = $chromeCandidates | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1
if (-not $chromePath) {
    throw 'Google Chrome was not found. Install Chrome and run this launcher again.'
}

$profileDirectory = Join-Path $env:LOCALAPPDATA 'WaslaPOS\ChromeSilentPrint'
$chromeArguments = @(
    '--kiosk-printing',
    ('--user-data-dir="{0}"' -f $profileDirectory),
    ('--app="{0}"' -f $posAddress.AbsoluteUri)
)

Write-Host 'Opening POS with Chrome silent printing. Use F6 to save and print.'
Write-Host 'Select your thermal printer as the default printer and configure 80 mm paper before printing.'
Start-Process -FilePath $chromePath -ArgumentList $chromeArguments -WindowStyle Normal
