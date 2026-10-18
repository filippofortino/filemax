<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Drop;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class StoreDropSubmissionRequest extends FormRequest
{
    /** Line breaks and invisible formatting characters, which could fake a file extension or reshape the owner's email. */
    private const string HIDDEN_CHARACTERS = '/[\p{Cc}\p{Cf}]/u';

    public function authorize(): bool
    {
        abort_unless($this->drop()->isOpen(), 410, 'This Drop is closed.');

        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'sender_name' => ['required', 'string', 'max:120', 'not_regex:'.self::HIDDEN_CHARACTERS],
            'message' => ['nullable', 'string', 'max:2000'],
            'files' => ['required', 'array', 'list', 'min:1', 'max:500'],
            'files.*' => ['required', 'array:name,size,type'],
            'files.*.name' => ['required', 'string', 'max:255', 'not_regex:'.self::HIDDEN_CHARACTERS],
            'files.*.size' => ['required', 'integer', 'min:0', 'max:'.$this->drop()->maxFileSize()],
            'files.*.type' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'sender_name.required' => 'Enter your name so the recipient knows who sent the files.',
            'sender_name.not_regex' => 'Your name can’t contain line breaks or hidden characters.',
            'files.*.name.not_regex' => 'A file name contains hidden characters. Rename the file and try again.',
            'files.*.size.max' => 'A file is larger than this Drop accepts.',
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $drop = $this->drop();
                /** @var list<array{name: string, size: int}> $files */
                $files = $validator->validated()['files'];
                foreach ($files as $index => $file) {
                    if (! $drop->accepts($file['name'])) {
                        $validator->errors()->add('files.'.$index.'.name', '“'.$file['name'].'” isn’t a file type this Drop accepts.');
                    }
                }

                if ($drop->max_submission_size !== null && array_sum(array_column($files, 'size')) > $drop->max_submission_size) {
                    $validator->errors()->add('files', 'These files add up to more than this Drop accepts at once.');
                }
            },
        ];
    }

    private function drop(): Drop
    {
        $drop = $this->route('drop');
        abort_unless($drop instanceof Drop, 404);

        return $drop;
    }
}
