# Come è scritto un CRUD in questo progetto

Torna al [README](../README.md).

---

Le pagine di gestione della dashboard — sezioni, consigli locali, foto, richieste — sono
componenti Livewire full-page con la stessa struttura.

Questo documento apre `LocalRecommendationManager` (225 righe: CRUD completo, attivazione
inline e riordino drag-and-drop) per mostrare lo schema che seguono tutte.

I file sorgente citati qui sono consultabili in [`examples/`](../examples/).

---

## Autorizzazione e caricamento

Ogni metodo che scrive sul database esegue due operazioni, in quest'ordine:

```php
$this->authorize('update', $this->property);
$recommendation = $this->property->localRecommendations()->findOrFail($id);
```

Prima l'autorizzazione sulla struttura, poi il caricamento attraverso la relazione.

Nel componente non compare mai `LocalRecommendation::find($id)`. Caricando dalla relazione, un
identificativo appartenente a un'altra struttura non viene trovato e la richiesta termina con
un `ModelNotFoundException`. Non è quindi necessario un confronto esplicito su `property_id`.

---

## Mount

```php
public function mount(Property $property): void
{
    $this->authorize('update', $property);
    $this->property = $property;
}
```

La struttura arriva per route model binding. L'autorizzazione precede l'assegnazione della
proprietà.

---

## Salvataggio

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

Alcune note sulla validazione.

La categoria è validata su un insieme chiuso costruito da `LocalRecommendation::CATEGORIES`.
L'elenco è definito una sola volta nel modello e la regola lo segue.

Su `maps_url` uso `starts_with:https://` oltre a `url`. La regola `url` accetterebbe anche
schemi come `javascript:`, e il valore finisce in un attributo `href` della pagina pubblica.

I dati salvati vengono costruiti da `$validated` e non dalle proprietà pubbliche del
componente, che in Livewire sono scrivibili dal client.

In creazione, `sort_order` parte da `max('sort_order') + 1` calcolato sulla relazione, quindi
sui soli consigli di quella struttura.

---

## Eliminazione

```php
public function deleteRecommendation(int $id): void
{
    $this->authorize('update', $this->property);
    $recommendation = $this->property->localRecommendations()->findOrFail($id);
    $recommendation->delete();

    Flux::toast(variant: 'success', text: 'Consiglio eliminato.');
}
```

Stesso schema dei metodi precedenti.

---

## Riordino

Il drag-and-drop richiama il metodo passando l'identificativo dell'elemento spostato e la nuova
posizione, in base 1.

```php
public function updateRecommendationsOrder(int $id, int $position): void
{
    $this->authorize('update', $this->property);

    // L'ordine restituito dal database non è garantito: serve un orderBy esplicito
    // prima di ricostruire la sequenza.
    $recommendations = $this->property->localRecommendations()->orderBy('sort_order')->get();

    // L'elemento spostato deve appartenere a questa struttura.
    $moved = $recommendations->firstWhere('id', $id);
    if (! $moved) {
        return;
    }

    $reordered = $recommendations->reject(fn ($r) => $r->id === $id)->values();

    $insertAt = max(0, $position - 1);
    $before   = $reordered->slice(0, $insertAt);
    $after    = $reordered->slice($insertAt);

    $final = $before->push($moved)->merge($after)->values();

    foreach ($final as $index => $recommendation) {
        $recommendation->update(['sort_order' => $index + 1]);
    }
}
```

Riscrivo l'intera sequenza da 1 a N invece di aggiornare solo le righe comprese tra la vecchia e
la nuova posizione. Su liste di poche decine di elementi la differenza di costo è trascurabile,
e l'ordinamento resta senza valori duplicati o mancanti.

Se l'elemento non appartiene alla struttura corrente il metodo esce senza modificare nulla e
senza segnalare errore, dato che l'identificativo proviene dal client.

---

## Test sulle autorizzazioni

Il caso di un utente che apre la struttura di un altro è coperto separatamente e restituisce
403 al `mount()`.

Questo test copre invece un utente autorizzato sulla propria struttura che passa
l'identificativo di un consiglio appartenente a un'altra:

```php
test('non-owner cannot edit a recommendation on another owner\'s property (BOLA)', function () {
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

    expect(fn () =>
        Livewire::actingAs($ownerB)
            ->test(LocalRecommendationManager::class, ['property' => $propertyB])
            ->call('deleteRecommendation', $recommendation->id)
    )->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});
```

Il controllo di autorizzazione passa, perché la struttura appartiene effettivamente a `ownerB`.
La query sulla relazione non trova il consiglio e il risultato è un `ModelNotFoundException`,
quindi un 404 anziché un 403: la risorsa non viene confermata come esistente.

---

## Riepilogo

| Aspetto | Implementazione |
|---|---|
| Autorizzazione | prima istruzione di ogni metodo che scrive |
| Caricamento | tramite la relazione, mai dal modello globale |
| Validazione | insiemi chiusi dalle costanti del modello, `starts_with:https://` sugli URL |
| Persistenza | dati costruiti da `validated()` |
| Ordinamento | sequenza riscritta da 1 a N |
| Test | copertura del caso con identificativo appartenente a un'altra struttura |
