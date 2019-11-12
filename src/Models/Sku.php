<?php

namespace Gtd\Sku\Models;

use Gtd\Sku\Contracts\SkuContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Sku extends Model implements SkuContract
{
    protected $guarded = ['id'];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('sku.table_names.skus'));
    }

    public function producible(): MorphTo
    {
        return $this->morphTo(config('sku.morph_name'));
    }

    public function attrs(): BelongsToMany
    {
        return $this->belongsToMany(config('sku.models.attr'));
    }
}