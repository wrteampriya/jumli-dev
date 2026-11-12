<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// One row per module setting key, shared by every module. Rows come from
// App\Services\Module\ModuleService::settingDefinitions() — never edited by hand.
class ModuleSettingDefinition extends Model
{
    protected $fillable = [
        'name',
        'type',
        'default_value',
        'options',
        'rules',
        'is_required',
        'section',
        'label',
        'help_text',
    ];

    protected $casts = [
        'options' => 'array',
        'is_required' => 'boolean',
    ];

    public function values()
    {
        return $this->hasMany(ModuleSettingValue::class);
    }
}
