<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Attendance\Services\PunchIngestion;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /api/v1/attendance/devices/{device}/punches — device push through its adapter (§24). */
class AttendancePunchController extends Controller
{
    public function store(Request $request, string $device, PunchIngestion $ingestion): JsonResponse
    {
        $model = AttendanceDevice::query()->where('code', $device)->where('status', 'active')->firstOrFail();

        $result = $ingestion->ingest($model, $request->all());

        return response()->json(['data' => $result], 202);
    }
}
