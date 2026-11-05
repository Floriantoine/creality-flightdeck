# K1 Explore — LECTURE SEULE : écoute l'imprimante et télécharge son interface web pour analyse.
# N'envoie aucune commande qui chauffe, bouge ou lance quoi que ce soit (seulement un "heart_beat").
param([string]$ip = "192.168.0.163", [int]$seconds = 25)
$ErrorActionPreference = "Continue"
$out = Join-Path $PSScriptRoot "explore"
New-Item -ItemType Directory -Force -Path $out | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $out "web") | Out-Null
$log = Join-Path $out "resume.txt"
"K1 Explore — $(Get-Date)  ip=$ip" | Set-Content $log -Encoding UTF8
function Log($s){ Write-Host $s; Add-Content $log $s -Encoding UTF8 }
[Net.ServicePointManager]::Expect100Continue = $false

# ---------- 1) Ports et adresses HTTP connus ----------
Log "`n=== HTTP ==="
$urls = @(
  "http://${ip}/", "http://${ip}:4408/", "http://${ip}:4409/", "http://${ip}:7125/server/info",
  "http://${ip}:7125/printer/objects/list", "http://${ip}:7125/printer/objects/query?bed_mesh",
  "http://${ip}:8080/?action=snapshot", "http://${ip}:8000/", "http://${ip}/downloads/original/current_print_image.png",
  "http://${ip}/info", "http://${ip}/api/info"
)
$i = 0
foreach ($u in $urls) {
  $i++
  try {
    $r = Invoke-WebRequest -Uri $u -TimeoutSec 5 -UseBasicParsing
    $len = if ($r.RawContentLength) { $r.RawContentLength } else { $r.Content.Length }
    Log ("OK   {0}  -> {1} {2} ({3} octets)" -f $u, $r.StatusCode, $r.Headers["Content-Type"], $len)
    $safe = ($u -replace '[^\w]+','_').Trim('_')
    if ($r.Headers["Content-Type"] -match "image") { [IO.File]::WriteAllBytes((Join-Path $out "$safe.bin"), $r.RawContentStream.ToArray()) }
    else { $r.Content | Set-Content (Join-Path $out "$safe.txt") -Encoding UTF8 }
  } catch {
    $code = $_.Exception.Response.StatusCode.value__
    Log ("FAIL {0}  -> {1}" -f $u, $(if ($code) { "HTTP $code" } else { $_.Exception.Message }))
  }
}

# ---------- 2) Interface web de l'imprimante (pour lire son JavaScript) ----------
Log "`n=== Interface web ==="
$done = @{}
function Grab($url) {
  if ($done.ContainsKey($url)) { return $null }
  $done[$url] = 1
  try {
    $r = Invoke-WebRequest -Uri $url -TimeoutSec 10 -UseBasicParsing
    $name = ($url -replace "^https?://[^/]+/", "") -replace '[\\/:*?"<>|]', '_'
    if (-not $name) { $name = "index.html" }
    $r.Content | Set-Content (Join-Path $out "web\$name") -Encoding UTF8
    Log "  + $url"
    return $r.Content
  } catch { Log "  - $url ($($_.Exception.Message))"; return $null }
}
foreach ($base in @("http://${ip}", "http://${ip}:4408")) {
  $html = Grab "$base/"
  if (-not $html) { continue }
  $refs = [regex]::Matches($html, '(?:src|href)\s*=\s*"([^"]+\.(?:js|css|json))"') | ForEach-Object { $_.Groups[1].Value }
  $queue = @()
  foreach ($ref in $refs) { $queue += $(if ($ref -match '^https?://') { $ref } elseif ($ref.StartsWith("/")) { "$base$ref" } else { "$base/$ref" }) }
  # Un niveau de plus : morceaux de JS chargés à la demande
  $more = @()
  foreach ($q in $queue) {
    $js = Grab $q
    if ($js) { [regex]::Matches($js, '["''](/?(?:static/)?js/[\w.\-]+\.js)["'']') | ForEach-Object { $p = $_.Groups[1].Value; $more += $(if ($p.StartsWith("/")) { "$base$p" } else { "$base/$p" }) } }
  }
  foreach ($m in ($more | Select-Object -Unique | Select-Object -First 60)) { Grab $m | Out-Null }
}

# ---------- 3) Écoute du websocket 9999 ----------
Log "`n=== Websocket ws://${ip}:9999 pendant $seconds s ==="
$msgFile = Join-Path $out "ws_messages.jsonl"
"" | Set-Content $msgFile -Encoding UTF8
$state = @{}
try {
  $ws = New-Object System.Net.WebSockets.ClientWebSocket
  $ct = [Threading.CancellationToken]::None
  if (-not $ws.ConnectAsync([Uri]"ws://${ip}:9999", $ct).Wait(6000)) { throw "délai de connexion" }
  Log "Connecté."
  $hb = [Text.Encoding]::UTF8.GetBytes('{"ModeCode":"heart_beat","msg":"explore"}')
  $ws.SendAsync([ArraySegment[byte]]$hb, [System.Net.WebSockets.WebSocketMessageType]::Text, $true, $ct).Wait(3000) | Out-Null
  $buf = New-Object byte[] 262144
  $sb = New-Object System.Text.StringBuilder
  $end = (Get-Date).AddSeconds($seconds)
  $count = 0
  $task = $null
  while ((Get-Date) -lt $end -and $ws.State -eq "Open") {
    if (-not $task) { $task = $ws.ReceiveAsync([ArraySegment[byte]]$buf, $ct) }
    if (-not $task.Wait(500)) { continue }
    $res = $task.Result; $task = $null
    [void]$sb.Append([Text.Encoding]::UTF8.GetString($buf, 0, $res.Count))
    if ($res.EndOfMessage) {
      $txt = $sb.ToString(); [void]$sb.Clear(); $count++
      Add-Content $msgFile $txt -Encoding UTF8
      try { $o = $txt | ConvertFrom-Json; foreach ($p in $o.PSObject.Properties) { $state[$p.Name] = $p.Value } } catch {}
    }
  }
  Log "$count messages reçus, $($state.Count) champs différents."
  $state | ConvertTo-Json -Depth 12 | Set-Content (Join-Path $out "ws_state.json") -Encoding UTF8
  try { $ws.CloseAsync([System.Net.WebSockets.WebSocketCloseStatus]::NormalClosure, "fin", $ct).Wait(2000) | Out-Null } catch {}
} catch { Log "Erreur websocket : $($_.Exception.Message)" }

Log "`nTerminé. Résultats dans : $out"
