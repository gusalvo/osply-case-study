# Estratti di codice

Torna al [README](../README.md).

---

Questa cartella contiene sette file presi dal codebase di Osply, organizzati secondo la
struttura originale del progetto.

Non è un'applicazione eseguibile: mancano `composer.json`, il bootstrap del framework, le view,
le rotte e la configurazione. I file sono qui per essere letti.

**I riferimenti interni di tracciabilità presenti nei docblock originali sono stati rimossi. La
logica è invariata.** Le uniche altre differenze rispetto ai file del repository privato sono i
nomi di tre gruppi `describe()` nel file di test.

---

## Una funzionalità completa

I primi cinque file sono la stessa funzionalità — i consigli locali di una struttura — vista
attraverso tutti i suoi livelli:

| File | Righe | Contenuto |
|---|---|---|
| [`database/migrations/…create_local_recommendations_table.php`](database/migrations/2026_06_21_000003_create_local_recommendations_table.php) | 31 | schema della tabella |
| [`app/Models/LocalRecommendation.php`](app/Models/LocalRecommendation.php) | 74 | modello, relazione, insieme chiuso delle categorie |
| [`app/Policies/PropertyPolicy.php`](app/Policies/PropertyPolicy.php) | 49 | autorizzazione, con bypass admin in `before()` |
| [`app/Livewire/Properties/LocalRecommendationManager.php`](app/Livewire/Properties/LocalRecommendationManager.php) | 229 | componente Livewire: CRUD, attivazione, riordino |
| [`tests/Feature/LocalRecommendationTest.php`](tests/Feature/LocalRecommendationTest.php) | 254 | copertura della funzionalità, comprese le autorizzazioni |

Il commento sulla struttura del componente è nella sua intestazione. La spiegazione discorsiva,
con il ragionamento sulle scelte, è in [Come è scritto un CRUD](../docs/crud-pattern.md).

## Un value object isolato

| File | Righe | Contenuto |
|---|---|---|
| [`app/Support/PhoneNumber.php`](app/Support/PhoneNumber.php) | 68 | normalizzazione dei numeri per i link `wa.me` |
| [`tests/Unit/PhoneNumberTest.php`](tests/Unit/PhoneNumberTest.php) | 28 | test unitario, dataset-driven |

Classe finale e statica, senza dipendenze. Restituisce `null` quando il numero non è
normalizzabile con certezza, invece di indovinare un prefisso.

---

## Da dove partire

Per una lettura rapida, in quest'ordine:

1. `app/Support/PhoneNumber.php` con il suo test — completo e leggibile in due minuti;
2. `app/Policies/PropertyPolicy.php` — il modello di autorizzazione;
3. `app/Livewire/Properties/LocalRecommendationManager.php` — metodi `mount`,
   `saveRecommendation` e `updateRecommendationsOrder`;
4. `tests/Feature/LocalRecommendationTest.php` — in particolare i tre test con `(BOLA)` nel
   nome, che coprono l'accesso a risorse di altre strutture.
