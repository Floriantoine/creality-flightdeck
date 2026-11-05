# K1 Probe — LECTURE SEULE : envoie seulement des demandes "get" (comme l'interface web de l'imprimante)
# et enregistre les réponses. Aucune commande qui chauffe, bouge ou lance quoi que ce soit.
param([string]$ip = "192.168.0.163", [int]$seconds = 20)
$out = Join-Path $PSScriptRoot "explore"
New-Item -ItemType Directory -Force -Path $out | Out-Null
$file = Join-Path $out "probe_messages.jsonl"
"" | Set-Content $file -Encoding UTF8
$ct = [Threading.CancellationToken]::None
$ws = New-Object System.Net.WebSockets.ClientWebSocket
if (-not $ws.ConnectAsync([Uri]"ws://${ip}:9999", $ct).Wait(6000)) { Write-Host "Connexion impossible"; exit 1 }
Write-Host "Connecté. Envoi des demandes de lecture..."
function Send($txt){ $b = [Text.Encoding]::UTF8.GetBytes($txt); $ws.SendAsync([ArraySegment[byte]]$b, [System.Net.WebSockets.WebSocketMessageType]::Text, $true, $ct).Wait(3000) | Out-Null; Add-Content $file ("SENT " + $txt) -Encoding UTF8 }
$reqs = @(
  '{"ModeCode":"heart_beat","msg":"probe"}',
  '{"method":"get","params":{"reqProbedMatrix":1}}',
  '{"method":"get","params":{"reqPrintObjects":1}}',
  '{"method":"get","params":{"ReqPrinterPara":1}}',
  '{"method":"get","params":{"reqHistory":1}}',
  '{"method":"get","params":{"reqGcodeFile":1}}'
)
$buf = New-Object byte[] 1048576
$sb = New-Object System.Text.StringBuilder
$end = (Get-Date).AddSeconds($seconds)
$i = 0; $next = Get-Date; $task = $null; $n = 0
while ((Get-Date) -lt $end -and $ws.State -eq "Open") {
  if ($i -lt $reqs.Count -and (Get-Date) -ge $next) { Send $reqs[$i]; $i++; $next = (Get-Date).AddSeconds(2) }
  if (-not $task) { $task = $ws.ReceiveAsync([ArraySegment[byte]]$buf, $ct) }
  if (-not $task.Wait(300)) { continue }
  $res = $task.Result; $task = $null
  [void]$sb.Append([Text.Encoding]::UTF8.GetString($buf, 0, $res.Count))
  if ($res.EndOfMessage) { $n++; Add-Content $file ("RECV " + $sb.ToString()) -Encoding UTF8; [void]$sb.Clear() }
}
Write-Host "$n messages enregistrés dans $file"
