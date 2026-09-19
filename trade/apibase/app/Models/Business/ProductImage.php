<?php

namespace App\Models\Business;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One picture of a product: the original as uploaded, and a display copy
 * for grids when the server could make one (see BusinessImage).
 */
class ProductImage extends Model
{
    protected $table = 'business_product_images';

    protected $fillable = ['product_id', 'path', 'thumb_path', 'sort'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function displayPath(): string
    {
        return $this->thumb_path ?: $this->path;
    }
}
