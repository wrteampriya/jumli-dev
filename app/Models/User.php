<?php

namespace App\Models;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\UniqueConstraintViolationException;
use Auth;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasPermissions;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable {
    use HasApiTokens, HasFactory, Notifiable, HasRoles, SoftDeletes, HasPermissions;

    /*
    |----------------------------------------------------------------------
    | Properties & Casts
    |----------------------------------------------------------------------
    */

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'mobile',
        'password',
        'type',
        'firebase_id',
        'profile',
        'address',
        'notification',
        'country_code',
        'show_personal_details',
        'is_verified',
        'auto_approve_item',
        'region_code',
        'referral_code',
        'refer_points',
        'used_referral_code'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $appends = [
        'initial',
        'avatar_color',
        'placeholder',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            // boolean columns cast to integer: the API returns 1/0 on both MySQL and PostgreSQL
            'notification' => 'integer',
            'is_verified' => 'integer',
            'auto_approve_item' => 'integer',
            'show_personal_details' => 'integer',
            'used_referral_code' => 'integer',
        ];
    }

    /*
    |----------------------------------------------------------------------
    | Booted
    |----------------------------------------------------------------------
    */

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($user) {
            if (empty($user->referral_code)) {
                $user->referral_code = strtoupper(Str::random(8));
            }
        });
    }

    /*
    |----------------------------------------------------------------------
    | Accessors
    |----------------------------------------------------------------------
    */

    public function getProfileAttribute($image) {
        if (!empty($image) && !filter_var($image, FILTER_VALIDATE_URL)) {
            return url(Storage::url($image));
        }
        return $image;
    }

    public function getInitialAttribute() {
        if (empty($this->name)) {
            return null;
        }
        return mb_strtoupper(mb_substr($this->name, 0, 1));
    }

    public function getAvatarColorAttribute() {
        if (empty($this->name)) {
            return null;
        }
        $colors = ['#1abc9c','#2ecc71','#3498db','#9b59b6','#34495e','#16a085','#27ae60','#2980b9','#8e44ad','#2c3e50','#f1c40f','#e67e22','#e74c3c','#d35400','#c0392b'];
        $sum = 0;
        for ($i = 0; $i < mb_strlen($this->name); $i++) {
            $sum += mb_ord(mb_substr($this->name, $i, 1));
        }
        return $colors[$sum % count($colors)];
    }

    public function getPlaceholderAttribute() {
        return asset('assets/images/default-profile-icon.svg');
    }

    public function getStatusAttribute($value)
    {
    if ($this->deleted_at) {
        return "inactive";
    }
    if ($this->expiry_date && $this->expiry_date < Carbon::now()) {
        return "expired";
    }
    return $value;
    }

    public function getEmailAttribute($value)
    {
        if(Auth::guard('sanctum')->check()){
            return $value;
        }
        return null;
    }

    public function getMobileAttribute($value)
    {
        if(Auth::guard('sanctum')->check()){
            return $value;
        }
        return null;
    }

    /** Emails are stored lowercase: PostgreSQL compares text case-sensitively (MySQL's *_ci collation did not). */
    public function setEmailAttribute($value)
    {
        $this->attributes['email'] = $value === null ? null : Str::lower(trim($value));
    }

    /*
    |----------------------------------------------------------------------
    | Relationships
    |----------------------------------------------------------------------
    */

    public function items() {
        return $this->hasMany(Item::class);
    }

    public function sellerReview() {
        return $this->hasMany(SellerRating::class , 'seller_id');
    }

    public function socialLogins(){
        return $this->hasMany(SocialLogin::class, 'user_id');
    }

    public function user_reports() {
        return $this->hasMany(UserReports::class);
    }

    public function fcm_tokens() {
        return $this->hasMany(UserFcmToken::class);
    }

    public function following()
    {
        return $this->belongsToMany(User::class, 'user_follows', 'follower_id', 'following_id')
            ->withTimestamps();
    }

    public function followers()
    {
        return $this->belongsToMany(User::class, 'user_follows', 'following_id', 'follower_id')
            ->withTimestamps();
    }

    public function referrals()
    {
        return $this->hasMany(Referral::class, 'referrer_id');
    }

    public function referredBy()
    {
        return $this->hasOne(Referral::class, 'referred_id');
    }

    public function referPointTransactions()
    {
        return $this->hasMany(ReferPointTransaction::class);
    }

    /*
    |----------------------------------------------------------------------
    | Scopes
    |----------------------------------------------------------------------
    */

    public function scopeSearch($query, $search) {
        $search = "%" . $search . "%";
        return $query->where(function ($q) use ($search) {
            $q->orWhereLike('email', $search)
                ->orWhereLike('mobile', $search)
                ->orWhereLike('name', $search)
                ->orWhereLike('type', $search)
                ->orWhereLike('notification', $search)
                ->orWhereLike('firebase_id', $search)
                ->orWhereLike('address', $search)
                ->orWhereLike('created_at', $search)
                ->orWhereLike('updated_at', $search);
        });
    }

    /** Case-insensitive email match on both engines; also matches rows saved before emails were lowercased. */
    public function scopeWhereEmail($query, ?string $email)
    {
        return $query->whereRaw('LOWER(email) = ?', [Str::lower(trim((string) $email))]);
    }

    /*
    |----------------------------------------------------------------------
    | Custom Methods
    |----------------------------------------------------------------------
    */

    public static function generateUniqueReferralCode(): string
    {
        return strtoupper(Str::random(8));
    }

    /**
     * Create a user with retry on referral_code collision.
     * The UNIQUE index on referral_code is the real safeguard —
     * on duplicate key (MySQL 1062 / PostgreSQL 23505) we regenerate and retry.
     * Each attempt runs in DB::transaction(): nested in a caller's transaction it becomes a savepoint,
     * so on PostgreSQL a collision rolls back only that attempt instead of aborting the whole transaction.
     */
    public static function createWithReferralCode(array $attributes, int $maxRetries = 3): self
    {
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                return DB::transaction(static fn () => static::create($attributes));
            } catch (UniqueConstraintViolationException $e) {
                if (str_contains($e->getMessage(), 'referral_code')) {
                    if ($attempt === $maxRetries) {
                        throw $e;
                    }
                    // Clear so boot event generates a fresh code on next attempt
                    unset($attributes['referral_code']);
                    continue;
                }
                throw $e; // Not a referral_code collision — rethrow
            }
        }
        throw new \RuntimeException('Failed to create user after ' . $maxRetries . ' attempts');
    }

    /**
     * updateOrCreate with retry on referral_code collision (for signup flow).
     */
    public static function updateOrCreateWithReferralCode(array $unique, array $attributes, int $maxRetries = 3): self
    {
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                return DB::transaction(static fn () => static::updateOrCreate($unique, $attributes));
            } catch (UniqueConstraintViolationException $e) {
                if (str_contains($e->getMessage(), 'referral_code')) {
                    if ($attempt === $maxRetries) {
                        throw $e;
                    }
                    unset($attributes['referral_code']);
                    continue;
                }
                throw $e;
            }
        }
        throw new \RuntimeException('Failed to create user after ' . $maxRetries . ' attempts');
    }

    public function isFollowing(int $userId): bool
    {
        return $this->following()->where('following_id', $userId)->exists();
    }

    public function isFollowedBy(int $userId): bool
    {
        return $this->followers()->where('follower_id', $userId)->exists();
    }
}
