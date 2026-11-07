# 02 – Basis-Administration: Updates, SSH, Firewall, Zeit, Logs

Aus dem frisch installierten Minimal-Debian (siehe `01-grundsystem.md`) wird ein Server, der sicher aus der Ferne verwaltet werden kann: aktuelle Pakete, SSH nur mit Schlüssel, Firewall, korrekte Uhrzeit, nachvollziehbare Logs.

Alle Schritte passieren im **fertigen System** (kein Live-System, kein chroot).

```
Windows (PuTTY + Schlüssel) ──SSH, Port 22──► Firewall (nftables) ──► sshd ──► stefan ──sudo──► root
```

---

## Inhalt

1. [Ausgangslage](#1-ausgangslage)
2. [SSH-Server installieren](#2-ssh-server-installieren)
3. [Verbinden mit PuTTY](#3-verbinden-mit-putty)
4. [Paketquellen ergänzen (deb822)](#4-paketquellen-ergänzen-deb822)
5. [System aktualisieren](#5-system-aktualisieren)
6. [Login mit SSH-Schlüssel](#6-login-mit-ssh-schlüssel)
7. [SSH härten](#7-ssh-härten)
8. [Firewall mit nftables](#8-firewall-mit-nftables)
9. [Zeitsynchronisation](#9-zeitsynchronisation)
10. [Logs mit journalctl](#10-logs-mit-journalctl)
11. [Exkurs: D-Bus](#11-exkurs-d-bus)
12. [Stolpersteine und Erkenntnisse](#12-stolpersteine-und-erkenntnisse)
13. [Snapshots](#13-snapshots)
14. [Nächste Schritte](#14-nächste-schritte)

---

## 1. Ausgangslage

- Debian 13 (trixie), manuell per debootstrap installiert, nur Konsole
- Netzwerk: VMware NAT, `ens33` per DHCP
- Benutzer `stefan` (Gruppe `sudo`)
- Arbeitsweise: immer als `stefan` anmelden, für Admin-Befehle `sudo -i`

| Prompt | Bedeutung |
|---|---|
| `stefan@debian-lab:~$` | normaler Benutzer (`$`) |
| `root@debian-lab:~#` | root (`#`), nach `sudo -i` mit dem **eigenen** Passwort |

**Warum nicht direkt als root?** Debian lässt root per SSH ohnehin nicht mit Passwort hinein. Jeder `sudo`-Aufruf wird mit Benutzername protokolliert, und Tippfehler als normaler Benutzer richten weniger Schaden an.

---

## 2. SSH-Server installieren

**SSH (Secure Shell)** ist ein verschlüsselter Fernzugang zur Kommandozeile. Auf Debian heisst der Server `sshd` (Paket `openssh-server`) und lauscht auf **TCP-Port 22**.

> **Reihenfolge:** SSH kommt **vor** den Paketquellen. Grund: In PuTTY kann man Text per Copy & Paste einfügen, im VMware-Fenster nicht. `openssh-server` liegt schon in der Hauptquelle `main`, und über NAT ist die VM nur vom eigenen Laptop erreichbar.

Im VMware-Fenster:

```bash
sudo -i
apt update
apt install -y openssh-server
systemctl status ssh        # erwartet: Active: active (running), Beenden mit q
ip -br a                    # IP von ens33 notieren (ohne /24)
```

| Befehl | Bedeutung |
|---|---|
| `systemctl status <dienst>` | Zustand eines systemd-Dienstes |
| `ip -br a` | `-br` = brief (eine Zeile pro Karte), `a` = Adressen |

Bei der Installation erzeugt der Server seine **Host-Schlüssel** (`Creating SSH2 … key`). Daran erkennen Clients später, dass sie mit dem richtigen Server sprechen.

---

## 3. Verbinden mit PuTTY

| Feld | Wert |
|---|---|
| Host Name | IP der VM (z. B. `192.168.38.129`) |
| Port | `22` |
| Connection type | SSH |
| Saved Sessions | `debian-lab` → **Save** |

Beim ersten Verbinden erscheint ein **Security Alert** mit dem Fingerprint des Host-Schlüssels → **Accept**. PuTTY merkt sich den Schlüssel. Meldet sich später ein anderer Rechner unter derselben IP, gibt es eine Warnung (Schutz vor Man-in-the-Middle).

**Copy & Paste in PuTTY:** Markieren = kopieren, Rechtsklick = einfügen. **In vim: Shift + Rechtsklick** (siehe Stolpersteine).

> Die IP kommt per DHCP und kann sich ändern. Bei einem Timeout zuerst in der VM `ip -br a` prüfen.

---

## 4. Paketquellen ergänzen (deb822)

debootstrap trägt nur die Hauptquelle ein:

```
deb http://deb.debian.org/debian trixie main
```

Es fehlen:

| Quelle | Inhalt |
|---|---|
| `trixie` | das Release selbst |
| `trixie-updates` | wichtige Korrekturen zwischen Punkt-Releases |
| `trixie-security` | **Sicherheitsupdates**, eigener Server `security.debian.org` |

Ab Debian 13 ist das empfohlene Format eine `.sources`-Datei im **deb822-Format** in `/etc/apt/sources.list.d/`. Das alte Einzeilenformat in `/etc/apt/sources.list` gilt als veraltet.

```bash
cat /etc/apt/sources.list                              # Ist-Zustand ansehen
mv /etc/apt/sources.list /etc/apt/sources.list.bak     # wird nicht mehr gelesen, bleibt als Sicherung
ls /usr/share/keyrings/                                # Signaturschlüssel finden
vim /etc/apt/sources.list.d/debian.sources
```

Inhalt von `/etc/apt/sources.list.d/debian.sources`:

```
Types: deb
URIs: http://deb.debian.org/debian
Suites: trixie trixie-updates
Components: main
Signed-By: /usr/share/keyrings/debian-archive-keyring.pgp

Types: deb
URIs: http://security.debian.org/debian-security
Suites: trixie-security
Components: main
Signed-By: /usr/share/keyrings/debian-archive-keyring.pgp
```

| Feld | Bedeutung |
|---|---|
| `Types: deb` | Binärpakete (`deb-src` = Quellcode) |
| `URIs` | Server-Adresse |
| `Suites` | Zweige, mehrere durch Leerzeichen getrennt |
| `Components` | `main` = nur freie Software (reicht für eine VM, keine Firmware nötig) |
| `Signed-By` | **welcher** Schlüssel diese Quelle signieren darf |
| Leerzeile | trennt Quellblöcke, ist **Pflicht** |

Dateiname und Ordner:

| Teil | Bedeutung |
|---|---|
| `/etc` | Konfiguration dieses Rechners (ursprünglich „et cetera“) |
| `sources.list.d/` | `.d` = directory, apt liest **alle** passenden Dateien darin |
| `.sources` | Endung für das deb822-Format, andere Endungen werden ignoriert |

**Signed-By:** Jede Quelle signiert ihre Paketlisten. Ohne `Signed-By` akzeptiert apt jeden Schlüssel, dem das System vertraut. Ab apt 3.0 erscheint sonst der Hinweis `Notice: Missing Signed-By`. In `/usr/share/keyrings/` liegen `.pgp` (neuer Name ab trixie) und `.gpg` (alter Name, Kompatibilität) – gewählt: `debian-archive-keyring.pgp` (Sammelschlüssel aller aktuellen Archive).

```bash
apt update
```

| Ausgabe | Bedeutung |
|---|---|
| `Hit` | Liste war schon aktuell |
| `Get` | Liste heruntergeladen |
| `E:` / `W:` / `Notice:` | Fehler / Warnung / Hinweis |

Erwartet: Zeilen für `trixie`, `trixie-updates` und `trixie-security`, keine `Notice` mehr.

---

## 5. System aktualisieren

```bash
apt upgrade
reboot          # nötig, wenn ein neuer Kernel dabei war
uname -r        # laufende Kernel-Version prüfen
```

| Abschnitt in der Ausgabe | Bedeutung |
|---|---|
| Upgrading | vorhandene Pakete werden aktualisiert (u. a. `openssl`, `apt`, `vim`) |
| Installing dependencies | neuer Kernel `linux-image-6.12.x…`, der alte bleibt als Rückfallebene |
| Suggested packages | nur Vorschläge, werden nicht installiert |

`linux-image-amd64` ist ein **Metapaket**: Es enthält selbst nichts, zeigt aber immer auf den aktuellen Kernel. Beim Upgrade baut Debian automatisch die initramfs neu und aktualisiert das GRUB-Menü.

Nach `reboot` in PuTTY: Rechtsklick auf die Titelleiste → **Restart Session**.

---

## 6. Login mit SSH-Schlüssel

Statt eines Passworts nutzt SSH ein **Schlüsselpaar**:

| Teil | Ort | Eigenschaft |
|---|---|---|
| Privater Schlüssel | nur auf dem Laptop (`.ppk`) | geheim, mit Passphrase geschützt |
| Öffentlicher Schlüssel | VM: `~/.ssh/authorized_keys` | darf jeder sehen |

Der Server stellt eine Aufgabe, die nur der passende private Schlüssel lösen kann. Es geht kein Passwort über die Leitung, Brute Force ist praktisch unmöglich.

### 6.1 Schlüssel erzeugen (Windows, PuTTYgen)

1. PuTTYgen → Typ **EdDSA / Ed25519** → **Generate**, Maus bewegen
2. Key comment: z. B. `stefan@laptop`
3. Key passphrase setzen (schützt die Datei, falls sie kopiert wird)
4. **Save private key** → `debian-lab.ppk`
5. Die Zeile `ssh-ed25519 AAAA… stefan@laptop` oben im Fenster kopieren

**Warum Ed25519 statt RSA?**

| | Ed25519 | RSA |
|---|---|---|
| Schlüssellänge | fest 256 Bit (≈ RSA-3072) | wählbar, mind. 3072 empfohlen |
| Fehlerquellen | keine Parameter, nichts falsch einstellbar | zu kurze Schlüssel, veraltete Signatur `ssh-rsa` (SHA-1) |
| Robustheit | Signatur hängt nicht von gutem Zufall ab | |
| Standard | Default von OpenSSH seit 9.5 | |
| Wann RSA? | — | alte Geräte/Appliances, bestimmte Smartcards, Firmenvorgaben |

### 6.2 Öffentlichen Schlüssel hinterlegen (VM, als `stefan`!)

> ⚠️ **Als `stefan`, nicht als root** – sonst landet die Datei im falschen Home bzw. gehört root.

```bash
mkdir -p ~/.ssh
chmod 700 ~/.ssh
vim ~/.ssh/authorized_keys          # i, Shift+Rechtsklick, Esc, :wq
chmod 600 ~/.ssh/authorized_keys
ls -la ~/.ssh
cat ~/.ssh/authorized_keys          # genau eine Zeile ssh-ed25519 …
```

Erwartet:

```
drwx------ 2 stefan stefan … .
-rw------- 1 stefan stefan … authorized_keys
```

**Rechte: Ordner 700, Datei 600.** sshd ignoriert die Datei, wenn andere sie ändern könnten.

| Recht | Datei | Ordner |
|---|---|---|
| `r` (4) | Inhalt lesen | Namen auflisten |
| `w` (2) | Inhalt ändern | Dateien anlegen/löschen |
| `x` (1) | ausführen | **hineinwechseln, auf Dateien zugreifen** |

### 6.3 PuTTY einstellen

1. Session `debian-lab` → **Load**
2. **Connection → Data** → Auto-login username: `stefan`
3. **Connection → SSH → Auth → Credentials** → Private key file: `debian-lab.ppk`
4. **Session** → **Save**

### 6.4 Testen

> ⚠️ Bestehende Sitzung **offen lassen** (Rettungsleine), Test im **neuen** Fenster.

Erwartet: `Authenticating with public key "stefan@laptop"` → Frage nach der **Passphrase** (nicht nach dem Linux-Passwort) → Prompt.

Komfort-Option (bewusst nicht genutzt): **Pageant**, der Schlüssel-Agent von PuTTY – Passphrase einmal pro Windows-Sitzung.

---

## 7. SSH härten

Erst wenn der Schlüssel-Login nachweislich funktioniert:

> ⚠️ **Reihenfolge:** Schlüssel testen → Passwort-Login abschalten. Umgekehrt sperrt man sich aus.

`/etc/ssh/sshd_config` bleibt unverändert. Sie bindet ganz oben `/etc/ssh/sshd_config.d/*.conf` ein. Eigene Datei:

```bash
sudo -i
vim /etc/ssh/sshd_config.d/10-hardening.conf
```

```
# Nur Schlüssel-Login, kein root per SSH
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
```

| Zeile | Bedeutung |
|---|---|
| `PermitRootLogin no` | root darf per SSH gar nicht hinein |
| `PasswordAuthentication no` | kein Passwort-Login |
| `KbdInteractiveAuthentication no` | schliesst den zweiten Weg für Passwortabfragen |
| `10-` | Dateien werden alphabetisch gelesen |

Bei sshd gilt der **erste** gefundene Wert. Da die Einbindung ganz oben steht, haben eigene Dateien Vorrang. Vorteil: Debian-Updates fragen nicht nach geänderter Konfiguration.

```bash
sshd -t                     # Syntax prüfen – keine Ausgabe = OK
systemctl reload ssh        # neu einlesen, offene Verbindungen bleiben
```

> ⚠️ **Immer zuerst `sshd -t`, dann `reload`.** Mit fehlerhafter Konfiguration startet der Dienst nicht mehr.

Optional die wirksamen Werte anzeigen:

```bash
sshd -T | grep -Ei 'permitrootlogin|passwordauth|kbdinteractive'
```

**Tests:**

| Test | Erwartet |
|---|---|
| Neues PuTTY-Fenster mit Schlüssel | Login klappt |
| Windows-`cmd`: `ssh stefan@<IP>` (kennt den PuTTY-Schlüssel nicht) | `Permission denied (publickey).` – es wird gar nicht nach einem Passwort gefragt |

**Was bleibt offen?** Die VMware-Konsole geht weiterhin mit Passwort (lokal, nicht SSH). Lücken in sshd selbst → Updates. Weitere Dienste → Firewall.

---

## 8. Firewall mit nftables

**nftables** gibt dem Kernel die Regeln, welche Netzwerkpakete durchgelassen werden. Nachfolger von iptables, Standard seit Debian 10.

| Begriff | Bedeutung |
|---|---|
| table | Behälter für Regeln (`inet` = IPv4 + IPv6) |
| chain | Regelkette an einer Stelle: `input` (an diesen Rechner), `forward` (hindurch), `output` (von diesem Rechner) |
| rule | einzelne Regel |
| policy | was gilt, wenn keine Regel passt: `accept` oder `drop` |

Ausgangslage: Paket installiert, Dienst `disabled`, die Vorlage `/etc/nftables.conf` hat leere Ketten ohne Policy → alles erlaubt.

**Ziel:** eingehend alles verwerfen ausser SSH und Ping, ausgehend alles erlauben.

```bash
vim /etc/nftables.conf      # vorher :set paste, dann gg, dG, i, einfügen
```

```
#!/usr/sbin/nft -f

flush ruleset

table inet filter {
	chain input {
		type filter hook input priority filter; policy drop;

		# Antworten auf eigene Verbindungen erlauben
		ct state established,related accept
		# kaputte Pakete verwerfen
		ct state invalid drop
		# Rechner spricht mit sich selbst
		iif "lo" accept
		# Ping erlauben (IPv4 und IPv6)
		ip protocol icmp accept
		meta l4proto ipv6-icmp accept
		# SSH erlauben
		tcp dport 22 accept
	}
	chain forward {
		type filter hook forward priority filter; policy drop;
	}
	chain output {
		type filter hook output priority filter; policy accept;
	}
}
```

| Zeile | Bedeutung |
|---|---|
| `#!/usr/sbin/nft -f` | Shebang: Datei wird mit `nft -f` geladen |
| `flush ruleset` | alle bestehenden Regeln löschen, jedes Laden beginnt bei null |
| `policy drop` (input) | Grundregel: was nicht erlaubt ist, wird stillschweigend verworfen |
| `ct state established,related accept` | Connection Tracking: Antworten auf eigene Verbindungen (z. B. `apt`, NTP). **Ohne diese Zeile ist `apt` kaputt.** |
| `ct state invalid drop` | ungültige Pakete verwerfen |
| `iif "lo" accept` | Loopback (`127.0.0.1`), z. B. später PHP ↔ MariaDB |
| `ip protocol icmp accept` | Ping (IPv4) |
| `meta l4proto ipv6-icmp accept` | ICMPv6 – für IPv6 zwingend nötig |
| `tcp dport 22 accept` | neue Verbindungen auf Port 22 |
| `policy drop` (forward) | VM ist kein Router |
| `policy accept` (output) | ausgehend alles erlaubt |

Regeln werden **von oben nach unten** geprüft, die erste passende entscheidet.

### Vorgehen (sicher über SSH)

```bash
nft -c -f /etc/nftables.conf          # 1. nur prüfen (-c = check) – keine Ausgabe = OK
nft -f /etc/nftables.conf             # 2. sofort laden, Dienst noch disabled
```

> **Sicherheitsnetz:** Solange der Dienst `disabled` ist, entfernt ein Neustart der VM die Regeln wieder.

3. Neues PuTTY-Fenster → Login muss klappen
4. `apt update` → muss klappen (prüft `established,related`)
5. Von Windows (PowerShell) einen gesperrten Port testen:

```powershell
Test-NetConnection 192.168.38.129 -Port 80
```

Erwartet nach ca. 20 s: `PingSucceeded : True`, `TcpTestSucceeded : False`. Die Verzögerung kommt von `drop`: Es gibt keine Antwort, der Client wartet bis zum Timeout. Mit `-Port 22` → sofort `True`.

6. Dauerhaft aktivieren und mit Neustart prüfen:

```bash
systemctl enable --now nftables
reboot
systemctl status nftables     # erwartet: enabled, active (exited)
```

| Teil | Bedeutung |
|---|---|
| `enable` | beim Booten starten (legt einen Symlink in einen `*.wants`-Ordner) |
| `--now` | zusätzlich sofort starten |
| `active (exited)` | korrekt: Der Dienst lädt die Regeln in den Kernel und beendet sich, die Regeln bleiben aktiv |

Hilfreich: `nft list ruleset` zeigt die aktuell geladenen Regeln.

---

## 9. Zeitsynchronisation

Korrekte Zeit ist Pflicht für Logs, Zertifikate und später Auftrags-Zeitstempel und TAT im Mini-LIS.

```bash
timedatectl
```

Nach debootstrap: `System clock synchronized: no`, `NTP service: n/a` – kein Synchronisationsdienst installiert. Die Uhr stimmte nur, weil VMware sie beim Start vom Host übernimmt.

**NTP** (Network Time Protocol, UDP-Port 123) gleicht die Uhr mit Zeitservern ab. **systemd-timesyncd** ist ein schlanker NTP-**Client** (`chrony` wäre nötig, wenn die VM selbst Zeit verteilen soll). Firewall: ausgehende Anfrage erlaubt, Antwort fällt unter `established` → keine Regel nötig.

```bash
apt install -y systemd-timesyncd
timedatectl                    # erwartet: synchronized: yes, NTP service: active
timedatectl timesync-status
```

| Wert | Bedeutung |
|---|---|
| `Server` | Zeitserver, Standard `debian.pool.ntp.org` |
| `Stratum` | Abstand zur Referenzuhr (1 = direkt angeschlossen) |
| `Reference: PPS` | Pulse Per Second, z. B. GPS-Empfänger |
| `Offset` | Abweichung der eigenen Uhr (gemessen: ca. −0,4 ms) |
| `Poll interval` | Abfrageabstand, wächst bei stabiler Uhr (32 s … 34 min) |
| `RTC in local TZ: no` | korrekt: Hardware-Uhr läuft in UTC (Linux-Standard) |

---

## 10. Logs mit journalctl

systemd sammelt die Meldungen aller Dienste und des Kernels zentral im **Journal**. Debian 13 speichert es dauerhaft unter `/var/log/journal/`.

```bash
journalctl --list-boots        # mehrere Einträge = dauerhaft gespeichert
```

| Befehl | Wirkung |
|---|---|
| `journalctl -b` | seit dem aktuellen Boot |
| `journalctl -b -1` | vorheriger Boot |
| `journalctl -u <dienst>` | nur ein Dienst (unit), z. B. `-u systemd-timesyncd` |
| `journalctl -t <name>` | nach Absendername (Tag vor der eckigen Klammer), z. B. `-t sshd-session` |
| `--since "17:25" --until "17:35"` | Zeitraum |
| `\| grep -i ssh` | Ausgabe nach Text filtern, `-i` = Gross/Klein egal |
| `-f` | live mitlesen (Beenden mit `Strg + C`) |

**Beispiel: abgewiesener SSH-Versuch finden**

```bash
journalctl --since "17:25" --until "17:35" | grep -i ssh
journalctl -t sshd-session
```

Typische Zeilen:

| Meldung | Bedeutung |
|---|---|
| `Accepted publickey for stefan from … ED25519 SHA256:…` | Login mit Schlüssel (Fingerprint des Schlüssels) |
| `Connection reset by authenticating user stefan … [preauth]` | Verbindung **vor** erfolgreicher Anmeldung beendet → abgewiesener Versuch |
| `Received SIGHUP; restarting.` | `systemctl reload ssh` |
| `Server listening on 0.0.0.0 port 22` / `:: port 22` | lauscht auf allen IPv4-/IPv6-Adressen |
| `syslogin_perform_logout: logout() returned an error` | harmlos (Login-Buchhaltung `wtmp` in der Minimalinstallation unvollständig) |

**Zwei SSH-Prozessarten** (seit OpenSSH 9.8):

| Absender | Rolle |
|---|---|
| `sshd[…]` | Hauptdienst, lauscht dauerhaft |
| `sshd-session[…]` | eigener Prozess pro Verbindung |

---

## 11. Exkurs: D-Bus

**D-Bus** ist ein Nachrichtensystem **innerhalb eines Rechners**: Programme sprechen über einen zentralen Vermittler (`dbus-daemon`) statt direkt miteinander.

| Spital-IT | D-Bus |
|---|---|
| Kommunikationsserver in der Mitte | `dbus-daemon` |
| angebundene Systeme | `timedatectl`, `systemd-timesyncd`, `hostnamectl` … |
| Endpunkt-Name | Bus-Name, z. B. `org.freedesktop.timesync1` |
| Berechtigungen | Policy-Dateien in `/usr/share/dbus-1/system.d/` |

Es gibt einen **System-Bus** (systemweite Dienste) und einen **Session-Bus** (pro Benutzer, vor allem Desktop).

`hostnamectl`, `timedatectl` & Co. funktionieren nur mit D-Bus (deshalb in `01-grundsystem.md` nachinstalliert). Neue Policy-Dateien werden erst nach `systemctl reload dbus` wirksam.

---

## 12. Stolpersteine und Erkenntnisse

| Problem | Ursache | Lösung |
|---|---|---|
| `mv: cannot stat '/etc/sources.list'` | Tippfehler, `apt/` fehlte | `/etc/apt/sources.list` |
| `Notice: Missing Signed-By …` | apt 3.0 empfiehlt einen expliziten Schlüssel pro Quelle | `Signed-By:` in der `.sources`-Datei |
| Rechtsklick in vim → `-- VISUAL --`, nichts eingefügt | Debian aktiviert in vim die Maus (`mouse=a`), vim fängt den Klick ab | **Shift + Rechtsklick** oder `:set mouse=` |
| eingefügter Text treppenartig eingerückt | vim rückt beim Einfügen ein | vorher `:set paste` |
| `chmod: cannot access '…/authorized_keys': Permission denied` | `chmod 600` auf den **Ordner** `~/.ssh` → `x` fehlt, kein Zugriff auf Dateien darin | `chmod 700 ~/.ssh` – Ordner 700, Datei 600 |
| `apt update` → `Could not open lock file … (13: Permission denied)` | als `stefan` statt root – **kein** Firewall-Problem | `sudo -i` |
| `timedatectl timesync-status` → `Failed to query server: Connection timed out` | D-Bus hatte die neue Policy von timesyncd noch nicht eingelesen; Synchronisation lief trotzdem | `systemctl reload dbus`, `systemctl restart systemd-timesyncd` |
| `journalctl -u ssh` → `No entries` für einen abgewiesenen Versuch | kurzlebiger `sshd-session`-Prozess vermutlich nicht dem Dienst zugeordnet | `journalctl -t sshd-session` oder Zeitraum + `grep` |

**Erkenntnisse**

- **Grundmuster:** Konfiguration ändern → prüfen (`sshd -t`, `nft -c`) → neu laden (`reload`) → testen.
- **Bei Zugangsänderungen immer eine Rettungsleine:** offene Sitzung behalten, im neuen Fenster testen.
- **Erst prüfen, was durchgehen soll, dann was blockiert sein soll.**
- **Fehlermeldungen lesen:** `Permission denied (13)` = Rechte, Hängen/Timeout = Netzwerk/Firewall.
- **Firewall erst `enable`, wenn getestet** – vorher räumt ein Neustart auf.

---

## 13. Snapshots

| Name | Stand |
|---|---|
| `04-ssh-updates` | SSH-Server, Paketquellen, System aktuell, neuer Kernel |
| `05-ssh-schluessel` | Schlüssel-Login, Passwort- und root-Login per SSH gesperrt |
| `06-firewall` | nftables aktiv und beim Booten geladen |
| `07-phase1-fertig` | + Zeitsynchronisation, Logs geprüft |

---

## 14. Nächste Schritte

- [ ] RAM auf dem Windows-Host freiräumen (Autostart-Programme)
- [ ] Phase 2: git installieren, GitHub-Repo, README, `docs/00–02` einchecken
- [ ] Phase 3: Apache, PHP, MariaDB – dabei Firewall um Port 80/443 erweitern
