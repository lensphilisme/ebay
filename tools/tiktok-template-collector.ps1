param(
    [string] $Profile = "",
    [string] $DownloadDir = "",
    [string] $QueuePath = "",
    [string] $StartUrl = "",
    [string] $Session = "tiktok-template-collector",
    [switch] $AutoConnect,
    [switch] $ReuseRunningBrowser
)

$ErrorActionPreference = "Stop"

function Read-DotEnvValue {
    param([string] $Path, [string] $Key, [string] $Default = "")
    if (-not (Test-Path -LiteralPath $Path)) {
        return $Default
    }
    foreach ($line in Get-Content -LiteralPath $Path) {
        $trimmed = $line.Trim()
        if ($trimmed -eq "" -or $trimmed.StartsWith("#") -or -not $trimmed.Contains("=")) {
            continue
        }
        $parts = $trimmed.Split("=", 2)
        if ($parts[0].Trim() -eq $Key) {
            return $parts[1].Trim().Trim('"').Trim("'")
        }
    }
    return $Default
}

$root = Resolve-Path -LiteralPath (Join-Path $PSScriptRoot "..")
$envPath = Join-Path $root ".env"

if ($Profile -eq "") {
    $Profile = Read-DotEnvValue $envPath "TIKTOK_COLLECTOR_BROWSER_PROFILE" "Default"
}
if ($DownloadDir -eq "") {
    $DownloadDir = Read-DotEnvValue $envPath "TIKTOK_SHOP_TEMPLATE_DIR" "$env:USERPROFILE\Downloads"
}
if ($QueuePath -eq "") {
    $QueuePath = Read-DotEnvValue $envPath "TIKTOK_TEMPLATE_REQUEST_QUEUE" "database/template-requests/tiktok-missing-categories.json"
}
if ($StartUrl -eq "") {
    $StartUrl = Read-DotEnvValue $envPath "TIKTOK_SELLER_CENTER_BULK_LISTING_URL" "https://seller-us.tiktok.com/product/manage"
}

if (-not (Test-Path -LiteralPath $DownloadDir)) {
    New-Item -ItemType Directory -Path $DownloadDir | Out-Null
}

if (-not [System.IO.Path]::IsPathRooted($QueuePath)) {
    $QueuePath = Join-Path $root $QueuePath
}
$queueDir = Split-Path -Parent $QueuePath
if (-not (Test-Path -LiteralPath $queueDir)) {
    New-Item -ItemType Directory -Path $queueDir | Out-Null
}

$agent = Get-Command agent-browser -ErrorAction SilentlyContinue
if ($null -eq $agent) {
    throw "agent-browser is not installed or not on PATH."
}

if (-not $ReuseRunningBrowser) {
    & agent-browser close --all | Out-Null
}

$agentArgs = @("--session", $Session)
if ($ReuseRunningBrowser) {
    Write-Host "Reusing running controlled browser session: $Session"
} else {
    $agentArgs += @("--session-name", $Session, "--headed", "--download-path", $DownloadDir)
    if ($AutoConnect) {
        $agentArgs += "--auto-connect"
    } elseif ($Profile -ne "") {
        $agentArgs += @("--profile", $Profile)
    }
}

Write-Host "Opening TikTok Seller Center in controlled browser session: $Session"
Write-Host "Download folder: $DownloadDir"
& agent-browser @agentArgs open $StartUrl
& agent-browser --session $Session wait 5000 | Out-Null

$currentUrl = (& agent-browser --session $Session get url).Trim()
$title = (& agent-browser --session $Session get title).Trim()
$snapshotPath = Join-Path $queueDir "tiktok-sellercenter-snapshot.txt"
& agent-browser --session $Session snapshot -i | Out-File -FilePath $snapshotPath -Encoding UTF8

Write-Host "Current URL: $currentUrl"
Write-Host "Page title: $title"
Write-Host "Snapshot saved: $snapshotPath"

if ($currentUrl -match "/login") {
    Write-Host ""
    Write-Host "The controlled browser is not logged in yet."
    Write-Host "Log in once in the opened controlled browser window, then run:"
    Write-Host ".\tools\tiktok-template-collector.ps1 -ReuseRunningBrowser"
    Write-Host "No product publishing or account changes were performed."
    exit 2
}

if (Test-Path -LiteralPath $QueuePath) {
    $queue = Get-Content -LiteralPath $QueuePath -Raw
    Write-Host "Missing-template queue: $QueuePath"
    Write-Host $queue
} else {
    Write-Host "No missing-template queue exists yet: $QueuePath"
}

Write-Host ""
Write-Host "Next automation step:"
Write-Host "Use the saved snapshot to map TikTok's current Bulk Listing controls, then the collector can click Download template for queued categories and save XLSX files into TIKTOK_SHOP_TEMPLATE_DIR."
