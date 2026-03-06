<?php

namespace Plugin\TutorialImporter\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Plugin\TutorialImporter\Services\TutorialImportService;
use Illuminate\Support\Facades\Log;

class TutorialImportController extends Controller
{
    public function import(Request $request)
    {
        try {
            $service = new TutorialImportService();
            $results = $service->import();
            
            return response()->json([
                'status' => 'success', // Changed to match common Xboard/V2Board response structure
                'message' => "导入完成。总计: {$results['total']}, 成功: {$results['success']}, 失败: {$results['failed']}",
                'data' => $results
            ]);
        } catch (\Exception $e) {
            Log::error('Tutorial Import Failed: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => '导入失败: ' . $e->getMessage()
            ], 500);
        }
    }
}
