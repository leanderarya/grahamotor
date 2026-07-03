<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class VersionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'version' => config('app.version', '1.0.0'),
            'update_url' => 'https://grahamotor.cahayaarkana.site/downloads/latest.apk',
        ]);
    }
}
