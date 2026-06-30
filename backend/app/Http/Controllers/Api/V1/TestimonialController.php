<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TestimonialResource;
use App\Models\Testimonial;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TestimonialController extends Controller
{
    /** Curated client testimonials for the home page (لماذا ممسى). */
    public function index(): AnonymousResourceCollection
    {
        return TestimonialResource::collection(Testimonial::active()->get());
    }
}
