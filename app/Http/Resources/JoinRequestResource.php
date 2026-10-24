<?php

namespace App\Http\Resources;

use App\Enums\JoinRequestStatus;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\JoinRequest
 */
class JoinRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $status = $this->status instanceof JoinRequestStatus ? $this->status->value : (string) $this->status;

        return [
            'id' => $this->id,
            'status' => $status,
            'message' => $this->message,
            'decline_reason' => $this->decline_reason,
            'user_id' => $this->user_id,
            'organization_id' => $this->organization_id,
            'branch_id' => $this->branch_id,
            // 'teacher' or 'admin': what the person asked to join as.
            'requested_role' => $this->requestedRole()->value,
            'user' => $this->whenLoaded('user', fn () => $this->user === null ? null : [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'created_at' => $this->user->created_at?->toISOString(),
            ]),
            'organization' => $this->whenLoaded('organization', fn () => $this->organization === null ? null : [
                'id' => $this->organization->id,
                'name' => $this->organization->name,
                'code' => $this->organization->code,
            ]),
            'branch' => $this->whenLoaded('branch', fn () => $this->branch === null ? null : [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'code' => $this->branch->code,
            ]),
            'decided_by' => $this->whenLoaded('decider', fn () => $this->decider === null ? null : [
                'id' => $this->decider->id,
                'name' => $this->decider->name,
            ]),
            'decided_at' => $this->decided_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
