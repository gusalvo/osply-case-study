# Architettura

Torna al [README](../README.md).

---

## Due utenti, due stack frontend

Osply ha due tipi di utente con esigenze diverse.

L'ospite apre la scheda una volta sola, da smartphone, spesso su rete cellulare. L'host lavora
sulla propria scheda da desktop e ha bisogno di form articolati e reattivi.

Per questo la pagina pubblica e la dashboard non condividono lo stack frontend. La dashboard usa
Livewire 4. La pagina pubblica è Blade con JavaScript vanilla e non carica né Livewire né
Alpine: `/g/{slug}` risponde in circa 0,3 secondi con un payload ridotto.

L'interattività della pagina pubblica — accordion, chip di accesso rapido, tracking dei click —
è scritta direttamente in un file JS, con un fallback `<noscript>` per i contenuti essenziali.

Sul lato host valgono le esigenze opposte: form, modali, riordino drag-and-drop e validazione
reattiva.

| Dashboard host — consigli locali | Dashboard host — scheda struttura |
|---|---|
| ![Modale consigli](img/consigli-modal.png) | ![Form struttura](img/form.png) |

La cura estetica è concentrata sulla pagina ospite, che è la parte del prodotto vista dagli
utenti finali. La dashboard è volutamente più essenziale.

---

## Stack

| Livello | Scelta |
|---|---|
| Framework | Laravel 13 |
| Runtime | PHP 8.3 |
| Database | MySQL 8 |
| Dashboard | Livewire 4 + Flux UI |
| Stile | Tailwind CSS 4, configurazione CSS-first |
| Auth | Laravel Fortify (via starter kit ufficiale Livewire) |
| Autorizzazione | Policy Laravel + campo `role` su `users` |
| Storage | disco `public` Laravel, symlink |
| Immagini | Intervention Image |
| QR code | `tarikmanoar/laravel-qrcode` (SVG + PNG) |
| Test | Pest 4 |
| Qualità | Pint, Larastan, GitHub Actions |

Non ho usato pacchetti per la gestione dei permessi. I ruoli previsti sono due (`admin` e
`owner`) e sono gestiti con una colonna stringa e un metodo `before()` nella Policy.

---

## Modello dati

```
User ──< Property ──< GuideSection
                  ├──< LocalRecommendation
                  ├──< PropertyPhoto
                  ├──< GuideView          (analytics, ip_hash)
                  └──< LeadRequest        (richieste info dalla vetrina)
```

### `properties`

Contiene identità, contatti, personalizzazione e stato di pubblicazione della struttura:

```php
$table->foreignId('user_id')->constrained()->cascadeOnDelete();
$table->string('slug')->unique();              // la URL pubblica
$table->string('brand_color', 7)->nullable();  // accento per-struttura
$table->string('host_whatsapp')->nullable();
$table->boolean('whatsapp_enabled')->default(false);
$table->string('guest_pin_hash')->nullable();  // hashato, mai in chiaro
$table->boolean('protected_content_enabled')->default(false);
$table->string('status', 20)->default('draft'); // draft | published | disabled
$table->timestamp('published_at')->nullable();
```

Il valore di `brand_color` passa dall'accessor `getSafeBrandColorAttribute()`, che lo valida
prima dell'inserimento in una CSS custom property. Un colore non validato in un attributo
`style` costituirebbe una possibile superficie di injection.

Il tema per struttura si basa su tre variabili derivate a runtime:

```php
$accentVars = "--brand: {$property->safe_brand_color};"
    ." --brand-d: color-mix(in srgb, var(--brand) 82%, #000);"
    ." --brand-l: color-mix(in srgb, var(--brand) 12%, #fff);";
```

La classe `.guest` mappa queste tre variabili sui token usati dalle partial, così la scheda
viene resa allo stesso modo nella pagina pubblica e nell'anteprima dell'host.

### `guide_sections`

Le sezioni dispongono sia di un campo `content` (testo libero) sia di `content_json`
(strutturato). WiFi, check-in, check-out ed emergenze usano il secondo, perché sono dati con una
forma definita, e vengono resi da partial dedicate (`section-content-wifi`,
`section-content-checkin`, e così via).

Ogni sezione ha `sort_order`, `is_active` e `is_protected`, quindi può essere riordinata,
disattivata e protetta in modo indipendente dalle altre.

### `guide_views`

```php
$table->string('event_type', 20);           // view | whatsapp_click | maps_click | pin_unlock
$table->string('ip_hash', 64)->nullable();  // hash, mai l'IP in chiaro
```

Non viene memorizzato alcun identificatore persistente dell'ospite. L'host vede il numero di
visite, i click su WhatsApp e la data dell'ultima visita.

---

## Rotte

### Pubbliche — nessuna autenticazione

```
GET   /g/{slug}                  scheda ospite
POST  /g/{slug}/unlock           verifica PIN          (rate limit: 5 / 10 min per IP+slug)
POST  /g/{slug}/track            evento analytics      (rate limit)

GET   /s/{slug}                  vetrina pubblica (SEO)
POST  /s/{slug}/richiesta        richiesta info        (consenso obbligatorio)
POST  /s/{slug}/track            evento analytics

GET   /sitemap.xml               cache 1h, chiave versionata
GET   /robots.txt
GET   /privacy
```

Il model binding avviene su slug (`{property:slug}`) e non su id, così le URL pubbliche non
espongono identificativi progressivi.

### Area host — `auth` + `verified`

```
GET     /dashboard
GET     /properties                          lista
GET     /properties/create · {id}/edit       form
GET     /properties/{id}/preview             anteprima della vista ospite
GET     /properties/{id}/sections            editor sezioni
GET     /properties/{id}/recommendations     consigli locali
GET     /properties/{id}/photos              galleria (throttle:30,1 anti-flood upload)
GET     /properties/{id}/qr/download         QR scheda
GET     /properties/{id}/qr/vetrina/download QR vetrina
GET     /leads                               inbox richieste
DELETE  /leads/{lead}
```

Le rotte Livewire usano `Route::livewire()`, la forma introdotta da Livewire 4.

---

## Autorizzazione

Policy Laravel, con auto-discovery per convenzione e bypass admin nel metodo `before()`:

```php
public function before(User $user, string $ability): bool|null
{
    if ($user->role === 'admin') {
        return true;
    }

    return null;   // gli owner passano ai controlli per-metodo
}

public function view(User $user, Property $property): bool
{
    return $user->id === $property->user_id;
}
```

La Policy viene richiamata nel `mount()` dei componenti Livewire, prima del caricamento dei
dati: un owner che tenta di aprire la struttura di un altro riceve 403 all'ingresso. La suite
copre questo caso per anteprima, sezioni, consigli e inbox.

Lo schema completo, con i metodi di scrittura e i relativi test, è in
[Come è scritto un CRUD](crud-pattern.md).

---

## Performance

* Eager loading di sezioni e consigli sulla scheda pubblica, per evitare query N+1.
* Query con proiezione dove serve: la sitemap legge `select(['slug', 'updated_at'])`.
* Cache della sitemap con TTL di un'ora e chiave versionata, descritta nel
  [README](../README.md#1-cache-della-sitemap-un-problema-emerso-in-produzione).
* Nessun framework JavaScript sulla pagina pubblica.
* Icone come sprite SVG inline, senza richieste aggiuntive.

---

## Deploy

VPS con pannello, ambiente chroot e separazione tra root applicativa e document root pubblica.
Il deploy è git-based, con build degli asset e `storage:link`.

Su questo ambiente la CLI PHP non è disponibile via SSH, quindi non è possibile eseguire
`php artisan cache:clear` al deploy. Per questo la chiave di cache viene versionata quando
cambia la struttura dei dati memorizzati.
