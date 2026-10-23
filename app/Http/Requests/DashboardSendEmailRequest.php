<?php

namespace App\Http\Requests;

class DashboardSendEmailRequest extends SendEmailRequest
{
    protected function prepareForValidation(): void
    {
        $payload = [];

        foreach (['to', 'cc', 'bcc'] as $field) {
            if (! $this->exists($field)) {
                continue;
            }

            if (blank($this->input($field))) {
                $payload[$field] = [];

                continue;
            }

            if (is_string($this->input($field))) {
                $payload[$field] = collect(preg_split('/[\n,]+/', (string) $this->input($field)) ?: [])
                    ->map(fn (string $address) => trim($address))
                    ->filter()
                    ->values()
                    ->all();
            }
        }

        $this->merge($payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        foreach (array_keys($rules) as $field) {
            if ($field === 'attachments' || str_starts_with($field, 'attachments.')) {
                unset($rules[$field]);
            }
        }
        $rules['attachments'] = ['prohibited'];

        return $rules;
    }
}
