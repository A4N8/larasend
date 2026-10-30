<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class EmailEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'email_id',
        'source_id',
        'event_type',
        'ses_message_id',
        'provider_event_id',
        'email_sequence',
        'contract_version',
        'public_event_data',
        'submission_correlation_id',
        'recipient',
        'url',
        'user_agent',
        'ip_address',
        'payload',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'email_sequence' => 'integer',
            'contract_version' => 'integer',
            'public_event_data' => 'array',
        ];
    }

    public function save(array $options = []): bool
    {
        if (! $this->exists || ! $this->isDirty(['email_id', 'source_id', 'event_type', 'ses_message_id', 'provider_event_id',
            'recipient', 'url', 'user_agent', 'ip_address', 'payload', 'occurred_at', 'created_at',
            'email_sequence', 'contract_version', 'public_event_data', 'submission_correlation_id'])) {
            return parent::save($options);
        }

        return $this->getConnection()->transaction(function () use ($options): bool {
            $current = $this->newQuery()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            if ($current->public_event_data !== null) {
                throw new LogicException('Published email event evidence is immutable.');
            }

            return parent::save($options);
        });
    }

    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
