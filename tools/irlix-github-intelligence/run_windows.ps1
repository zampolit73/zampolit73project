$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot
$env:PYTHONUTF8 = '1'
if (-not (Test-Path '.venv\Scripts\python.exe')) {
    if (Get-Command py -ErrorAction SilentlyContinue) {
        & py -3 -m venv .venv
    } else {
        & python -m venv .venv
    }
    if ($LASTEXITCODE -ne 0) { throw 'Python 3.10+ is required. See README.md.' }
}
$pythonPath = Join-Path $PSScriptRoot '.venv\Scripts\python.exe'
& $pythonPath -m pip install -r requirements.txt
if ($LASTEXITCODE -ne 0) { throw 'Dependency installation failed.' }
Write-Host 'IRLIX GitHub Intelligence'
Write-Host '1 - Demo (no token), 2 - GitHub discovery, 3 - Seed repositories only'
$choice = Read-Host 'Choose 1, 2 or 3'
if ($choice -eq '1') {
    & $pythonPath -m irlix_intel local examples/demo --db data/demo.sqlite --out reports/demo
    if (Test-Path 'reports/demo/report.html') { Start-Process 'reports/demo/report.html' }
} elseif ($choice -in @('2', '3')) {
    if (-not $env:GITHUB_TOKEN) {
        $secureToken = Read-Host 'GitHub token (Enter to use limited anonymous mode)' -AsSecureString
        $tokenPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureToken)
        try {
            $env:GITHUB_TOKEN = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($tokenPointer)
        } finally {
            [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($tokenPointer)
        }
    }
    if ($choice -eq '3') {
        & $pythonPath -m irlix_intel scan --no-search --seeds config/seeds.txt --companies config/companies.example.json
    } else {
        & $pythonPath -m irlix_intel scan --profile quick --seeds config/seeds.txt --companies config/companies.example.json
    }
    if (Test-Path 'reports/report.html') { Start-Process 'reports/report.html' }
} else {
    Write-Host 'No mode selected. Use README.md for command-line options.'
}
