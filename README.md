# Laravel Talario

Laravel-Mail-Transport f�r die Talario-Versand-API. Nach Installation und Konfiguration
verwenden bestehende Mailables und Mail-Notifications Talario �ber HTTPS.

**Entwicklungsstand:** erster Transport f�r den strukturierten Versandvertrag v1 vom
17.10.2026. Noch kein ver�ffentlichtes Release und noch kein Packagist-Eintrag.
Die �bernahme durch Talario ist keine Best�tigung der Zustellung beim Empf�nger.

## Voraussetzungen

- PHP ab 8.2, Laravel 11 oder 12; Laravel 13 ben�tigt mindestens PHP 8.3.
- Ein Talario-API-Key mit Freigabe f�r die verwendete Absenderdomain.
- Eine sendef�hige Domain und ein mit Talario synchronisierter Mailserver.

## Installation w�hrend der Entwicklung

Solange das Paket noch nicht auf Packagist steht, das GitHub-Repository in der
`composer.json` der **Kundenanwendung** erg�nzen:

```json
"repositories": [
    {"type": "vcs", "url": "https://github.com/muehleis/laravel-talario"}
]
```

Nach dem Merge dieser Implementierung:

```bash
composer require muehleis/laravel-talario:dev-main
```

Ein privates Repository ben�tigt zus�tzlich eine lokale GitHub-Authentifizierung.
Zugangstokens niemals in die Projektdateien oder das Repository schreiben.

Laravel entdeckt den ServiceProvider automatisch. Er registriert den Transport
und erg�nzt `mail.mailers.talario`, falls die Anwendung diesen Eintrag noch nicht hat.
Es ist keine �nderung an `config/mail.php` erforderlich.

## Konfiguration

In der `.env` der Kundenanwendung:

```dotenv
MAIL_MAILER=talario
TALARIO_API_KEY=tlr_api_DEIN_KEY
MAIL_FROM_ADDRESS=noreply@deine-domain.de
MAIL_FROM_NAME="Meine Anwendung"
```

Optional:

```dotenv
TALARIO_ENDPOINT=https://talario.eu/api/v1
TALARIO_TIMEOUT=60
TALARIO_CONNECT_TIMEOUT=10
```

Der Endpoint ist die API-Basis ohne `/messages`. Nur HTTPS ist erlaubt;
HTTP-Weiterleitungen werden nicht verfolgt. Der API-Key ist ein Kunden-API-Key,
kein Agent-Token und kein SMTP-Passwort.

Bei aktivem Konfigurationscache anschlie�end:

```bash
php artisan config:cache
```

Lang laufende Queue-Worker nach Konfigurations�nderungen ebenfalls neu starten.
Die Konfigurationsdatei kann bei Bedarf ver�ffentlicht werden:

```bash
php artisan vendor:publish --tag=talario-config
```

Bei mehreren Talario-Mailern lassen sich `api_key`, `endpoint`, `timeout` und
`connect_timeout` je Mailer in `config/mail.php` �berschreiben. Dort weiterhin
`env(...)` verwenden und keine Geheimnisse eintragen.

## Verwendung

```php
use Illuminate\Support\Facades\Mail;

Mail::raw('Hallo aus Talario!', function ($message) {
    $message->to('empfaenger@example.org')->subject('Talario-Test');
});
```

Bestehende Mailables, Markdown-Mails und Notifications �ber den Mail-Kanal verwenden
denselben Transport. Laravel-Queues funktionieren weiterhin; der Transport selbst
f�hrt pro Versand genau einen API-Aufruf aus.

Unterst�tzt werden ein From-Absender, To/Cc/Bcc, Reply-To, UTF-8-Text und HTML,
normale Anh�nge inklusive Datei- und Stream-Inhalt sowie die von der API erlaubten
Header: `X-*` au�er `X-Talario-*`, `List-Unsubscribe`, `List-Unsubscribe-Post`,
`List-Id`, `In-Reply-To` und `References`. API-Validierung und Gr��enlimits bleiben
ma�geblich; serverseitige Validierungsfehler ergeben HTTP 422.

Bcc-Adressen werden ausschlie�lich im Bcc-Feld der API �bertragen; Talario nimmt
sie nicht in sichtbare Header auf. Bei expliziten SMTP-Envelopes werden keine
ausgeschlossenen Empf�nger hinzugef�gt. Zus�tzliche Envelope-Empf�nger bleiben verborgen.

### Grenzen der API v1

Der Transport lehnt nicht darstellbare Funktionen vor dem API-Aufruf ab:

- eingebettete Bilder und Inline-Anh�nge, etwa `embed()` oder `embedData()`;
- mehrere From-Absender, eigene Sender-/Return-Path-Adressen und abweichende Envelope-Absender;
- Nachrichten ohne To-Empf�nger, auch reine Bcc-Mails;
- beliebige Raw-MIME-Nachrichten, benutzerdefinierte MIME-Bodies und Nicht-UTF-8-Bodies;
- nicht freigegebene oder mehrfach vorkommende eigene Header.

F�r solche Nachrichten vorerst Talarios SMTP-Zugang mit Laravels `smtp`-Transport
verwenden. Talario erzeugt die MIME-Nachricht neu und vergibt Datum und Message-ID.
Eine lokale Message-ID wird nicht an die API �bergeben. Der R�ckgabewert des Versands
liefert �ber `getMessageId()` die von Talario vergebene Message-ID ohne Winkelklammern.
Die lokale Originalnachricht beh�lt ihre eigenen Header.

Ein k�nftig vom Server gemeldeter Modus `sandbox` wird akzeptiert; das Paket kann
den Zugangsmodus nicht pro Anfrage �ndern. Dies implementiert keine Sandbox auf dem Server.

## Fehler und Wiederholungen

`TalarioTransportException` ist eine Symfony-Mailer-Transportexception. Sie enth�lt
bei API-Antworten `httpStatus`, optional `apiCode` und `retryAfter` (Sekunden).
HTTP 401/403 weisen auf Zugang oder Berechtigungen hin, 422 auf eine abgelehnte
Nachricht, 429 auf ein Limit und 503 auf eine tempor�r nicht m�gliche Einlieferung.
Fehlertexte enthalten keine Rohantworten, API-Keys oder Nachrichteninhalte.

**Keine automatischen HTTP-Wiederholungen:** Die API unterst�tzt noch keinen
Idempotency-Key. Bei einem Timeout kann die Nachricht bereits angenommen worden
sein. Auch automatische Laravel-Queue-Wiederholungen oder Failover-Mailer k�nnen
daher Duplikate erzeugen. In einem solchen Fall zuerst die Talario-Protokolle pr�fen.
Ein erfolgreicher Aufruf best�tigt nur die Annahme; Zustellung und Bounces werden
separat durch Talario erfasst.

## Entwicklung

```bash
composer install
composer validate --strict
composer test
```

Die Tests verwenden HTTP-Fakes und versenden keine E-Mails. Sie pr�fen unter anderem
Empf�nger und Bcc, Anh�nge, Nachrichten-ID, abgelehnte Funktionen, API- und
Netzwerkfehler sowie die Laravel-Registrierung. Die CI pr�ft Laravel 11/12/13.
Ein echter Versand aus einer Kundenanwendung bleibt vor einem Release erforderlich.

## Ver�ffentlichung

Nach bestandener CI und echtem Integrationstest das Repository �ffentlich zug�nglich
machen, einen SemVer-Tag wie `v0.1.0` erstellen und das Repository auf
[Packagist](https://packagist.org) registrieren. Nach eingerichteter GitHub-Aktualisierung
�bernimmt Packagist weitere Versionstags. Erst dann gen�gt beim Kunden:

```bash
composer require muehleis/laravel-talario
```

Lizenz: MIT, siehe [LICENSE](LICENSE).
