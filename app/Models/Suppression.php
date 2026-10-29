<?php

namespace App\Models;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Suppression extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'project_id',
        'source_id',
        'email_id',
        'email',
        'reason',
        'event_type',
        'expires_at',
        'provider_metadata',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'provider_metadata' => 'array',
        ];
    }

    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $email): string => self::normalizeEmail($email));
    }

    public static function normalizeEmail(string $email): string
    {
        return strtr(trim($email, ' '), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }

    /** @param Builder<Suppression> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /** @param Builder<Suppression> $query */
    public function scopeWhereNormalizedEmail(Builder $query, string $email): void
    {
        $query->where($this->normalizedEmailColumn(), self::normalizeEmail($email));
    }

    /**
     * @param  Builder<Suppression>  $query
     * @param  iterable<int, string>  $emails
     */
    public function scopeWhereNormalizedEmailIn(Builder $query, iterable $emails): void
    {
        $query->whereIn($this->normalizedEmailColumn(), $this->normalizeEmails($emails));
    }

    /**
     * @param  Builder<Suppression>  $query
     * @param  iterable<int, string>  $emails
     */
    public function scopeWhereNormalizedEmailNotIn(Builder $query, iterable $emails): void
    {
        $query->whereNotIn($this->normalizedEmailColumn(), $this->normalizeEmails($emails));
    }

    private function normalizedEmailColumn(): string|Expression
    {
        if (in_array($this->getConnection()->getDriverName(), ['mysql', 'mariadb', 'sqlsrv'], true)) {
            return 'email_normalized';
        }

        $expression = 'TRIM(email)';

        foreach (range('A', 'Z') as $uppercase) {
            $expression = sprintf("REPLACE(%s, '%s', '%s')", $expression, $uppercase, strtolower($uppercase));
        }

        return DB::raw($expression);
    }

    /**
     * @param  iterable<int, string>  $emails
     * @return array<int, string>
     */
    private function normalizeEmails(iterable $emails): array
    {
        return collect($emails)->map(fn (string $email): string => self::normalizeEmail($email))->uniqueStrict()->values()->all();
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function emailMessage(): BelongsTo
    {
        return $this->belongsTo(Email::class, 'email_id');
    }
}
