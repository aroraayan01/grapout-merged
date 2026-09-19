<?php

namespace App\Models\Business;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One document in a company's folder: what it is, the number on it, and
 * the file on the private disk. Two of these earn a verification request.
 */
class CompanyDocument extends Model
{
    use HasUuids;

    public const KINDS = ['gst', 'iec', 'registration', 'tax', 'msme', 'license', 'other'];

    public const KIND_LABELS = [
        'gst' => 'GST certificate', 'iec' => 'IEC certificate', 'registration' => 'Company registration', 'tax' => 'Tax ID',
        'msme' => 'MSME / Udyam certificate', 'license' => 'Import or export licence', 'other' => 'Other document',
    ];

    protected $fillable = ['page_id', 'user_id', 'kind', 'number', 'path', 'original_name', 'mime', 'size'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
