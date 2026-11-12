<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class UserPurchasedPackage extends Model {
    use HasFactory;

    /*
    |----------------------------------------------------------------------
    | Properties & Casts
    |----------------------------------------------------------------------
    */

    protected $fillable = [
        'user_id',
        'package_id',
        'start_date',
        'end_date',
        'total_limit',
        'used_limit',
        'payment_transactions_id',
        'listing_duration_type' ,
        'listing_duration_days' 
    ];

    protected $appends = ['remaining_days', 'remaining_item_limit', 'status'];

    /*
    |----------------------------------------------------------------------
    | Accessors
    |----------------------------------------------------------------------
    */

    public function getRemainingDaysAttribute() {
        if (!empty($this->end_date)) {
            $startDate = Carbon::createFromFormat('Y-m-d', $this->start_date);
            $endDate = Carbon::createFromFormat('Y-m-d', $this->end_date);
            return $startDate->diffInDays($endDate);
        }
        return "unlimited";
    }

    public function getStatusAttribute()
    {
        if (empty($this->end_date)) {
            return "Active"; // Or "Unlimited" if you prefer
        }

        $endDate = Carbon::createFromFormat('Y-m-d', $this->end_date)->startOfDay();
        $now = Carbon::now()->startOfDay();

        return $now->lessThanOrEqualTo($endDate) ? "Active" : "Expired";
    }

    public function getRemainingItemLimitAttribute() {
        if (!empty($this->total_limit)) {
            return $this->total_limit - $this->used_limit;
        }
        return "unlimited";
    }

    /*
    |----------------------------------------------------------------------
    | Relationships
    |----------------------------------------------------------------------
    */

    public function package() {
        return $this->belongsTo(Package::class);
    }

    public function user() {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function PaymentTransaction() {
        return $this->belongsTo(PaymentTransaction::class);
    }

    /*
    |----------------------------------------------------------------------
    | Scopes
    |----------------------------------------------------------------------
    */

    public function scopeOnlyActive($query) {
        return $query->where('user_id', Auth::user()->id)->whereDate('start_date', '<=', date('Y-m-d'))->where(function ($q) {
            $q->whereDate('end_date', '>', date('Y-m-d'))->orWhereNull('end_date');
        })->where(function ($q) {
            $q->whereColumn('used_limit', '<', 'total_limit')->orWhereNull('total_limit');
        })->orderBy('end_date', 'asc');
    }

    public function scopeSearch($query, $search) {
        $search = "%" . $search . "%";
        $query = $query->where(function ($q) use ($search) {
            $q->orWhereLike('user_id', $search)
                ->orWhereLike('package_id', $search)
                ->orWhereLike('start_date', $search)
                ->orWhereLike('end_date', $search)
                ->orWhereLike('total_limit', $search)
                ->orWhereLike('used_limit', $search)
                ->orWhereLike('created_at', $search)
                ->orWhereLike('updated_at', $search)
                ->orWhereHas('package', function ($q) use ($search) {
                    $q->whereLike('name', $search);
                })->orWhereHas('user', function ($q) use ($search) {
                    $q->whereLike('name', $search);
                });
        });
        return $query;
    }
}
