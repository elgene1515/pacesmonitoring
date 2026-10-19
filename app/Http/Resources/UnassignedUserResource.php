<?php

namespace App\Http\Resources;

use App\Enums\JoinRequestStatus;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An account that belongs to no organization yet (owner's home page).
 *
 * @mixin \App\Models\User
 */
class UnassignedUserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $joinRequest = $this->relationLoaded('latestJoinRequest') ? $this->latestJoinRequest : null;
        $records = (array) ($this->resource->getAttribute('records') ?? []);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->roleEnum()->value,
            'created_at' => $this->created_at?->toISOString(),
            // Set when a school removed the member; null for accounts that never joined one.
            'left_organization_at' => $this->left_organization_at?->toISOString(),
            // How far back "not in a school" goes: the removal, else the sign-up.
            'unassigned_since' => ($this->left_organization_at ?? $this->created_at)?->toISOString(),
            'join_request' => $joinRequest === null ? null : [
                'id' => $joinRequest->id,
                'status' => $joinRequest->status instanceof JoinRequestStatus ? $joinRequest->status->value : (string) $joinRequest->status,
                'organization_name' => $joinRequest->organization?->name,
                'branch_name' => $joinRequest->branch?->name,
                'decline_reason' => $joinRequest->decline_reason,
                'created_at' => $joinRequest->created_at?->toISOString(),
            ],
            'records' => $records + ['total' => array_sum($records)],
        ];
    }
}
