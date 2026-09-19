<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class DealerPersonLogin extends Authenticatable
{
    use HasApiTokens;

    protected $table      = 'dealer_person_login';
    protected $primaryKey = 'dpId';
    public    $timestamps = false;
    protected $guarded    = [];
    protected $hidden     = ['pass'];

    public function getAuthIdentifierName(): string
    {
        return 'dpId';
    }
}