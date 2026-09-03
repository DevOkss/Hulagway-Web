<?php

namespace App\Http\Controllers;

use App\Models\Barangay;
use App\Services\BoundaryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BoundaryController extends Controller
{
    public function __construct(private readonly BoundaryService $boundaries) {}

    public function index(): Response
    {
        return Inertia::render('Boundaries/Index', [
            'stats' => [
                'total' => Barangay::count(),
                'withBoundary' => Barangay::whereNotNull('boundary')->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:json,geojson,txt', 'max:20480'],
        ]);

        $result = $this->boundaries->importFromUpload($request->file('file'));

        $message = "{$result['matched']} of {$result['total']} features matched to barangays.";
        if ($result['unmatched'] !== []) {
            $message .= ' Unmatched: '.implode(', ', array_slice($result['unmatched'], 0, 8))
                .(count($result['unmatched']) > 8 ? '…' : '');
        }

        return back()->with(
            $result['matched'] > 0 ? 'success' : 'error',
            $message,
        );
    }
}
