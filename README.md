# UniPresenze

Web app PHP/PWA per il registro delle presenze, con API per la sincronizzazione dei profili e script Python per le notifiche push.

## Contenuto

- `index.html`, `manifest.webmanifest`, `sw-v8.js` e icone: interfaccia e PWA.
- `api.php`: autenticazione, sincronizzazione e gestione notifiche.
- `cron_push.py` e `send_single_push.py`: invio delle notifiche.
- `requirements.txt`: dipendenza Python per Web Push.
- `lessons_catalog.example.json` e `lezioni.ics.example`: esempi vuoti per i file calendario generati in produzione.

## Requisiti

- PHP 8 o successivo, con supporto JSON.
- Python 3.9 o successivo.
- Un server web configurato per eseguire PHP. L'app attuale usa il percorso `/var/www/html` negli script Python; per un'installazione in un percorso diverso occorre configurare quel percorso negli script.

## Branch e ambienti

- `main` è la versione destinata alla produzione.
- `develop` raccoglie le modifiche in prova e va pubblicato su un ambiente di staging con document root e dati separati dalla produzione.
- Le nuove modifiche entrano in `develop` tramite pull request. Dopo la verifica sullo staging, una pull request da `develop` a `main` prepara il rilascio.
- Solo `main` si distribuisce in produzione. Il deploy resta manuale e non è configurato alcun workflow automatico verso il server live.
- Produzione e staging devono avere directory `data/`, chiavi VAPID e calendari indipendenti. Non copiare dati degli utenti o segreti tra ambienti.

## Installazione sul server

1. Clona questo repository privato nella document root prevista dal server.
2. Installa le dipendenze con `python3 -m venv push_venv` e `push_venv/bin/pip install -r requirements.txt`.
3. Crea `data/users/` e configura `data/vapid.json` e `data/push_receipt_secret` sul server. Non committare questi file o le chiavi private.
4. Crea `lessons_catalog.json` e `lezioni.ics` usando la procedura di aggiornamento calendario già adottata dal server; non aggiungere al repository calendari personali o dati degli utenti.
5. Configura il server web affinché `data/` non sia raggiungibile via HTTP. `data/.htaccess` copre server Apache; con Lighttpd o un altro server, aggiungi la regola equivalente nella configurazione del server.
6. Configura il job cron di `cron_push.py` solo dopo aver installato e verificato la configurazione push.

## Aggiornamento

Dalla directory di installazione, esegui `git pull` dopo aver salvato eventuali modifiche locali. I dati runtime in `data/` e i file di calendario sono esclusi da Git.

## Dati da non committare

Non caricare profili in `data/users/`, chiavi VAPID o PEM, segreti per le ricevute push, log, backup, ambienti virtuali, file `.ics` personali o il catalogo generato dal server.
