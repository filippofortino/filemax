<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;
use RuntimeException;
use Throwable;

final class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /** @param array<string, mixed> $input */
    public function update(User $user, array $input): void
    {
        /** @var array{name: string, avatar?: UploadedFile|null, remove_avatar?: bool|int|string} $validated */
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'avatar' => [
                'nullable', 'prohibited_if_accepted:remove_avatar', 'image', 'mimes:jpg,jpeg,png', 'max:5120',
                Rule::dimensions()->minWidth(200)->minHeight(200)->maxWidth(4096)->maxHeight(4096),
            ],
            'remove_avatar' => ['sometimes', 'boolean'],
        ])->validateWithBag('updateProfileInformation');

        $path = null;
        if (($validated['avatar'] ?? null) instanceof UploadedFile) {
            try {
                $path = Image::fromUpload($validated['avatar'])
                    ->orient()->cover(256, 256)->toWebp()->quality(80)->store('avatars');
            } catch (Throwable $exception) {
                report($exception);
                $path = false;
            }

            if ($path === false) {
                throw ValidationException::withMessages(['avatar' => 'Your photo could not be saved. Please try another image.'])
                    ->errorBag('updateProfileInformation');
            }
        }

        try {
            $oldPath = DB::transaction(function () use ($user, $validated, $path): ?string {
                $profile = User::query()->lockForUpdate()->findOrFail($user->id);
                $oldPath = $profile->avatar_path;
                $profile->forceFill(['name' => $validated['name']]);

                if ($path !== null || ($validated['remove_avatar'] ?? false)) {
                    $profile->setAttribute('avatar_path', $path);
                }

                $profile->save();

                return $oldPath !== $profile->avatar_path ? $oldPath : null;
            });
        } catch (Throwable $throwable) {
            if ($path !== null) {
                $this->deleteAvatar($path);
            }

            throw $throwable;
        }

        if ($oldPath !== null) {
            $this->deleteAvatar($oldPath);
        }

        $user->refresh();
    }

    private function deleteAvatar(string $path): void
    {
        try {
            report_unless(Storage::delete($path), new RuntimeException('An unused profile photo could not be deleted.'));
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }
}
