# Creality OS local API (as used by Creality Flightdeck)

These are the stock-firmware interfaces of Creality's Klipper-based printers (K1 series, likely K2 / Ender-3 V3 / Hi).
**Source** marks where each format comes from:
- **UI**: the printer's own web interface (`http://<ip>/static/js/app.js`, K1 Max fw 2.3.5.34)
- **Community**: third-party docs (see links at the bottom), not always verified on every model

> Formats can change between firmware versions. `set` commands act on the machine, so test them carefully.

## WebSocket: `ws://<ip>:9999`

### Connection
- The printer **pushes JSON status deltas**: only the fields that changed, so merge them.
- Heartbeat: the client sends `{"ModeCode":"heart_beat","msg":"<date>"}` (UI), the printer replies `ok`. If the printer sends `heart_beat`, reply `ok` (Community).

### Status fields seen on a K1 Max
`state` (0 idle, 1 printing, 2 done, others unverified), `deviceState`, `printFileName`, `printProgress`, `dProgress`, `layer`, `TotalLayer`, `printJobTime`, `printLeftTime`, `printStartTime`, `nozzleTemp`, `targetNozzleTemp`, `maxNozzleTemp`, `bedTemp0..2`, `targetBedTemp0..2`, `maxBedTemp`, `boxTemp`, `curPosition` (`"X:… Y:… Z:…"`), `autohome`, `realTimeSpeed`, `realTimeFlow`, `curFeedratePct`, `curFlowratePct`, `usedMaterialLength`, `modelFanPct`, `caseFanPct`, `auxiliaryFanPct`, `fan`, `fanCase`, `fanAuxiliary`, `lightSw`, `pressureAdvance`, `smoothTime`, `velocityLimits`, `accelerationLimits`, `accelToDecelLimits`, `cornerVelocityLimits`, `autoLevelResult` (e.g. `"36:0.08"`), `objects` (**JSON string**), `excluded_objects` (JSON string), `err` (`{errcode, key}`), `cfsConnect`, `materialDetect`, `materialStatus`, `powerLoss`, `repoPlrStatus`, `video`, `videoElapse*`, `aiSw`, `aiDetection`, `aiFirstFloor`, `aiPausePrint`, `tfCard`, `upgradeStatus`, `model`, `hostname`, `modelVersion`.

### Read requests: `{"method":"get","params":{"<key>":1}}`

| Key | Reply | Source |
|---|---|---|
| `ReqPrinterPara` | position/parameters refresh | UI |
| `reqProbedMatrix` | `probedMatrix: {val: [{x, y, z}, …]}` | UI |
| `reqPrintObjects` | `objects`, `excluded_objects` (JSON strings: `[{name, center, polygon}]`) | UI |
| `reqGcodeFile` | `retGcodeFileInfo: {fileInfo: "path:name:size:layers:time:length_mm:thumb;…"}` (also seen as `retGcodeFileInfo2`) | UI |
| `reqHistory` | `historyList: [{id, filename, starttime, usagetime, size, …}]` | UI |
| `reqGcodeList` | file list | Community |
| `reqMaterials` | filament library | Community |
| `boxsInfo` | `boxsInfo: {same_material, materialBoxs: [{id, type (0 = CFS, 1 = external), temp, humidity, materials: [{id, vendor, type, name, rfid, color "#0RRGGBB", minTemp, maxTemp, pressure, percent, state, selected, editStatus}]}]}` | Community |
| `boxConfig` | `boxConfig: {autoRefill, cAutoFeed, cSelfTest, cAutoUpdateFilament}` | Community |

### Commands: `{"method":"set","params":{…}}`

**Print**
| Params | Effect | Source |
|---|---|---|
| `{"opGcodeFile":"printprt:<dir>/<file>"}` | start a print | UI |
| `{"opGcodeFile":"deleteprt:<dir>/<file>"}` | delete a file | UI |
| `{"opGcodeFile":"renameprt:<dir>/<old>:<dir>/<new>"}` | rename a file | UI |
| `{"pause":1}` / `{"pause":0}` | pause / resume | UI |
| `{"stop":1}` | stop the print | UI |
| `{"excludeObjects":["<object name>"]}` | exclude an object | UI |
| `{"repoPlrStatus":1}` / `0` | resume / discard after a power loss | UI |
| `{"setFeedratePct":100}` | speed % | UI |

**Temperature, fans, light**
| Params | Effect | Source |
|---|---|---|
| `{"nozzleTempControl":220}` | nozzle target | UI |
| `{"bedTempControl":{"num":0,"val":55}}` | bed target (zone `num`) | UI |
| `{"fan":1}`, `{"fanCase":1}`, `{"fanAuxiliary":1}` | fans on/off | UI |
| `{"gcodeCmd":"M106 P0 S204"}` | part fan speed (P0 part, P1 case, P2 auxiliary; S 0–255) | UI |
| `{"lightSw":1}` | light | UI |

**Motion**
| Params | Effect | Source |
|---|---|---|
| `{"autohome":"X Y Z"}` (or `"X Y"`, `"X"`, `"Y"`, `"Z"`) | homing | UI |
| `{"setPosition":"X10 F3000"}` / `"X-10 F3000"` / `"Z-1 F600"` | relative jog | UI |
| `{"setZOffset":"+0.005"}` / `"-0.005"` | live Z-offset step | UI |
| `{"savePara":1}` | save parameters (Z-offset) | UI |

**Misc / maintenance**
| Params | Effect | Source |
|---|---|---|
| `{"gcodeCmd":"<any G-code>"}` | send raw G-code | UI |
| `{"cleanErr":1}` | clear the current error | UI |
| `{"restartKlipper":1}` / `{"restartFirmware":1}` | restart | UI |
| `{"rmProbedMatrix":1}` | delete the saved bed mesh | UI |
| `{"deleteHistory":[<id>, …]}` | remove history entries | UI |

**CFS**
| Params | Effect | Source |
|---|---|---|
| `{"feedInOrOut":{"boxId":1,"materialId":0,"isFeed":1}}` | load (1) / unload (0) a slot | Community |
| `{"boxTempControl":50}` | drying temperature (0 = off) | Community |
| `{"boxConfig":{"autoRefill":1,"cAutoFeed":1,"cSelfTest":0,"ignoreColorAutoFeed":0}}` | CFS settings | Community |
| `{"modifyMaterial":{"boxId":1,"id":0,"type":"PLA","vendor":"…","name":"…","color":"#RRGGBB",…}}` | edit slot metadata (not used here) | Community |

## HTTP

| URL | Description | Source |
|---|---|---|
| `GET http://<ip>/info` | `{"mac","model","version"}` | UI / Community |
| `POST http://<ip>/upload/<name>.gcode` | multipart, **only** the `file` field | UI / Community |
| `GET http://<ip>/downloads/humbnail/<name>.png` | file thumbnail (sic: "humbnail") | UI |
| `GET http://<ip>/downloads/defData/<name>.png` | fallback thumbnail | UI |
| `GET http://<ip>/downloads/original/current_print_image.png` | current print image | UI |
| `GET http://<ip>:8080/?action=stream` / `?action=snapshot` | camera (MJPEG, K1) | Community |

The printer sends **no CORS headers**: a page can open the WebSocket and send uploads, but it can't read HTTP replies.

## Sources
- The printer's own web UI (`static/js/app.js`), K1 Max firmware 2.3.5.34
- [sandman21vs/creality-cfs-panel: docs/cfs-api.md](https://github.com/sandman21vs/creality-cfs-panel/blob/main/docs/cfs-api.md)
- [alex-mextner/3d-cli #93](https://github.com/alex-mextner/3d-cli/issues/93)
- [OrcaSlicer #8193](https://github.com/OrcaSlicer/OrcaSlicer/issues/8193)
- [3dg1luk43/ha_creality_ws](https://github.com/3dg1luk43/ha_creality_ws)
