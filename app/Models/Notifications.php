<?php

namespace App\Models;

use App\Models\Concerns\BelongsToModule;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Notifications extends Model {
    use BelongsToModule, HasFactory;

    /*
    |----------------------------------------------------------------------
    | Properties & Casts
    |----------------------------------------------------------------------
    */

    protected $fillable = [
        'title',
        'module_id',
        'message',
        'image',
        'item_id',
        'user_id',
        'send_to'
    ];

    // module_id hidden from serialization — see Category.php's $hidden for why.
    protected $hidden = [
        'updated_at',
        'deleted_at',
        'module_id',
    ];

    /*
    |----------------------------------------------------------------------
    | Accessors
    |----------------------------------------------------------------------
    */

    public function getImageAttribute($value) {
        if (!empty($value)) {
            return url(Storage::url($value));
        }
        return "";
    }

    /*
    |----------------------------------------------------------------------
    | Relationships
    |----------------------------------------------------------------------
    */

    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id', 'id');
    }

    /*
    |----------------------------------------------------------------------
    | Scopes
    |----------------------------------------------------------------------
    */

    public function scopeSearch($query, $search) {
        $search = "%" . $search . "%";
        $query = $query->where(function ($q) use ($search) {
            $q->orWhereLike('title', $search)
                ->orWhereLike('message', $search)
                ->orWhereLike('send_to', $search)
                ->orWhereLike('item_id', $search)
                ->orWhereLike('user_id', $search)
                ->orWhereLike('created_at', $search)
                ->orWhereLike('updated_at', $search);
        });
        return $query;
    }
}
