# 00 – Fahrplan: Linux-Lab & Mini-LIS

Privates Lern- und Portfolio-Projekt: eine kleine, realitätsnahe Spital-/Labor-IT in VMs – von einem von Hand gebauten Debian bis zu einem eigenen Mini-LIS mit Schnittstellen und Live-Visualisierung.

> Nur Lern- und Demozwecke, kein klinischer Einsatz. Ausschliesslich erfundene Daten.

---

## Leitlinien

- **Verstehen vor Automatisieren:** erst von Hand, dann dokumentieren, dann automatisieren.
- **Erst Infrastruktur, dann Daten (Entscheid 8. Nov. 2026):** Phasen 3–5 werden vollständig und sauber eingerichtet (inkl. Backup/Restore, TLS, Windows Server, SMB, AD), bevor ab Phase 6 LIS-Daten entstehen. Für Übungen wie Backup/Restore genügen kleine Testtabellen ohne Bezug zum LIS.
- **Jeder Schritt wird dokumentiert:** `docs/NN-thema.md` (Doku-Nummer ≠ Phasen-Nummer) mit Werkzeug-Erklärung, Befehlen, erwarteter Ausgabe und Stolpersteinen.
- **Snapshot vor jedem grösseren Schritt:** Benennung `NN-beschreibung`.
- **Systemcheck zu Beginn jeder Sitzung:** Zeit, Laufzeit, ausgefallene Dienste, Fehler im Journal, Platz, Speicher, Updates.
- **Minimal patchen:** Sicherheitsupdates gezielt und zeitnah, Rest gesammelt; jedes Update erst bewerten (DSA/CVE), dann einspielen, dann prüfen.
- **Ereignis-Logging von Anfang an:** Jeder LIS-Baustein meldet Auftragsnummer, Zeitstempel, Station und Status.
- **Keine Geheimnisse und keine Arbeitgeber-Interna im Repo.** Private Schlüssel nie (`.gitignore`: `*.key`).
- **Eigene oder geänderte Konfigurationen ins Repo:** `config/…` spiegelt das Dateisystem (`config/etc/…`, `config/root/…`, `config/home/stefan/…`), Paketvorgaben nicht.

---

## Phase 0 – Grundsystem ✅

- [x] VM in VMware Workstation Pro (UEFI, 4 Kerne, 4 GB RAM, 60 GB) – RAM am 15. Nov. 2026 auf 3 GB reduziert (Platz für die Windows-Server-VM)
- [x] Partitionierung (GPT: EFI + LVM)
- [x] LVM: `vg0` mit `root` 20 GB, `swap` 2 GB, Rest frei
- [x] Debian 13 per debootstrap und chroot
- [x] fstab, Hostname, Zeitzone, Locale, Benutzer, Netzwerk
- [x] GRUB (UEFI + removable), erster Start, dbus
- [x] Doku: `docs/01-grundsystem.md`

---

## Phase 1 – Basis-Administration ✅

- [x] Paketquellen um Security- und Update-Repos ergänzen (deb822, Signed-By), System aktualisieren
- [x] SSH-Server installieren, Zugriff per PuTTY
- [x] SSH-Schlüssel (Ed25519) statt Passwort, root-Login per SSH sperren
- [x] Firewall mit nftables (nur SSH und Ping erlauben, Rest blockieren)
- [x] ~~Konsolenauflösung dauerhaft über GRUB~~ – nicht nötig, VMware-Konsole ist gut lesbar
- [x] Zeit-Synchronisation (systemd-timesyncd nachinstalliert)
- [x] Logs verstehen: `journalctl` (Journal dauerhaft gespeichert)
- [ ] RAM auf dem Windows-Host freiräumen (Autostart-Programme) – **vor Phase 4 erledigen**
- [x] Doku: `docs/02-basis-administration.md`

---

## Phase 2 – Versionierung & Doku ✅

- [x] git installieren und konfigurieren (noreply-Adresse, `main`)
- [x] GitHub-Repo `stefan-krimmer/linux-lab` (öffentlich), `.gitignore`, MIT-Lizenz
- [x] README: Ziel, Architekturdiagramm, Stand, Hinweis „nur Demo/erfundene Daten“
- [x] `docs/` mit 00 bis 02 eingecheckt
- [x] Eigener SSH-Schlüssel der VM für GitHub
- [x] Repo auf GitHub-Profil angepinnt
- [ ] Auf LinkedIn unter „Im Fokus“ verlinken – **jetzt möglich**, der Web-/DB-Stack läuft
- [x] Doku: `docs/03-git-github.md`

---

## Phase 3 – Web- und Datenbank-Stack ✅

Reihenfolge angepasst: eigenes LV direkt nach der MariaDB-Installation, solange das Datenverzeichnis noch leer ist.

- [x] Apache installieren, eigener Virtual Host `lis.lab.test` (namensbasiert, DocumentRoot `/var/www/lis/public`, Standard-Site aus)
- [x] Konfigurationen im Repo versionieren (`config/etc/…`, Website unter `www/`, ohne Passwörter, `*.example`)
- [x] Firewall um Port 80 und 443 erweitert
- [x] PHP 8.5 aus Fremdquelle Sury (deb822, Signed-By, Pinning: nur `php*` von Sury), Anbindung über PHP-FPM (Apache bleibt `mpm_event`)
- [x] MariaDB 11.8 aus Debian – `mariadb-secure-installation` bewusst **nicht** ausgeführt (Debian ist bereits sicher eingerichtet), stattdessen von Hand geprüft
- [x] Eigenes LV `vg0/mariadb` (10 GB) für `/var/lib/mysql`, Datenverzeichnis umgezogen, Neustart getestet
- [x] Datenbank-Benutzer `lis_web` (nur DML), PHP-Treiber `php8.5-mysql`, DB-Testseite (PDO über Unix-Socket, Zugangsdaten nur als `*.example` im Repo)
- [x] Backup und Restore geübt (`mariadb-dump` mit `/root/.my.cnf`, Restore mit `pv`, Gegenprobe über die Anwendung)
- [x] TLS mit selbstsigniertem Zertifikat (SAN), Port 443 in nftables, Weiterleitung 80 → 443, `ServerTokens Prod`
- [x] Prüfen des Systems (Prozesse, Dienste, Patch-Stand, Sicherheitslücken), Spickzettel `spick`, Systemcheck als Routine
- [x] Sicherheitsupdates nach Bewertung eingespielt (Apache 2.4.71, Kernel 6.12.116 und 6.12.117)
- [x] Doku: `docs/04-web-db.md`
- [ ] Doku: `docs/05-systemzustand-sicherheit.md`

**Geplante Sites (Entscheid 8. Nov. 2026):** jetzt nur `lis.lab.test` mit Testseiten. Später getrennt wie im echten Spital: `lis.lab.test` (Phase 6), `order.lab.test` (Order Entry, Phase 6), `monitor.lab.test` (Phase 7). XML-Server, HL7 und Gerätesimulator sind Dienste, keine Virtual Hosts. Namensauflösung bis Phase 4 über die Windows-`hosts`-Datei, danach AD-DNS.

**Später (offen, nicht blockierend):** Backup automatisieren (Skript + systemd-Timer) und auf den Laptop kopieren – spätestens in Phase 8; Zertifikat erneuern üben (gültig bis Nov. 2027).

---

## Phase 4 – Windows Server & SMB

- [ ] Windows Server 2025 (Evaluation, englisch) als zweite VM mit **Desktop Experience** (Entscheid 9. Nov. 2026: Windows wird in der Praxis überwiegend grafisch verwaltet; zu jedem grafischen Schritt zusätzlich der PowerShell-Befehl)
- [ ] Beide VMs ins gleiche virtuelle Netz, feste IPs (`hosts`-Eintrag anpassen)
- [ ] Debian als SMB-Server (Samba), Zugriff von Windows
- [ ] Windows als SMB-Server, Einhängen unter Debian (`cifs`, fstab)
- [ ] Active Directory: Windows Server als Domänencontroller
- [ ] DNS-Einträge für `*.lab.test` im AD-DNS (ersetzt den `hosts`-Eintrag)
- [ ] Eigene CA im AD, Zertifikat für `lis.lab.test` (ersetzt das selbstsignierte, keine Browser-Warnung mehr)
- [ ] Debian der Domäne beitreten lassen, Anmeldung mit AD-Benutzer
- [ ] Freigabe-Rechte über AD-Gruppen
- [ ] Doku: `docs/06-windows-smb-ad.md`

---

## Phase 5 – Container

- [ ] Docker installieren, Grundlagen (Image, Container, Volume, Netzwerk)
- [ ] Docker Compose
- [ ] Oracle Database Free als Container, Vergleich mit MariaDB
- [ ] Doku: `docs/07-container.md`

---

## Phase 6 – Mini-LIS (ab hier entstehen Daten)

**Datenmodell**
- [ ] Patient (erfunden), Auftrag, Probe, Analyse, Resultat
- [ ] Referenzbereiche (alters-/geschlechtsabhängig)
- [ ] Rollen und Rechte (Erfassung, technische und medizinische Validierung)
- [ ] Audit-Trail (wer, wann, was, Quelle – z. B. „from XML Server“)

**Prozess**
- [ ] Order Entry (Webformular, Auftragsnummer, Barcode)
- [ ] XML-Server als systemd-Dienst (Empfang, Prüfung, Speicherung)
- [ ] Probeneingang, Arbeitslisten pro Gerät
- [ ] Gerätesimulator (ASTM oder HL7), inkl. Fehler- und Extremwerte – Übertragungsweg austauschbar halten (TCP oder seriell), damit er später auf einen Raspberry Pi umziehen kann
- [ ] Resultatbearbeitung: Flags (H/L), Deltacheck, Plausibilitätsregeln
- [ ] Technische und medizinische Validierung
- [ ] Befund-PDF, Ablage per SMB auf Windows Server
- [ ] Resultat per HL7 an ein simuliertes „KIS“

**Spital-Simulator (erfundene Patienten im Echtbetrieb)**
- [ ] Patientenpool mit synthetischen Stammdaten (Synthea)
- [ ] Zufällige Ankünfte (Poisson-Prozess) mit Tagesprofil: Spitze am Vormittag, ruhige Nacht, Abendspitze auf dem Notfall
- [ ] Stationen mit eigenem Auftragsprofil:
  - Notfall: STAT-Aufträge (Blutbild, CRP, Elektrolyte, Laktat), kurze Ziel-TAT
  - Ambulant: Routine-Panels, vor allem vormittags
  - Bettenstation: Morgenrunde (viele Aufträge gleichzeitig), Verlaufskontrollen
  - optional IPS: häufige Blutgase, Wiederholungen (Deltacheck)
- [ ] Ablauf wie in echt: HL7 ADT Aufnahme (A01/A04) → Auftrag → Entlassung (A03)
- [ ] Krankheitsbilder statt reinem Zufall (z. B. Infekt, Dehydratation), damit Resultate zum Patienten passen
- [ ] Simulator als systemd-Dienst (z. B. Python mit SimPy), Zeitraffer-Modus für Demos (1 h = 1 min)
- [ ] Jedes Ereignis ins Ereignis-Log (Grundlage für Phase 7)

**Doku:** `docs/08-mini-lis.md` plus Architektur- und Datenflussdiagramm

---

## Phase 7 – Monitoring & Live-Visualisierung

- [ ] Ereignistabelle bzw. Event-Stream aller Bausteine
- [ ] Kleiner Server, der Ereignisse per WebSocket an den Browser liefert
- [ ] Live-Flow-Grafik: Aufträge wandern durch die Stationen, Staus werden rot
- [ ] Klick auf Auftrag zeigt den vollständigen Weg (Rückverfolgung)
- [ ] Turnaround Time (TAT) live berechnen, getrennt nach Station (Notfall vs. Routine)
- [ ] Alarme mit Schwellenwerten (Warteschlange, Inaktivität)
- [ ] Prometheus und Grafana für technische Kennzahlen
- [ ] Doku: `docs/09-monitoring.md`

---

## Phase 8 – Betrieb wie in echt

- [ ] Test- und Produktivumgebung trennen (Updates erst testen)
- [ ] Backup automatisieren, Kopie ausserhalb der VM, Restore-Übungen, Wiederherstellung dokumentieren
- [ ] Ausfallszenarien: DB weg, Schnittstelle hängt, Gerät liefert Unsinn
- [ ] Netzwerkzonen mit Firewall-VM (Labor, Geräte, KIS, Verwaltung)
- [ ] Doku: `docs/10-betrieb.md`

---

## Phase 9 – Eigener Server, echte Geräte & Automatisierung

**Server**
- [ ] Hardware: Minisforum UM790 Pro (64 GB, 1 TB) + Powerline
- [ ] Proxmox VE installieren
- [ ] debian-lab von VMware nach Proxmox migrieren (OVF, UEFI, Netzwerkname `ens33` → `ens18`)
- [ ] VM-Vorlagen (Templates)

**Echte Geräte (Raspberry Pi)**
- [ ] Gerätesimulator auf einen Pi umziehen, ASTM über echte serielle Leitung (USB-Seriell-Adapter, Nullmodem): Baudrate, ENQ/ACK, Prüfsummen
- [ ] Zweiter Pi als Netzwerkgerät: HL7 über TCP (MLLP), POCT-ähnlich
- [ ] Optional: Station Probeneingang mit USB-Barcodescanner (und Etikettendrucker)
- [ ] Optional: Pi im Kiosk-Modus als Wandbildschirm für die Live-Flow-Grafik
- [ ] Eigenes VLAN „Geräte“ (Managed Switch), Zugriffe über die Firewall geregelt
- [ ] Pis per LAN statt WLAN anbinden

**Automatisierung**
- [ ] Ansible: ganzes Lab per Befehl neu aufbauen – VMs (x86) und Pis (ARM); Richtung dann Repo → `/etc`
- [ ] Doku: `docs/11-proxmox-ansible.md`

---

## Snapshots (VMware)

| Name | Stand |
|---|---|
| `00-leer` | VM angelegt, Platte leer |
| `01-debootstrap` | Basissystem per debootstrap |
| `02-grundsystem` | Installation fertig, vor erstem Start |
| `03-erster-start` | startet, Netzwerk und sudo funktionieren |
| `04-ssh-updates` | SSH-Server, Paketquellen, System aktuell |
| `05-ssh-schluessel` | Schlüssel-Login, Passwort-/root-Login per SSH gesperrt |
| `07-phase1-fertig` | + Firewall, Zeitsynchronisation, Logs geprüft (`06-firewall` nicht angelegt) |
| `08-git` | git eingerichtet, Repo mit GitHub synchron |
| `09-apache` | Apache läuft, Port 80 offen |
| `10-vhost` | eigener Virtual Host `lis.lab.test`, Standard-Site aus |
| `11-php` | Sury-Quelle mit Pinning, PHP 8.5 FPM an Apache angebunden |
| `12-mariadb` | MariaDB installiert, Daten noch auf root-LV |
| `13-mariadb-lv` | eigenes LV für `/var/lib/mysql` |
| `14-db-benutzer` | `lab_test`, Anwendungsbenutzer `lis_web` |
| `15-db-testseite` | PHP-Treiber, DB-Testseite |
| `16-vor-kernel-6.12.116` | vor Kernel-Sicherheitsupdate |
| `17-pruefwerkzeuge` | Prüfwerkzeuge, Kernel 6.12.116, Spickzettel |
| `18-…` | Apache 2.4.71 und PHP 8.5.12-3 eingespielt |
| `19-backup-restore` | Backup-Ordner, `.my.cnf`, Restore geübt |
| `20-vor-kernel-6.12.117` | vor Kernel-Sicherheitsupdate |
| `21-kernel-6.12.117` | Kernel 6.12.117, alte Kernel entfernt, RAM 3 GB |
| `22-tls` | HTTPS, Weiterleitung, Port 443, `ServerTokens Prod` – **Phase 3 fertig** |
