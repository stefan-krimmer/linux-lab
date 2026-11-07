# 00 – Fahrplan: Linux-Lab & Mini-LIS

Privates Lern- und Portfolio-Projekt: eine kleine, realitätsnahe Spital-/Labor-IT in VMs – von einem von Hand gebauten Debian bis zu einem eigenen Mini-LIS mit Schnittstellen und Live-Visualisierung.

> Nur Lern- und Demozwecke, kein klinischer Einsatz. Ausschliesslich erfundene Daten.

---

## Leitlinien

- **Verstehen vor Automatisieren:** erst von Hand, dann dokumentieren, dann automatisieren.
- **Jeder Schritt wird dokumentiert:** `docs/NN-thema.md` (Doku-Nummer ≠ Phasen-Nummer) mit Werkzeug-Erklärung, Befehlen, erwarteter Ausgabe und Stolpersteinen.
- **Snapshot vor jedem grösseren Schritt:** Benennung `NN-beschreibung`.
- **Ereignis-Logging von Anfang an:** Jeder LIS-Baustein meldet Auftragsnummer, Zeitstempel, Station und Status.
- **Keine Geheimnisse und keine Arbeitgeber-Interna im Repo.**

---

## Phase 0 – Grundsystem ✅

- [x] VM in VMware Workstation Pro (UEFI, 4 Kerne, 4 GB RAM, 60 GB)
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
- [ ] RAM auf dem Windows-Host freiräumen (Autostart-Programme)
- [x] Doku: `docs/02-basis-administration.md`

---

## Phase 2 – Versionierung & Doku ✅

- [x] git installieren und konfigurieren (noreply-Adresse, `main`)
- [x] GitHub-Repo `stefan-krimmer/linux-lab` (öffentlich), `.gitignore`, MIT-Lizenz
- [x] README: Ziel, Architekturdiagramm, Stand, Hinweis „nur Demo/erfundene Daten“
- [x] `docs/` mit 00 bis 02 eingecheckt
- [x] Eigener SSH-Schlüssel der VM für GitHub
- [x] Repo auf GitHub-Profil angepinnt
- [ ] Auf LinkedIn unter „Im Fokus“ verlinken – erst wenn der Web-/DB-Stack läuft
- [x] Doku: `docs/03-git-github.md`

---

## Phase 3 – Web- und Datenbank-Stack

- [ ] Apache installieren, eigener Virtual Host
- [ ] Konfigurationen im Repo versionieren (ohne Passwörter, `*.example`)
- [ ] Firewall um Port 80/443 erweitern
- [ ] PHP (Version bewusst wählen und dokumentieren)
- [ ] MariaDB: Installation, `mariadb-secure-installation`, Benutzer und Rechte
- [ ] Eigenes LV für Datenbankdaten anlegen (Übung: LVM erweitern)
- [ ] Backup und Restore der Datenbank üben (`mariadb-dump`)
- [ ] TLS mit selbstsigniertem Zertifikat
- [ ] Doku: `docs/04-web-db.md`

---

## Phase 4 – Windows Server & SMB

- [ ] Windows Server (Evaluation) als zweite VM, möglichst Server Core
- [ ] Beide VMs ins gleiche virtuelle Netz, feste IPs
- [ ] Debian als SMB-Server (Samba), Zugriff von Windows
- [ ] Windows als SMB-Server, Einhängen unter Debian (`cifs`, fstab)
- [ ] Active Directory: Windows Server als Domänencontroller
- [ ] Debian der Domäne beitreten lassen, Anmeldung mit AD-Benutzer
- [ ] Freigabe-Rechte über AD-Gruppen
- [ ] Doku: `docs/05-windows-smb-ad.md`

---

## Phase 5 – Container

- [ ] Docker installieren, Grundlagen (Image, Container, Volume, Netzwerk)
- [ ] Docker Compose
- [ ] Oracle Database Free als Container, Vergleich mit MariaDB
- [ ] Doku: `docs/06-container.md`

---

## Phase 6 – Mini-LIS

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

**Doku:** `docs/07-mini-lis.md` plus Architektur- und Datenflussdiagramm

---

## Phase 7 – Monitoring & Live-Visualisierung

- [ ] Ereignistabelle bzw. Event-Stream aller Bausteine
- [ ] Kleiner Server, der Ereignisse per WebSocket an den Browser liefert
- [ ] Live-Flow-Grafik: Aufträge wandern durch die Stationen, Staus werden rot
- [ ] Klick auf Auftrag zeigt den vollständigen Weg (Rückverfolgung)
- [ ] Turnaround Time (TAT) live berechnen, getrennt nach Station (Notfall vs. Routine)
- [ ] Alarme mit Schwellenwerten (Warteschlange, Inaktivität)
- [ ] Prometheus und Grafana für technische Kennzahlen
- [ ] Doku: `docs/08-monitoring.md`

---

## Phase 8 – Betrieb wie in echt

- [ ] Test- und Produktivumgebung trennen (Updates erst testen)
- [ ] Backup- und Restore-Übungen, Wiederherstellung dokumentieren
- [ ] Ausfallszenarien: DB weg, Schnittstelle hängt, Gerät liefert Unsinn
- [ ] Netzwerkzonen mit Firewall-VM (Labor, Geräte, KIS, Verwaltung)
- [ ] Doku: `docs/09-betrieb.md`

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
- [ ] Ansible: ganzes Lab per Befehl neu aufbauen – VMs (x86) und Pis (ARM)
- [ ] Doku: `docs/10-proxmox-ansible.md`

---

## Snapshots (VMware)

| Name | Stand |
|---|---|
| `00-leer` | VM angelegt, Platte leer |
| `02-grundsystem` | Installation fertig, vor erstem Start |
| `03-erster-start` | startet, Netzwerk und sudo funktionieren |
| `04-ssh-updates` | SSH-Server, Paketquellen, System aktuell |
| `05-ssh-schluessel` | Schlüssel-Login, Passwort-/root-Login per SSH gesperrt |
| `06-firewall` | nftables aktiv und beim Booten geladen |
| `07-phase1-fertig` | + Zeitsynchronisation, Logs geprüft |
| `08-git` | git eingerichtet, Repo mit GitHub synchron |
