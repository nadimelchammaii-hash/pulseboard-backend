<?php

namespace App\Http\Controllers\Api;

use App\Actions\Demo\StartDemo;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class DemoController extends Controller
{
    /**
     * "Try the demo": create a temporary demo account with its own sample
     * workspace and start an authenticated session for it.
     */
    public function __invoke(Request $request, StartDemo $startDemo): JsonResponse
    {
        $user = $startDemo->handle();

        Auth::guard('web')->login($user);

        $request->session()->regenerate();

        // meta.landing: the sample board the SPA opens straight away.
        return UserResource::make($user)
            ->additional(['meta' => ['landing' => $startDemo->landing()]])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
