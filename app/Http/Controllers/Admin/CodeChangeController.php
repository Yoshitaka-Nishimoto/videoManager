<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CodeChange;
use Illuminate\Contracts\View\View;

class CodeChangeController extends Controller
{
    public function index(): View
    {
        return view('admin.code-changes.index', [
            'changes' => CodeChange::latest('id')->paginate(30),
        ]);
    }

    public function show(CodeChange $codeChange): View
    {
        return view('admin.code-changes.show', [
            'change' => $codeChange,
        ]);
    }
}
