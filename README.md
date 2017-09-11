AVM Fritzbox PHP Module für IP-Symcon
===
Dieses IP-Symcon PHP Modul integriert Informationen einer beliebigen Fritzbox von AVM in eine bestehende IP-Symcon Installation.
Außerdem werden applikationsweite Methoden zur Steuerung bereitgestellt.

Das Modul verwendet die Fritzbox API Klasse von Gregor Nathanael Meyer (Gregor [at] der-meyer.de)welche unter Creative Commons freigegeben wurde.

**Content**

1. [Funktionsumfang](#1-funktionsumfang)
2. [Anforderungen](#2-anforderungen)
3. [Vorbereitung & Installation & Konfiguration](#3-vorbereitung--installation--konfiguration)
4. [Funktionen](#6-funktionen)

## 1. Funktionsumfang  
Die folgenden Funktionalitäten sind implementiert:
- Anrufliste
  - Abhängig von bereits im System bestehenden Timestamp
  - Abhören der Mailboxnachrichten
- Aktivieren von WLAN und Gast WLAN
  - automatisches generieren von WPA Passwörtern des Gast WLAN's
- Reboot der Fritzbox
- Reconnect der Internetverbindung
- Internet Verbindungsstatus und Verbindungsgeschwindigkeiten
- Externe IP-Adresse

## 2. Anforderungen
- IP-Symcon 4.x installation (Linux / Windows)
- Bereits bestehende Plex Home Theater Instanz
  - Windows
  - Linux
  - OSX
  - Rasplex
- Netzwerkverbindung zu einer Fritzbox

## 3. Vorbereitung & Installation & Konfiguration

### Installation in IPS 4.x
Im "Module Control" (Kern Instanzen->Modules) die URL "git://github.com/daschaefer/SymconFritzbox.git" hinzufügen.  
Danach ist es möglich eine neue Fritzbox Instanz innerhalb des Objektbaumes von IP-Symcon zu erstellen.
### Konfiguration
**IP-Adresse:**

*Die IP-Adresse/Hostname der Fritzbox. Default: fritz.box (muss in der Regel nicht geändert werden)*

**Benutzername:**

*Der Benutzername mit dem sich das Modul an der Fritzbox zur Datenkommunikation anmeldet. Default: user@user.com (muss in der Regel nicht geändert werden)*

**Passwort:**

*Das Passwort der Weboberfläche der Fritzbox.*

## 4. Funktionen

```php
FBX_DetailsForPhoneNumber(InstanceID: Integer, phoneNumber: Variant)
```
Rückwärtssuche einer Telefonnummer

```php
FBX_DisableCallDiversion(InstanceID: Integer)
```
Rufweiterleitungen deaktivieren

```php
FBX_EnableCallDiversion(InstanceID: Integer, diversionNumber: Variant)
```
Rufweiterleitungen aktivieren

```php
FBX_GetAmountOfMessages(InstanceID: Integer)
```
Anzahl Mailboxnachrichten ausgeben

```php
FBX_GetAmountOfMissedCalls(InstanceID: Integer)
```
Anzahl verpasster Anrufe ausgeben

```php
FBX_Restart(InstanceID: Integer)
```
Fritzbox neustarten

```php
FBX_Reconnect(InstanceID: Integer)
```
Internetverbindung trennen und neu aufbauen

```php
FBX_SetWifiState(InstanceID: Integer)
```
WLAN Konfigurieren