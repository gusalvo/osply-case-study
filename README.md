*[English version](README.en.md)*

# Osply

Scheda ospite digitale per strutture ricettive.

Osply permette all'host di raccogliere in un'unica pagina le informazioni utili durante il
soggiorno: check-in, WiFi, regole della struttura, emergenze, check-out e consigli locali.

La scheda viene condivisa tramite link, QR code o WhatsApp ed è progettata principalmente per
l'utilizzo da smartphone.

🔗 **In produzione:** [osply.app](https://osply.app)

🔗 **Scheda demo:** [osply.app/g/casa-azzurra-palermo](https://osply.app/g/casa-azzurra-palermo)

---

> **Cosa contiene questo repository.** Non è il codice sorgente di Osply, ma una descrizione
> tecnica di come l'ho progettato e realizzato, con alcuni estratti di codice a supporto. Il
> codice completo è privato. Se sta valutando il mio profilo e desidera vederlo, sono
> disponibile a mostrarlo in condivisione schermo o a fornire un accesso in lettura al
> repository privato.

---

## In breve

**Laravel 13 · PHP 8.3 · Livewire 4 · Tailwind 4 · MySQL**

Ho seguito direttamente progettazione, sviluppo, test e messa in produzione.

| | |
|---|---|
| Commit di codice | 117 |
| Test | 250 (Pest) — 246 verdi, 4 skip |
| Classi PHP applicative | 36 |
| Viste Blade | 77 |
| Periodo | giugno – settembre 2026, due milestone |
| Stato | in produzione su VPS, dominio proprio, HTTPS |

Il repository privato contiene altri 145 commit `docs`: il resto sono note di progettazione.
Li tengo fuori dal conteggio perché non sono codice.

---

## La scheda ospite

| Sezioni della guida | Contenuti protetti | Emergenze e contatto host |
|---|---|---|
| ![Scheda ospite](docs/img/01-guest-full.png) | ![Contenuti protetti](docs/img/02-guest-full.png) | ![Fondo scheda](docs/img/03-guest-full.png) |

---

## Documentazione tecnica

* [Architettura](docs/architettura.md) — stack, modello dati, rotte, autorizzazione, deploy
* [Decisioni tecniche](docs/decisioni-tecniche.md) — scelte, alternative valutate, un errore
* [Come è scritto un CRUD](docs/crud-pattern.md) — il codice di tutti i giorni, con i test

---

## Alcune scelte tecniche

### 1. Cache della sitemap: un problema emerso in produzione

`/sitemap.xml` funzionava correttamente alla prima richiesta, mentre le successive restituivano
errore 500.

La causa era una differenza tra ambiente di test e produzione: nei test la cache utilizzava il
driver `array`, mentre in produzione veniva utilizzato `database`.

Il controller memorizzava direttamente una Collection Eloquent. Con il driver database il valore
veniva serializzato e, alla lettura successiva, non manteneva il comportamento atteso.

La soluzione è stata memorizzare nella cache soltanto i dati necessari in forma di array:

```php
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
```

Dopo la correzione ho aggiunto anche un test specifico utilizzando il driver `database`, in modo
da riprodurre il comportamento dell'ambiente di produzione.

La chiave di cache è versionata perché sull'ambiente di produzione non è disponibile la CLI PHP
via SSH. In questo modo una modifica della struttura dei dati può utilizzare immediatamente una
nuova chiave senza richiedere un `cache:clear`.

### 2. Privacy e gestione dei dati

L'ospite non deve creare un account e non effettua login.

I dati raccolti sono limitati a quelli necessari al funzionamento e alle statistiche della
scheda. L'indirizzo IP non viene memorizzato direttamente ma trasformato in hash.

Per le richieste provenienti dalla vetrina viene inoltre registrato il momento del consenso.

È previsto un comando di retention per eliminare automaticamente le richieste chiuse oltre il
periodo stabilito.

### 3. Protezione dei contenuti tramite PIN

Alcune informazioni, come WiFi o codici di accesso, possono essere protette tramite PIN.

Il PIN viene memorizzato tramite hash e lo sblocco viene mantenuto nella sessione dell'ospite.

Il rate limiting utilizza una chiave basata su IP e struttura, con un massimo di cinque
tentativi in dieci minuti. In questo modo i tentativi effettuati su una struttura non
interferiscono con l'accesso alle altre.

L'accesso dell'host alle proprie strutture è gestito tramite Laravel Policies. Ogni metodo che
scrive sul database segue lo stesso schema:

```php
$this->authorize('update', $this->property);
$recommendation = $this->property->localRecommendations()->findOrFail($id);
```

Prima l'autorizzazione, poi il caricamento attraverso la relazione già filtrata. Un identificativo
appartenente a un'altra struttura non viene trovato, senza bisogno di controlli aggiuntivi.
Il dettaglio, con i test relativi, è in [Come è scritto un CRUD](docs/crud-pattern.md).

### 4. Normalizzazione dei numeri WhatsApp

Per generare correttamente i link `wa.me` ho isolato la normalizzazione dei numeri telefonici in
un piccolo value object:

```php
// Restituisce le cifre in formato E.164, oppure null se il numero è ambiguo.
public static function toE164(?string $raw): ?string
```

Gestisce i diversi formati più comuni e, quando il numero non può essere normalizzato con
certezza, evita di costruire un link potenzialmente errato e utilizza un'alternativa più sicura.

La classe è indipendente dal resto dell'applicazione ed è coperta da test unitari.

---

## Come l'ho testato

La suite comprende 250 test Pest, principalmente feature test sul comportamento
dell'applicazione.

Sono coperti, tra gli altri:

* autorizzazioni e accesso alle strutture;
* contenuti pubblici e protetti;
* PIN e rate limiting;
* privacy delle richieste;
* link WhatsApp e QR code;
* regressioni individuate durante l'utilizzo in produzione.

Pint e Larastan vengono inoltre eseguiti automaticamente tramite GitHub Actions.

---

## Scelte di prodotto

La prima versione è stata volutamente limitata alle funzionalità necessarie per creare e
condividere una scheda ospite.

PMS, booking engine, pagamenti, multilingua e chatbot sono rimasti fuori dalla prima milestone.

La v1.1 ha aggiunto successivamente la vetrina pubblica delle strutture e la gestione delle
richieste, mantenendo separate le due fasi di sviluppo.

---

## Cosa migliorerei

Il problema della sitemap ha evidenziato l'importanza di ridurre le differenze tra ambiente di
test e produzione. Aggiungerei quindi alla CI almeno alcuni smoke test eseguiti con gli stessi
driver utilizzati in produzione.

Migliorerei inoltre la gestione automatica dei formati immagine per la pagina mobile e
completerei la schedulazione automatica del comando di retention.

---

## Licenza

Il materiale presente nel repository è pubblicato per finalità di valutazione tecnica.

Può essere consultato e citato, ma non riutilizzato, redistribuito o utilizzato per effettuare
deploy.

Vedi [LICENSE](LICENSE).
