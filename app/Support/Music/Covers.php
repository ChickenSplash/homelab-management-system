<?php

namespace App\Support\Music;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

// Cover art files: uploaded in the studio, or pulled out of an MP3 by the scanner.
class Covers
{
    public const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public static function root(): string
    {
        return rtrim(config('music.covers'), '/');
    }

    public static function storeUpload(UploadedFile $file): string
    {
        return static::put($file->get(), $file->getMimeType());
    }

    /** Saves the image under a random name and returns that name, or null for an unknown type. */
    public static function put(string $data, ?string $mime): ?string
    {
        $extension = self::EXTENSIONS[$mime] ?? null;

        if (! $extension) {
            return null;
        }

        File::ensureDirectoryExists(static::root());
        $name = Str::random(32).'.'.$extension;
        File::put(static::root().'/'.$name, $data);

        return $name;
    }

    public static function path(string $name): string
    {
        return static::root().'/'.basename($name);
    }

    public static function delete(?string $name): void
    {
        if ($name) {
            File::delete(static::path($name));
        }
    }
}
