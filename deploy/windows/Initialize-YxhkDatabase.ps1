[CmdletBinding()]
param(
    [ValidatePattern('^[a-z][a-z0-9-]{0,31}$')][string]$TenantId = 'owner',
    [string]$SiteUrl = 'http://127.0.0.1:3034',
    [Parameter(Mandatory=$true)][string]$AdminEmail
)
$ErrorActionPreference = 'Stop'
$uri = [Uri]$SiteUrl
if (-not $uri.IsAbsoluteUri -or $uri.Scheme -notin @('http', 'https') -or $uri.UserInfo) { throw 'Invalid site URL' }
if ($AdminEmail -notmatch '^[^\s@]+@[^\s@]+\.[^\s@]+$') { throw 'Invalid administrator email' }
$root = 'E:\pxy-deploy\YXHK'
$runtime = Join-Path 'E:\pxy-runtime\YXHK\tenants' $TenantId
$prepared = Join-Path $root "instances\$TenantId\prepared.json"
if (-not (Test-Path -LiteralPath $prepared)) { throw 'Prepare a release before initializing the database' }
$release = (Get-Content -LiteralPath $prepared -Raw | ConvertFrom-Json).release
$configDir = Join-Path $runtime 'config'
New-Item -ItemType Directory -Force -Path $configDir | Out-Null
$account = [Security.Principal.WindowsIdentity]::GetCurrent().Name
& icacls.exe $configDir /inheritance:r /grant:r "${account}:(OI)(CI)(F)" '*S-1-5-18:(OI)(CI)(F)' '*S-1-5-32-544:(OI)(CI)(F)' | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Could not protect private customer configuration' }
$credential = Get-Credential -UserName 'root' -Message 'YXHK: enter the LOCAL MySQL administrator password. It will not be saved.'
if (-not $credential) { throw 'Database login cancelled' }
$payload = @{tenant=$TenantId; user=$credential.UserName; password=$credential.GetNetworkCredential().Password;
    site_url=$SiteUrl; admin_email=$AdminEmail} | ConvertTo-Json -Compress
$php = Join-Path $root 'tools\php-8.3.35\php.exe'
$helper = Join-Path $PSScriptRoot 'initialize-database.php'
$start = [Diagnostics.ProcessStartInfo]::new()
$start.FileName = $php
$start.Arguments = '"' + $helper + '"'
$start.UseShellExecute = $false
$start.CreateNoWindow = $true
$start.RedirectStandardInput = $true
$start.RedirectStandardOutput = $true
$start.RedirectStandardError = $true
$process = [Diagnostics.Process]::Start($start)
try {
    $process.StandardInput.WriteLine($payload)
    $process.StandardInput.Close()
    $payload = $null
    $credential = $null
    $stdout = $process.StandardOutput.ReadToEnd()
    $stderr = $process.StandardError.ReadToEnd()
    $process.WaitForExit()
    if ($process.ExitCode -ne 0) { throw $stderr.Trim() }
    Write-Output $stdout.Trim()
} finally { $process.Dispose() }
Push-Location $release
try {
    & $php bin/console mautic:install $SiteUrl --force --no-interaction --env=prod
    if ($LASTEXITCODE -ne 0) { throw 'Mautic installation failed; customer data/configuration preserved for diagnosis' }
    & $php bin/console cache:clear --env=prod --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'Installed database, but cache rebuild failed' }
} finally { Pop-Location }
Write-Output ('Database initialized. Initial login is stored privately at: ' + (Join-Path $configDir 'initial-access.json'))
