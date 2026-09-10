<?php

use App\Livewire\Properties\LocalRecommendationManager;
use App\Models\LocalRecommendation;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// ──────────────────────────────────────────────────────────────────────────────
// CRUD consigli locali — solo il proprietario
// ──────────────────────────────────────────────────────────────────────────────

describe('CRUD consigli locali — solo il proprietario', function () {

    test('owner can add a recommendation to own property', function () {
        $owner    = User::factory()->create(['role' => 'owner']);
        $property = Property::factory()->create(['user_id' => $owner->id]);
        $countBefore = $property->localRecommendations()->count();

        Livewire::actingAs($owner)
            ->test(LocalRecommendationManager::class, ['property' => $property])
            ->set('editCategory', 'restaurant')
            ->set('editName', 'Trattoria del Porto')
            ->set('editDescription', 'Ottimo pesce fresco')
            ->call('saveRecommendation');

        expect($property->localRecommendations()->count())->toBe($countBefore + 1);
        expect($property->localRecommendations()->where('name', 'Trattoria del Porto')->exists())->toBeTrue();
    });

    test('owner can edit a recommendation on own property', function () {
        $owner    = User::factory()->create(['role' => 'owner']);
        $property = Property::factory()->create(['user_id' => $owner->id]);
        $recommendation = $property->localRecommendations()->create([
            'category'   => 'bar',
            'name'       => 'Bar Vecchio Nome',
            'sort_order' => 1,
            'is_active'  => true,
        ]);

        Livewire::actingAs($owner)
            ->test(LocalRecommendationManager::class, ['property' => $property])
            ->set('editingId', $recommendation->id)
            ->set('editCategory', 'bar')
            ->set('editName', 'Bar Nuovo Nome')
            ->set('editDescription', 'Descrizione aggiornata')
            ->call('saveRecommendation');

        expect($recommendation->fresh()->name)->toBe('Bar Nuovo Nome');
        expect($recommendation->fresh()->description)->toBe('Descrizione aggiornata');
    });

    test('owner can delete a recommendation from own property', function () {
        $owner    = User::factory()->create(['role' => 'owner']);
        $property = Property::factory()->create(['user_id' => $owner->id]);
        $recommendation = $property->localRecommendations()->create([
            'category'   => 'pharmacy',
            'name'       => 'Farmacia Centrale',
            'sort_order' => 1,
            'is_active'  => true,
        ]);

        $countBefore = $property->localRecommendations()->count();

        Livewire::actingAs($owner)
            ->test(LocalRecommendationManager::class, ['property' => $property])
            ->call('deleteRecommendation', $recommendation->id);

        expect($property->localRecommendations()->count())->toBe($countBefore - 1);
        expect(LocalRecommendation::find($recommendation->id))->toBeNull();
    });

    test('non-owner cannot add recommendation to another owner\'s property (BOLA)', function () {
        // Un owner non autorizzato non arriva nemmeno a montare il componente.
        $ownerA   = User::factory()->create(['role' => 'owner']);
        $ownerB   = User::factory()->create(['role' => 'owner']);
        $property = Property::factory()->create(['user_id' => $ownerA->id]);

        Livewire::actingAs($ownerB)
            ->test(LocalRecommendationManager::class, ['property' => $property])
            ->assertForbidden();
    });

    test('non-owner cannot edit a recommendation on another owner\'s property (BOLA)', function () {
        $ownerA   = User::factory()->create(['role' => 'owner']);
        $ownerB   = User::factory()->create(['role' => 'owner']);
        $propertyA = Property::factory()->create(['user_id' => $ownerA->id]);
        $propertyB = Property::factory()->create(['user_id' => $ownerB->id]);
        $recommendation = $propertyA->localRecommendations()->create([
            'category'   => 'restaurant',
            'name'       => 'Ristorante di A',
            'sort_order' => 1,
            'is_active'  => true,
        ]);

        // ownerB tries to mount against their own property but with ownerA's recommendation id
        // The scoped query on ownerB's property will not find ownerA's recommendation -> ModelNotFoundException
        expect(fn () =>
            Livewire::actingAs($ownerB)
                ->test(LocalRecommendationManager::class, ['property' => $propertyB])
                ->call('deleteRecommendation', $recommendation->id)
        )->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    });

    test('non-owner cannot delete a recommendation from another owner\'s property (BOLA)', function () {
        $ownerA   = User::factory()->create(['role' => 'owner']);
        $ownerB   = User::factory()->create(['role' => 'owner']);
        $propertyA = Property::factory()->create(['user_id' => $ownerA->id]);
        $propertyB = Property::factory()->create(['user_id' => $ownerB->id]);
        $recommendation = $propertyA->localRecommendations()->create([
            'category'   => 'bar',
            'name'       => 'Bar di A',
            'sort_order' => 1,
            'is_active'  => true,
        ]);

        // Mount on ownerB's property — recommendation belongs to ownerA, scoped query finds nothing
        expect(fn () =>
            Livewire::actingAs($ownerB)
                ->test(LocalRecommendationManager::class, ['property' => $propertyB])
                ->call('deleteRecommendation', $recommendation->id)
        )->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    });

});

// ──────────────────────────────────────────────────────────────────────────────
// Attivazione e disattivazione
// ──────────────────────────────────────────────────────────────────────────────

describe('toggle is_active', function () {

    test('toggling is_active on a recommendation persists to database', function () {
        $owner    = User::factory()->create(['role' => 'owner']);
        $property = Property::factory()->create(['user_id' => $owner->id]);
        $recommendation = $property->localRecommendations()->create([
            'category'   => 'supermarket',
            'name'       => 'Supermercato Test',
            'sort_order' => 1,
            'is_active'  => true,
        ]);

        Livewire::actingAs($owner)
            ->test(LocalRecommendationManager::class, ['property' => $property])
            ->call('toggleActive', $recommendation->id);

        expect($recommendation->fresh()->is_active)->toBeFalse();

        Livewire::actingAs($owner)
            ->test(LocalRecommendationManager::class, ['property' => $property])
            ->call('toggleActive', $recommendation->id);

        expect($recommendation->fresh()->is_active)->toBeTrue();
    });

});

// ──────────────────────────────────────────────────────────────────────────────
// Validazione dei campi
// ──────────────────────────────────────────────────────────────────────────────

describe('validazione', function () {

    test('creating a recommendation with an invalid category is rejected', function () {
        // La categoria deve appartenere a LocalRecommendation::CATEGORIES.
        $owner    = User::factory()->create(['role' => 'owner']);
        $property = Property::factory()->create(['user_id' => $owner->id]);

        Livewire::actingAs($owner)
            ->test(LocalRecommendationManager::class, ['property' => $property])
            ->set('editCategory', 'invalid_category')
            ->set('editName', 'Posto Test')
            ->call('saveRecommendation')
            ->assertHasErrors(['editCategory']);

        expect($property->localRecommendations()->where('name', 'Posto Test')->exists())->toBeFalse();
    });

    test('creating a recommendation with a valid category succeeds', function () {
        $owner    = User::factory()->create(['role' => 'owner']);
        $property = Property::factory()->create(['user_id' => $owner->id]);

        foreach (['restaurant', 'bar', 'pharmacy', 'parking', 'other'] as $category) {
            Livewire::actingAs($owner)
                ->test(LocalRecommendationManager::class, ['property' => $property])
                ->set('editCategory', $category)
                ->set('editName', "Test {$category}")
                ->call('saveRecommendation')
                ->assertHasNoErrors();
        }

        expect($property->localRecommendations()->count())->toBe(5);
    });

    test('maps_url without https scheme is rejected', function () {
        // maps_url deve iniziare con https://.
        $owner    = User::factory()->create(['role' => 'owner']);
        $property = Property::factory()->create(['user_id' => $owner->id]);

        Livewire::actingAs($owner)
            ->test(LocalRecommendationManager::class, ['property' => $property])
            ->set('editCategory', 'restaurant')
            ->set('editName', 'Ristorante Test')
            ->set('editMapsUrl', 'http://maps.google.com/test')
            ->call('saveRecommendation')
            ->assertHasErrors(['editMapsUrl']);
    });

    test('optional fields (address, maps_url, phone, website_url) are saved correctly', function () {
        $owner    = User::factory()->create(['role' => 'owner']);
        $property = Property::factory()->create(['user_id' => $owner->id]);

        Livewire::actingAs($owner)
            ->test(LocalRecommendationManager::class, ['property' => $property])
            ->set('editCategory', 'attraction')
            ->set('editName', 'Museo del Mare')
            ->set('editDescription', 'Bellissimo museo')
            ->set('editAddress', 'Via Marina 1')
            ->set('editMapsUrl', 'https://maps.google.com/museo')
            ->set('editPhone', '+39 091 123456')
            ->set('editWebsiteUrl', 'https://museodelmare.it')
            ->call('saveRecommendation')
            ->assertHasNoErrors();

        $rec = $property->localRecommendations()->where('name', 'Museo del Mare')->first();
        expect($rec)->not->toBeNull();
        expect($rec->address)->toBe('Via Marina 1');
        expect($rec->maps_url)->toBe('https://maps.google.com/museo');
        expect($rec->phone)->toBe('+39 091 123456');
        expect($rec->website_url)->toBe('https://museodelmare.it');
    });

    test('updateRecommendationsOrder reorders recommendations correctly', function () {
        $owner    = User::factory()->create(['role' => 'owner']);
        $property = Property::factory()->create(['user_id' => $owner->id]);

        $rec1 = $property->localRecommendations()->create(['category' => 'restaurant', 'name' => 'Primo', 'sort_order' => 1, 'is_active' => true]);
        $rec2 = $property->localRecommendations()->create(['category' => 'bar', 'name' => 'Secondo', 'sort_order' => 2, 'is_active' => true]);
        $rec3 = $property->localRecommendations()->create(['category' => 'pharmacy', 'name' => 'Terzo', 'sort_order' => 3, 'is_active' => true]);

        // Move rec3 to position 1
        Livewire::actingAs($owner)
            ->test(LocalRecommendationManager::class, ['property' => $property])
            ->call('updateRecommendationsOrder', $rec3->id, 1);

        expect($rec3->fresh()->sort_order)->toBe(1);
        expect($rec1->fresh()->sort_order)->toBe(2);
        expect($rec2->fresh()->sort_order)->toBe(3);
    });

});
