<?php

namespace App\Models\Outreach;

use App\Models\Business\Page;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An email written in words, with placeholders the mailer fills in for
 * each contact. The platform keeps some for every page; a page may write
 * its own.
 */
class Template extends Model
{
    use HasUuids;

    protected $table = 'outreach_templates';

    public const KINDS = ['invitation', 'catalogue', 'custom'];

    /** What a template may say, and what each one becomes. */
    public const PLACEHOLDERS = [
        'contact_name' => "The contact person's name, or the company, or 'there'",
        'company_name' => "The contact's company",
        'page_name' => 'Your company name',
        'page_link' => 'The link to your page on GrapOut Trade',
        'catalogue_link' => 'The link to your PDF catalogue',
        'sells' => 'What you sell, from your trade lines',
        'buys' => 'What you buy, from your trade lines',
        'sender_name' => 'The name of the person sending',
        'note' => 'The personal line typed when sending',
    ];

    protected $fillable = ['page_id', 'name', 'kind', 'subject', 'body', 'with_prices', 'active', 'created_by'];

    protected function casts(): array
    {
        return ['with_prices' => 'boolean', 'active' => 'boolean'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }
}
