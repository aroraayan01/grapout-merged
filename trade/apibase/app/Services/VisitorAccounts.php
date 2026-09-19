<?php

namespace App\Services;

use App\Models\Business\Enquiry;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The account a visitor gets for confirming an enquiry.
 *
 * They proved the address by typing the code back, so the email is
 * verified from the first minute. The code is their password until they
 * change it — the app asks them to — and the profile carries the company
 * they named, so a later "join" or "open a page" starts half done.
 */
class VisitorAccounts
{
    public function __construct(private AppIdService $appIds)
    {
    }

    public function createFromEnquiry(Enquiry $e, string $temporaryPassword): User
    {
        return DB::transaction(function () use ($e, $temporaryPassword) {
            $user = User::create([
                'name' => $e->guest_name ?: 'Visitor',
                'username' => $this->freeUsername($e->guest_name ?: $e->guest_email),
                'email' => mb_strtolower($e->guest_email),
                'password' => $temporaryPassword,
            ]);
            // Neither is mass-assignable, on purpose; here both are the point.
            $user->forceFill(['email_verified_at' => now(), 'force_password_change' => true])->save();
            $user->profile()->create([
                'country' => $e->guest_country,
                'company_name' => $e->guest_company,
                'timezone' => 'Asia/Kolkata',
                'language' => 'en',
                'account_type' => 'business',
            ]);
            $user->settings()->create([]);
            $this->appIds->generateFor($user);
            if ($role = Role::where('slug', 'user')->first()) {
                $user->roles()->attach($role->id);
            }

            return $user;
        });
    }

    /** A handle nobody has: letters and digits from the name, a number on collision. */
    private function freeUsername(string $seed): string
    {
        $base = mb_strtolower(preg_replace('/[^a-zA-Z0-9]/', '', explode('@', $seed)[0]));
        if (mb_strlen($base) < 4) {
            $base = $base !== '' ? str_pad($base, 4, (string) random_int(10, 99)) : 'user' . random_int(100, 999);
        }
        $base = mb_substr($base, 0, 20);
        $candidate = $base;
        $n = 0;
        while (User::whereRaw('LOWER(username) = ?', [$candidate])->exists()) {
            $n++;
            $tail = (string) $n;
            $candidate = mb_substr($base, 0, 20 - mb_strlen($tail)) . $tail;
        }

        return $candidate;
    }
}
