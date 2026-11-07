# Linux-Lab & Mini-LIS

Privates Lern- und Portfolio-Projekt: eine kleine, realitätsnahe Spital- und Labor-IT in virtuellen Maschinen – von einem von Hand gebauten Debian bis zu einem eigenen Mini-Laborinformationssystem (LIS) mit Schnittstellen und Live-Visualisierung.

> **Hinweis:** Nur Lern- und Demozwecke, kein klinischer Einsatz. Alle Daten sind erfunden, es werden keine echten Patientendaten verwendet.

---

## Ziel

Jede Schicht einer Labor-IT selbst aufbauen und verstehen – statt fertige Installer und Images zu verwenden:

```
Order Entry ──► XML-Server ──► Datenbank ──► Gerätesimulator (ASTM/HL7)
                                   │
                                   ▼
               Validierung ──► Befund-PDF (SMB) ──► HL7 an simuliertes KIS

Jeder Baustein meldet Ereignisse (Auftragsnr., Zeitstempel, Station, Status)
──► Live-Flow-Visualisierung, Turnaround Time (TAT), Alarme
```

## Umgebung

| Komponente | Stand |
|---|---|
| Hypervisor | VMware Workstation Pro (später Proxmox VE) |
| VM `debian-lab` | Debian 13 „trixie“, manuell per debootstrap installiert, nur Konsole |
| Speicher | UEFI, GPT, LVM (`vg0`), ext4 |
| Zugang | SSH nur mit Ed25519-Schlüssel, root-Login gesperrt |
| Firewall | nftables, eingehend nur SSH und Ping |

## Stand

| Phase | Inhalt | Status |
|---|---|---|
| 0 | Grundsystem von Hand (debootstrap, chroot, LVM, GRUB) | ✅ |
| 1 | Basis-Administration (Updates, SSH, Firewall, Zeit, Logs) | ✅ |
| 2 | Versionierung und Doku (git, GitHub) | ✅ |
| 3 | Web- und Datenbank-Stack (Apache, PHP, MariaDB) | ⏳ |
| 4 | Windows Server, SMB, Active Directory | ⏳ |
| 5 | Container (Docker, Oracle Database Free) | ⏳ |
| 6 | Mini-LIS | ⏳ |
| 7 | Monitoring und Live-Visualisierung | ⏳ |
| 8 | Betrieb (Test/Prod, Backup/Restore, Ausfallszenarien) | ⏳ |
| 9 | Proxmox und Ansible | ⏳ |

Details: [`docs/00-fahrplan.md`](docs/00-fahrplan.md)

## Dokumentation

Jeder Schritt wird dokumentiert – mit Erklärung des Werkzeugs, Befehlen, erwarteter Ausgabe und Stolpersteinen.

| Datei | Inhalt |
|---|---|
| [`00-fahrplan.md`](docs/00-fahrplan.md) | Gesamtplan und Snapshots |
| [`01-grundsystem.md`](docs/01-grundsystem.md) | Debian 13 von Hand aufsetzen (ohne Installer) |
| [`02-basis-administration.md`](docs/02-basis-administration.md) | Updates, SSH mit Schlüssel, nftables, Zeitsynchronisation, journalctl |
| [`03-git-github.md`](docs/03-git-github.md) | git, GitHub per SSH, .gitignore, Lizenz, täglicher Ablauf |

## Grundsätze

- **Verstehen vor Automatisieren:** erst von Hand, dann dokumentieren, dann automatisieren.
- **Keine Geheimnisse im Repo:** Zugangsdaten nur lokal, im Repo höchstens als `*.example`-Vorlage (siehe `.gitignore`).
- **Nur erfundene Daten.**

## Lizenz

[MIT](LICENSE)
