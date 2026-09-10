# Decisioni tecniche

Torna al [README](../README.md).

Per ogni scelta riporto l'alternativa valutata e il motivo per cui non l'ho adottata.

---

## 1. Blade e Livewire invece di una SPA

Laravel full-stack, senza API separata e senza Vue, React o Inertia.

Osply è principalmente CRUD di contenuti, pagine pubbliche e una dashboard di gestione. Una SPA
avrebbe aggiunto un secondo runtime, un layer di serializzazione, un router lato client e un
processo di build da mantenere, per rendere form e liste già gestibili con Blade.

Il costo di questa scelta è che l'interattività più elaborata richiede più lavoro: il riordino
drag-and-drop delle sezioni è più laborioso in Livewire che in React.

In cambio ho una sola codebase, un solo deploy e la possibilità di rendere la pagina ospite
senza JavaScript di framework.

---

## 2. La pagina pubblica non carica Livewire né Alpine

`/g/{slug}` e `/s/{slug}` sono Blade con un file JavaScript scritto direttamente.

È la pagina che l'ospite apre da rete mobile, spesso al momento dell'arrivo. Caricare un
framework reattivo per gestire l'apertura di alcuni accordion avrebbe aggiunto peso permanente
a fronte di un beneficio limitato.

Gli accordion usano `<details>` e `<summary>` nativi e funzionano anche senza JavaScript. I chip
di accesso rapido usano `scrollIntoView`. Il tracking degli eventi è una `fetch` senza attesa
della risposta, con `keepalive: true` per non trattenere la navigazione:

```js
fetch(url, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
  body: JSON.stringify({ event_type: el.dataset.track }),
  keepalive: true,
}).catch(function () {});
```

Il costo è avere due modelli di interattività nello stesso progetto, che vanno documentati per
evitare che la pagina pubblica venga modificata con direttive Livewire.

---

## 3. QR code: libreria sostituita per un conflitto di dipendenze

`simplesoftwareio/simple-qrcode` richiede `bacon/bacon-qr-code ^2`, mentre Fortify — incluso
nello starter kit ufficiale — porta `bacon/bacon-qr-code ^3`. Il conflitto non è risolvibile via
Composer e la libreria non è aggiornata dal 2021.

Ho adottato `tarikmanoar/laravel-qrcode`, che usa BaconQrCode v3 e mantiene un'API equivalente.

Situazione analoga per la sitemap: `spatie/laravel-sitemap` richiede PHP ^8.4, non disponibile
sull'hosting utilizzato. Ho scritto una rotta e una view Blade XML senza dipendenze aggiuntive.

---

## 4. Nessun pacchetto per i permessi

I ruoli previsti sono due. Ho usato una colonna `role` su `users` e un metodo `before()` nella
Policy.

`spatie/laravel-permission` avrebbe introdotto tabelle di ruoli e permessi, una pivot e una cache
dei permessi, per un modello di autorizzazione che al momento non li richiede.

Se in futuro servissero più ruoli o permessi granulari per risorsa, la migrazione resta
contenuta: l'autorizzazione passa già interamente dalle Policy, quindi cambierebbe
l'implementazione dei controlli e non i punti in cui sono chiamati.

---

## 5. PIN invece di account ospite

I contenuti sensibili sono protetti da un PIN per struttura, memorizzato come hash e sbloccato a
livello di sessione.

L'alternativa era un account ospite, con registrazione, password, reset e verifica email, e la
conseguente gestione dei dati personali di persone che devono solo consultare gli orari di
check-out.

Il PIN utilizza un canale che l'host già impiega — WhatsApp o il messaggio di conferma della
prenotazione — e non comporta la creazione di dati personali.

Il rate limiting usa una chiave che combina IP e slug della struttura:

```php
$key = 'pin-unlock:' . sha1($request->ip() . '|' . $property->slug);
```

Con la sola chiave IP, tentativi ripetuti su una struttura bloccherebbero quell'indirizzo su
tutte le altre. Con il solo slug, sarebbe possibile bloccare gli accessi legittimi a una
struttura specifica. La combinazione limita l'effetto al singolo caso.

---

## 6. `content` e `content_json` sulla stessa tabella

Le sezioni della guida hanno sia un campo di testo libero sia un campo JSON.

"Regole della casa" è un testo. "WiFi" è una coppia rete/password. "Check-in" è un orario più
alcune istruzioni. Usare un solo formato avrebbe comportato la perdita della struttura oppure la
definizione di uno schema rigido per contenuti che variano tra le strutture.

Il costo è avere due percorsi di rendering e dover sapere quale campo è autoritativo per ogni
tipo di sezione. La gestione avviene tramite partial dedicate per i tipi strutturati e una
partial di default per gli altri.

L'alternativa valutata era una tabella per tipo di sezione, che avrebbe moltiplicato le tabelle
per dieci tipi con comportamento in larga parte comune.

---

## 7. Seeder demo realizzato all'inizio

La struttura demo "Casa Azzurra Palermo", completa di sezioni, consigli e contatti, è stata
scritta prima della maggior parte delle funzionalità.

Ha fornito un riferimento visivo costante durante lo sviluppo, permettendo di verificare ogni
sezione e ogni stato con dati realistici. Al termine della v1 la demo era già disponibile senza
lavoro aggiuntivo.

---

## 8. Divergenza tra ambiente di test e produzione

`phpunit.xml` imposta `CACHE_STORE=array`, mentre la produzione usa `CACHE_STORE=database`. Il
driver array non serializza i valori, quello database sì. Il controller della sitemap memorizzava
modelli Eloquent, che non mantengono il comportamento atteso dopo
`serialize()`/`unserialize()`.

`/sitemap.xml` restituiva quindi 500 in produzione a ogni lettura da cache, e la suite non poteva
rilevarlo perché l'ambiente di test non riproduceva la condizione.

La correzione è stata memorizzare dati primitivi, versionare la chiave di cache — sulla
produzione non è disponibile la CLI PHP via SSH — e aggiungere un test che forza il driver
`database`.

Considero ora le differenze di driver tra test e produzione come casi da coprire esplicitamente
in CI.

---

## 9. Definizione dello scope della v1

Il criterio adottato per valutare le funzionalità proposte è stato: la funzionalità aiuta una
struttura a creare e condividere una scheda ospite più chiara? In caso negativo veniva rinviata.

La v1 aveva inoltre una checklist di accettazione definita prima dell'inizio dello sviluppo, per
delimitare il rilascio senza estendere lo scope in corso d'opera.

Sono rimasti fuori: PMS, channel manager, booking engine, pagamenti, abbonamenti, multilingua,
chatbot, integrazioni OTA e app nativa.

La v1.1 ha aggiunto la vetrina pubblica e la gestione delle richieste come milestone separata,
con lo stesso criterio.
