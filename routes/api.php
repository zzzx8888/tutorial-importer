<?php

use Illuminate\Support\Facades\Route;
use Plugin\TutorialImporter\Controllers\TutorialImportController;

Route::group([
    'prefix' => 'tutorial-importer',
    'middleware' => ['admin'] // Ensure only admin can access
], function () {
    Route::post('/import', [TutorialImportController::class, 'import'])->name('plugin.tutorial-importer.import');
});
