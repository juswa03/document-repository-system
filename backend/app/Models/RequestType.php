<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RequestType extends Model
{
    protected $fillable = ['type_name', 'type_code'];

    public function requests(): HasMany
    {
        return $this->hasMany(SubmissionRequest::class);
    }
}
