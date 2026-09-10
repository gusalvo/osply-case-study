# Osply — case study tecnico

**Scheda ospite digitale per strutture ricettive.** Un host crea la guida della propria
struttura — check-in, WiFi, regole, emergenze, check-out, consigli locali — e la condivide
con l'ospite tramite un solo link, un QR code o WhatsApp.

🔗 **In produzione:** [osply.app](https://osply.app) · **Scheda demo:** [osply.app/g/casa-azzurra-palermo](https://osply.app/g/casa-azzurra-palermo)

> Aprila dal telefono: la pagina ospite è il prodotto, ed è disegnata mobile-first.

---

> **Cos'è questo repository.** Non è il codice di Osply. È un documento tecnico che racconta
> come l'ho progettato e costruito, con estratti di codice reale a supporto. Il codice
> completo è privato — vedi [Licenza](#licenza). Se stai valutando il mio profilo e vuoi
> vedere il sorgente, chiedimelo: te lo mostro volentieri in condivisione schermo o con un
> accesso in lettura al repo privato.

---

## In breve

Laravel 13 · PHP 8.3 · Livewire 4 · Tailwind 4 · MySQL. Progettato, scritto, testato e
deployato da solo, dal dominio vuoto alla produzione.

| | |
|---|---|
| **Commit di codice** | 117 — 63 `feat`, 27 `test`, 15 `fix`, 6 `chore`, 1 `refactor`, 5 di restyling UI |
| **Test** | 250 (Pest) — 246 verdi, 4 skip, 0 falliti |
| **Classi PHP applicative** | 36 |
| **Viste Blade** | 77 |
| **Arco temporale** | giugno → settembre 2026, due milestone (v1.0, v1.1) |
| **Stato** | in produzione su VPS, dominio proprio, HTTPS |

Il repository privato contiene altri 145 commit `docs`: sono artefatti di pianificazione
(requisiti, piani di fase, verifiche). Li tengo fuori dal conteggio perché non sono codice —
ma sono il motivo per cui i commit di codice sono piccoli e ordinati.

---

## Il prodotto in tre schermate

| Scheda ospite | Contenuti protetti | Emergenze, check-out, WhatsApp |
|---|---|---|
| ![Scheda ospite](docs/img/01-guest-full.png) | ![Contenuti protetti](docs/img/02-guest-full.png) | ![Fondo scheda](docs/img/03-guest-full.png) |

L'ospite non ha account, non fa login, non lascia dati. Apre un link e legge. I contenuti
sensibili — codice del portone, password WiFi — stanno dietro un PIN che l'host comunica
separatamente.

Il pulsante WhatsApp è l'unico canale di contatto diretto, e apre la chat con un messaggio
già scritto che identifica la struttura — così l'host sa subito chi sta scrivendo e da dove.

---

## Architettura in una pagina

```
                    ┌──────────────────────────────┐
   ospite  ──────►  │  /g/{slug}   scheda guida    │  pubblica, no auth, mobile-first
   (anonimo)        │  /s/{slug}   vetrina         │  pubblica, SEO, form lead
                    └──────────────┬───────────────┘
                                   │  GuideView (ip_hash) · LeadRequest
                                   ▼
                    ┌──────────────────────────────┐
   host    ──────►  │  /dashboard  area riservata  │  Fortify + Policy
   (auth)           │  strutture · sezioni ·       │
                    │  consigli · foto · inbox     │
                    └──────────────────────────────┘
```

Laravel full-stack, niente SPA. La dashboard è Livewire 4; la pagina pubblica è Blade puro
con un filo di JavaScript vanilla — **non carica né Livewire né Alpine**, perché è la pagina
che deve aprirsi in fretta sulla connessione mobile di un ospite appena arrivato.

Dettagli: **[docs/architettura.md](docs/architettura.md)** ·
Scelte e trade-off: **[docs/decisioni-tecniche.md](docs/decisioni-tecniche.md)**

---

## Quattro cose che vale la pena guardare

### 1. Un bug che esisteva solo in produzione, e perché la suite non poteva vederlo

`/sitemap.xml` rispondeva 500. Non sempre: **solo dopo il primo hit**. Il primo caricamento
funzionava, tutti i successivi per un'ora no.

La causa: il controller faceva `Cache::remember` di una Collection Eloquent. In produzione
`CACHE_STORE=database`, che serializza il valore con `serialize()`/`unserialize()`. Al MISS il
closure restituisce la Collection vera — 200, il difetto resta nascosto. A ogni HIT successivo
il valore torna dalla deserializzazione degradato a stringhe, e la view esplode con
`Attempt to read property slug on string`.

Il punto interessante è **perché 250 test non l'avevano preso**: `phpunit.xml` forza
`CACHE_STORE=array`, che non serializza mai. La suite era strutturalmente cieca a questa
classe di bug.

Il fix è banale — cachare primitive invece di modelli. Quello che ho aggiunto dopo conta di più:

```php
/**
 * The cached value MUST be a plain array of primitives (not an Eloquent Collection
 * or Property models). Production's CACHE_STORE=database round-trips the value
 * through PHP serialize()/unserialize(); Eloquent models do not reliably survive
 * that round-trip on read, degrading into plain strings or incomplete objects and
 * causing a 500 on every warm-cache request.
 *
 * The cache key is versioned ('sitemap_v2'). The payload shape changed [...] Bumping
 * the key retires it instantly instead of requiring a cache flush at deploy time
 * (production has no PHP CLI, so `cache:clear` is not available over SSH).
 * Any future change to the shape of this payload must bump the key again.
 */
public function index(): Response
{
    $properties = Cache::remember('sitemap_v2', 3600, fn () =>
        Property::where('vetrina_enabled', true)
            ->select(['slug', 'updated_at'])
            ->get()
            ->map(fn (Property $property) => [
                'slug'       => $property->slug,
                'updated_at' => $property->updated_at->toAtomString(),
            ])
            ->all()
    );

    return response()->view('sitemap', compact('properties'))
        ->header('Content-Type', 'application/xml');
}
```

Il versionamento della chiave non è eleganza: il piano di hosting non espone la CLI PHP via
SSH, quindi `cache:clear` al deploy non è un'opzione. Bumpare la chiave ritira la voce vecchia
al primo colpo e lascia scadere la precedente sul suo TTL.

E un test che riproduce lo scenario forzando il driver `database`, così la suite smette di
essere cieca:

```php
// tests/Feature/SitemapDatabaseCacheTest.php — cold hit E warm hit devono dare 200
```

### 2. Privacy come vincolo di schema, non come informativa

L'ospite è anonimo per costruzione. Non c'è tabella `guests`, non c'è login ospite, non c'è
un cookie di profilazione. Quello che resta è ridotto al minimo già a livello di migration:

```php
// guide_views — analytics della scheda
$table->string('event_type', 20);       // view | whatsapp_click | maps_click | pin_unlock
$table->string('ip_hash', 64)->nullable();   // hash, mai l'IP
$table->string('user_agent')->nullable();

// lead_requests — richieste info dalla vetrina
$table->timestamp('consent_given_at')->nullable();  // consenso esplicito, con timestamp
$table->string('ip_hash', 64)->nullable();
```

E una retention attiva, non solo dichiarata:

```php
protected $signature = 'osply:prune-leads {--days=90 : Giorni di retention}';

// Elimina i LeadRequest in stato terminale (Persa / Archiviata) più vecchi della
// finestra di retention. I lead in stati attivi (Nuova, InTrattativa, Confermata)
// non vengono mai toccati.
```

### 3. Il PIN: rate limiting per IP *e* per struttura

I contenuti sensibili stanno dietro un PIN per struttura, hashato. Lo sblocco vive in
sessione. Il rate limit è chiavato su IP **e** slug, così chi tenta a forza bruta su una
struttura non blocca gli ospiti legittimi delle altre:

```php
// Rate limiting: IP + slug key, max 5 attempts in a 10-minute window
$key = 'pin-unlock:' . sha1($request->ip() . '|' . $property->slug);

if (RateLimiter::tooManyAttempts($key, 5)) {
    $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
    return back()->withErrors([
        'pin' => "Troppi tentativi. Riprova tra {$minutes} {$label}.",
    ]);
}

if (! Hash::check($request->input('pin'), $property->guest_pin_hash)) {
    RateLimiter::hit($key, 600);
    return back()->withErrors(['pin' => 'PIN errato. Riprova.']);
}

RateLimiter::clear($key);
$request->session()->put('guide_unlocked_' . $property->id, true);
```

L'autorizzazione lato host è una Policy Laravel con bypass admin nel `before()`, e i test
coprono esplicitamente il caso BOLA — un owner che prova ad aprire la struttura di un altro
prende 403 già al `mount()` del componente Livewire, non dopo aver caricato i dati.

### 4. Un value object piccolo, puro e testato

Normalizzare un numero di telefono italiano per un link `wa.me/` sembra una riga di codice.
Non lo è: `+39`, `0039`, `339 123 4567`, `0912345678`, spazi, punti, trattini. E soprattutto
va deciso cosa fare quando il numero **non** è normalizzabile.

```php
/**
 * Normalize a phone string to E.164 digits (no leading +) for wa.me/ links.
 *
 * Rules, applied in order:
 *   1. null / blank → null.
 *   2. International prefix recognised BEFORE stripping: if the string starts
 *      with '+' or '00', drop the prefix, strip non-digits, and use the digits
 *      as-is when >= 8 remain (no extra '39'); otherwise null.
 *   3. Otherwise strip non-digits: if the result starts with '3' and is 9–10
 *      digits long → prepend '39' (Italian mobile).
 *   4. Any other case (too short, not starting with 3, landline without prefix)
 *      → null (caller falls back to a "copy number" button).
 */
public static function toE164(?string $raw): ?string
```

Classe finale, statica, senza dipendenze, con il suo test unitario. La regola 4 è la parte
di prodotto: se il numero è ambiguo **non** si tira a indovinare un prefisso — si degrada a
un pulsante "copia numero", che è sempre corretto.

---

## Come l'ho testato

250 test Pest, prevalentemente feature test contro il comportamento osservabile.

| Area | Cosa verifica |
|---|---|
| Autorizzazione | un owner vede e modifica solo le proprie strutture; BOLA su preview, sezioni, consigli, inbox |
| Scheda pubblica | mostra solo contenuti attivi; le sezioni protette non espongono nulla senza PIN |
| PIN | PIN corretto sblocca, errato no, rate limit dopo 5 tentativi |
| Privacy lead | consenso registrato, IP hashato, retention degli stati terminali |
| Integrazioni | link WhatsApp generato correttamente, QR punta alla URL giusta |
| Regressioni | il caso sitemap sopra, con driver di cache forzato |

Qualità automatizzata: **Pint** (code style) e **Larastan** (analisi statica) su due workflow
GitHub Actions a ogni push e PR.

---

## Cosa ho lasciato fuori, di proposito

La v1 non è un PMS, non ha booking engine, pagamenti, multilingua o chatbot. Ogni funzione
proposta doveva rispondere sì a una domanda sola: *aiuta una struttura a creare e condividere
una scheda ospite più chiara?* Se no, andava nel backlog.

È la parte del lavoro di cui vado più fiero: la v1 è stata dichiarata finita e chiusa con una
checklist di accettazione scritta **prima** di iniziare, senza aggiunte "già che ci sono".
La v1.1 (vetrina pubblica + raccolta richieste) è arrivata dopo, come milestone separata, con
lo stesso metodo.

---

## Cosa farei diversamente

- **Il caso sitemap mi ha insegnato una cosa strutturale**: se l'ambiente di test diverge
  dalla produzione su un driver (cache, sessioni, coda), quella divergenza è un punto cieco,
  non un dettaglio di configurazione. Oggi metterei in CI almeno uno smoke test con i driver
  di produzione.
- **Le immagini** passano da Intervention ma non ho ancora un formato moderno servito in
  automatico. Su una pagina mobile-first è la prossima ottimizzazione che conta davvero.
- **Il comando di retention esiste ma va schedulato**: la logica c'è ed è testata, la
  pianificazione in produzione no. Preferisco dirlo che scoprirlo insieme in colloquio.

---

## Licenza

Materiale proprietario, pubblicato **solo per valutazione**. Leggibile e citabile; non
riutilizzabile, non ridistribuibile, non deployabile. Vedi [LICENSE](LICENSE).

Gli estratti di codice sono frammenti illustrativi di un codebase privato più ampio: sono lì
per essere letti, non per essere riusati.
