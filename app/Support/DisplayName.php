<?php

namespace App\Support;

class DisplayName
{
    /**
     * Any name at all, as long as it has something visible and no control characters.
     *
     * @return array<int, mixed>
     */
    public static function rules(): array
    {
        return ['required', 'string', 'max:32', 'regex:/\S/u', 'not_regex:/\p{C}/u'];
    }
}
