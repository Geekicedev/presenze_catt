#!/usr/bin/env python3
"""
Cron script per invio automatico Web Push per lezioni universitarie.
Eseguito ogni minuto via crontab: * * * * * /var/www/html/push_venv/bin/python3 /var/www/html/cron_push.py
"""

import os
import sys
import json
import glob
import re
import hashlib
import hmac
import unicodedata
from datetime import datetime, timezone
from zoneinfo import ZoneInfo
from pywebpush import webpush, WebPushException

HTML_DIR = "/var/www/html"
DATA_DIR = os.path.join(HTML_DIR, "data")
USERS_DIR = os.path.join(DATA_DIR, "users")
VAPID_FILE = os.path.join(DATA_DIR, "vapid.json")
VAPID_PEM_FILE = os.path.join(DATA_DIR, "private_key.pem")
LOG_SENT_FILE = os.path.join(DATA_DIR, "sent_notifications.json")
PUSH_RECEIPT_SECRET_FILE = os.path.join(DATA_DIR, "push_receipt_secret")
ICS_FILE = os.path.join(HTML_DIR, "lezioni.ics")
CATALOG_FILE = os.path.join(HTML_DIR, "lessons_catalog.json")

def load_vapid():
    if not os.path.exists(VAPID_FILE):
        return None
    try:
        with open(VAPID_FILE, "r", encoding="utf-8") as f:
            v = json.load(f)
        if not os.path.exists(VAPID_PEM_FILE) or os.path.getsize(VAPID_PEM_FILE) == 0:
            with open(VAPID_PEM_FILE, "w", encoding="utf-8") as pf:
                pf.write(v["private_key"])
            os.chmod(VAPID_PEM_FILE, 0o600)
        return v
    except Exception as e:
        print(f"Error loading vapid: {e}")
        return None

def load_sent_log():
    if not os.path.exists(LOG_SENT_FILE):
        return {}
    try:
        with open(LOG_SENT_FILE, "r", encoding="utf-8") as f:
            return json.load(f)
    except Exception:
        return {}

def save_sent_log(log_data):
    try:
        with open(LOG_SENT_FILE, "w", encoding="utf-8") as f:
            json.dump(log_data, f, indent=2)
    except Exception as e:
        print(f"Error saving sent log: {e}")

def parse_ics_datetime(val):
    clean = re.sub(r'[^0-9T]', '', val)
    if len(clean) >= 15:
        try:
            dt = datetime.strptime(clean[:15], "%Y%m%dT%H%M%S")
            return dt.replace(tzinfo=timezone.utc)
        except Exception:
            return None
    return None

def parse_ics(filepath):
    if not os.path.exists(filepath):
        return []

    events = []
    current = {}
    in_event = False

    try:
        with open(filepath, "r", encoding="utf-8", errors="ignore") as f:
            for line in f:
                line = line.strip()
                if line == "BEGIN:VEVENT":
                    in_event = True
                    current = {}
                elif line == "END:VEVENT":
                    in_event = False
                    if "summary" in current and "dtstart" in current:
                        events.append(current)
                elif in_event:
                    if line.startswith("SUMMARY:"):
                        current["summary"] = line[8:].strip()
                    elif line.startswith("DTSTART:"):
                        current["dtstart"] = parse_ics_datetime(line[8:].strip())
                    elif line.startswith("DTEND:"):
                        current["dtend"] = parse_ics_datetime(line[6:].strip())
                    elif line.startswith("LOCATION:"):
                        current["location"] = line[9:].strip()
                    elif line.startswith("UID:"):
                        current["uid"] = line[4:].strip()
    except Exception as e:
        print(f"Error parsing ics: {e}")

    return events

def load_lesson_catalog():
    try:
        with open(CATALOG_FILE, "r", encoding="utf-8") as f:
            catalog = json.load(f)
        return catalog if isinstance(catalog, dict) and isinstance(catalog.get("events"), list) else {}
    except Exception:
        return {}

def catalog_events_for_push(catalog):
    events = []
    for item in catalog.get("events", []):
        if not isinstance(item, dict) or item.get("source_present") is False:
            continue
        try:
            start = datetime.fromisoformat(item["start"]).astimezone(timezone.utc)
            end = datetime.fromisoformat(item["end"]).astimezone(timezone.utc)
        except (KeyError, TypeError, ValueError):
            continue
        events.append({
            "uid": item.get("id", ""),
            "summary": item.get("title") or item.get("course") or "Lezione",
            "course": item.get("course", ""),
            "dtstart": start,
            "dtend": end,
            "location": item.get("location", ""),
            "suspended": bool(item.get("suspended", False)),
        })
    return events

def normalize_course(value):
    value = unicodedata.normalize("NFD", str(value or ""))
    value = "".join(ch for ch in value if unicodedata.category(ch) != "Mn")
    return re.sub(r"\s+", " ", value.strip()).casefold()

def pending_lesson_count(user_data, catalog, now_local):
    """Count today's ended catalog events with no response recorded."""
    today = now_local.date()
    responses = user_data.get("lesson_responses", {})
    if not isinstance(responses, dict):
        responses = {}
    pending_ids = set()
    for event in catalog.get("events", []):
        if not isinstance(event, dict) or not event.get("id"):
            continue
        try:
            start = datetime.fromisoformat(event.get("start", ""))
            end = datetime.fromisoformat(event.get("end", ""))
            if start.tzinfo is None:
                start = start.replace(tzinfo=now_local.tzinfo)
            else:
                start = start.astimezone(now_local.tzinfo)
            if end.tzinfo is None:
                end = end.replace(tzinfo=now_local.tzinfo)
            else:
                end = end.astimezone(now_local.tzinfo)
        except (TypeError, ValueError):
            continue
        if start.date() != today or end.date() != today or end > now_local:
            continue
        response = responses.get(event["id"])
        if response is None or response == "" or response == {}:
            pending_ids.add(event["id"])
    return len(pending_ids)

def add_push_receipt(payload, username, device_id, delivery_key, now_utc):
    try:
        with open(PUSH_RECEIPT_SECRET_FILE, "r", encoding="utf-8") as f:
            secret = f.read().strip()
        sent_at = str(int(now_utc.timestamp()))
        delivery_id = hashlib.sha256(delivery_key.encode()).hexdigest()[:24]
        message = "|".join([username, device_id, delivery_id, sent_at])
        signature = hmac.new(secret.encode(), message.encode(), hashlib.sha256).hexdigest()
        payload["_push_receipt"] = {
            "username": username,
            "device_id": device_id,
            "delivery_id": delivery_id,
            "sent_at": sent_at,
            "signature": signature
        }
    except Exception as e:
        print(f"Could not sign push receipt: {e}")

def send_push(subscription, payload_data, vapid_data):
    try:
        webpush(
            subscription_info=subscription,
            data=json.dumps(payload_data),
            vapid_private_key=VAPID_PEM_FILE,
            vapid_claims={"sub": vapid_data["sub"]},
            timeout=10
        )
        return True
    except WebPushException as ex:
        print(f"WebPushException: {ex}")
        if ex.response and ex.response.status_code in [404, 410]:
            return "expired"
        return False
    except Exception as e:
        print(f"General push error: {e}")
        return False

def main():
    vapid_data = load_vapid()
    if not vapid_data:
        print("VAPID data missing, exiting.")
        sys.exit(0)

    catalog = load_lesson_catalog()
    events = catalog_events_for_push(catalog) if catalog else []

    now_utc = datetime.now(timezone.utc)
    now_local = now_utc.astimezone(ZoneInfo("Europe/Rome"))
    sent_log = load_sent_log()

    cleaned_log = {}
    cutoff_ts = now_utc.timestamp() - (7 * 86400)
    for k, v in sent_log.items():
        if isinstance(v, (int, float)) and v > cutoff_ts:
            cleaned_log[k] = v
    sent_log = cleaned_log

    user_files = glob.glob(os.path.join(USERS_DIR, "*.json"))

    for u_file in user_files:
        try:
            with open(u_file, "r", encoding="utf-8") as f:
                user_data = json.load(f)
        except Exception:
            continue

        username = user_data.get("username", "")
        subscriptions = user_data.get("push_subscriptions", [])
        if not isinstance(subscriptions, list):
            subscriptions = []
        legacy = user_data.get("push_subscription")
        if isinstance(legacy, dict):
            subscriptions.append(legacy)
        unique_subscriptions = {}
        for sub in subscriptions:
            if isinstance(sub, dict) and sub.get("endpoint"):
                unique_subscriptions[sub["endpoint"]] = sub
        subscriptions = list(unique_subscriptions.values())
        if not subscriptions:
            continue
        expired_endpoints = set()

        settings = user_data.get("settings", {})
        default_notify_start = settings.get("notify_attendance_start", True)
        default_notify_pre = settings.get("notify_pre_lecture", True)
        default_pre_minutes = int(settings.get("pre_lecture_minutes", 15))

        reminder_time = str(settings.get("pending_lesson_reminder_time", "18:00"))
        if not re.fullmatch(r"(?:[01]\d|2[0-3]):[0-5]\d", reminder_time):
            reminder_time = "18:00"
        pending_count = pending_lesson_count(user_data, catalog, now_local) if catalog else 0
        if pending_count > 0 and now_local.strftime("%H:%M") >= reminder_time:
            for push_sub in subscriptions:
                device_settings = push_sub.get("notification_settings", {})
                if not isinstance(device_settings, dict) or device_settings.get("notify_evening") is not True:
                    continue
                device_id = hashlib.sha256(push_sub["endpoint"].encode()).hexdigest()[:12]
                receipt_device_id = hashlib.sha256(push_sub["endpoint"].encode()).hexdigest()[:16]
                reminder_key = f"{username}_pending_lessons_{now_local.date().isoformat()}_{device_id}"
                if reminder_key in sent_log:
                    continue
                payload = {
                    "title": f"Hai {pending_count} lezioni da regolarizzare",
                    "body": "Apri il registro e segna ogni lezione come presente, assente o non svolta.",
                    "url": "https://lezioni.rsessa.it/?view=lessons",
                    "tag": f"pending-lessons-{now_local.date().isoformat()}"
                }
                add_push_receipt(payload, username, receipt_device_id, reminder_key, now_utc)
                result = send_push(push_sub, payload, vapid_data)
                if result is True:
                    sent_log[reminder_key] = now_utc.timestamp()
                    print(f"Sent pending-lesson reminder to {username} ({device_id}, {pending_count})")
                elif result == "expired":
                    expired_endpoints.add(push_sub["endpoint"])

        user_courses = user_data.get("courses", [])
        if not user_courses or not events:
            continue

        course_keywords = []
        normalized_courses = [normalize_course(c.get("name")) for c in user_courses if isinstance(c, dict)]
        for c in user_courses:
            name = c.get("name", "")
            words = [w.lower() for w in re.split(r'\s+', name) if len(w) > 3 and not w.lower().startswith("fv")]
            if words:
                course_keywords.append(words)

        for ev in events:
            summary = ev.get("summary", "")
            if ev.get("suspended") or re.search(r'\[SOSPESO\]|ANNULLATO', summary, re.IGNORECASE):
                continue

            dtstart = ev.get("dtstart")
            if not dtstart:
                continue

            event_course = normalize_course(ev.get("course"))
            matched = any(event_course == name or event_course in name or name in event_course for name in normalized_courses) if event_course else False
            if not event_course:
                summary_lower = summary.lower()
                for kw_list in course_keywords:
                    if any(w in summary_lower for w in kw_list):
                        matched = True
                        break
            if not matched:
                continue

            diff_seconds = (dtstart - now_utc).total_seconds()
            diff_minutes = diff_seconds / 60.0

            local_dtstart = dtstart.astimezone()
            time_str = local_dtstart.strftime("%H:%M")
            aula_str = f" in aula {ev.get('location')}" if ev.get("location") else ""

            uid = ev.get("uid") or f"{dtstart.isoformat()}_{summary}"

            for push_sub in subscriptions:
                device_settings = push_sub.get("notification_settings", {})
                if not isinstance(device_settings, dict):
                    device_settings = {}
                notify_start = device_settings.get("notify_attendance_start", default_notify_start)
                notify_pre = device_settings.get("notify_pre_lecture", default_notify_pre)
                try:
                    pre_minutes = int(device_settings.get("pre_lecture_minutes", default_pre_minutes))
                except (TypeError, ValueError):
                    pre_minutes = default_pre_minutes
                device_id = hashlib.sha256(push_sub["endpoint"].encode()).hexdigest()[:12]
                receipt_device_id = hashlib.sha256(push_sub["endpoint"].encode()).hexdigest()[:16]

                if notify_start and (-1.0 <= -diff_minutes <= 3.0):
                    log_key = f"{username}_start_{uid}_{device_id}"
                    if log_key not in sent_log:
                        payload = {
                            "title": f"Inizia la lezione di {summary.split('–')[0].strip()}! 🔔",
                            "body": f"La lezione è iniziata alle {time_str}{aula_str}. Ricordati di registrare la presenza su UniPresenze!",
                            "url": "https://lezioni.rsessa.it/"
                        }
                        add_push_receipt(payload, username, receipt_device_id, log_key, now_utc)
                        res = send_push(push_sub, payload, vapid_data)
                        if res is True:
                            sent_log[log_key] = now_utc.timestamp()
                        elif res == "expired":
                            expired_endpoints.add(push_sub["endpoint"])

                if notify_pre:
                    target_diff = float(pre_minutes)
                    if (target_diff - 1.0) <= diff_minutes <= (target_diff + 1.5):
                        log_key = f"{username}_pre_{pre_minutes}_{uid}_{device_id}"
                        if log_key not in sent_log:
                            payload = {
                                "title": f"Promemoria: {summary.split('–')[0].strip()} tra {pre_minutes} min ⏳",
                                "body": f"Inizio alle {time_str}{aula_str}. Preparati per la lezione!",
                                "url": "https://lezioni.rsessa.it/"
                            }
                            add_push_receipt(payload, username, receipt_device_id, log_key, now_utc)
                            res = send_push(push_sub, payload, vapid_data)
                            if res is True:
                                sent_log[log_key] = now_utc.timestamp()
                                print(f"Sent pre-notification to {username} for {summary}")
                            elif res == "expired":
                                expired_endpoints.add(push_sub["endpoint"])

        if expired_endpoints:
            remaining = [sub for sub in subscriptions if sub.get("endpoint") not in expired_endpoints]
            user_data["push_subscriptions"] = remaining
            user_data["push_subscription"] = remaining[-1] if remaining else None
            with open(u_file, "w", encoding="utf-8") as fw:
                json.dump(user_data, fw, indent=2)

    save_sent_log(sent_log)

if __name__ == "__main__":
    main()
