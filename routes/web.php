<?php

use App\Http\Controllers\ApiDocumentationController;
use App\Http\Controllers\DataModelDocumentationController;
use App\Http\Controllers\WorkflowDocumentationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    abort(404);
});

Route::get('/'.config('api-docs.path', 'docs-api-endpoints'), ApiDocumentationController::class)
    ->middleware(config('api-docs.middleware', []))
    ->name('api-docs');

Route::get('/'.config('data-model-docs.path', 'docs-data-model'), DataModelDocumentationController::class)
    ->middleware(config('data-model-docs.middleware', []))
    ->name('data-model-docs');

Route::prefix(config('workflow-docs.path', 'docs-work-flow'))
    ->middleware(config('workflow-docs.middleware', []))
    ->controller(WorkflowDocumentationController::class)
    ->group(function (): void {
        Route::get('/', 'index')->name('workflow-docs');
        Route::get('/documents/{document}', 'show')
            ->where('document', '[a-z0-9]+(?:[-\/][a-z0-9]+)*')
            ->name('workflow-docs.document');
    });
