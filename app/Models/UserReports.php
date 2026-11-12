<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserReports extends Model {
    use HasFactory;

    /*
    |----------------------------------------------------------------------
    | Properties & Casts
    |----------------------------------------------------------------------
    */

    protected $fillable = [
        'id',
        'report_reason_id',
        'item_id',
        'user_id',
        'other_message',
        'reason'
    ];

    /*
    |----------------------------------------------------------------------
    | Accessors
    |----------------------------------------------------------------------
    */

    public function getStatusAttribute($value) {
        if ($this->deleted_at) {
            return "inactive";
        }

        return $value;
    }

    /*
    |----------------------------------------------------------------------
    | Relationships
    |----------------------------------------------------------------------
    */

    public function user() {
        return $this->belongsTo(User::class);
    }

    public function report_reason() {
        return $this->belongsTo(ReportReason::class);
    }

    public function item() {
        return $this->belongsTo(Item::class);
    }

    /*
    |----------------------------------------------------------------------
    | Scopes
    |----------------------------------------------------------------------
    */

    public function scopeSearch($query, $search) {
        $search = "%" . $search . "%";
        $query = $query->where(function ($q) use ($search) {
            $q->orWhereLike('report_reason_id', $search)
                ->orWhereLike('item_id', $search)
                ->orWhereLike('user_id', $search)
                ->orWhereLike('other_message', $search)
                ->orWhereLike('reason', $search)
                ->orWhereLike('created_at', $search)
                ->orWhereLike('updated_at', $search)
                ->orWhereHas('report_reason', function ($q) use ($search) {
                    $q->whereLike('reason', $search);
                })->orWhereHas('item', function ($q) use ($search) {
                    $q->whereLike('name', $search);
                })->orWhereHas('user', function ($q) use ($search) {
                    $q->whereLike('name', $search);
                });
        });
        return $query;
    }

    public function scopeSort($query, $column, $order) {
        if ($column == "item_name") {
            $query = $query->leftjoin('items', 'items.id', '=', 'user_reports.item_id')->orderBy('items.name', $order);
        } else if ($column == "user_name") {
            $query = $query->leftjoin('users', 'users.id', '=', 'user_reports.user_id')->orderBy('users.name', $order);
        } else if ($column == "report_reason_name") {
            $query = $query->leftjoin('report_reasons', 'report_reasons.id', '=', 'user_reports.report_reason_id')->orderBy('report_reasons.reason', $order);
        } else {
            $query = $query->orderBy($column, $order);
        }

        return $query->select('user_reports.*');
    }

    public function scopeFilter($query, $filterObject) {
        if (!empty($filterObject)) {
            foreach ($filterObject as $column => $value) {
                $query->where((string)$column, (string)$value);
            }
        }
        return $query;

    }
}
