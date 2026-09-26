<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['name', 'slug', 'timezone', 'is_active'])]
final class Organization extends Model
{
    use HasFactory;

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function canvasEnvironments(): HasMany
    {
        return $this->hasMany(CanvasEnvironment::class);
    }

    public function aiGraderClientSetting(): HasOne
    {
        return $this->hasOne(AiGraderClientSetting::class);
    }
}
