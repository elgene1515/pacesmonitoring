<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\User
 */
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // The name in parts (null for accounts made before the parts existed), contact details and terms acceptance.
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'suffix' => $this->suffix,
            'phone' => $this->phone,
            'address' => $this->address,
            'terms_accepted_at' => $this->terms_accepted_at?->toISOString(),
            'terms_version' => $this->terms_version,
            'email' => $this->email,
            'email_verified' => $this->hasVerifiedEmail(),
            'role' => $this->roleEnum()->value,
            'is_owner' => $this->isOwner(),
            'is_admin' => $this->isAdmin(),
            'is_teacher' => $this->isTeacher(),
            'is_platform' => $this->isPlatform(),
            // The member's organization was deactivated by the owner (the relation excludes soft-deleted rows).
            'organization_suspended' => $this->organization_id !== null && $this->relationLoaded('organization') && $this->organization === null,
            'organization_id' => $this->organization_id,
            'branch_id' => $this->branch_id,
            'current_school_year_id' => $this->current_school_year_id,
            'organization' => new OrganizationResource($this->whenLoaded('organization')),
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'join_request' => new JoinRequestResource($this->whenLoaded('latestJoinRequest')),
            // Platform staff only: requests waiting across every organization (the navigation badges).
            'pending_counts' => $this->when($this->getAttribute('pending_counts') !== null, fn () => $this->getAttribute('pending_counts')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
