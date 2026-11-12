<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// A module's own value for one ModuleSettingDefinition. No row means the
// module uses the definition's default_value.
class ModuleSettingValue extends Model
{
    protected $fillable = [
        'module_id',
        'module_setting_definition_id',
        'value',
    ];

    public function module()
    {
        return $this->belongsTo(Module::class);
    }

    public function definition()
    {
        return $this->belongsTo(ModuleSettingDefinition::class, 'module_setting_definition_id');
    }
}
