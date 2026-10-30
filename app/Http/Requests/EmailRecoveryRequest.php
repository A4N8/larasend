<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class EmailRecoveryRequest extends ApiSourceRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['client_key' => trim((string) $this->header('Idempotency-Key', ''))]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'client_key' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (strlen($value) > 255 || preg_match('//u', $value) !== 1) {
                    $fail('The Idempotency-Key header must contain valid UTF-8 and may not exceed 255 bytes.');
                }
            }],
        ];
    }
}
