#!/usr/bin/env python3
"""
Script di utilità per inviare una notifica push singola (usato anche da api.php per il test).
Uso: python3 send_single_push.py '<subscription_json>' '<payload_json>'
"""

import sys
import json
import os
from pywebpush import webpush, WebPushException

HTML_DIR = "/var/www/html"
DATA_DIR = os.path.join(HTML_DIR, "data")
VAPID_FILE = os.path.join(DATA_DIR, "vapid.json")
VAPID_PEM_FILE = os.path.join(DATA_DIR, "private_key.pem")

def main():
    if len(sys.argv) < 3:
        print(json.dumps({"status": "error", "message": "Argomenti insufficienti"}))
        sys.exit(1)

    try:
        subscription = json.loads(sys.argv[1])
        payload = json.loads(sys.argv[2])
    except Exception as e:
        print(json.dumps({"status": "error", "message": f"Errore parsing JSON: {e}"}))
        sys.exit(1)

    if not os.path.exists(VAPID_FILE):
        print(json.dumps({"status": "error", "message": "File vapid.json non trovato"}))
        sys.exit(1)

    try:
        with open(VAPID_FILE, "r", encoding="utf-8") as f:
            vapid = json.load(f)
    except Exception as e:
        print(json.dumps({"status": "error", "message": f"Errore lettura vapid: {e}"}))
        sys.exit(1)

    # Assicurati che private_key.pem esista su disco (pywebpush richiede path del file PEM)
    if not os.path.exists(VAPID_PEM_FILE) or os.path.getsize(VAPID_PEM_FILE) == 0:
        try:
            with open(VAPID_PEM_FILE, "w", encoding="utf-8") as pf:
                pf.write(vapid["private_key"])
            os.chmod(VAPID_PEM_FILE, 0o600)
        except Exception as e:
            print(json.dumps({"status": "error", "message": f"Errore scrittura private_key.pem: {e}"}))
            sys.exit(1)

    try:
        res = webpush(
            subscription_info=subscription,
            data=json.dumps(payload),
            vapid_private_key=VAPID_PEM_FILE,
            vapid_claims={"sub": vapid["sub"]},
            timeout=10
        )
        status_code = res.status_code if res else 200
        print(json.dumps({"status": "success", "message": "Notifica push inviata con successo!", "code": status_code}))
        sys.exit(0)
    except WebPushException as ex:
        code = ex.response.status_code if ex.response else 500
        print(json.dumps({"status": "error", "message": f"WebPushException: {ex}", "http_code": code}))
        sys.exit(1)
    except Exception as e:
        print(json.dumps({"status": "error", "message": f"Errore invio push: {e}"}))
        sys.exit(1)

if __name__ == "__main__":
    main()
