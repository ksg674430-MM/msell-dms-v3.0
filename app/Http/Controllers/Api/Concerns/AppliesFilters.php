<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Support\FilterBuilder;
use Illuminate\Http\Request;

trait AppliesFilters
{
    protected function filterRequest(string $resource, Request $request): FilterBuilder
    {
        return FilterBuilder::make($resource, $request);
    }
}