<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocalRecommendation extends Model
{
    use HasFactory;

    /**
     * All valid recommendation category keys.
     *
     * Used as a whitelist for `in:` validation rules on the category field.
     * Any new category addition must go through a deliberate schema + UX decision.
     */
    public const CATEGORIES = [
        'restaurant',
        'bar',
        'supermarket',
        'pharmacy',
        'beach',
        'parking',
        'attraction',
        'taxi',
        'transfer',
        'other',
    ];

    protected $fillable = [
        'property_id',
        'category',
        'name',
        'description',
        'address',
        'maps_url',
        'phone',
        'website_url',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * Return the Italian display label for a given recommendation category.
     */
    public static function categoryLabel(string $category): string
    {
        return match ($category) {
            'restaurant'  => 'Ristorante',
            'bar'         => 'Bar / Caffè',
            'supermarket' => 'Supermercato',
            'pharmacy'    => 'Farmacia',
            'beach'       => 'Spiaggia',
            'parking'     => 'Parcheggio',
            'attraction'  => 'Attrazione',
            'taxi'        => 'Taxi',
            'transfer'    => 'Transfer',
            'other'       => 'Altro',
            default       => $category,
        };
    }
}
