<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Auth;

/**
 * Route-model binding restricted to the authenticated user's own records.
 *
 * Models using this trait must define a `scopeOwnedBy(Builder $query, User|int $user)`
 * scope. A record that does not belong to the current user resolves to null,
 * which the router turns into a 404, so other users' data is never reachable
 * through the API, not even by guessing ids.
 */
trait OwnedByUser
{
    /**
     * Retrieve the model for a bound value, limited to the current user's records.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $user = Auth::user();

        if ($user === null) {
            return null;
        }

        return $this->newQuery()
            ->ownedBy($user)
            ->where($this->qualifyColumn($field ?? $this->getRouteKeyName()), $value)
            ->first();
    }
}
