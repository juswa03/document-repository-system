<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = ['category_name', 'category_code'];

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
