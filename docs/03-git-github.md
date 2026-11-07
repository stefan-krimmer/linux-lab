# 03 – Versionierung mit git und GitHub

Das Lab bekommt ein Gedächtnis: Alle Dokumentation (und später Konfigurationen und Code) liegt in einem git-Repository auf der VM und wird in ein öffentliches GitHub-Repo hochgeladen.

```
Laptop ──(PuTTY-Schlüssel)──► VM ~/linux-lab ──(VM-Schlüssel, git push)──► github.com/stefan-krimmer/linux-lab
```

Alle Linux-Schritte passieren im **fertigen System** als Benutzer **`stefan`** (nicht root).

---

## Inhalt

1. [Das Werkzeug: git](#1-das-werkzeug-git)
2. [git installieren und konfigurieren](#2-git-installieren-und-konfigurieren)
3. [Repository anlegen](#3-repository-anlegen)
4. [.gitignore – keine Geheimnisse im Repo](#4-gitignore--keine-geheimnisse-im-repo)
5. [Dateien von Windows übertragen (pscp)](#5-dateien-von-windows-übertragen-pscp)
6. [Staging und erster Commit](#6-staging-und-erster-commit)
7. [SSH-Schlüssel der VM für GitHub](#7-ssh-schlüssel-der-vm-für-github)
8. [GitHub-Repo anlegen und hochladen](#8-github-repo-anlegen-und-hochladen)
9. [Lizenz und git pull](#9-lizenz-und-git-pull)
10. [Täglicher Ablauf (Spickzettel)](#10-täglicher-ablauf-spickzettel)
11. [Stolpersteine und Erkenntnisse](#11-stolpersteine-und-erkenntnisse)
12. [Snapshot](#12-snapshot)
13. [Nächste Schritte](#13-nächste-schritte)

---

## 1. Das Werkzeug: git

**git** ist ein Versionsverwaltungssystem. Jeder gespeicherte Stand (**Commit**) enthält Zeitpunkt, Autor und Beschreibung; jeder frühere Stand bleibt abrufbar.

| Begriff | Bedeutung |
|---|---|
| Repository (Repo) | überwachter Projektordner, Geschichte im versteckten Ordner `.git` |
| Commit | gespeicherter Stand mit Beschreibung und eindeutiger ID |
| Staging (`git add`) | Änderungen für den nächsten Commit vormerken |
| Remote | Kopie des Repos auf einem Server (hier GitHub, Name `origin`) |
| push / pull | Commits hochladen / herunterladen |
| Branch | Entwicklungszweig, Hauptzweig heisst `main` |

```
Arbeitsordner ──git add──► Staging ──git commit──► lokale Geschichte ──git push──► GitHub
                                                                   ◄──git pull───
```

**git vs. GitHub:** git ist das Programm (läuft lokal), GitHub ein Onlinedienst, der Repos speichert und anzeigt.

**git vs. VMware-Snapshot:** Ein Snapshot setzt die ganze VM zurück, git einzelne Dateien.

**Warum vor Apache?** Ab dem Web-/DB-Stack entstehen viele eigene Dateien – sie sollen von Anfang an versioniert sein.

---

## 2. git installieren und konfigurieren

```bash
sudo -i
apt install -y git
exit                    # zurück zu stefan – git als normaler Benutzer
git --version           # git version 2.47.x
```

Jeder Commit trägt Name und E-Mail. Da das Repo öffentlich ist, wird die **anonyme noreply-Adresse von GitHub** verwendet (GitHub → Settings → Emails → „Keep my email addresses private“ und „Block command line pushes that expose my email“).

```bash
git config --global user.name "Stefan Krimmer"
git config --global user.email "353825100+stefan-krimmer@users.noreply.github.com"
git config --global init.defaultBranch main
git config --global --list
```

| Teil | Bedeutung |
|---|---|
| `--global` | gilt für alle Repos des Benutzers, gespeichert in `~/.gitconfig` |
| `user.email` | erscheint öffentlich in jedem Commit |
| `init.defaultBranch main` | Hauptzweig heisst `main` (GitHub-Standard) statt `master` |

**Warum nicht die private E-Mail?** Commit-Daten öffentlicher Repos sind frei abrufbar und werden gezielt nach Adressen durchsucht (Spam, Phishing). Eine einmal hochgeladene Adresse bleibt in der Geschichte. Die noreply-Adresse verknüpft Commits trotzdem mit dem Profil. Die Registrierungs-E-Mail des GitHub-Kontos ist davon unabhängig und nicht öffentlich.

---

## 3. Repository anlegen

```bash
mkdir -p ~/linux-lab/docs
cd ~/linux-lab
git init                # Initialized empty Git repository in /home/stefan/linux-lab/.git/
ls -la                  # .git und docs
git status              # On branch main, No commits yet
```

Leere Ordner zählen für git nicht – es verwaltet nur Dateien.

---

## 4. .gitignore – keine Geheimnisse im Repo

`.gitignore` listet Dateien, die git **nie** übernimmt – auch nicht bei `git add .`.

```bash
vim .gitignore          # :set paste, i, Shift+Rechtsklick, Esc, :wq
```

```
# Geheimnisse – niemals ins Repo
*.env
.env*
*.key
*.pem
*.ppk
secrets/
*passw*

# Echte Konfigurationen mit Zugangsdaten (nur *.example einchecken)
*.local.conf

# Datenbank-Dumps und Backups
*.sql.gz
*.dump
backup/

# Logs und temporäre Dateien
*.log
*.tmp
*.swp
*~
```

| Muster | Bedeutung |
|---|---|
| `*.key` | alle Dateien mit dieser Endung |
| `.env*` | Dateien, die so beginnen |
| `secrets/` | ganzer Ordner (Schrägstrich am Ende) |
| `*.swp` | Zwischendateien von vim |

**Prinzip für später:** Konfigurationen mit Passwörtern nur als Vorlage einchecken (`db.conf.example` mit Platzhalter), die echte Datei bleibt lokal.

---

## 5. Dateien von Windows übertragen (pscp)

**pscp** gehört zu PuTTY und kopiert Dateien über SSH – gleicher Schlüssel, gleicher Port 22, keine neue Firewall-Regel.

Windows-Eingabeaufforderung:

```
mkdir C:\lab
cd C:\lab
dir
pscp -i "C:\Users\<name>\Documents\SSH\debian-lab.ppk" README.md stefan@192.168.38.129:linux-lab/
pscp -i "C:\Users\<name>\Documents\SSH\debian-lab.ppk" 0*.md stefan@192.168.38.129:linux-lab/docs/
```

| Teil | Bedeutung |
|---|---|
| `-i "…ppk"` | privater Schlüssel (Pfad: Shift + Rechtsklick auf die Datei → „Als Pfad kopieren“) |
| `0*.md` | alle Dateien, die mit `0` beginnen und auf `.md` enden |
| `stefan@IP:` | Benutzer und Rechner, der Doppelpunkt trennt den Pfad ab |
| `linux-lab/docs/` | Ziel relativ zum Home von stefan |

Erwartet pro Datei: `… | 100%`. Lange Dateinamen werden in der Anzeige abgeschnitten – die Datei heisst trotzdem richtig.

---

## 6. Staging und erster Commit

```bash
git status              # Untracked files: .gitignore, README.md, docs/
git add .
git status              # Changes to be committed: new file: …
```

> **Kontrollmoment:** Vor jedem Commit zeigt `git status`, was ins Repo kommt. Eine versehentlich vorgemerkte Datei nimmt `git rm --cached <datei>` wieder heraus.

```bash
git commit -m "Grundsystem und Basis-Administration dokumentiert"
git log
```

| Ausgabe | Bedeutung |
|---|---|
| `root-commit` | erster Commit ohne Vorgänger |
| `a2e8259` | Kurzform der Commit-ID |
| `HEAD -> main` | aktueller Standort: neuester Commit auf `main` |
| `100644` | normale Datei (`rw-r--r--`) |

**Commit-Nachrichten:** kurz, „was wurde gemacht“, z. B. „Firewall-Regel für HTTP ergänzt“.

---

## 7. SSH-Schlüssel der VM für GitHub

Bisher: Laptop → VM. Jetzt: **VM → GitHub**. Die VM braucht einen eigenen Schlüssel (pro Gerät ein Schlüssel – bei Verlust nur diesen sperren).

```bash
ssh-keygen -t ed25519 -C "stefan@debian-lab"
```

| Frage | Eingabe |
|---|---|
| Speicherort | Enter (`~/.ssh/id_ed25519`) |
| Passphrase | eine Passphrase (wird bei jedem `push` abgefragt) |

| Datei | Inhalt |
|---|---|
| `~/.ssh/id_ed25519` | **privat**, verlässt die VM nie |
| `~/.ssh/id_ed25519.pub` | öffentlich, kommt zu GitHub |

Das **randomart** am Ende ist eine Bildversion des Fingerprints – nicht geheim, nur eine Wiedererkennungshilfe.

```bash
cat ~/.ssh/id_ed25519.pub     # ⚠️ mit .pub!
```

GitHub → Settings → **SSH and GPG keys** → **New SSH key** → Title `debian-lab`, Typ Authentication Key → Zeile einfügen → **Add SSH key**.

**Verbindung testen:**

```bash
ssh -T git@github.com
```

Beim ersten Mal fragt ssh nach dem Host-Schlüssel. GitHubs veröffentlichter ED25519-Fingerprint:

```
SHA256:+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU
```

Stimmt er → `yes`. Er wird in `~/.ssh/known_hosts` gespeichert.

Erwartet (= **Erfolg**, obwohl es nach Fehler klingt):

```
Hi stefan-krimmer! You've successfully authenticated, but GitHub does not provide shell access.
```

| Teil | Bedeutung |
|---|---|
| `-T` | kein Terminal anfordern, nur Anmeldung testen |
| `git@github.com` | alle verbinden sich als `git`, GitHub erkennt den Nutzer am Schlüssel |

---

## 8. GitHub-Repo anlegen und hochladen

GitHub → **+** → **New repository**:

| Feld | Wert |
|---|---|
| Name | `linux-lab` |
| Sichtbarkeit | Public |
| README / .gitignore / License | **alles leer lassen** |

> ⚠️ Legt GitHub eigene Dateien an, hat das Remote einen Commit, den die VM nicht kennt – der erste `push` wird abgelehnt.

Auf der „Quick setup“-Seite **SSH** wählen (nicht HTTPS): `git@github.com:stefan-krimmer/linux-lab.git`

```bash
git remote add origin git@github.com:stefan-krimmer/linux-lab.git
git remote -v
git push -u origin main
```

| Teil | Bedeutung |
|---|---|
| `remote add origin …` | Remote unter dem Namen `origin` bekannt machen |
| `-u` | Verbindung merken, künftig reicht `git push` / `git pull` |

Erwartet: `* [new branch] main -> main`. Im Browser zeigt GitHub das README als Startseite.

---

## 9. Lizenz und git pull

**MIT-Lizenz:** Jeder darf alles damit machen, solange Copyright-Hinweis und Lizenztext erhalten bleiben; keine Haftung („as is“). Kurz, verbreitet, passt für Code und Lernprojekte.

| Lizenz | Kernidee |
|---|---|
| MIT | alles erlaubt, Namensnennung, keine Haftung |
| GPL | veränderte Weitergabe muss ebenfalls offen sein (Copyleft) |
| Apache 2.0 | wie MIT, regelt zusätzlich Patente |
| CC BY 4.0 | für Texte/Bilder, Namensnennung |
| keine | nur ansehen erlaubt |

Angelegt **auf GitHub**: Add file → Create new file → Name `LICENSE` → Choose a license template → MIT → Jahr und Name → Commit.

Damit hat GitHub einen Commit, den die VM nicht kennt → herunterholen:

```bash
git pull
git log --oneline
ls                      # docs  LICENSE  README.md
```

Erwartet: `Fast-forward` – lokal gab es nichts Neues, git schiebt den Zeiger einfach vor.

**Profil:** Profil → Customize your pins → `linux-lab` anpinnen.

---

## 10. Täglicher Ablauf (Spickzettel)

```bash
cd ~/linux-lab
git pull                          # 1. aktuellen Stand holen
# … Dateien ändern …
git status                        # 2. was hat sich geändert?
git diff                          #    Änderungen im Detail (Beenden mit q)
git add <datei>  oder  git add .  # 3. vormerken
git status                        # 4. kontrollieren – keine Geheimnisse?
git commit -m "Was wurde gemacht"  # 5. speichern
git push                          # 6. hochladen
```

| Befehl | Zweck |
|---|---|
| `git log --oneline` | Geschichte kompakt |
| `git diff --staged` | was im nächsten Commit landet |
| `git restore <datei>` | lokale Änderung verwerfen (Vorsicht!) |
| `git rm --cached <datei>` | Datei aus dem Staging nehmen, bleibt auf der Platte |

---

## 11. Stolpersteine und Erkenntnisse

| Problem | Ursache | Lösung |
|---|---|---|
| `cd C:\lab` → „Das System kann den angegebenen Pfad nicht finden.“ | Ordner existierte noch nicht | `mkdir C:\lab` |
| Unsicherheit beim randomart | Bildversion des Fingerprints | nicht geheim, kein Handlungsbedarf |
| „GitHub does not provide shell access“ | keine Shell bei GitHub | bedeutet **Erfolg** |
| `02-basis-administration.m` in der pscp-Anzeige | Spaltenbreite | Datei heisst richtig |
| Gefahr: falscher Schlüssel kopiert | `id_ed25519` statt `id_ed25519.pub` | immer `.pub` prüfen |
| Gefahr: push abgelehnt | Repo auf GitHub mit README/Lizenz angelegt | Repo leer anlegen bzw. zuerst `git pull` |

**Erkenntnisse**

- **Pro Richtung, pro Gerät ein Schlüssel:** Laptop → VM (PuTTY), VM → GitHub (ssh-keygen).
- **Host-Fingerprint beim ersten Kontakt prüfen** – danach vergleicht ssh automatisch.
- **`git status` vor jedem Commit** ist die letzte Kontrolle gegen Geheimnisse im Repo.
- **Öffentlich heisst dauerhaft:** E-Mail und Inhalte bleiben in der Geschichte.

---

## 12. Snapshot

| Name | Stand |
|---|---|
| `08-git` | git eingerichtet, VM-Schlüssel bei GitHub, Repo `linux-lab` synchron |

---

## 13. Nächste Schritte

- [ ] Phase 3: Apache, PHP, MariaDB – Konfigurationen direkt im Repo versionieren
- [ ] Firewall um Port 80/443 erweitern
- [ ] Repo auf LinkedIn verlinken, sobald der Web-/DB-Stack läuft
