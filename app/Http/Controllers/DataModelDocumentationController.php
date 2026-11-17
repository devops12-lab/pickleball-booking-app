<?php

namespace App\Http\Controllers;

use App\Services\DataModelDocumentation\DataModelDocumentationService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DataModelDocumentationController extends Controller
{
    /**
     * Show the data model documentation portal.
     */
    public function __invoke(Request $request, DataModelDocumentationService $documentation): View
    {
        abort_unless(config('data-model-docs.enabled'), 404);

        return view('data-model-docs.index', [
            'documentation' => $documentation->build(),
        ]);
    }
}
