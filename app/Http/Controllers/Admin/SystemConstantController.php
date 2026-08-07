<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSystemConstantRequest;
use App\Models\SystemConstant;
use App\Services\Audit\AuditLogService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class SystemConstantController extends Controller
{
    public function __construct(private AuditLogService $audit) {}

    public function index(): View
    {
        return view('admin.constants.index', [
            'constants' => SystemConstant::orderBy('key')->get(),
        ]);
    }

    public function update(UpdateSystemConstantRequest $request, SystemConstant $systemConstant): RedirectResponse
    {
        $before = ['value' => $systemConstant->value];
        $systemConstant->update($request->validated());
        $this->audit->log('constant.updated', $systemConstant, $before, ['value' => $systemConstant->value]);

        return redirect()->route('admin.constants.index')
            ->with('status', "Updated {$systemConstant->key}.");
    }
}
