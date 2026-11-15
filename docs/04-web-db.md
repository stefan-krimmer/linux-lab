# 04 – Web- und Datenbank-Stack: Apache, PHP, MariaDB, Backup, TLS

Auf dem abgesicherten Grundsystem (siehe `02-basis-administration.md`) entsteht der Stack für das spätere Mini-LIS: ein Webserver mit eigener Site, PHP als eigener Dienst, eine Datenbank auf eigenem Volume, ein geprüftes Backup und verschlüsselter Zugriff per HTTPS.

```
Windows-Browser ──HTTPS, Port 443──► nftables ──► Apache (vHost lis.lab.test)
                 (HTTP, Port 80 → Weiterleitung)        │ proxy_fcgi, Unix-Socket
                                                        ▼
                                                    PHP-FPM 8.5 ──PDO, Unix-Socket──► MariaDB 11.8
                                                                                       (LV vg0/mariadb)
```

---

## Inhalt

1. [Ausgangslage](#1-ausgangslage)
2. [Apache](#2-apache)
3. [Virtual Host lis.lab.test](#3-virtual-host-lislabtest)
4. [PHP 8.5 aus der Fremdquelle Sury](#4-php-85-aus-der-fremdquelle-sury)
5. [MariaDB](#5-mariadb)
6. [Eigenes Logical Volume für die Datenbank](#6-eigenes-logical-volume-für-die-datenbank)
7. [Test-Datenbank, Anwendungsbenutzer, Testseite](#7-test-datenbank-anwendungsbenutzer-testseite)
8. [Backup und Restore](#8-backup-und-restore)
9. [TLS: HTTPS für lis.lab.test](#9-tls-https-für-lislabtest)
10. [Dateien im Repo](#10-dateien-im-repo)
11. [Stolpersteine](#11-stolpersteine)
12. [Erkenntnisse](#12-erkenntnisse)
13. [Snapshots](#13-snapshots)
14. [Nächste Schritte](#14-nächste-schritte)

---

## 1. Ausgangslage

- Debian 13 (trixie), Phase 1 und 2 fertig: SSH nur mit Schlüssel, nftables (22 + Ping), Zeit, Journal, git-Repo
- Arbeitsweise: als `stefan` anmelden, Admin-Befehle nach `sudo -i`, danach `exit`
- Grundmuster für jede Änderung: **ändern → prüfen → laden → testen**

| Prüfbefehl | prüft |
|---|---|
| `apache2ctl configtest` | Apache-Konfiguration |
| `nft -c -f /etc/nftables.conf` | Firewall-Datei |
| `php -l datei.php` | PHP-Syntax |
| `findmnt --verify` | fstab |
| `mariadb-dump --print-defaults` | wirksame Dump-Optionen |

---

## 2. Apache

**Apache HTTP Server** (Paket `apache2`) ist der Webserver: Er nimmt Anfragen auf Port 80/443 an und liefert Dateien aus oder reicht sie an PHP weiter. Debian nutzt das Multi-Processing-Modul **mpm_event** (wenige Prozesse, viele Verbindungen).

```bash
sudo -i
apt install apache2 curl
systemctl status apache2      # enabled, active (running)
```

Port 80 in der Firewall (`/etc/nftables.conf`, Kette `input`):

```
		# HTTP erlauben
		tcp dport 80 accept
```

```bash
nft -c -f /etc/nftables.conf && systemctl reload nftables
nft list ruleset | grep dport        # Datei ≠ aktiv – Kernel prüfen
```

**Debians Ordnerprinzip** (gilt auch für Module und Konfigurationsschnipsel):

| Ordner | Inhalt | Schalter |
|---|---|---|
| `sites-available/` | alle Sites | `a2ensite` / `a2dissite` |
| `sites-enabled/` | Links auf aktive Sites | |
| `mods-available/` → `mods-enabled/` | Module | `a2enmod` / `a2dismod`, Kontrolle `apache2ctl -M` |
| `conf-available/` → `conf-enabled/` | Konfigurationsschnipsel | `a2enconf` / `a2disconf` |

**Globaler ServerName** gegen die Warnung `AH00558: Could not reliably determine the server's fully qualified domain name`:

```bash
echo "ServerName debian-lab" > /etc/apache2/conf-available/servername.conf
a2enconf servername
apache2ctl configtest && systemctl reload apache2
```

`apache2ctl` liegt in `/usr/sbin` und ist für `stefan` nur mit `sudo` erreichbar.

---

## 3. Virtual Host lis.lab.test

Ein **Virtual Host** ist eine eigene Site auf demselben Server. Apache wählt sie anhand des Namens in der Anfrage (Header `Host:`). Passt kein `ServerName`, nimmt Apache die **erste** Site für den Port.

Verzeichnisse:

```
/var/www/lis/            stefan:stefan,   755
├── config/              stefan:www-data, 750   (nicht per Browser erreichbar)
│   └── db.php           stefan:www-data, 640   Zugangsdaten, NICHT im Repo
└── public/              stefan:stefan,   755   DocumentRoot
    ├── index.html
    └── db-test.php
```

Apache/PHP-FPM laufen als `www-data` und dürfen nur **lesen**. Die Website gehört `stefan` → Bearbeiten ohne root.

Aktivieren:

```bash
a2ensite lis.lab.test
a2dissite 000-default
apache2ctl configtest && systemctl reload apache2
curl -H "Host: lis.lab.test" http://localhost      # Test ohne DNS
```

Auf Windows in `C:\Windows\System32\drivers\etc\hosts` (als Administrator):

```
192.168.38.129  lis.lab.test
```

Die endgültige Datei mit HTTPS steht in [Abschnitt 9](#9-tls-https-für-lislabtest).

---

## 4. PHP 8.5 aus der Fremdquelle Sury

Debian 13 liefert PHP 8.4. PHP 8.5 gibt es nur über **packages.sury.org** (Ondřej Surý, Debian-PHP-Maintainer). Hier bewusst als Übung: **Wie bindet man eine Fremdquelle sicher ein?**

### 4.1 Erst prüfen, dann vertrauen

Schlüssel über das Keyring-Paket `debsuryorg-archive-keyring` (der Schlüssel läuft regelmässig ab, aktuell bis 2028-02-04; die Verlängerung kommt dann per apt). Vor der Installation untersucht:

```bash
dpkg-deb -I paket.deb      # Metadaten
dpkg-deb -c paket.deb      # Dateiliste
dpkg-deb -e paket.deb dir  # Installationsskripte lesen
sha256sum paket.deb
gpg --show-keys /usr/share/keyrings/debsuryorg-archive-keyring.gpg
```

Fingerprint `15058500A0235D97F5D10063B188E2B695BD4743`, unabhängig bestätigt.

### 4.2 Quelle und Pinning

`/etc/apt/sources.list.d/sury-php.sources`:

```
Types: deb
URIs: https://packages.sury.org/php/
Suites: trixie
Components: main
Signed-By: /usr/share/keyrings/debsuryorg-archive-keyring.gpg
```

`/etc/apt/preferences.d/sury-php.pref`:

```
# PHP-Pakete dürfen von Sury kommen (Standardpriorität)
Package: php*
Pin: origin packages.sury.org
Pin-Priority: 500

# Alles andere von Sury: nur, wenn Debian es nicht hat
Package: *
Pin: origin packages.sury.org
Pin-Priority: 100
```

Ziel: Sury ersetzt **nie** still ein Debian-Paket.

### 4.3 apt policy lesen

```bash
apt policy php8.5-fpm
```

```
php8.5-fpm:
  Installed: 8.5.12-3+0~deb.sury.org.1+debian13
  Candidate: 8.5.12-3+0~deb.sury.org.1+debian13
  Version table:
 *** 8.5.12-3+0~deb.sury.org.1+debian13 500
        100 https://packages.sury.org/php trixie/main amd64 Packages
        100 /var/lib/dpkg/status
```

| Stelle | Bedeutung |
|---|---|
| Zahl **hinter** der Version | **wirksame** Priorität (hier 500 durch den `php*`-Pin) |
| Zahl **vor** der Quelle | Grundpriorität der Quelle (100 durch die zweite Regel) |
| `***` | installiert |
| `100 /var/lib/dpkg/status` | „installiert“, keine Download-Quelle |

Entscheidung: höchste wirksame Priorität gewinnt, bei Gleichstand die höhere Version.

### 4.4 PHP-FPM statt mod_php

```bash
apt install php8.5-fpm          # NICHT php / php8.5 – das zieht mod_php
a2enmod proxy_fcgi setenvif
a2enconf php8.5-fpm
apache2ctl configtest && systemctl restart apache2   # neue Module → restart
```

| Entscheidung | Grund |
|---|---|
| PHP-FPM | PHP läuft als eigener Dienst, Apache bleibt bei `mpm_event` |
| Anbindung | Unix-Socket `/run/php/php8.5-fpm.sock` (`srw-rw---- www-data www-data`) |

Zwei `php.ini`: `/etc/php/8.5/cli/` (Konsole) und `/etc/php/8.5/fpm/` (Web). Erweiterungen: `mods-available/*.ini` → Links in `cli/conf.d/` bzw. `fpm/conf.d/`, Schalter `phpenmod` / `phpdismod`, Kontrolle `php -m`. `phpinfo()` nur kurz zum Test, danach löschen, nie ins Repo.

**Dienst ≠ Prozess:** Dienst `php8.5-fpm` (für `systemctl`), Prozesse `php-fpm: master process` (root) und `php-fpm: pool www` (`www-data`).

---

## 5. MariaDB

| Entscheidung | Grund |
|---|---|
| Quelle Debian (11.8.6, LTS) statt mariadb.org | Sicherheitsupdates über `trixie-security`, Fremdquelle nur mit konkretem Grund |
| `--no-install-recommends` | ≈ 21 statt ≈ 48 Pakete; `pv` gezielt dazu |
| `bind-address 127.0.0.1` (Debian-Standard) | nicht von aussen erreichbar, keine Firewall-Regel für 3306 |
| `mariadb-secure-installation` **nicht** ausgeführt | Debian ist schon sicher eingerichtet (Skript: „useless at best, misleading at worst“) |

```bash
apt policy mariadb-server                                     # nur Debian, 500
apt -s install --no-install-recommends mariadb-server pv | grep '^Inst ' | grep -v Debian   # leer
apt install --no-install-recommends mariadb-server pv
```

Von Hand geprüft:

```bash
mariadb -e "SELECT user, host FROM mysql.user;"     # mariadb.sys, mysql, root – alle localhost
mariadb -e "SHOW DATABASES;"                        # keine test-DB
mariadb -e "SHOW GRANTS FOR 'root'@'localhost';"    # USING 'invalid' OR unix_socket
ss -tlnp | grep mariadb                             # 127.0.0.1:3306
```

`root` meldet sich nur über **unix_socket** an: Wer Linux-root ist, ist MariaDB-root, ohne Passwort. Ein Passwort-Login für `root` gibt es nicht.

---

## 6. Eigenes Logical Volume für die Datenbank

**Warum:** `/var` wächst (Logs, Datenbanken). Läuft `/` voll, steht das ganze System. Die Datenbank bekommt deshalb ein eigenes Volume, ein volles Datenbank-Volume legt nicht das Betriebssystem lahm.

Der Pfad `/var/lib/mysql` bleibt gleich → keine Änderung an der MariaDB-Konfiguration.

> ⚠️ **Reihenfolge-kritisch**

```bash
lvcreate -L 10G -n mariadb vg0
mkfs.ext4 -L mariadb /dev/vg0/mariadb
mkdir /mnt/mariadb-neu && mount /dev/vg0/mariadb /mnt/mariadb-neu
rmdir /mnt/mariadb-neu/lost+found                 # sonst Datenbank "#mysql50#lost+found"
systemctl stop mariadb && pgrep -a mariadbd       # muss leer sein
rsync -aHAX /var/lib/mysql/ /mnt/mariadb-neu/     # Schrägstrich hinter der Quelle!
diff -r /var/lib/mysql /mnt/mariadb-neu           # leer = identisch
umount /mnt/mariadb-neu
mv /var/lib/mysql /var/lib/mysql.alt              # Rückfallebene
mkdir /var/lib/mysql                              # Mountpoint, root:root 755 (gewollt)
cp /etc/fstab /etc/fstab.bak
```

`/etc/fstab`:

```
/dev/mapper/vg0-mariadb  /var/lib/mysql  ext4  defaults,noatime  0  2
```

```bash
systemctl daemon-reload            # systemd erzeugt Mount-Units aus der fstab
findmnt --verify
mount -a && findmnt /var/lib/mysql
systemctl start mariadb
mariadb -e "SELECT @@datadir;"
reboot
journalctl -b -u var-lib-mysql.mount -u mariadb    # „Mounted“ vor „Starting“
# erst danach: rm -rI /var/lib/mysql.alt, rm /etc/fstab.bak, rmdir /mnt/mariadb-neu
```

| Detail | Grund |
|---|---|
| `rsync -aHAX` | Rechte, Besitzer, Hardlinks, ACLs, erweiterte Attribute |
| `noatime` | keine Schreibzugriffe nur fürs Lesen |
| Mountpoint gehört root | Schlägt das Einhängen fehl, kann MariaDB nicht in den leeren Ordner schreiben → **lauter Fehler statt leerer Datenbank** |
| `.alt` behalten | erst löschen, wenn der Neustart-Test bestanden ist |

---

## 7. Test-Datenbank, Anwendungsbenutzer, Testseite

### 7.1 Datenbank und Benutzer

Interaktiv als root (`sudo -i` → `mariadb`), **nicht** per `mariadb -e`, damit das Passwort nicht in `~/.bash_history` landet. Der MariaDB-Client speichert Zeilen mit `IDENTIFIED`/`PASSWORD` nicht in `~/.mariadb_history`.

```sql
CREATE DATABASE lab_test;
CREATE USER 'lis_web'@'localhost' IDENTIFIED BY '…';     -- Passwort aus KeePassXC
GRANT SELECT, INSERT, UPDATE, DELETE ON lab_test.* TO 'lis_web'@'localhost';
SHOW GRANTS FOR 'lis_web'@'localhost';

USE lab_test;
CREATE TABLE notiz (
  id INT AUTO_INCREMENT PRIMARY KEY,
  text VARCHAR(200) NOT NULL,
  erstellt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO notiz (text) VALUES ('Erste Testzeile');
```

| Regel | Grund |
|---|---|
| ein Benutzer pro Anwendung | Rechte gezielt vergeben und entziehen |
| nur DML (`SELECT/INSERT/UPDATE/DELETE`) | die Website kann keine Tabellen anlegen oder löschen; DDL macht root |
| Passwort in KeePassXC auf dem Laptop | Snapshots setzen die VM zurück, das Passwort darf nicht nur dort liegen |

**Minimale Rechte testen, nicht nur vergeben** (als `stefan`: `mariadb -u lis_web -p lab_test`): `INSERT` klappt, `CREATE TABLE` / `DROP TABLE` → `ERROR 1142` = Erfolg.

MariaDB-Prompts: `MariaDB [db]>` bereit · `->` Befehl ohne `;` · `'>` offener Text → `'` + Enter, dann `\c`.

### 7.2 Zugangsdaten und PHP-Treiber

```bash
apt install php8.5-mysql       # mysqlnd, mysqli, pdo_mysql (aus Sury, Pinning greift)
```

`/var/www/lis/config/db.php` (ausserhalb des DocumentRoot, `stefan:www-data 640`):

```php
<?php
return [
    'dsn'      => 'mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=lab_test;charset=utf8mb4',
    'user'     => 'lis_web',
    'password' => '…',
];
```

Kein `?>` am Ende (keine versehentliche Ausgabe). Im Repo nur die Vorlage `www/lis/config/db.php.example` (neu geschrieben, **nicht** per `cp` aus der echten Datei) und die `.gitignore`-Regel `db.php`. Prüfen: `git check-ignore -v www/lis/config/db.php`.

Rechte gegenprüfen: `sudo -u www-data head -3 config/db.php` (ok), `sudo -u nobody head -3 config/db.php` (Permission denied).

### 7.3 Testseite `db-test.php`

- `declare(strict_types=1);`
- `$config = require __DIR__ . '/../config/db.php';`
- `new PDO(…, [ERRMODE_EXCEPTION, FETCH_ASSOC])`
- `try/catch PDOException` → `error_log(…)`, `http_response_code(500)`, allgemeine Meldung: **Fehlerdetails ins Log, nicht in den Browser**
- Ausgabe nur über `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')` (Schutz vor XSS)

| Entscheidung | Grund |
|---|---|
| PDO | einheitliche Schnittstelle, später auch für Oracle |
| Unix-Socket | kein TCP-Port nötig, schneller, nur lokal |

---

## 8. Backup und Restore

### 8.1 Das Werkzeug

`mariadb-dump` schreibt eine Datenbank als **SQL-Text** heraus (`CREATE TABLE`, `INSERT`). Zum Wiederherstellen schickt man die Datei an den Client `mariadb`.

| Eigenschaft | Bedeutung |
|---|---|
| logisches Backup | lesbar, durchsuchbar, versionsunabhängig |
| im laufenden Betrieb | MariaDB muss nicht gestoppt werden |
| Grenze | bei sehr grossen DBs langsamer Restore → physische Backups (`mariabackup`) |

`cp -r /var/lib/mysql` bei laufender Datenbank ist **kein** Backup (Dateien mitten im Schreiben).

### 8.2 Ablage und Optionen

```bash
sudo -i
mkdir -p /var/backups/mariadb
chmod 700 /var/backups/mariadb          # Dumps enthalten alle Daten im Klartext
```

`/root/.my.cnf` (`chmod 600`):

```ini
# Standard-Optionen für mariadb-dump (Backup)
[mariadb-dump]
single-transaction
routines
triggers
events
```

| Option | Bedeutung |
|---|---|
| `single-transaction` | ein stimmiger Stand in einer Transaktion, ohne Sperren (InnoDB) |
| `routines`, `triggers`, `events` | sonst fehlen Prozeduren, Trigger, Events **stillschweigend** |

MariaDB liest mehrere Optionsdateien und führt sie zusammen. Kontrolle:

```bash
mariadb-dump --print-defaults
# --socket=/run/mysqld/mysqld.sock --quick --quote-names --max_allowed_packet=16M
# --single-transaction --routines --triggers --events
```

| Option | Herkunft |
|---|---|
| `--socket` | `/etc/mysql/mariadb.cnf`, Abschnitt `[client-server]` |
| `--quick`, `--quote-names`, `--max_allowed_packet` | `/etc/mysql/conf.d/mysqldump.cnf`, Abschnitt `[mysqldump]` |
| Rest | `/root/.my.cnf` |

### 8.3 Dump erstellen

```bash
mariadb-dump lab_test > /var/backups/mariadb/lab_test_$(date +%F_%H%M).sql
```

| Teil | Bedeutung |
|---|---|
| ohne `--databases` | kein `CREATE DATABASE`/`USE` → in **jede** Datenbank einspielbar |
| `$(date +%F_%H%M)` | Zeitstempel, z. B. `2026-11-13_2125`, nichts wird überschrieben |

Nachweis, dass die `.my.cnf` wirkt: alter (langer Befehl) und neuer (kurzer Befehl) Dump mit `diff` verglichen → nur `67c67`, der Zeitstempel in der letzten Zeile.

**Aufbau eines Dumps:**

| Abschnitt | Bedeutung |
|---|---|
| `/*!40101 SET … */` | versionierte Kommentare, sichern Zeichensatz und Einstellungen |
| `DROP TABLE IF EXISTS` | ⚠️ der Restore **ersetzt** Tabellen, er ergänzt nicht |
| `CREATE TABLE`, `LOCK TABLES`, `INSERT … VALUES (…),(…)` | Struktur und Daten |
| `-- Dump completed on …` | ⚠️ letzte Zeile: nur dann ist der Dump **vollständig** |

```bash
tail -1 datei.sql
gzip *.sql                              # komprimiert, ersetzt die .sql
zcat datei.sql.gz | tail -1             # Prüfung ohne Auspacken
```

### 8.4 Restore (geübt)

> ⚠️ **Reihenfolge-kritisch**

```bash
mariadb lab_test -e "SELECT * FROM notiz;"                                   # 1. Ist-Zustand
# 2. Schaden simuliert: DELETE … WHERE id = 2;  UPDATE … SET text = 'KAPUTT' WHERE id = 1;
mariadb-dump lab_test > /var/backups/mariadb/lab_test_vor-restore_$(date +%F_%H%M).sql   # 3. aktuellen Stand sichern!
pv /var/backups/mariadb/lab_test_2026-11-13_2125.sql | mariadb lab_test      # 4. Restore
mariadb lab_test -e "SELECT * FROM notiz;"                                   # 5. Gegenprobe
curl -H "Host: lis.lab.test" http://localhost/db-test.php                    # 6. Anwendung sieht die Daten
```

| Schritt | Grund |
|---|---|
| 3. vorher sichern | Alles nach dem Backup Geschriebene wäre sonst weg |
| `pv` | Fortschrittsbalken; bei grossen Dumps sieht man, ob der Restore noch läuft |
| Gegenprobe über die Anwendung | nicht nur root, auch `lis_web` muss die Daten sehen |

Aus `.gz`: `pv datei.sql.gz | gunzip | mariadb lab_test` (nichts Ausgepacktes auf der Platte).

Rückmeldungen lesen: `Query OK, 1 row affected`, `Rows matched: 1  Changed: 1`. Autocommit → kein `COMMIT` nötig. **`WHERE` nie vergessen.**

**Grenzen:** Das Backup liegt in derselben VM. Kopie auf den Laptop, Automatisierung (systemd-Timer) und Backup-Konzept folgen in Phase 8.

---

## 9. TLS: HTTPS für lis.lab.test

### 9.1 Begriffe

**TLS** (Transport Layer Security, früher SSL) ist das „S“ in HTTPS:

| Leistung | Bedeutung |
|---|---|
| Verschlüsselung | niemand liest mit |
| Integrität | niemand verändert unbemerkt |
| Echtheit | ein **Zertifikat** beweist den Namen des Servers |

| Datei | Ort (Debian) | geheim? |
|---|---|---|
| privater Schlüssel | `/etc/ssl/private/` (`root:ssl-cert 710`) | **ja** |
| Zertifikat | `/etc/ssl/certs/` (dort auch ~150 CA-Zertifikate aus `ca-certificates`) | nein |

`710` bei `private`: Die Gruppe `ssl-cert` darf hindurchgehen, aber nicht auflisten. Ein Dienst in der Gruppe öffnet nur Dateien, deren Namen er kennt.

**Selbstsigniert:** mit dem eigenen Schlüssel unterschrieben statt von einer CA. Gleich starke Verschlüsselung, aber der Browser kann die Echtheit nicht prüfen.

### 9.2 Schlüssel und Zertifikat

```bash
openssl req -x509 -newkey rsa:3072 -sha256 -days 365 -noenc \
  -keyout /etc/ssl/private/lis.lab.test.key \
  -out /etc/ssl/certs/lis.lab.test.crt \
  -subj "/CN=lis.lab.test" \
  -addext "subjectAltName=DNS:lis.lab.test"
```

| Teil | Bedeutung |
|---|---|
| `-x509` | direkt ein fertiges, selbstsigniertes Zertifikat |
| `-newkey rsa:3072` | neuer RSA-Schlüssel, 3072 Bit |
| `-days 365` | ein Jahr – die Erneuerung wird später geübt |
| `-noenc` | Schlüssel ohne Passphrase (sonst Passwort bei jedem Apache-Start); früher `-nodes` |
| `-subj "/CN=…"` | Name, ohne Rückfragen |
| `-addext "subjectAltName=…"` | ⚠️ **Pflicht:** Browser prüfen nur den SAN, nicht den CN |

Die Ausgabe `.....+++*` ist die Primzahlsuche für den Schlüssel.

```bash
ls -l /etc/ssl/private/lis.lab.test.key     # -rw------- root root
openssl x509 -in /etc/ssl/certs/lis.lab.test.crt -noout -subject -issuer -dates -ext subjectAltName
```

```
subject=CN=lis.lab.test
issuer=CN=lis.lab.test               ← subject = issuer: selbstsigniert
notBefore=Nov 15 15:44:24 2026 GMT   ← Zertifikate rechnen in GMT/UTC
notAfter=Nov 15 15:44:24 2027 GMT
X509v3 Subject Alternative Name:
    DNS:lis.lab.test
```

Apache liest den Schlüssel beim Start als root → `600 root` reicht, die Gruppe `ssl-cert` wird nicht gebraucht.

### 9.3 Apache

```bash
a2enmod ssl                     # zieht socache_shmcb mit (Sitzungs-Cache für TLS)
```

`/etc/apache2/ports.conf` muss nicht geändert werden, Debian hat vorgesorgt:

```
<IfModule ssl_module>
        Listen 443
</IfModule>
```

`/etc/apache2/sites-available/lis.lab.test.conf` (endgültig):

```apache
# Mini-LIS – Virtual Host (HTTP)
<VirtualHost *:80>
	ServerName lis.lab.test
	ServerAdmin webmaster@lab.test

	Redirect permanent / https://lis.lab.test/

	ErrorLog ${APACHE_LOG_DIR}/lis-error.log
	CustomLog ${APACHE_LOG_DIR}/lis-access.log combined
</VirtualHost>

# Mini-LIS – Virtual Host (HTTPS)
<VirtualHost *:443>
	ServerName lis.lab.test
	ServerAdmin webmaster@lab.test

	DocumentRoot /var/www/lis/public

	<Directory /var/www/lis/public>
		Options -Indexes +FollowSymLinks
		AllowOverride None
		Require all granted
	</Directory>

	SSLEngine on
	SSLCertificateFile    /etc/ssl/certs/lis.lab.test.crt
	SSLCertificateKeyFile /etc/ssl/private/lis.lab.test.key

	ErrorLog ${APACHE_LOG_DIR}/lis-error.log
	CustomLog ${APACHE_LOG_DIR}/lis-ssl-access.log combined
</VirtualHost>
```

Beim Einfügen in vim vorher `:set paste`, danach `:set nopaste`.

```bash
apache2ctl configtest
systemctl restart apache2            # neues Modul → restart
ss -tlnp | grep apache2              # *:80 und *:443, drei PIDs (Haupt- + 2 Kindprozesse)
```

### 9.4 Testen mit curl

```bash
curl --resolve lis.lab.test:443:127.0.0.1 https://lis.lab.test/db-test.php
# curl: (60) SSL certificate problem: self-signed certificate   ← gewollt

curl --cacert /etc/ssl/certs/lis.lab.test.crt --resolve lis.lab.test:443:127.0.0.1 https://lis.lab.test/db-test.php
# Testseite – verschlüsselt UND geprüft
```

| Option | Bedeutung |
|---|---|
| `--resolve name:port:ip` | Namensauflösung ersetzen, ohne `hosts` zu ändern |
| `--cacert datei` | diesem Zertifikat als Aussteller vertrauen, volle Prüfung inkl. Name |
| `-k` | Prüfung **aus** – verschlüsselt, schützt aber nicht vor einem Betrüger in der Mitte. Nur zum Wissen. |

Ein beim Handshake abgebrochener Versuch erscheint **nicht** im Zugriffslog: Ins Log kommt nur, was als HTTP-Anfrage bei Apache ankommt.

### 9.5 Firewall und Weiterleitung

> ⚠️ **Reihenfolge-kritisch:** erst Port 443 öffnen, dann auf HTTPS umleiten. Sonst ist die Website vom Laptop aus nicht mehr erreichbar.

`/etc/nftables.conf`:

```
		# HTTP erlauben
		tcp dport 80 accept
		tcp dport 443 accept
```

```bash
nft -c -f /etc/nftables.conf
systemctl reload nftables
nft list ruleset | grep dport          # 22, 80, 443
```

Danach im 80er-vHost `DocumentRoot` und `<Directory>` durch `Redirect permanent / https://lis.lab.test/` ersetzt (Modul `alias`, bei Debian aktiv). Sicherung dafür nach `/root` – **nicht** in `sites-available`, dort könnte sie versehentlich aktiviert werden.

```bash
apache2ctl configtest && systemctl reload apache2      # nur Konfiguration → reload
curl -I -H "Host: lis.lab.test" http://localhost/db-test.php
```

```
HTTP/1.1 301 Moved Permanently
Location: https://lis.lab.test/db-test.php        ← Pfad bleibt erhalten
```

### 9.6 Apache verrät seine Version nicht mehr

`/etc/apache2/conf-available/security.conf` (Debian, aktiv) – Zeile ohne `#` ist wirksam, die `#`-Zeilen sind Alternativen:

| Zeile | vorher | nachher |
|---|---|---|
| 12 | `ServerTokens OS` | `ServerTokens Prod` |
| 23 | `ServerSignature On` | `ServerSignature Off` |

Header danach: `Server: Apache` statt `Server: Apache/2.4.71 (Debian)`. `TraceEnable Off` ist schon gesetzt. Debian schreibt selbst, dass das die Sicherheit „nicht wirklich“ erhöht – es verrät nur weniger. **Kein Ersatz fürs Patchen.**

Für später (Mini-LIS) vorgemerkt: `.git`-Ordner sperren, `X-Content-Type-Options`, `Content-Security-Policy frame-ancestors`.

### 9.7 Browser

`https://lis.lab.test` → `NET::ERR_CERT_AUTHORITY_INVALID`.

- **AUTHORITY_INVALID** = Aussteller unbekannt, der **Name passt** (sonst `COMMON_NAME_INVALID`)
- Weiter über „Erweitert“ → Testseite
- Vertrauen herstellen: nicht per Handimport, sondern in Phase 4 über eine **eigene CA im Active Directory**

---

## 10. Dateien im Repo

Prinzip: **Pfad im Repo = Pfad im System.** Eigene oder geänderte Dateien ja, Paketvorgaben, Daten, Schlüssel und Passwörter nie.

| System | Repo |
|---|---|
| `/etc/nftables.conf` | `config/etc/nftables.conf` |
| `/etc/apache2/sites-available/lis.lab.test.conf` | `config/etc/apache2/sites-available/` |
| `/etc/apache2/conf-available/servername.conf`, `security.conf` | `config/etc/apache2/conf-available/` |
| `/etc/apt/sources.list.d/sury-php.sources`, `/etc/apt/preferences.d/sury-php.pref` | `config/etc/apt/…` |
| `/etc/fstab` | `config/etc/fstab` |
| `/root/.my.cnf` | `config/root/.my.cnf` |
| `/var/www/lis/public/` | `www/lis/public/` |
| `db.php` | **nur** `www/lis/config/db.php.example` |
| `/etc/ssl/private/*.key` | **nie** – `.gitignore` `*.key` |
| Dumps | **nie** |

- Dateien aus `/root` mit `sudo cp` kopieren → danach `sudo chown stefan:stefan`
- git speichert nur das Ausführbar-Bit (`100644` / `100755`) → beim Zurückkopieren `chmod 600` selbst setzen
- `.gitignore`-Regeln testen: `touch test.key; git check-ignore -v test.key; rm test.key`
- Vor jedem Commit: `git pull`, `git status`, `git diff`, gezielt `git add`, `git diff --staged --stat`

---

## 11. Stolpersteine

| Problem | Ursache | Lösung |
|---|---|---|
| `index.html` gehört root | nach `sudo -i` kein `exit`, vim lief als root | `chown stefan:stefan`; Prompt prüfen (`#` = root) |
| `apache2ctl: command not found` | liegt in `/usr/sbin` | `sudo apache2ctl` |
| `apt -s` zeigt bei Sury nur `trixie` | Sury-Release ohne Label | Herkunft immer mit `apt policy` |
| Keyring legt Schlüssel zusätzlich global ab | Verhalten des Pakets | in Kauf genommen, alle Quellen haben `Signed-By` |
| `grep ^Inst` zählt 50 statt 48 | apt 3.0 schreibt Überschriften `Installing:` | `grep '^Inst '` (mit Leerzeichen) |
| `WARNING: apt does not have a stable CLI interface` | `apt` in einer Pipe | harmlos; in Skripten `apt-get` |
| Riesige Zeile bei `grep` in `phpinfo` | Logo als base64 in derselben Zeile | `grep -o` |
| `NOTICE: Not enabling PHP 8.5 FPM by default` | Standardtext des Triggers | ignorieren, Kontrolle `apache2ctl -M` |
| `findmnt --verify`: „systemd still uses the old version“ | Mount-Units erst nach `daemon-reload` aktuell | `systemctl daemon-reload` |
| Datenbank `#mysql50#lost+found` | `lost+found` im neuen Datenverzeichnis | vor dem Kopieren `rmdir lost+found` |
| `CREATE USER … BY '` + Enter → `'>` | Enter mitten im Text | `'` + Enter, dann `\c` |
| `chgrp www-data` als stefan nicht möglich | nicht Mitglied der Gruppe | `sudo chgrp` |
| `grep -c` findet Platzhalter nicht | Tippfehler im Suchbegriff | Zeile mit `grep -n` direkt ansehen |
| Dump als stefan nicht auffindbar, keine Tab-Ergänzung | Ordner `700` root | `sudo -i` und hinnavigieren |
| SQL-Beispiel für Eingabe gehalten | Beispiel nicht gekennzeichnet | Anleitungen trennen: eingeben / nur lesen |
| `maria: command not found` | Tippfehler | `mariadb` |
| Diff des vHost sieht „verdreht“ aus | git zeigt den kürzesten Unterschied | Ergebnis zählt, nicht die Darstellung |
| `.my.cnf` im Repo `100644` | git speichert nur das x-Bit | beim Zurückkopieren `chmod 600` |
| `grep -rn` trifft Kommentarzeilen | grep sucht reinen Text | Zeilen mit `#` wirken nicht |

---

## 12. Erkenntnisse

- **Datei ≠ aktiv:** nftables (`nft list ruleset`), Apache-Module (`apache2ctl -M`), Links in `*-enabled`, fstab (`daemon-reload`), Dump-Optionen (`--print-defaults`).
- **Ändern → prüfen → laden → testen,** am Schluss ein Neustart-Test.
- **Fremdquelle erst ansehen, dann vertrauen:** Inhalt, Skripte, Fingerprint, Pinning, `apt policy`.
- **Umziehen statt löschen:** `.alt` / `.bak` behalten, bis der Test bestanden ist.
- **Lauter Fehler statt stiller Fehler:** Mountpoint gehört root.
- **Minimale Rechte testen, nicht nur vergeben:** die verbotene Aktion ausprobieren, die Fehlermeldung ist der Erfolg.
- **Geheimnisse doppelt absichern:** ausserhalb DocumentRoot + `.gitignore` + `.example`-Vorlage; Passwörter in KeePassXC auf dem Host.
- **Fehlerdetails ins Log, nicht in den Browser.**
- **Ein Backup ist erst ein Backup, wenn der Restore geklappt hat.** Vor dem Restore den Ist-Stand sichern.
- **TLS ohne Prüfung schützt nicht.** Vertrauen gezielt herstellen (`--cacert`, später eigene CA).
- **Bei Netzwerkänderungen erst den Weg öffnen, dann umleiten.**

---

## 13. Snapshots

| Name | Stand |
|---|---|
| `09-apache` | Apache installiert, Port 80 offen |
| `10-vhost` | Virtual Host `lis.lab.test` |
| `11-php` | PHP 8.5 aus Sury über FPM |
| `12-mariadb` | MariaDB installiert, Daten noch auf root-LV |
| `13-mariadb-lv` | eigenes LV, aufgeräumt |
| `14-db-benutzer` | `lab_test`, `lis_web` |
| `15-db-testseite` | PHP-Treiber, Testseite |
| `19-backup-restore` | Backup-Ordner, `.my.cnf`, Restore geübt |
| `22-tls` | HTTPS, Weiterleitung, Port 443, `ServerTokens Prod` – **Phase 3 fertig** |

Die Snapshots 16–18, 20 und 21 gehören zu Updates und Prüfwerkzeugen (siehe `05-systemzustand-sicherheit.md`).

---

## 14. Nächste Schritte

- [ ] `05-systemzustand-sicherheit.md`: Systemcheck, Prozesse, Dienste, Patch-Stand, Updates bewerten, Kernel-Updates
- [ ] Phase 4: Windows Server 2025 (Desktop Experience), feste IPs, SMB in beide Richtungen, Active Directory mit eigener CA
- [ ] später: Backup automatisieren (Skript + systemd-Timer), Kopie auf den Laptop
- [ ] später: Zertifikat erneuern üben, `mycli`

Aktueller Gesamtplan: `00-fahrplan.md`
