<?php

namespace App\Http\Resources;

use App\Enums\PaceStockMovementType;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PaceStockMovement
 */
class PaceStockMovementResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $person = static fn ($user) => $user === null ? null : ['id' => $user->id, 'name' => $user->name];

        return [
            'id' => $this->id,
            'pace_id' => $this->pace_id,
            'type' => $this->type instanceof PaceStockMovementType ? $this->type->value : $this->type,
            'quantity' => (int) $this->quantity,
            'from_user_id' => $this->from_user_id,
            'to_user_id' => $this->to_user_id,
            'performed_by' => $this->performed_by,
            'pace_request_id' => $this->pace_request_id,
            'note' => $this->note,
            'from_teacher' => $this->whenLoaded('fromTeacher', fn () => $person($this->fromTeacher)),
            'to_teacher' => $this->whenLoaded('toTeacher', fn () => $person($this->toTeacher)),
            'performer' => $this->whenLoaded('performer', fn () => $person($this->performer)),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
