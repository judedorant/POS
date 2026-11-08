<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
final class SessionController
{
    public function store(Request $request)
    {
        $credentials = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $credentials['email'] = strtolower(trim($credentials['email']));
        if (! Auth::guard('web')->attempt([...$credentials, 'disabled_at' => null])) {
            throw ValidationException::withMessages(['email' => ['Invalid credentials.']]);
        }
        $request->session()->regenerate();
        return response()->json(['user' => Auth::guard('web')->user()]);
    }
    public function destroy(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->noContent();
    }
}
