# bMaxSend

Piattaforma multi-tenant per creare newsletter / DEM e gestirne l'invio schedulato a liste di indirizzi email.

Stack: **PHP 8.1+ · MariaDB 10.6+ / MySQL 8 · nginx + PHP-FPM**, nessun framework; unica dipendenza Composer: [PHPMailer](https://github.com/PHPMailer/PHPMailer).

## Tre livelli di accesso

| Livello | Cosa può fare |
|---|---|
| **Admin** | Tutto: crea admin, rivenditori e clienti; crea i server SMTP e li assegna; gestisce tutti i domini; può forzare la verifica di un dominio; può "accedere come" qualsiasi utente. |
| **Rivenditore** | Crea e gestisce i propri clienti (con limite opzionale `max_clients`); assegna ai propri clienti i server SMTP ricevuti dall'admin; gestisce i domini propri e dei propri clienti; può "accedere come" i propri clienti. |
| **Cliente** | Crea liste di indirizzi (anche da CSV), campagne DEM con editor HTML e anteprima, invii di test, invii schedulati; vede i report (aperture, clic, disiscrizioni, errori); gestisce la propria lista di esclusione e i propri domini alias. |

Per i clienti si possono impostare i limiti di **iscritti massimi** ed **email al mese** (vuoto = illimitato).

L'"accesso come" (impersonificazione) è registrato nell'`audit_log` con l'utente reale che ha eseguito l'azione.

## Server SMTP

- Creati solo dall'admin (host, porta, STARTTLS/SSL/nessuna, autenticazione, password cifrata con libsodium, HELO, Return-Path).
- **Limiti di velocità** per minuto e per ora, condivisi da tutte le campagne che usano quel server.
- **Domini mittente consentiti**: se compilato, una campagna non parte se il `From` non appartiene a quei domini.
- Assegnazione a cascata: admin → rivenditori → clienti. L'admin può anche assegnare direttamente a qualsiasi cliente. Togliendo un server a un rivenditore viene tolto anche a tutti i suoi clienti; spostando un cliente sotto un altro rivenditore restano solo i server che il nuovo rivenditore possiede.
- Pulsante "Prova connessione" (connessione + autenticazione).

## Domini alias

Servono per la **versione web** delle email, i **link tracciati** e la **pagina di disiscrizione**, così il destinatario vede il dominio del mittente (es. `news.cliente.it`) invece di quello della piattaforma.

1. Si aggiunge l'hostname (admin e rivenditori possono scegliere il proprietario e renderlo **condiviso** con gli utenti sottostanti: un dominio condiviso dall'admin è usabile da tutti i clienti, uno condiviso da un rivenditore dai suoi clienti).
2. Si creano i record DNS mostrati:
   - `CNAME news.cliente.it → <tracking.cname_target>` (oppure A/AAAA con gli stessi IP)
   - `TXT _bmaxsend.news.cliente.it = bmaxsend-verify=<token>` (disattivabile con `tracking.require_txt`)
3. "Verifica DNS" (o `bin/check-domains.php` da cron). L'admin può forzare la verifica.
4. Nella campagna si sceglie il dominio da usare.

Sugli hostname alias rispondono **solo** gli endpoint pubblici (`/v`, `/o`, `/c`, `/u`); il pannello risponde solo sull'host di `app_url`, gli host sconosciuti ricevono 404. Per l'HTTPS sugli alias serve un certificato per ciascun hostname (vedi `docs/nginx.conf.example`).

## Campagne e invio

- Editor HTML con anteprima live (iframe sandbox, script disabilitati), versione testo generata automaticamente se lasciata vuota, preheader, modello di partenza.
- Segnaposto: `{{first_name}}`, `{{last_name}}`, `{{full_name}}`, `{{email}}`, qualsiasi colonna importata da CSV (`{{citta}}`), valore di riserva `{{first_name|cliente}}`, `{{unsubscribe_url}}`, `{{webversion_url}}`, `{{date}}`, `{{year}}`.
- Se l'HTML non contiene `{{unsubscribe_url}}` viene aggiunto automaticamente un piè di pagina con il link di disiscrizione.
- Header `List-Unsubscribe` + `List-Unsubscribe-Post: List-Unsubscribe=One-Click` (RFC 8058, richiesto da Gmail/Yahoo per gli invii massivi), `List-Id`, `Feedback-ID`.
- Tracciamento aperture (pixel) e clic (redirect) attivabili per campagna.
- Stati: bozza → programmata → in invio ⇄ in pausa → inviata / annullata. Le campagne si possono duplicare.
- Alla partenza viene creata una "fotografia" dei destinatari: deduplicati per email, esclusi disiscritti, rimbalzati e indirizzi nella lista di esclusione del cliente.
- Errori temporanei: nuovo tentativo fino a `worker.max_attempts`. Errori permanenti 5xx sul destinatario: iscritto marcato come *rimbalzato* e aggiunto alla lista di esclusione.
- Se il server SMTP viene disattivato/revocato, il cliente è sospeso o raggiunge il limite mensile, la campagna va **in pausa** con il motivo indicato; si riprende dal report.

## Installazione

```bash
git clone https://github.com/MartinMcFlyRN/bMaxSend.git /var/www/bmaxsend
cd /var/www/bmaxsend
composer install --no-dev -o

# database
mysql -e "CREATE DATABASE bmaxsend CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
          CREATE USER 'bmaxsend'@'localhost' IDENTIFIED BY 'password-sicura';
          GRANT ALL ON bmaxsend.* TO 'bmaxsend'@'localhost';"

cp config/config.example.php config/config.php   # modificare app_url, db, tracking.cname_target
php bin/install.php                              # genera app_key, crea le tabelle e il primo admin

chown -R www-data:www-data storage
```

Configurare nginx come in `docs/nginx.conf.example` (document root = `public/`).

### Cron

```cron
* * * * *  www-data  php /var/www/bmaxsend/bin/worker.php >> /var/www/bmaxsend/storage/logs/cron.log 2>&1
17 * * * * www-data  php /var/www/bmaxsend/bin/check-domains.php > /dev/null
40 3 * * * www-data  php /var/www/bmaxsend/bin/cleanup.php > /dev/null
```

Il worker gira al massimo `worker.max_runtime` secondi ed è protetto da lock (una sola istanza). `php bin/worker.php -v --once` per un giro manuale con output.

### Sviluppo locale

```bash
php -S 127.0.0.1:8080 -t public bin/dev-router.php
```

## Struttura

```
app/Core          router, DB (PDO), sessione, CSRF, auth e gerarchia, i18n, cifratura
app/Controllers   Auth, Dashboard, Profile, User, Smtp, Domain, List, Campaign, Public (tracking)
app/Services      SmtpService, DomainService, MessageBuilder, CampaignQueue, Sender (worker)
app/Views         template PHP
bin/              install, worker, check-domains, cleanup, keygen, dev-router
database/         schema.sql
lang/             en.php (lingua base, chiavi = testo inglese), it.php
public/           index.php (front controller), assets
```

## Lingue

La lingua base è l'inglese: il testo inglese è la chiave di traduzione (`__('Save')`). Ogni lingua ha un file in `lang/<codice>.php`; è inclusa la traduzione italiana. Lingua predefinita in `config.php` (`default_language`), modificabile per utente.

## Sicurezza

- Password con `password_hash`, limite tentativi di login per IP, sessione con cookie HttpOnly/SameSite e timeout di inattività.
- Token CSRF su tutti i form; query sempre preparate; output sempre con escaping.
- Password SMTP cifrate (libsodium secretbox, chiave `app_key`: **non cambiarla** dopo aver salvato dei server).
- Token destinatario casuali a 128 bit per web version, tracciamento e disiscrizione.

## Da fare / idee

- Gestione bounce asincroni (casella IMAP o VERP) e feedback loop (complaint).
- Editor drag & drop / libreria di modelli, allegati e immagini caricate.
- Form di iscrizione pubblici con double opt-in.
- API REST per integrazioni.
- DKIM/SPF check sui domini mittente.
- Segmentazione delle liste per campi personalizzati.
