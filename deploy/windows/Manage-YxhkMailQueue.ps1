[CmdletBinding()]
param(
    [ValidateSet('Status', 'Start', 'Pause', 'Stop', 'Interval')][string]$Action = 'Status',
    [ValidatePattern('^[a-z][a-z0-9-]{0,31}$')][string]$TenantId = 'owner',
    [string]$JobId,
    [ValidateRange(1, 86400)][int]$IntervalSeconds = 20,
    [ValidateRange(0, 20000)][int]$MaxRecipients = 0,
    [switch]$ShowRecipients,
    [string]$ExportCsv
)

$ErrorActionPreference = 'Stop'
$instance = Join-Path 'E:\pxy-deploy\YXHK\instances' $TenantId
$release = Join-Path $instance 'current'
$php = 'E:\pxy-deploy\YXHK\tools\php-8.3.35\php.exe'
$serviceName = "yxhk-$TenantId-mailqueue"
if (-not (Test-Path -LiteralPath $php -PathType Leaf) -or -not (Test-Path -LiteralPath (Join-Path $release 'bin\console') -PathType Leaf)) {
    throw 'YXHK 发布版本或 PHP 不存在，请先部署。'
}
if ($Action -in @('Start', 'Pause', 'Stop') -and [string]::IsNullOrWhiteSpace($JobId)) {
    throw '开始、暂停或停止时必须填写 -JobId。先运行 -Action Status 查看批次 ID。'
}
if ($ExportCsv -and [string]::IsNullOrWhiteSpace($JobId)) {
    throw '导出明细时必须填写 -JobId，避免混合不同批次。'
}
if ($MaxRecipients -gt 0 -and $Action -ne 'Start') {
    throw '-MaxRecipients 仅用于 -Action Start。'
}
if ($Action -eq 'Start') {
    $service = Get-Service -Name $serviceName -ErrorAction Stop
    if ($service.Status -ne 'Running') {
        Start-Service -Name $serviceName
    }
}

$commandAction = $Action.ToLowerInvariant()
$arguments = @('bin/console', 'yxhk:mail-queue:control', $commandAction, '--env=prod', '--no-interaction')
if ($JobId) { $arguments += "--job=$JobId" }
if ($Action -eq 'Interval') { $arguments += "--seconds=$IntervalSeconds" }
if ($MaxRecipients -gt 0) { $arguments += "--limit=$MaxRecipients" }
if ($ShowRecipients -or $ExportCsv) { $arguments += '--recipients' }
Push-Location $release
try {
    $raw = & $php @arguments 2>&1
    if ($LASTEXITCODE -ne 0) { throw "YXHK 队列命令失败：$($raw -join ' ')" }
    $payload = [string]@($raw)[-1]
    $jsonStart = $payload.IndexOf('{')
    if ($jsonStart -lt 0) { throw "YXHK 队列命令未返回状态数据：$($raw -join ' ')" }
    $result = $payload.Substring($jsonStart) | ConvertFrom-Json
} finally {
    Pop-Location
}

Write-Host "后台服务：$(if ($result.worker_online) { '在线' } else { '未在线' })；全局间隔：$($result.interval_seconds) 秒"
$statusLabels = @{ paused = '已暂停'; running = '发送中'; completed = '已完成'; stopped = '已停止'; pending = '待发送'; sending = '投递中'; sent = 'SMTP 已接收'; skipped = '已跳过'; failed = '发送失败'; uncertain = '待核对' }
$result.jobs | ForEach-Object {
    $job = $_
    [pscustomobject]@{
        批次ID = $job.id
        名称 = $job.name
        状态 = $statusLabels[$job.status]
        总数 = $job.total
        已接收 = [int]$job.counts.sent
        失败 = [int]$job.counts.failed
        跳过 = [int]$job.counts.skipped
        待核对 = [int]$job.counts.uncertain
        待发送 = [int]$job.counts.pending
    }
} | Format-Table -AutoSize

if ($ShowRecipients) {
    foreach ($job in $result.jobs) {
        Write-Host "批次：$($job.name) [$($job.id)]"
        $job.recipients | ForEach-Object {
            [pscustomobject]@{ 邮箱 = $_.email; 状态 = $statusLabels[$_.status]; 发件邮箱 = $_.sender; 打开追踪时间 = $_.opened_at }
        } | Format-Table -AutoSize
    }
    Write-Host $result.open_tracking_note
}

if ($ExportCsv) {
    $job = @($result.jobs | Where-Object { $_.id -eq $JobId })
    if ($job.Count -ne 1) { throw '找不到指定批次，未导出。' }
    $destination = [IO.Path]::GetFullPath($ExportCsv)
    if (-not (Test-Path -LiteralPath (Split-Path -Parent $destination) -PathType Container)) {
        throw '导出目录不存在。'
    }
    $job[0].recipients | ForEach-Object {
        [pscustomobject]@{
            邮箱 = if ($_.email -match '^[=+\-@]') { "'" + $_.email } else { $_.email }
            状态 = $statusLabels[$_.status]
            发件邮箱 = if ($_.sender -match '^[=+\-@]') { "'" + $_.sender } else { $_.sender }
            尝试时间 = if ($_.attempted) { [DateTimeOffset]::FromUnixTimeSeconds([long]$_.attempted).LocalDateTime.ToString('yyyy-MM-dd HH:mm:ss') } else { '' }
            完成时间 = if ($_.finished) { [DateTimeOffset]::FromUnixTimeSeconds([long]$_.finished).LocalDateTime.ToString('yyyy-MM-dd HH:mm:ss') } else { '' }
            打开追踪时间 = $_.opened_at
        }
    } | Export-Csv -LiteralPath $destination -NoTypeInformation -Encoding $(if ($PSVersionTable.PSVersion.Major -ge 6) { 'utf8BOM' } else { 'UTF8' })
    Write-Host "收件明细已导出：$destination"
    Write-Host $result.open_tracking_note
}
