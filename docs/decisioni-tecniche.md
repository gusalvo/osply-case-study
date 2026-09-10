# Decisioni tecniche e trade-off

Torna al [case study](../README.md).

Ogni voce è una scelta reale, con l'alternativa che ho scartato e il motivo. Dove ho sbagliato
lo dico.

---

## 1. Blade + Livewire invece di una SPA

**Scelta:** Laravel full-stack, niente API separata, niente Vue/React/Inertia.

**Perché:** Osply è CRUD di contenuti, pagine pubbliche e una dashboard semplice. Una SPA
avrebbe aggiunto un secondo runtime, un layer di serializzazione, un router client e un
processo di build da mantenere — per rendere form e liste che Blade rende benissimo.

**Il costo che accetto:** l'interattività più ricca costa di più. Il riordino delle sezioni
drag-and-drop è più laborioso in Livewire che in React.

**Il beneficio che incasso:** una sola codebase, un solo deploy, un solo modello mentale, e la
possibilità di renderizzare la pagina ospite senza *alcun* JavaScript di framework.

---

## 2. La pagina pubblica non carica Livewire né Alpine

**Scelta:** `/g/{slug}` e `/s/{slug}` sono Blade puro; l'interattività è un file JS scritto a
mano.

**Perché:** è la pagina che decide se il prodotto funziona. Un ospite la apre una volta, da
rete mobile, con l'urgenza di trovare il codice del portone. Caricare un framework reattivo
per aprire e chiudere degli accordion sarebbe stato pagare un costo permanente per un
beneficio marginale.

**Come:** gli accordion sono `<details>`/`<summary>` **nativi** — funzionano senza JS. I chip
di accesso rapido fanno scroll con `scrollIntoView`. Il tracking degli eventi è una `fetch`
fire-and-forget con `keepalive: true`, così non trattiene la navigazione.

```js
// tracking fire-and-forget: se fallisce, l'ospite non se ne accorge
fetch(url, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
  body: JSON.stringify({ event_type: el.dataset.track }),
  keepalive: true,
}).catch(function () {});
```

**Il costo:** due modelli di interattività da mantenere nello stesso progetto. Va documentato,
altrimenti il prossimo che tocca la pagina pubblica ci infila un `wire:click` e rompe tutto.

---

## 3. `simplesoftwareio/simple-qrcode` scartato per un conflitto di dipendenze

**Il problema:** la libreria QR più diffusa in ambito Laravel richiede `bacon/bacon-qr-code ^2`.
Fortify — che arriva con lo starter kit ufficiale — porta `bacon/bacon-qr-code ^3`. Conflitto
Composer irrisolvibile, e la libreria è ferma dal 2021.

**Scelta:** `tarikmanoar/laravel-qrcode`, che wrappa BaconQrCode v3 con la stessa API fluente.

**Perché lo racconto:** è il tipo di decisione che non si prende leggendo un tutorial. La
libreria "standard" era incompatibile con lo starter kit "standard", e la risposta giusta non
era forzare la versione ma cambiare libreria.

Stessa logica per la sitemap: `spatie/laravel-sitemap` richiede PHP ^8.4, che quell'hosting non
offre. Ho scritto una rotta e una view Blade XML — trenta righe, zero dipendenze.

---

## 4. Nessun pacchetto di permessi

**Scelta:** una colonna `role` su `users` con due valori, e un `before()` nella Policy.

**Perché no `spatie/laravel-permission`:** due ruoli non giustificano tabelle di ruoli,
permessi, pivot, cache dei permessi e una gerarchia da mantenere. Sarebbe stata complessità
comprata in anticipo per un'esigenza ipotetica.

**Quando cambierei idea:** al terzo ruolo, o al primo permesso granulare per-risorsa. La
migrazione è contenuta proprio perché l'autorizzazione passa già tutta dalle Policy: cambia
l'implementazione del check, non i punti di chiamata.

---

## 5. Il PIN invece di un account ospite

**Scelta:** contenuti sensibili dietro un PIN per struttura, hashato, sbloccato in sessione.

**Perché:** l'alternativa era un account ospite. Che significa raccogliere email, gestire
password, reset, verifica, e diventare titolare del trattamento dei dati di persone che
vogliono solo sapere l'orario del check-out. Un costo enorme, per l'utente e per me.

Il PIN sposta il segreto su un canale che l'host già usa — WhatsApp, il messaggio di conferma
della prenotazione — e non crea alcun dato personale.

**Il dettaglio che conta:** il rate limit è chiavato su IP **e** slug.

```php
$key = 'pin-unlock:' . sha1($request->ip() . '|' . $property->slug);
```

Con la sola chiave IP, un attacco a forza bruta su una struttura avrebbe bloccato quell'IP per
tutte. Con la sola chiave slug, un attaccante avrebbe bloccato gli ospiti legittimi di quella
struttura. La coppia isola il problema dove nasce.

---

## 6. `content` e `content_json` insieme sulla stessa tabella

**Scelta:** le sezioni guida hanno sia un campo testo libero sia un campo JSON.

**Perché:** "Regole della casa" è un paragrafo. "WiFi" è una coppia rete/password. "Check-in" è
un orario più delle istruzioni. Costringerli nella stessa forma avrebbe significato o perdere
struttura (tutto testo) o inventare uno schema rigido per contenuti che rigidi non sono.

**Il costo:** due percorsi di rendering e la necessità di sapere quale campo è autoritativo per
ogni tipo. Lo gestisco con partial dedicate per tipo (`section-content-wifi`,
`section-content-checkin`, …) e un default per il resto.

**L'alternativa scartata:** una tabella per tipo di sezione. Corretta in teoria, sproporzionata
per dieci tipi che condividono l'80% del comportamento.

---

## 7. Il seeder demo scritto presto, non alla fine

**Scelta:** "Casa Azzurra Palermo" — struttura completa e realistica — esisteva prima della
maggior parte delle feature.

**Perché ha funzionato:** ha dato un riferimento visivo continuo durante lo sviluppo. Ogni
sezione, ogni consiglio, ogni stato è stato disegnato guardando dati veri invece che
`lorem ipsum`. E a fine v1 la demo da mostrare esisteva già, senza lavoro aggiuntivo.

È la decisione di processo che rifarei per prima in qualunque progetto.

---

## 8. Dove ho sbagliato: la divergenza tra test e produzione

**L'errore:** `phpunit.xml` forza `CACHE_STORE=array`. La produzione usa `CACHE_STORE=database`.
Il driver `array` non serializza mai; quello `database` sì. Ho cachato modelli Eloquent, che
non sopravvivono in modo affidabile al round-trip `serialize()`/`unserialize()`.

Risultato: `/sitemap.xml` restituiva 500 in produzione a ogni cache hit, e **250 test verdi non
potevano accorgersene**. Non era un buco di copertura: era un buco *strutturale*, l'ambiente di
test non poteva riprodurre la condizione.

**Cosa ho fatto:** cachato primitive invece di modelli, versionato la chiave di cache (la CLI
PHP non è disponibile in produzione, quindi `cache:clear` non è un'opzione), e scritto un test
che forza il driver `database` per riprodurre lo scenario.

**Cosa mi porto dietro:** ogni divergenza fra ambiente di test e produzione su un driver —
cache, sessioni, coda, filesystem — è un punto cieco della suite, non un dettaglio di
configurazione. Vale la pena elencarle esplicitamente e coprire almeno il percorso critico con
i driver veri.

---

## 9. Anti-scope-creep come regola scritta

**Scelta:** una domanda sola come filtro, decisa prima di scrivere una riga: *aiuta una
struttura a creare e condividere una scheda ospite più chiara?* Se la risposta è no, la
funzione va nel backlog, non nella v1.

E una checklist di accettazione scritta **prima** di iniziare, con la regola esplicita: quando
è tutta spuntata la v1 è chiusa, niente aggiunte "già che ci sono".

**Cosa ha tenuto fuori:** PMS, channel manager, booking engine, pagamenti, abbonamenti,
multilingua, chatbot, integrazioni OTA, app nativa. Tutte cose che un prodotto per strutture
ricettive "dovrebbe" avere, e che avrebbero trasformato una v1 consegnabile in un cantiere
aperto.

**Cosa è arrivato dopo, come milestone separata:** vetrina pubblica SEO e raccolta richieste
info — la v1.1, pianificata e chiusa con lo stesso metodo.
