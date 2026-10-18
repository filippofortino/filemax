<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Drop;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreDropRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Drop::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'accept_days' => ['required', 'integer', Rule::in([1, 7, 14, 30])],
            'keep_days' => ['required', 'integer', Rule::in([7, 14, 30])],
            'max_file_size' => ['nullable', 'integer', 'min:1', 'max:'.Drop::MAX_FILE_SIZE],
            'max_submission_size' => ['nullable', 'integer', 'min:1', Rule::when($this->filled('max_file_size'), ['gte:max_file_size'])],
            'file_types' => ['nullable', 'array', 'list'],
            'file_types.*' => ['required', 'string', 'distinct', Rule::in(array_keys(Drop::FILE_TYPES))],
            'extensions' => ['nullable', 'array', 'list', 'max:20'],
            'extensions.*' => ['required', 'string', 'distinct', 'regex:/^[a-z0-9]{1,16}$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'title.required' => 'Add a title so people know what to send.',
            'max_file_size.max' => 'Filemax accepts files up to 100 GB. Enter 100 GB or less.',
            'max_submission_size.gte' => 'Make the total at least the size per file.',
            'extensions.*.regex' => 'Use letters and numbers only for extensions, like .indd or .zip.',
        ];
    }

    public function prepareForValidation(): void
    {
        $extensions = $this->input('extensions');

        if (is_array($extensions)) {
            $normalised = array_map(fn (mixed $extension): mixed => is_string($extension) ? mb_strtolower(mb_ltrim(mb_trim($extension), '.')) : $extension, $extensions);
            $this->merge(['extensions' => array_values(array_unique(array_filter($normalised, fn (mixed $extension): bool => $extension !== ''), SORT_REGULAR))]);
        }
    }
}
