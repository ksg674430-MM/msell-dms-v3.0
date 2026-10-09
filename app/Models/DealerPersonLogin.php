<?php

namespace App\Models;

use App\Support\UserExtras;
use Illuminate\Database\Eloquent\Casts\Attribute;
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

    private ?array $extras = null;

    public function getAuthIdentifierName(): string
    {
        return 'dpId';
    }

    private function extras(): array
    {
        return $this->extras ??= UserExtras::for($this);
    }

    protected function rolename(): Attribute
    {
        return Attribute::get(fn () => $this->extras()['rolename']);
    }

    protected function templateId(): Attribute
    {
        return Attribute::get(fn () => $this->extras()['templateId']);
    }

    protected function ownershipTypeId(): Attribute
    {
        return Attribute::get(fn () => $this->extras()['ownershipTypeId']);
    }
}