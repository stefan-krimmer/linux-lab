# 01 – Debian 13 von Hand aufsetzen (ohne Installer)

Manuelle Installation eines konsolenbasierten Debian 13 („trixie“) in einer VMware-VM – mit UEFI, LVM, debootstrap und chroot. Ziel: verstehen, woraus ein Debian-System besteht, indem jede Schicht selbst eingerichtet wird.

```
UEFI → GRUB → Kernel → initramfs → LVM → ext4 → systemd → Login
```

---

## Inhalt

1. [Ausgangslage und VM](#1-ausgangslage-und-vm)
2. [Live-System starten](#2-live-system-starten)
3. [Umgebung prüfen und Werkzeuge holen](#3-umgebung-prüfen-und-werkzeuge-holen)
4. [Partitionieren mit fdisk](#4-partitionieren-mit-fdisk)
5. [LVM einrichten](#5-lvm-einrichten)
6. [Dateisysteme anlegen](#6-dateisysteme-anlegen)
7. [Einhängen (mount)](#7-einhängen-mount)
8. [Grundsystem mit debootstrap](#8-grundsystem-mit-debootstrap)
9. [In das neue System wechseln (chroot)](#9-in-das-neue-system-wechseln-chroot)
10. [Pakete installieren](#10-pakete-installieren)
11. [/etc/fstab](#11-etcfstab)
12. [Hostname und /etc/hosts](#12-hostname-und-etchosts)
13. [Zeitzone und Locale](#13-zeitzone-und-locale)
14. [root-Passwort und Benutzer](#14-root-passwort-und-benutzer)
15. [Netzwerk](#15-netzwerk)
16. [Bootloader GRUB](#16-bootloader-grub)
17. [Aufräumen und erster Start](#17-aufräumen-und-erster-start)
18. [Nach dem ersten Start](#18-nach-dem-ersten-start)
19. [Stolpersteine und Erkenntnisse](#19-stolpersteine-und-erkenntnisse)
20. [Snapshots](#20-snapshots)
21. [Nächste Schritte](#21-nächste-schritte)

---

## 1. Ausgangslage und VM

**Hypervisor:** VMware Workstation Pro
**ISO:** `debian-live-13.x.x-amd64-standard.iso` (Live-Variante **ohne** Desktop)
Quelle: <https://cdimage.debian.org/debian-cd/current-live/amd64/iso-hybrid/>

> Nicht den Ordner `current` (ohne „-live“) nehmen – dort liegt nur der normale Installer.

**VM anlegen:** File → New Virtual Machine → Custom

| Einstellung | Wert |
|---|---|
| Hardware compatibility | Standard (neueste Version) |
| Guest OS | Linux, Debian 13.x 64-bit |
| Name | `debian-lab` |
| Firmware | **UEFI**, Secure Boot **aus** |
| CPU | 1 Prozessor, 4 Kerne (max. Hälfte der logischen Kerne des Hosts) |
| RAM | 4096 MB (Host hat 16 GB) |
| Netzwerk | NAT |
| Controller / Disk | Empfehlung (SCSI) → Platte heisst später `/dev/sda` |
| Festplatte | 60 GB, „single file“, nicht vorab reservieren |
| CD/DVD | ISO einbinden |

**Firmware nachträglich prüfen/ändern:** VM → Settings → Options → Advanced → Firmware type: UEFI

---

## 2. Live-System starten

Bootmenü: **„Live system (amd64)“** → automatische Anmeldung als `user` (Passwort falls nötig: `live`).

**Anzeige vergrössern (VMware):** View → Stretch Guest, Vollbild mit `Strg + Alt + Enter`. Maus aus der VM lösen: `Strg + Alt`.

### Tastatur (Schweizer Layout)

`loadkeys` funktioniert im Live-System nicht (Keymap-Dateien fehlen). Stattdessen:

```bash
sudo -i                                   # root werden (Prompt: # statt $)
dpkg-reconfigure keyboard-configuration   # Menü
setupcon                                  # Einstellung anwenden – nicht vergessen!
```

Menüauswahl: Generic 105-key PC → German (Switzerland) → German (Switzerland) → The default for the keyboard layout → No compose key

| Befehl | Bedeutung |
|---|---|
| `sudo -i` | Login-Shell als root. `dpkg-reconfigure` liegt in `/usr/sbin` und ist nur im Pfad von root. |
| `dpkg-reconfigure <paket>` | Einrichtungsfragen eines installierten Pakets erneut stellen. Ergebnis landet in `/etc/default/keyboard`. |
| `setupcon` | liest diese Datei und wendet sie auf die laufende Konsole an |

> **Merke:** Das Live-System läuft im RAM. Nach einem Neustart sind Tastatur und installierte Pakete weg – Änderungen auf der Platte bleiben.

---

## 3. Umgebung prüfen und Werkzeuge holen

```bash
ls /sys/firmware/efi      # existiert nur bei UEFI-Start
ip a                      # Netzwerkkarten und IPs (VMware: meist ens33)
ping -c 3 deb.debian.org  # Internet + Namensauflösung
apt update                # Paketlisten laden (installiert nichts)
apt install -y debootstrap fdisk lvm2 dosfstools
```

| Paket | Zweck |
|---|---|
| `debootstrap` | baut ein minimales Debian in einem Ordner auf |
| `fdisk` | partitionieren |
| `lvm2` | LVM-Werkzeuge |
| `dosfstools` | enthält `mkfs.fat` für die EFI-Partition |

> Fehlt `/sys/firmware/efi`, ist die VM im BIOS-Modus gestartet → ausschalten, Firmware auf UEFI stellen, neu starten.

---

## 4. Partitionieren mit fdisk

**Ziel (GPT):**

| Partition | Grösse | Typ | Zweck |
|---|---|---|---|
| `/dev/sda1` | 512 MB | EFI System | Bootloader, von UEFI gelesen |
| `/dev/sda2` | Rest (~59.5 GB) | Linux LVM | Pool für alle weiteren Volumes |

```bash
lsblk              # Platte identifizieren (sda, 60G)
fdisk /dev/sda
```

Eingaben in fdisk (jeweils mit Enter):

| Eingabe | Wirkung |
|---|---|
| `m` | Hilfe anzeigen |
| `g` | neue GPT-Partitionstabelle |
| `n`, Enter, Enter, `+512M` | Partition 1 mit 512 MB |
| `t`, `uefi` | Typ „EFI System“ |
| `n`, Enter, Enter, Enter | Partition 2 mit dem Rest |
| `t`, `2`, `lvm` | Typ „Linux LVM“ |
| `p` | Tabelle anzeigen (kontrollieren!) |
| `w` | schreiben und beenden |
| `q` | beenden **ohne** Speichern |

> Die Meldung „Device does not contain a recognized partition table“ ist bei einer leeren Platte normal. Bis `w` wird nichts geschrieben.

---

## 5. LVM einrichten

**LVM (Logical Volume Manager)** ist eine flexible Schicht zwischen Platte und Dateisystem. Volumes lassen sich später vergrössern, neue Platten in den Pool aufnehmen und Snapshots erstellen.

| Ebene | Bedeutung | Hier |
|---|---|---|
| PV – Physical Volume | Rohmaterial | `/dev/sda2` |
| VG – Volume Group | gemeinsamer Speicherpool | `vg0` |
| LV – Logical Volume | herausgeschnittene Bereiche | `root`, `swap` |

Befehlsfamilie: `pv…`, `vg…`, `lv…` jeweils mit `create`, `s` (Übersicht), `display`, `extend`, `remove`.

```bash
pvcreate /dev/sda2              # sda2 als PV kennzeichnen
pvs

vgcreate vg0 /dev/sda2          # Volume Group vg0 aus sda2
vgs

lvcreate -L 20G -n root vg0     # -L Grösse, -n Name, aus vg0
lvcreate -L 2G  -n swap vg0
lvs
vgs                             # VFree: ~37.5G bewusst frei für später
```

---

## 6. Dateisysteme anlegen

| Ziel | Dateisystem | Grund |
|---|---|---|
| `/dev/vg0/root` | ext4 | Linux-Standard, robust |
| `/dev/sda1` | FAT32 | von UEFI vorgeschrieben |
| `/dev/vg0/swap` | swap | Auslagerungsspeicher |

```bash
mkfs.ext4 /dev/vg0/root
mkfs.fat -F 32 /dev/sda1
mkswap /dev/vg0/swap
lsblk -f                        # zeigt Dateisysteme und UUIDs
```

---

## 7. Einhängen (mount)

Linux hat **einen** Verzeichnisbaum ab `/`. Jedes Dateisystem wird an einem **Mountpoint** eingehängt.

```bash
mount /dev/vg0/root /mnt        # neues Root unter /mnt
mkdir -p /mnt/boot/efi          # -p: fehlende Zwischenordner mit anlegen
mount /dev/sda1 /mnt/boot/efi
findmnt -R /mnt                 # Kontrolle
```

> Achtung Tippfehler: `/mnt`, nicht `/mount`.

---

## 8. Grundsystem mit debootstrap

debootstrap lädt die Pakete mit Priorität „required“ und „important“, prüft Signaturen, entpackt und konfiguriert sie. Ergebnis: ein nacktes Debian **ohne** Kernel, Bootloader und Konfiguration.

```bash
debootstrap --arch amd64 trixie /mnt http://deb.debian.org/debian
ls /mnt
```

| Teil | Bedeutung |
|---|---|
| `--arch amd64` | 64-Bit-x86 |
| `trixie` | Codename Debian 13 (Debian 12 = bookworm) |
| `/mnt` | Zielordner |
| URL | Paketquelle |

Erwartet: `I: Base system installed successfully.`

---

## 9. In das neue System wechseln (chroot)

**Live-System vs. neues System**

| | Live-System | Neues System |
|---|---|---|
| Liegt | im RAM | auf der Platte |
| Zugriff | über `/mnt` | per `chroot /mnt` als `/` |
| Prompt | `root@debian:~#` | `root@debian:/#` |

**Bind-Mounts:** `/dev`, `/proc`, `/sys` werden vom Kernel bereitgestellt und im chroot gebraucht. Sie werden aus dem Live-System hineingespiegelt.

> **Reihenfolge ist entscheidend:** erst draussen vorbereiten, **dann** hinein.

```bash
# im Live-System:
mount --rbind /dev  /mnt/dev
mount --rbind /proc /mnt/proc
mount --rbind /sys  /mnt/sys
mount --make-rslave /mnt/dev
mount --make-rslave /mnt/proc
mount --make-rslave /mnt/sys
cp -L /etc/resolv.conf /mnt/etc/resolv.conf   # DNS für apt im chroot

chroot /mnt /bin/bash
cat /etc/debian_version                       # 13.x
```

| Befehl | Bedeutung |
|---|---|
| `--rbind` | rekursiver Bind-Mount: Ordner samt Unter-Mounts an zweiter Stelle zeigen |
| `--make-rslave` | Änderungen wirken nicht mehr aufs Original zurück. Ohne das schlägt späteres Aushängen mit „target is busy“ fehl, weil es auf das echte `/dev` übergreifen würde. |
| `cp -L` | Datei kopieren, Verweise (Symlinks) dabei auflösen |
| `chroot` | „change root“: Shell mit `/mnt` als neuer Wurzel |

---

## 10. Pakete installieren

Im chroot:

```bash
apt update
apt install -y linux-image-amd64 lvm2 grub-efi-amd64 \
               keyboard-configuration console-setup locales sudo vim
```

| Paket | Zweck |
|---|---|
| `linux-image-amd64` | Kernel inkl. initramfs |
| `lvm2` | damit das neue System sein LVM-Root beim Start findet |
| `grub-efi-amd64` | Bootloader für UEFI |
| `keyboard-configuration`, `console-setup` | Tastatur und Konsolenschrift, dauerhaft |
| `locales` | Sprache, Zeichensatz, Formate |
| `sudo` | Admin-Rechte für normale Benutzer |
| `vim` | Editor (statt vim-tiny) |

Menüs während der Installation: Tastatur wie oben, Encoding **UTF-8**, Character set **Guess optimal**, Font **Terminus 16x32**.

> Meldung „/proc is not mounted“ → Bind-Mounts fehlten. Reparatur: raus, Mounts setzen, wieder hinein, dann `dpkg --configure -a` und den Installationsbefehl erneut ausführen.

---

## 11. /etc/fstab

Die **file system table** sagt dem System beim Start, was wo eingehängt wird.

| Spalte | Bedeutung |
|---|---|
| 1 Gerät | was |
| 2 Mountpoint | wohin |
| 3 Typ | Dateisystem |
| 4 Optionen | z. B. `errors=remount-ro` |
| 5 dump | veraltet, immer `0` |
| 6 pass | Prüfreihenfolge: `1` root, `2` andere, `0` keine |

LVM-Volumes haben feste Namen (`/dev/mapper/vg0-root`). Normale Partitionen per **UUID** ansprechen, da sich `/dev/sdX` verschieben kann.

```bash
blkid                                              # alle UUIDs
blkid -s UUID -o value /dev/sda1 >> /etc/fstab     # UUID anhängen, nicht abtippen
vim /etc/fstab
```

Endergebnis:

```
/dev/mapper/vg0-root  /          ext4  errors=remount-ro  0  1
/dev/mapper/vg0-swap  none       swap  sw                 0  0
UUID=XXXX-XXXX        /boot/efi  vfat  umask=0077         0  2
```

```bash
findmnt --verify     # erwartet: 0 parse errors, 0 errors
```

**`umask=0077`:** FAT kennt keine Linux-Rechte; die umask legt sie beim Einhängen fest. Rechte als Zahl: r=4, w=2, x=1. `777 − 077 = 700` → nur root hat Zugriff.

### vim – verwendete Befehle

| Taste(n) | Wirkung |
|---|---|
| `i` | Einfügen vor dem Cursor |
| `I` (Shift+i) | Einfügen am Zeilenanfang |
| `A` (Shift+a) | Einfügen am Zeilenende |
| `o` / `O` | neue Zeile darunter / darüber |
| `Esc` | zurück in den Normalmodus |
| `dd` | Zeile löschen (`4dd` = 4 Zeilen) |
| `x` | Zeichen unter dem Cursor löschen |
| `f<z>` | zum nächsten Zeichen `<z>` in der Zeile springen |
| `gg` / `G` / `8G` | Anfang / Ende / Zeile 8 |
| `dG` | ab Cursor alles bis zum Ende löschen |
| `u` | rückgängig |
| `:wq` | speichern und beenden |
| `:q!` | beenden ohne Speichern |

Befehle wie `I`, `A`, `dd` werden im **Normalmodus** gedrückt – sie erscheinen nicht als Text.

---

## 12. Hostname und /etc/hosts

| Datei | Inhalt |
|---|---|
| `/etc/hostname` | nur der Name des Rechners |
| `/etc/hosts` | lokale Namensliste, wird vor DNS geprüft |

```bash
echo debian-lab > /etc/hostname
```

`/etc/hosts` (existierte bereits von debootstrap):

```
127.0.0.1 localhost
127.0.1.1 debian-lab
::1 localhost ip6-localhost ip6-loopback
ff02::1 ip6-allnodes
ff02::2 ip6-allrouters
```

| Operator | Wirkung |
|---|---|
| `>` | Ausgabe in Datei, **überschreibt** |
| `>>` | Ausgabe ans Dateiende **anhängen** |

> Jedes `>>` hängt eine weitere Zeile an – Befehle mit `>>` nicht mehrfach ausführen. Name in `hostname` und `hosts` muss identisch sein.

---

## 13. Zeitzone und Locale

```bash
dpkg-reconfigure tzdata     # Europe → Zurich
dpkg-reconfigure locales    # de_CH.UTF-8 und en_US.UTF-8 mit Leertaste markieren, Tab → Ok
                            # Standard: en_US.UTF-8
```

Englisch als Systemsprache: Fehlermeldungen lassen sich 1:1 im Internet finden. `de_CH` ist trotzdem verfügbar.

In Auswahllisten: Pfeiltasten bewegen, **Leertaste** setzt `[*]`, **Tab** springt zu `<Ok>`.

---

## 14. root-Passwort und Benutzer

```bash
passwd                                  # root-Passwort (Eingabe bleibt unsichtbar)
useradd -m -s /bin/bash -G sudo stefan  # Benutzer mit Home, bash, Gruppe sudo
passwd stefan
id stefan                               # muss "sudo" enthalten
```

| Option | Bedeutung |
|---|---|
| `-m` | Home-Verzeichnis anlegen |
| `-s /bin/bash` | Login-Shell |
| `-G sudo` | Mitglied der Gruppe sudo |

---

## 15. Netzwerk

DHCP über ifupdown (`/etc/network/interfaces`) plus DHCP-Client.

```bash
apt install -y dhcpcd-base      # Alternative: isc-dhcp-client
ip -br link                     # Name der Karte, hier ens33
vim /etc/network/interfaces
```

```
auto lo
iface lo inet loopback

allow-hotplug ens33
iface ens33 inet dhcp
```

---

## 16. Bootloader GRUB

**Startablauf:** UEFI lädt GRUB von der EFI-Partition → GRUB lädt Kernel + initramfs → initramfs aktiviert LVM und hängt root ein → systemd übernimmt.

```bash
update-initramfs -u -k all
grub-install --target=x86_64-efi --efi-directory=/boot/efi --bootloader-id=debian
grub-install --target=x86_64-efi --efi-directory=/boot/efi --removable
update-grub
ls /boot/efi/EFI        # erwartet: BOOT  debian
```

| Befehl | Zweck |
|---|---|
| `update-initramfs -u -k all` | Startsystem neu bauen (stellt sicher, dass LVM enthalten ist) |
| `grub-install … --bootloader-id=debian` | GRUB installieren und UEFI-Booteintrag „debian“ anlegen |
| `grub-install … --removable` | zusätzlich als `EFI/BOOT/BOOTX64.EFI` – Fallback, den jede UEFI-Firmware startet |
| `update-grub` | Bootmenü aus den installierten Kerneln erzeugen |

Die Warnung zu `os-prober` ist harmlos (keine anderen Betriebssysteme vorhanden).

---

## 17. Aufräumen und erster Start

```bash
exit                # chroot verlassen
umount -R /mnt      # alles sauber aushängen
poweroff            # nicht reboot – zuerst ISO entfernen
```

In VMware: Settings → CD/DVD → „Connect at power on“ deaktivieren → Snapshot `02-grundsystem` → starten.

GRUB-Menü → Debian startet → `debian-lab login:` → Anmeldung als `stefan`.

---

## 18. Nach dem ersten Start

**Hostname war noch `debian`** (Datei wurde nicht korrekt im chroot geschrieben). `hostnamectl` scheiterte:

```
Failed to connect to system scope bus via local transport
```

Grund: `hostnamectl` kommuniziert über **D-Bus** mit systemd – D-Bus war in der Minimalinstallation nicht vorhanden. Lösung von Hand:

```bash
sudo -i
echo debian-lab > /etc/hostname   # dauerhaft
hostname debian-lab               # sofort, ohne D-Bus
apt install -y dbus               # damit hostnamectl, timedatectl usw. funktionieren
```

Die Warnung `sudo: unable to resolve host debian` verschwindet, sobald Hostname und `/etc/hosts` übereinstimmen.

Funktionstests:

```bash
sudo -i                       # fragt nach dem eigenen Passwort
ip a                          # ens33 mit IP
ping -c 3 deb.debian.org
```

---

## 19. Stolpersteine und Erkenntnisse

| Problem | Ursache | Lösung |
|---|---|---|
| `loadkeys ch/sg` – file not found | Keymaps fehlen im Live-System | `dpkg-reconfigure keyboard-configuration` + `setupcon` |
| `dpkg-reconfigure: command not found` | als normaler Benutzer, `/usr/sbin` nicht im Pfad | zuerst `sudo -i` |
| Tastatur nach Konfiguration unverändert | `setupcon` vergessen | `setupcon` ausführen |
| `/sys/firmware/efi` fehlt | VM im BIOS-Modus | Firmware auf UEFI umstellen |
| `mkfs.fat: command not found` | Paket fehlt | `apt install dosfstools` |
| `mount point does not exist` | Tippfehler `/mount` statt `/mnt` | Pfad korrigieren |
| `/mnt/dev does not exist` | chroot **vor** den Bind-Mounts | `exit`, Mounts setzen, dann chroot |
| `/proc is not mounted` | `/dev` versehentlich nach `/mnt/proc` gespiegelt | `findmnt` prüfen, `--make-rslave`, `umount -R`, neu mounten |
| `umount: target is busy` | Mount-Propagation zum Original | vorher `mount --make-rslave` |
| doppelte Zeilen in `/etc/hosts` | `>>` mehrfach ausgeführt | Datei löschen und neu schreiben bzw. mit vim bereinigen |
| `hostnamectl` – bus error | D-Bus nicht installiert | `hostname` + Datei, dann `apt install dbus` |

**Grundmuster unter Linux:** Konfigurationsdatei ändern → Einstellung anwenden bzw. Dienst neu laden.

**Hilfe finden:** `m` bzw. `--help` im Werkzeug, `man <befehl>` (Beenden mit `q`).

---

## 20. Snapshots

| Name | Stand |
|---|---|
| `00-leer` | VM angelegt, Platte leer |
| `01-debootstrap` | Grundsystem auf der Platte (optional) |
| `02-grundsystem` | Installation fertig, vor dem ersten Start |
| `03-erster-start` | System startet, Netzwerk und sudo funktionieren |

Übersicht: VM → Snapshot → Snapshot Manager (`Strg + M`).

---

## 21. Nächste Schritte

- [ ] Paketquellen um Security-Updates ergänzen, System aktualisieren
- [ ] SSH-Server, Zugriff per PuTTY, später Schlüssel statt Passwort
- [ ] Firewall mit nftables
- [ ] Konsolenauflösung dauerhaft über GRUB (`video=…`)
- [ ] git-Repository anlegen, diese Doku einchecken
- [ ] Apache, PHP, MariaDB
- [ ] Windows Server als zweite VM, SMB in beide Richtungen, später AD
- [ ] Docker (Oracle Database Free als Container)
- [ ] Mini-Laborprozess: Order Entry → XML-Server → Gerätesimulator → Validierung → Befund-PDF per SMB → Schnittstelle zum „KIS“
