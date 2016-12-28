<?php

namespace Interpro\QS\Model;

use Illuminate\Database\Eloquent\Model;

class Block extends Model
{
    protected $primaryKey = 'name';
    public $incrementing = false;
    public $timestamps = false;
    protected static $unguarded = true;
}
