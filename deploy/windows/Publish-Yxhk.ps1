[CmdletBinding()]
param(
    [ValidatePattern('^[a-z][a-z0-9-]{0,31}$')][string]$TenantId = 'owner',
    [ValidatePattern('^$|^[0-9a-fA-F]{40}$')][string]$SourceCommit = '',
    [switch]$Preview
)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$source = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$deployRoot = 'E:\pxy-deploy\YXHK'
$runtime = Join-Path 'E:\pxy-runtime\YXHK\tenants' $TenantId
$instance = Join-Path (Join-Path $deployRoot 'instances') $TenantId
$php = Join-Path $deployRoot 'tools\php-8.3.35\php.exe'
$composer = Join-Path $deployRoot 'tools\composer.phar'
$revision = if ($SourceCommit) { $SourceCommit } else { 'HEAD' }
$commit = (& git.exe -C $source rev-parse --verify "$revision^{commit}").Trim()
if ($LASTEXITCODE -ne 0 -or $commit -notmatch '^[a-f0-9]{40}$') { throw 'Cannot resolve source commit' }
$releaseId = (Get-Date -Format 'yyyyMMdd-HHmmss') + '-' + $commit.Substring(0, 12)
$release = Join-Path (Join-Path $instance 'releases') $releaseId
if ($Preview) {
    [ordered]@{status='preview'; tenant=$TenantId; source_commit=$commit; release_path=$release;
        runtime_root=$runtime; activation='separate_after_database_and_service_provisioning';
        email_delivery='disabled_until_configured'} | ConvertTo-Json
    return
}
foreach ($file in @($php, $composer)) {
    if (-not (Test-Path -LiteralPath $file -PathType Leaf)) { throw "Missing prerequisite: $file" }
}
$npm = (Get-Command npm.cmd -ErrorAction Stop).Source
$npx = (Get-Command npx.cmd -ErrorAction Stop).Source
New-Item -ItemType Directory -Force -Path $instance | Out-Null
$lockPath = Join-Path $instance 'publish.lock'
$lock = [IO.File]::Open($lockPath, [IO.FileMode]::OpenOrCreate, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None)
$oldPath = $env:PATH
$oldMirror = $env:COMPOSER_MIRROR_PATH_REPOS
function Invoke-Checked([string]$Program, [string[]]$Arguments) {
    & $Program @Arguments
    if ($LASTEXITCODE -ne 0) { throw "$Program failed: exit=$LASTEXITCODE" }
}
function Write-Utf8([string]$Path, [string]$Value) {
    [IO.File]::WriteAllText($Path, $Value, [Text.UTF8Encoding]::new($false))
}
try {
    New-Item -ItemType Directory -Path $release -Force | Out-Null
    $archive = Join-Path $instance ($releaseId + '.zip')
    Invoke-Checked git.exe @('-C', $source, 'archive', '--format=zip', "--output=$archive", $commit)
    Expand-Archive -LiteralPath $archive -DestinationPath $release
    Remove-Item -LiteralPath $archive
    $env:PATH = (Split-Path $php) + ';' + $oldPath
    $env:COMPOSER_MIRROR_PATH_REPOS = '1'
    Push-Location $release
    try {
        Invoke-Checked $php @($composer, 'install', '--no-dev', '--no-scripts', '--prefer-dist', '--no-interaction')
        Invoke-Checked $php @($composer, 'check-platform-reqs', '--no-dev')
        Invoke-Checked $npm @('ci', '--no-audit', '--no-fund')
        Invoke-Checked $npx @('--no-install', 'patch-package')
        Invoke-Checked $npm @('ci', '--prefix', 'plugins/GrapesJsBuilderBundle', '--no-audit', '--no-fund')
        Invoke-Checked $npm @('run', 'build', '--prefix', 'plugins/GrapesJsBuilderBundle')
        $bundleConfig = "<?php`nrequire_once dirname(__DIR__).'/deploy/windows/WindowsAssetsBundle.php';`n" + '$bundles[] = new \Yxhk\NativeWindows\WindowsAssetsBundle();' + "`n"
        Write-Utf8 (Join-Path $release 'config\bundles_local.php') $bundleConfig
        Invoke-Checked $php @('bin/console', 'mautic:assets:generate', '--env=prod', '--no-interaction')
        Invoke-Checked $php @('bin/console', 'assets:install', '.', '--env=prod', '--no-interaction')
    } finally { Pop-Location }
    foreach ($directory in @('config', 'logs', 'tmp', 'sessions', 'imports')) {
        New-Item -ItemType Directory -Force -Path (Join-Path $runtime $directory) | Out-Null
    }
    # Mutable media is seeded once, then stays outside release directories.
    foreach ($directory in @('images', 'files')) {
        $link = Join-Path $release "media\$directory"
        $target = Join-Path $runtime "media\$directory"
        if (-not (Test-Path -LiteralPath $target)) {
            New-Item -ItemType Directory -Force -Path $target | Out-Null
            if (Test-Path -LiteralPath $link) {
                Get-ChildItem -LiteralPath $link -Force | Copy-Item -Destination $target -Recurse -Force
            }
        }
        # This is a newly exported release, never an active customer's path.
        if (Test-Path -LiteralPath $link) {
            $resolved = [IO.Path]::GetFullPath($link)
            if (-not $resolved.StartsWith($release + '\', [StringComparison]::OrdinalIgnoreCase)) { throw 'Unsafe media path' }
            Remove-Item -LiteralPath $link -Recurse -Force
        }
        New-Item -ItemType Junction -Path $link -Target $target | Out-Null
    }
    # Downloaded language packs persist across releases and remain writable by the customer service.
    $translations = Join-Path $runtime 'translations'
    New-Item -ItemType Directory -Force -Path $translations | Out-Null
    $translationLink = Join-Path $release 'translations'
    if (Test-Path -LiteralPath $translationLink) {
        Get-ChildItem -LiteralPath $translationLink -Force | Copy-Item -Destination $translations -Recurse -Force
        $resolved = [IO.Path]::GetFullPath($translationLink)
        if (-not $resolved.StartsWith($release + '\', [StringComparison]::OrdinalIgnoreCase)) { throw 'Unsafe translation path' }
        Remove-Item -LiteralPath $translationLink -Recurse -Force
    }
    New-Item -ItemType Junction -Path $translationLink -Target $translations | Out-Null
    $localConfig = (Join-Path $runtime 'config\local.php').Replace('\', '/')
    Write-Utf8 (Join-Path $release 'config\paths_local.php') ("<?php`n" + '$paths[''local_config''] = ''' + $localConfig + "';`n")
    $metadata = [ordered]@{schema_version=1; project='YXHK'; tenant=$TenantId; commit=$commit;
        release=$release; runtime_root=$runtime; status='prepared'; built_at=(Get-Date).ToUniversalTime().ToString('o')}
    Write-Utf8 (Join-Path $release '.pxy-release.json') ($metadata | ConvertTo-Json)
    Write-Utf8 (Join-Path $instance 'prepared.json') ($metadata | ConvertTo-Json)
    $metadata | ConvertTo-Json
} catch {
    # No active pointer or database is changed by preparation.
    Write-Error "Release preparation failed; existing service unchanged. Artifact: $release. $($_.Exception.Message)"
    throw
} finally {
    $env:PATH = $oldPath
    $env:COMPOSER_MIRROR_PATH_REPOS = $oldMirror
    $lock.Dispose()
}
