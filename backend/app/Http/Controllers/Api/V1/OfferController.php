<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OfferResource;
use App\Models\Offer;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OfferController extends Controller
{
    /** Active seasonal offers for the home page (العروض الموسمية). */
    public function index(): AnonymousResourceCollection
    {
        return OfferResource::collection(Offer::active()->get());
    }
}
