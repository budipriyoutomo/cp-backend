<?php

namespace App\Models;

use App\Traits\HasUuid;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pesan `closingreport.submitted` yang menunggu (atau sudah) dikirim ke BI.
 *
 * Plain Model, bukan BaseModel — sama seperti FailedPosMessage: ini log
 * operasional, bukan data domain. Percobaan ulang dijalankan scheduler tanpa
 * user, dan baris yang dihapus lunak tidak ada artinya bagi antrean kirim.
 *
 * Kebijakan retry (batas percobaan, jeda) bukan urusan model ini; pemanggil
 * yang menentukan `maxAttempts` dan kapan percobaan berikutnya.
 */
class ClosingReportOutbox extends Model
{
    use HasUuid;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_FAILED = 'failed';

    protected $table = 'closing_report_outbox';

    protected $fillable = [
        'closing_report_id',
        'payload',
        'status',
        'attempts',
        'last_error',
        'next_attempt_at',
        'published_at',
    ];

    protected $attributes = [
        'status'   => self::STATUS_PENDING,
        'attempts' => 0,
    ];

    protected $casts = [
        'payload'         => 'array',
        'attempts'        => 'integer',
        'next_attempt_at' => 'datetime',
        'published_at'    => 'datetime',
    ];

    public function closingReport(): BelongsTo
    {
        return $this->belongsTo(ClosingReport::class, 'closing_report_id');
    }

    /**
     * Baris yang boleh dicoba kirim sekarang: belum terkirim, jatah percobaan
     * belum habis, dan jadwal percobaan berikutnya sudah lewat (atau belum ada).
     */
    public function scopeDue(Builder $query, int $maxAttempts): Builder
    {
        return $query
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_FAILED])
            ->where('attempts', '<', $maxAttempts)
            ->where(function (Builder $q) {
                $q->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('created_at');
    }

    public function markPublished(): void
    {
        $this->update([
            'status'          => self::STATUS_PUBLISHED,
            'attempts'        => $this->attempts + 1,
            'published_at'    => now(),
            'last_error'      => null,
            'next_attempt_at' => null,
        ]);
    }

    public function markFailed(string $error, DateTimeInterface $retryAt): void
    {
        $this->update([
            'status'          => self::STATUS_FAILED,
            'attempts'        => $this->attempts + 1,
            'last_error'      => $error,
            'next_attempt_at' => $retryAt,
        ]);
    }
}
