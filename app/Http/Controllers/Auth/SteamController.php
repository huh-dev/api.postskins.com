<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;

class SteamController extends Controller
{
    /**
     * Redirect the user to Steam's OpenID authentication page.
     */
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('steam')->redirect();
    }

    /**
     * Handle the callback from Steam, provision the user, and start a session.
     */
    public function callback(): RedirectResponse
    {
        /** @var SocialiteUser $steamUser */
        $steamUser = Socialite::driver('steam')->user();

        $user = User::updateOrCreate(
            ['steam_id' => $steamUser->getId()],
            [
                'name' => $steamUser->getNickname() ?: $steamUser->getName() ?: 'Steam User',
                'avatar' => $steamUser->getAvatar(),
            ]
        );

        Auth::login($user, remember: true);

        return redirect()->away(config('services.steam.frontend_url'));
    }

    /**
     * Log the user out and invalidate their session.
     */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out']);
    }
}
