<?php

namespace App\Http\Requests;

use App\Rules\PublicWebhookUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWebhookEndpointRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'url' => ['bail', 'required', 'url:http,https', 'max:2048', new PublicWebhookUrl],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in([
                'delivery', 'bounce', 'complaint', 'open', 'click', 'suppress', 'inbound.received',
                'submission_started', 'submission_accepted', 'submission_rejected', 'delivery_uncertain', 'failed',
                'send', 'reject', 'delivery_delay', 'rendering_failure',
            ])],
            'status' => ['required', Rule::in(['active', 'paused'])],
        ];
    }
}
