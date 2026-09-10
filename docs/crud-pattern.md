# Come è scritto un CRUD in questo progetto

Torna al [case study](../README.md).

---

Le pagine di gestione della dashboard — sezioni, consigli locali, foto, inbox — sono tutte
componenti Livewire full-page con la stessa forma. Questo documento ne apre una,
`LocalRecommendationManager` (241 righe: CRUD completo, attivazione inline e riordino
drag-and-drop), per mostrare la regola che le attraversa tutte.

Non è il codice più brillante del progetto. È il codice più **ripetuto**, ed è per questo che
vale la pena guardarlo: se la regola regge qui, regge ovunque.

---

## L'invariante

Ogni metodo che tocca il database fa due cose, in quest'ordine, senza eccezioni:

```php
$this->authorize('update', $this->property);
$recommendation = $this->property->localRecommendations()->findOrFail($id);
```

**Prima autorizza. Poi carica attraverso la relazione, mai dal modello globale.**

Non compare da nessuna parte un `LocalRecommendation::find($id)`. La differenza sembra
stilistica e non lo è: caricando dalla relazione, un id che appartiene a un'altra struttura non
viene trovato affatto. Non serve un controllo aggiuntivo che confronti `property_id` — e non
serve ricordarsi di scriverlo. Il perimetro è la query.

---

## L'ingresso

```php
public function mount(Property $property): void
{
    $this->authorize('update', $property);
    $this->property = $property;
}
```

La struttura arriva per route model binding. L'autorizzazione è la **prima riga**, prima di
qualunque assegnazione: chi non ha i permessi non arriva a caricare nulla.

---

## Scrittura: validare, poi costruire dal validato

```php
public function saveRecommendation(): void
{
    $this->authorize('update', $this->property);

    $validCategories = implode(',', LocalRecommendation::CATEGORIES);

    $validated = $this->validate(
        [
            'editCategory'    => ['required', "in:{$validCategories}"],
            'editName'        => ['required', 'string', 'max:255'],
            'editDescription' => ['nullable', 'string', 'max:1000'],
            'editAddress'     => ['nullable', 'string', 'max:255'],
            'editMapsUrl'     => ['nullable', 'url', 'starts_with:https://', 'max:2048'],
            'editPhone'       => ['nullable', 'string', 'max:30'],
            'editWebsiteUrl'  => ['nullable', 'url', 'max:2048'],
        ],
        [
            'editName.required'       => 'Il nome del consiglio è obbligatorio.',
            'editCategory.in'         => 'Seleziona una categoria.',
            'editMapsUrl.starts_with' => 'Il link Google Maps deve iniziare con https://.',
        ]
    );

    $data = [
        'category'    => $validated['editCategory'],
        'name'        => $validated['editName'],
        'description' => $validated['editDescription'] ?: null,
        'address'     => $validated['editAddress'] ?: null,
        'maps_url'    => $validated['editMapsUrl'] ?: null,
        'phone'       => $validated['editPhone'] ?: null,
        'website_url' => $validated['editWebsiteUrl'] ?: null,
    ];

    if ($this->editingId) {
        $this->property->localRecommendations()->findOrFail($this->editingId)->update($data);
    } else {
        $maxOrder = $this->property->localRecommendations()->max('sort_order') ?? 0;
        $data['sort_order'] = $maxOrder + 1;
        $data['is_active']  = true;
        $this->property->localRecommendations()->create($data);
    }

    Flux::modal('edit-recommendation')->close();
    Flux::toast(variant: 'success', text: 'Consiglio salvato.');
}
```

Tre dettagli che non sono casuali.

**La categoria è validata su un insieme chiuso**, costruito dalla costante del modello. Le
categorie non sono una stringa libera né un elenco duplicato nel componente: se se ne aggiunge
una, si aggiunge in un posto solo e la validazione la segue.

**`maps_url` richiede `starts_with:https://`**, non solo `url`. Un URL valido può essere
`javascript:`, e quel valore finisce in un `href` che l'ospite clicca dal telefono.

**`$data` è costruito da `$validated`, mai dalle proprietà pubbliche del componente.** In
Livewire le proprietà pubbliche sono scrivibili dal client: leggere `$this->editName` invece di
`$validated['editName']` significa fidarsi di un valore che non è passato dalle regole. La
differenza è di due caratteri e cambia il modello di fiducia.

Il campo `sort_order` in creazione parte da `max('sort_order') + 1` sulla relazione già filtrata:
il nuovo consiglio finisce in fondo alla lista di *quella* struttura.

---

## Cancellazione

```php
public function deleteRecommendation(int $id): void
{
    $this->authorize('update', $this->property);
    $recommendation = $this->property->localRecommendations()->findOrFail($id);
    $recommendation->delete();

    Flux::toast(variant: 'success', text: 'Consiglio eliminato.');
}
```

Tre righe, e sono le stesse tre di sempre. La noia è il punto.

---

## Riordino: l'unica parte non banale

Il drag-and-drop chiama il metodo con l'id spostato e la nuova posizione (1-based). Ricostruire
l'ordine sembra semplice finché non ci si accorge che spostare un elemento cambia la posizione
di tutti gli altri.

```php
public function updateRecommendationsOrder(int $id, int $position): void
{
    $this->authorize('update', $this->property);

    // Sempre ordinare per sort_order prima di ricostruire: l'ordine di ritorno
    // del database non è garantito, e senza questo il riordino è instabile.
    $recommendations = $this->property->localRecommendations()->orderBy('sort_order')->get();

    // L'elemento spostato deve appartenere a questa struttura.
    $moved = $recommendations->firstWhere('id', $id);
    if (! $moved) {
        return;
    }

    // Rimuovi l'elemento e reinseriscilo alla nuova posizione.
    $reordered = $recommendations->reject(fn ($r) => $r->id === $id)->values();

    $insertAt = max(0, $position - 1);
    $before   = $reordered->slice(0, $insertAt);
    $after    = $reordered->slice($insertAt);

    $final = $before->push($moved)->merge($after)->values();

    // Riscrivi sort_order come 1..N: nessun buco, nessun duplicato.
    foreach ($final as $index => $recommendation) {
        $recommendation->update(['sort_order' => $index + 1]);
    }
}
```

Due scelte da difendere.

**Riscrivo l'intera sequenza `1..N` invece di aggiornare solo le righe fra la vecchia e la nuova
posizione.** È più lavoro per il database, ma l'alternativa produce buchi e duplicati che poi
vanno gestiti in lettura. Su liste di questa dimensione — una manciata di consigli per struttura
— la sequenza pulita vale più della scrittura risparmiata.

**Se `$moved` non c'è, esco in silenzio.** L'id arriva dal client: se non appartiene a questa
struttura, la richiesta non è un errore da mostrare all'utente, è un tentativo da ignorare.

---

## Il test che tiene in piedi tutto questo

L'invariante vale quanto la prova che lo difende. Questo test è la più interessante della serie,
perché non verifica il caso ovvio:

```php
test('non-owner cannot edit a recommendation on another owner\'s property', function () {
    $ownerA    = User::factory()->create(['role' => 'owner']);
    $ownerB    = User::factory()->create(['role' => 'owner']);
    $propertyA = Property::factory()->create(['user_id' => $ownerA->id]);
    $propertyB = Property::factory()->create(['user_id' => $ownerB->id]);

    $recommendation = $propertyA->localRecommendations()->create([
        'category'   => 'restaurant',
        'name'       => 'Ristorante di A',
        'sort_order' => 1,
        'is_active'  => true,
    ]);

    // ownerB monta il componente sulla PROPRIA struttura — è perfettamente autorizzato —
    // ma passa l'id di un consiglio di ownerA. La query scoped non lo trova.
    expect(fn () =>
        Livewire::actingAs($ownerB)
            ->test(LocalRecommendationManager::class, ['property' => $propertyB])
            ->call('deleteRecommendation', $recommendation->id)
    )->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});
```

Il caso ovvio — un utente che apre la struttura di un altro — è coperto altrove e prende 403 al
`mount()`. Questo copre quello **subdolo**: un utente legittimo, sulla propria pagina, con un id
altrui. Lì il controllo di autorizzazione passa, perché la struttura è davvero sua. L'unica cosa
che lo ferma è che la query è scoped.

E infatti non ottiene un 403 ma un `ModelNotFoundException`, cioè un 404: **quella riga, per lui,
non esiste.** È la risposta giusta — un 403 confermerebbe che l'id esiste da qualche parte.

---

## In sintesi

| Aspetto | Scelta |
|---|---|
| Autorizzazione | prima riga di ogni metodo, anche di quelli che sembrano innocui |
| Caricamento | sempre dalla relazione, mai dal modello globale |
| Validazione | insiemi chiusi dalle costanti del modello, `starts_with:https://` sugli URL |
| Persistenza | dati costruiti da `validated()`, mai dalle proprietà pubbliche |
| Ordinamento | sequenza riscritta `1..N`, nessun buco |
| Copertura | il test punta al caso ambiguo, non a quello evidente |
