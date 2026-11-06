<?php

namespace App\Http\Controllers;

use App\Services\WorkflowDocumentation\WorkflowDocumentationService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class WorkflowDocumentationController extends Controller
{
    /**
     * Show the backend workflow documentation portal.
     */
    public function index(WorkflowDocumentationService $documentation): View
    {
        abort_unless(config('workflow-docs.enabled'), 404);

        return view('workflow-docs.index', [
            'documentation' => $documentation->build(),
        ]);
    }

    /**
     * Return one rendered document. Slugs are looked up in the discovered
     * index only; they are never used to build a filesystem path.
     */
    public function show(string $document, WorkflowDocumentationService $documentation): JsonResponse
    {
        abort_unless(config('workflow-docs.enabled'), 404);

        $found = $documentation->document($document);

        abort_if($found === null, 404);

        return response()->json(['data' => $found]);
    }
}
