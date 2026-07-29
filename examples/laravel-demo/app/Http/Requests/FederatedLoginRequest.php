<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class FederatedLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:255'],
        ];
    }

    public function subject(): string
    {
        return (string) $this->validated()['subject'];
    }
}
