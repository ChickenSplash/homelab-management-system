<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public static $wrap = null;

    /**
     * The public ID is the only identifier that leaves the API; the numeric key stays internal.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...$this->resource->toPublicArray(),
            'email' => $this->when($request->user()?->is($this->resource), $this->email),
        ];
    }
}
