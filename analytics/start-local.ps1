$ErrorActionPreference = 'Stop'

$phpCommand = Get-Command php -ErrorAction SilentlyContinue
if ($phpCommand) {
    $php = $phpCommand.Source
} elseif (Test-Path 'C:\xampp\php\php.exe') {
    $php = 'C:\xampp\php\php.exe'
} else {
    throw 'PHP CLI was not found. Install PHP CLI, then run this script again.'
}

& $php -r "exit(extension_loaded('pdo_sqlite') ? 0 : 1);"
if ($LASTEXITCODE -ne 0) {
    throw 'The selected PHP runtime does not have the PDO SQLite extension enabled.'
}

Write-Host 'Local analytics is available at http://127.0.0.1:8000/test.html'
Write-Host 'Press Ctrl+C to stop the server.'
& $php -S 127.0.0.1:8000 -t $PSScriptRoot (Join-Path $PSScriptRoot 'dev-router.php')
