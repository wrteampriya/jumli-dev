<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ServiceHealthLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use Illuminate\Routing\Controller;
use App\Services\SystemIntegrityService;

final class AccessRestorationController extends Controller
{
    public function show(): View
    {
        return view('restore-access');
    }

    public function recover(Request $request): RedirectResponse
    {
        $request->validate([
            'purchase_code' => 'required|string|max:255',
        ]);

        $domain = preg_replace('#^https?://#i', '', url('/'));

        try {
            $cfg = SystemIntegrityService::config();
            $response = Http::get($cfg['ep'], [
                base64_decode('cHVyY2hhc2VfY29kZQ==') => $request->input('purchase_code'),
                base64_decode('ZG9tYWluX3VybA==') => $domain,
            ])->json();
        } catch (\Throwable) {
            return redirect()
                ->route('restore.access.show')
                ->withErrors(['purchase_code' => 'Could not reach the validation server. Please try again.'])
                ->withInput();
        }

        if ($response['error'] ?? true) {
            return redirect()
                ->route('restore.access.show')
                ->withErrors([
                    'purchase_code' => $response['message'] ?? 'Validation failed. Please check your purchase code.',
                ])
                ->withInput();
        }

        // Native .env update to ensure independence after package removal
        $this->updateEnv($cfg['ek'], $request->input('purchase_code'));

        try {
            ServiceHealthLog::query()->delete();
        } catch (\Throwable) {
        }
        session()->forget([base64_decode('X3NyaV90cw=='), base64_decode('X3NobF9pbml0')]);

        return redirect('/');
    }

    private function updateEnv(string $key, string $value): void
    {
        $path = base_path('.env');
        if (!file_exists($path)) {
            return;
        }

        $content = file_get_contents($path);
        
        // Find existing key
        if (preg_match("/^{$key}=.*/m", $content)) {
            $content = preg_replace("/^{$key}=.*/m", "{$key}=\"{$value}\"", $content);
        } else {
            // Append if not exists
            $content .= "\n{$key}=\"{$value}\"";
        }

        file_put_contents($path, $content);
    }
}
