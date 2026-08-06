<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanFeature extends Model
{
   protected $fillable = ['plan_id', 'feature_key', 'value'];
}
