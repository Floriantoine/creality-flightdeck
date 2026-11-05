"""
K1 Monitor v1 - Connexion à une Creality K1 / K1 Max (firmware stock, sans root)
---------------------------------------------------------------------------------
Se connecte au websocket local de l'imprimante (port 9999), comme Creality Print / Orca,
et affiche l'état en direct : températures, progression, couches, temps restant, état.

Lecture seule : ce script n'envoie AUCUNE commande physique (pas de chauffe, pas de mouvement).

Installation :
    pip install websockets

Utilisation :
    python k1_monitor.py 192.168.1.50          # affiche l'état en direct
    python k1_monitor.py 192.168.1.50 --raw    # enregistre aussi tous les messages bruts
                                               # dans k1_raw.jsonl (pratique pour découvrir
                                               # les champs du CFS, etc.)
"""

import argparse
import asyncio
import json
import sys
import time
from datetime import timedelta

try:
    import websockets
except ImportError:
    sys.exit("Il manque la librairie 'websockets'. Fais : pip install websockets")

PORT = 9999
HEARTBEAT_EVERY = 10  # secondes
RAW_LOG = "k1_raw.jsonl"

# Codes d'état connus (peuvent varier selon le firmware)
STATES = {
    0: "Inactive",
    1: "Impression",
    2: "Terminée",
    3: "Échec / arrêt",
    4: "Annulée",
    5: "Pause",
}


class K1State:
    """Garde l'état cumulé : l'imprimante n'envoie souvent que les champs qui changent."""

    def __init__(self):
        self.data = {}
        self.keys_seen = set()

    def update(self, msg: dict):
        self.data.update(msg)
        self.keys_seen.update(msg.keys())

    def get(self, *keys, default="-"):
        for k in keys:
            if k in self.data and self.data[k] not in ("", None):
                return self.data[k]
        return default


def fmt_temp(current, target):
    try:
        c = float(current)
    except (TypeError, ValueError):
        return "-"
    try:
        t = float(target)
        return f"{c:6.1f} / {t:5.1f} °C"
    except (TypeError, ValueError):
        return f"{c:6.1f} °C"


def fmt_time(seconds):
    try:
        return str(timedelta(seconds=int(float(seconds))))
    except (TypeError, ValueError):
        return "-"


def render(state: K1State, ip: str, connected_since: float):
    s = state
    raw_state = s.get("state", default=None)
    try:
        state_txt = STATES.get(int(raw_state), f"Code {raw_state}")
    except (TypeError, ValueError):
        state_txt = "-" if raw_state is None else str(raw_state)

    progress = s.get("printProgress", "dProgress")
    lines = [
        f"=== K1 Max @ {ip}:{PORT}  (connecté depuis {fmt_time(time.time() - connected_since)}) ===",
        "",
        f"  État        : {state_txt}",
        f"  Fichier     : {s.get('printFileName')}",
        f"  Progression : {progress} %",
        f"  Couche      : {s.get('layer')} / {s.get('TotalLayer')}",
        f"  Temps écoulé: {fmt_time(s.get('printJobTime', default=None))}",
        f"  Restant     : {fmt_time(s.get('printLeftTime', default=None))}",
        "",
        f"  Buse        : {fmt_temp(s.get('nozzleTemp', default=None), s.get('targetNozzleTemp', default=None))}",
        f"  Plateau     : {fmt_temp(s.get('bedTemp0', default=None), s.get('targetBedTemp0', default=None))}",
        f"  Enceinte    : {fmt_temp(s.get('boxTemp', default=None), None)}",
        "",
        f"  Champs reçus jusqu'ici : {len(s.keys_seen)}  (utilise --raw pour tous les voir)",
        "",
        "  Ctrl+C pour quitter.",
    ]
    # Efface l'écran et réaffiche
    sys.stdout.write("\033[2J\033[H" + "\n".join(lines) + "\n")
    sys.stdout.flush()


async def heartbeat(ws):
    """Garde la connexion vivante, comme le fait Creality Print."""
    while True:
        await asyncio.sleep(HEARTBEAT_EVERY)
        payload = {"ModeCode": "heart_beat", "msg": time.strftime("%Y-%m-%d %H:%M:%S")}
        await ws.send(json.dumps(payload))


async def run(ip: str, raw: bool):
    url = f"ws://{ip}:{PORT}"
    state = K1State()
    raw_file = open(RAW_LOG, "a", encoding="utf-8") if raw else None

    while True:  # reconnexion automatique
        try:
            print(f"Connexion à {url} ...")
            async with websockets.connect(url, ping_interval=None, open_timeout=10) as ws:
                connected_since = time.time()
                hb = asyncio.create_task(heartbeat(ws))
                try:
                    async for message in ws:
                        if message == "ok":  # réponse au heartbeat
                            continue
                        try:
                            msg = json.loads(message)
                        except json.JSONDecodeError:
                            continue
                        if not isinstance(msg, dict):
                            continue
                        if raw_file:
                            raw_file.write(json.dumps({"t": time.time(), "msg": msg}, ensure_ascii=False) + "\n")
                            raw_file.flush()
                        state.update(msg)
                        render(state, ip, connected_since)
                finally:
                    hb.cancel()
        except (OSError, asyncio.TimeoutError, websockets.exceptions.WebSocketException) as e:
            print(f"Connexion perdue ou impossible ({e}). Nouvel essai dans 5 s...")
            await asyncio.sleep(5)


def main():
    parser = argparse.ArgumentParser(description="Moniteur K1 / K1 Max via websocket (port 9999)")
    parser.add_argument("ip", nargs="?", default="192.168.0.163",
                        help="Adresse IP de l'imprimante (défaut : 192.168.0.163)")
    parser.add_argument("--raw", action="store_true", help=f"Enregistre tous les messages dans {RAW_LOG}")
    args = parser.parse_args()
    try:
        asyncio.run(run(args.ip, args.raw))
    except KeyboardInterrupt:
        print("\nBye!")


if __name__ == "__main__":
    main()
