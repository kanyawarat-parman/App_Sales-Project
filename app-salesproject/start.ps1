# ============================================================
# start.ps1 — รัน PHP Dev Server สำหรับ e-Bidding Web App
# ============================================================

param(
    [string]$HostName = "localhost",
    [int]   $Port     = 8000,
    [switch]$NoBrowser
)

$ProjectRoot = $PSScriptRoot
$ServerUrl   = "http://${HostName}:${Port}"

# ---- สี ----
function Write-Color([string]$Text, [string]$Color = "White") {
    Write-Host $Text -ForegroundColor $Color
}

Clear-Host
Write-Color "============================================================" Cyan
Write-Color "   📋  ระบบจัดการประกาศ e-Bidding เฟอร์นิเจอร์" Cyan
Write-Color "============================================================" Cyan
Write-Host ""

# ---- ตรวจ PHP ----
$phpPath = (Get-Command php -ErrorAction SilentlyContinue)?.Source
if (-not $phpPath) {
    Write-Color "❌  ไม่พบ PHP ในระบบ" Red
    Write-Color "    กรุณาติดตั้ง PHP และเพิ่มลงใน PATH" Yellow
    Write-Color "    ดาวน์โหลด: https://windows.php.net/download/" Yellow
    Read-Host "กด Enter เพื่อออก"
    exit 1
}

$phpVersion = (php -r "echo PHP_VERSION;")
Write-Color "✅  PHP: $phpVersion  ($phpPath)" Green

# ---- ตรวจ port ว่างหรือไม่ ----
$portInUse = (Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue)
if ($portInUse) {
    Write-Color "⚠️   Port $Port ถูกใช้งานอยู่ ลองเปลี่ยน port ด้วย -Port XXXX" Yellow
    $ans = Read-Host "ต้องการใช้ port อื่นอัตโนมัติ? (Y/n)"
    if ($ans -ne 'n' -and $ans -ne 'N') {
        $Port = $Port + 1
        while (Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue) {
            $Port++
        }
        $ServerUrl = "http://${HostName}:${Port}"
        Write-Color "➡️   ใช้ port $Port แทน" Cyan
    } else {
        exit 1
    }
}

# ---- แสดง URL ----
Write-Host ""
Write-Color "🌐  Server URL : $ServerUrl" White
Write-Color "⚙️   Setup page : $ServerUrl/setup.php" White
Write-Color "📁  Project    : $ProjectRoot" White
Write-Host ""
Write-Color "─────────────────────────────────────────────────────────" DarkGray
Write-Color "  Users เริ่มต้น (หลัง setup.php):" DarkCyan
Write-Color "    admin       / Admin@1234  (ผู้ดูแลระบบ)" White
Write-Color "    secretary01 / Admin@1234  (ธุรการขาย)" White
Write-Color "    sale01      / Admin@1234  (Sales)" White
Write-Color "    sale02      / Admin@1234  (Sales)" White
Write-Color "─────────────────────────────────────────────────────────" DarkGray
Write-Host ""
Write-Color "  กด Ctrl+C เพื่อหยุด server" Yellow
Write-Host ""

# ---- เปิด browser ----
if (-not $NoBrowser) {
    Start-Sleep -Milliseconds 600
    Start-Process $ServerUrl
}

# ---- รัน PHP built-in server ----
Write-Color "🚀  Starting PHP server ..." Green
Write-Host ""

& php -S "${HostName}:${Port}" -t "$ProjectRoot" 2>&1 | ForEach-Object {
    $line = $_
    if ($line -match '\[200\]') { Write-Host $line -ForegroundColor DarkGreen }
    elseif ($line -match '\[404\]') { Write-Host $line -ForegroundColor DarkYellow }
    elseif ($line -match '\[500\]|\[503\]') { Write-Host $line -ForegroundColor Red }
    else { Write-Host $line -ForegroundColor DarkGray }
}
