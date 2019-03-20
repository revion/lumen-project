<?php

namespace App;

use Illuminate\Auth\Authenticatable;
use Laravel\Lumen\Auth\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;

class Invoice extends Model implements AuthenticatableContract, AuthorizableContract
{
    use Authenticatable, Authorizable;

    // protected $table = 'invoice';

    protected $fillable = [
      'id_user', 'id_product', 'amount', 'has_bought'
    ];
}
