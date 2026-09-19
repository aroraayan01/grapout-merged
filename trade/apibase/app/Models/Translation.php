<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One sentence, bought from the provider once. */
class Translation extends Model
{
    protected $fillable = ['hash', 'provider', 'target', 'source', 'text', 'translated'];
}
