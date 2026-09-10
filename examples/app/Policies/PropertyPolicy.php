<?php

namespace App\Policies;

use App\Models\Property;
use App\Models\User;

class PropertyPolicy
{
    /**
     * Admin bypass — grants any ability to admin users.
     * Returns null for owners so they fall through to per-method checks.
     */
    public function before(User $user, string $ability): bool|null
    {
        if ($user->role === 'admin') {
            return true;
        }

        return null;
    }

    /**
     * Determine whether the user can view the property.
     * An owner can only see their own properties.
     */
    public function view(User $user, Property $property): bool
    {
        return $user->id === $property->user_id;
    }

    /**
     * Determine whether the user can update the property.
     * An owner can only edit their own properties.
     */
    public function update(User $user, Property $property): bool
    {
        return $user->id === $property->user_id;
    }

    /**
     * Determine whether the user can delete the property.
     * An owner can only delete their own properties.
     */
    public function delete(User $user, Property $property): bool
    {
        return $user->id === $property->user_id;
    }
}
