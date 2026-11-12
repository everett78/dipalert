<?php

namespace App\Policies;

use App\Models\Collection as ProductCollection;
use App\Models\User;

/**
 * Like ProductPolicy: only the owner can see or change a collection.
 */
class CollectionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ProductCollection $collection): bool
    {
        return $this->owns($user, $collection);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, ProductCollection $collection): bool
    {
        return $this->owns($user, $collection);
    }

    public function delete(User $user, ProductCollection $collection): bool
    {
        return $this->owns($user, $collection);
    }

    public function restore(User $user, ProductCollection $collection): bool
    {
        return $this->owns($user, $collection);
    }

    public function forceDelete(User $user, ProductCollection $collection): bool
    {
        return $this->owns($user, $collection);
    }

    protected function owns(User $user, ProductCollection $collection): bool
    {
        return (int) $collection->user_id === (int) $user->getKey();
    }
}
