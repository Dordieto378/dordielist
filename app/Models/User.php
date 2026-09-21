<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    use HasFactory, Notifiable, TwoFactorAuthenticatable;
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'users';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'user_id';

    /**
     * Indicates if the model should be timestamped.
     * (No created_at / updated_at columns on this table.)
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'username',
        'email',
        'password',
        'status',
        'role_id',
        'email_verified_at',
        'anilist_access_token',
        'tmdb_api_token',
        'tmdb_session_id',
        'vndb_api_token',
        'vndb_username',
        'vndb_password',
    ];

    /**
     * The attributes that should be hidden for arrays and JSON.
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'anilist_access_token',
        'tmdb_api_token',
        'tmdb_session_id',
        'vndb_api_token',
        'vndb_password',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at'    => 'datetime',
        'two_factor_confirmed' => 'boolean',
        'anilist_access_token' => 'encrypted',
        'tmdb_api_token'       => 'encrypted',
        'tmdb_session_id'      => 'encrypted',
        'vndb_api_token'       => 'encrypted',
        'vndb_password'        => 'encrypted',
    ];

    /**
     * Automatically hash passwords when setting.
     */
    public function setPasswordAttribute($value)
    {
        $this->attributes['password'] = bcrypt($value);
    }

    /**
     * Relationship: user belongs to a role.
     */
    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id', 'role_id');
    }

    public function collections()
    {
        return $this->hasMany(\App\Models\Collection::class);
    }

}
