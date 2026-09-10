<?php

namespace App\Livewire\Properties;

use App\Models\LocalRecommendation;
use App\Models\Property;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Full-page CRUD for a property's local recommendations.
 *
 * Every method that touches the database authorizes against the property first,
 * then loads the recommendation through the scoped relation
 * ($this->property->localRecommendations()->findOrFail()). An id belonging to a
 * different property is therefore never found, so no explicit property_id check
 * is needed anywhere in this class.
 *
 * Editing happens in a Flux modal; ordering is handled by wire:sort.
 */
#[Title('Consigli locali')]
class LocalRecommendationManager extends Component
{
    // ── Model reference ───────────────────────────────────────────────────────
    public Property $property;

    // ── Modal form properties ─────────────────────────────────────────────────
    public ?int $editingId = null;

    public string $editCategory    = 'restaurant';
    public string $editName        = '';
    public string $editDescription = '';
    public string $editAddress     = '';
    public string $editMapsUrl     = '';
    public string $editPhone       = '';
    public string $editWebsiteUrl  = '';

    /**
     * Mount the component.
     *
     * The property arrives via route model binding. Authorization runs before
     * the property is assigned.
     */
    public function mount(Property $property): void
    {
        $this->authorize('update', $property);
        $this->property = $property;
    }

    // ── Toggle action ─────────────────────────────────────────────────────────

    /**
     * Toggle is_active for a single recommendation.
     */
    public function toggleActive(int $id): void
    {
        $this->authorize('update', $this->property);
        $recommendation = $this->property->localRecommendations()->findOrFail($id);
        $recommendation->update(['is_active' => ! $recommendation->is_active]);

        if ($recommendation->fresh()->is_active) {
            Flux::toast(variant: 'success', text: 'Consiglio attivato.');
        } else {
            Flux::toast(variant: 'warning', text: 'Consiglio disattivato.');
        }
    }

    /**
     * Rebuild sort_order for the whole list after a drag-and-drop.
     *
     * The database does not guarantee a return order, so the collection is
     * explicitly ordered before being rebuilt. sort_order is rewritten as 1..N
     * to avoid gaps and duplicates.
     *
     * @param int $id       The moved recommendation's DB id
     * @param int $position The new 1-based position in the list
     */
    public function updateRecommendationsOrder(int $id, int $position): void
    {
        $this->authorize('update', $this->property);

        $recommendations = $this->property->localRecommendations()->orderBy('sort_order')->get();

        // The moved item must belong to this property.
        $moved = $recommendations->firstWhere('id', $id);
        if (! $moved) {
            return;
        }

        $reordered = $recommendations->reject(fn ($r) => $r->id === $id)->values();

        // $position is 1-based; insert before the item currently at that index.
        $insertAt = max(0, $position - 1);
        $before   = $reordered->slice(0, $insertAt);
        $after    = $reordered->slice($insertAt);

        $final = $before->push($moved)->merge($after)->values();

        foreach ($final as $index => $recommendation) {
            $recommendation->update(['sort_order' => $index + 1]);
        }
    }

    // ── Modal actions ─────────────────────────────────────────────────────────

    /**
     * Open the create modal, resetting every form property to avoid stale state.
     */
    public function openCreate(): void
    {
        $this->editingId       = null;
        $this->editCategory    = 'restaurant';
        $this->editName        = '';
        $this->editDescription = '';
        $this->editAddress     = '';
        $this->editMapsUrl     = '';
        $this->editPhone       = '';
        $this->editWebsiteUrl  = '';

        Flux::modal('edit-recommendation')->show();
    }

    /**
     * Open the edit modal pre-populated with the recommendation's data.
     */
    public function openEdit(int $id): void
    {
        $this->authorize('update', $this->property);
        $recommendation = $this->property->localRecommendations()->findOrFail($id);

        // Reset form first (avoid stale state)
        $this->openCreate();

        $this->editingId       = $recommendation->id;
        $this->editCategory    = $recommendation->category;
        $this->editName        = $recommendation->name;
        $this->editDescription = $recommendation->description ?? '';
        $this->editAddress     = $recommendation->address ?? '';
        $this->editMapsUrl     = $recommendation->maps_url ?? '';
        $this->editPhone       = $recommendation->phone ?? '';
        $this->editWebsiteUrl  = $recommendation->website_url ?? '';

        Flux::modal('edit-recommendation')->show();
    }

    /**
     * Create or update a recommendation.
     *
     * The category is validated against LocalRecommendation::CATEGORIES.
     * maps_url requires starts_with:https:// on top of url, because url alone
     * would accept schemes such as javascript: and the value ends up in an href
     * on the public page. The saved payload is built from validated(), never
     * from the component's public properties, which are writable by the client.
     */
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
                'editName.required'     => 'Il nome del consiglio è obbligatorio.',
                'editCategory.required' => 'Seleziona una categoria.',
                'editCategory.in'       => 'Seleziona una categoria.',
                'editMapsUrl.url'       => 'Il link Google Maps deve essere un URL valido.',
                'editMapsUrl.starts_with' => 'Il link Google Maps deve iniziare con https://.',
                'editWebsiteUrl.url'    => 'Il sito web deve essere un URL valido.',
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
            // Edit mode
            $this->property->localRecommendations()->findOrFail($this->editingId)->update($data);
        } else {
            // Create mode
            $maxOrder = $this->property->localRecommendations()->max('sort_order') ?? 0;
            $data['sort_order'] = $maxOrder + 1;
            $data['is_active']  = true;
            $this->property->localRecommendations()->create($data);
        }

        Flux::modal('edit-recommendation')->close();
        Flux::toast(variant: 'success', text: 'Consiglio salvato.');
    }

    /**
     * Delete a recommendation. Every recommendation is deletable.
     */
    public function deleteRecommendation(int $id): void
    {
        $this->authorize('update', $this->property);
        $recommendation = $this->property->localRecommendations()->findOrFail($id);
        $recommendation->delete();
        Flux::toast(variant: 'success', text: 'Consiglio eliminato.');
    }

    public function render()
    {
        $recommendations = $this->property->localRecommendations()->orderBy('sort_order')->get();

        return view('livewire.properties.local-recommendation-manager', compact('recommendations'))
            ->layout('layouts.app', ['title' => 'Consigli locali']);
    }
}
