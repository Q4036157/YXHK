[CmdletBinding()]
param([string]$SshTarget = 'tokyo2')
$ErrorActionPreference = 'Stop'
$source = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$commit = (& git -C $source rev-parse HEAD).Trim()
if ($commit -notmatch '^[a-f0-9]{40}$') { throw 'Invalid source commit' }
$archive = Join-Path $env:TEMP ("yxhk-unsubscribe-$commit.tar")
& git -C $source archive --format=tar "--output=$archive" $commit deploy/edge204
if ($LASTEXITCODE -ne 0) { throw 'Archive failed' }
& scp -q $archive "${SshTarget}:/tmp/yxhk-unsubscribe-$commit.tar"
if ($LASTEXITCODE -ne 0) { throw 'Upload failed' }
$release = "/opt/yxhk-unsubscribe/releases/$commit"
& ssh $SshTarget "mkdir -p $release; tar -xf /tmp/yxhk-unsubscribe-$commit.tar -C $release --strip-components=2; bash $release/install.sh $release"
if ($LASTEXITCODE -ne 0) { throw '204 unsubscribe deployment failed' }
Write-Output "204 unsubscribe deployed: $commit"
