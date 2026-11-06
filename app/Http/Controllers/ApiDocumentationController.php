<?php

namespace App\Http\Controllers;

use App\Services\ApiDocumentation\ApiDocumentationService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApiDocumentationController extends Controller
{
    /**
     * Show the API documentation portal.
     */
    public function __invoke(Request $request, ApiDocumentationService $documentation): View
    {
        abort_unless(config('api-docs.enabled'), 404);

        return view('api-docs.index', [
            'documentation' => $documentation->build(config('api-docs.base_url') ?: $request->root()),
        ]);
    }
}
