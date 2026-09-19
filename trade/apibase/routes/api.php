<?php

use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\StatsController;
use App\Http\Controllers\Api\V1\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\V1\AppIdController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BlockController;
use App\Http\Controllers\Api\V1\BookingPageController;
use App\Http\Controllers\Api\V1\CallController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\BroadcastController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\ConnectionController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\GroupController;
use App\Http\Controllers\Api\V1\NoteController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PresenceController;
use App\Http\Controllers\Api\V1\ReminderController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\TaskController;
use Illuminate\Support\Facades\Route;

// Gateway webhooks (unauthenticated; protected by signature verification).
Route::post('/webhooks/cashfree', [\App\Http\Controllers\Api\WebhookController::class, 'cashfree'])
    ->middleware('throttle:120,1');

Route::prefix('v1')->group(function () {

    // Public pricing
    Route::get('/plans', [\App\Http\Controllers\Api\V1\SubscriptionController::class, 'plans'])
        ->middleware('throttle:30,1');

    // Public file share link. The token is the whole check, so this is
    // throttled to make guessing one impractical.
    Route::get('/f/{token}', [FileController::class, 'downloadByLink'])
        ->middleware('throttle:60,1')
        ->where('token', '[A-Za-z0-9]{32,64}');

    // Join a meeting with a passcode and no account. Throttled hard: this is
    // the one door into the app that no session guards.
    // A browser reporting that it broke. Open on purpose: the errors most worth
// knowing about are the ones that stop someone signing in, and those have no
// token to offer. Throttled, and everything it stores is truncated.
Route::post('/client-errors', [\App\Http\Controllers\Api\V1\ClientErrorController::class, 'store'])
    ->middleware('throttle:20,1');

// Is this code worth showing a password box for? Answered before anyone types
// anything, so a meeting with no password says "sign in" up front instead of
// after a failed attempt. Booleans only — no title, nothing a guessed code
// could harvest.
Route::get('/meetings/{code}/guest', [\App\Http\Controllers\Api\V1\MeetingGuestController::class, 'peek'])
    ->middleware('throttle:30,1');

Route::post('/meetings/{code}/guest', [\App\Http\Controllers\Api\V1\MeetingGuestController::class, 'join'])
        ->middleware('throttle:10,1');

// The browser moved a push subscription. Open because a service worker holds
// no session and this can fire with no tab to lend it one; the old endpoint is
// what stands in for the session, and only the device that had it knows it.
// The Android notification's Decline button. No sanctum here on purpose: the
// press happens in native code that holds no token, and the URL's signature
// (call + callee + one-minute expiry) is the entire authorisation.
Route::post('/push/calls/{call}/decline', [\App\Http\Controllers\Api\V1\CallController::class, 'declineFromPush'])
    ->name('push.calls.decline')
    ->middleware('signed');

Route::post('/push/rotate', [\App\Http\Controllers\Api\V1\PushSubscriptionController::class, 'rotate'])
    ->middleware('throttle:20,1');

/*
 * Booking links: the second door with no session behind it.
 *
 * Somebody who has been handed a link can see when the host is free and
 * take one of those times, and afterwards can move or cancel what they took
 * using the token in their confirmation email. None of it requires an
 * account, which is the entire point — and which is why every route here is
 * throttled and returns nothing about the host beyond what the link already
 * gave away.
 *
 * Reading is looser than writing. Browsing a fortnight of slots is a
 * handful of requests as somebody flicks between weeks; booking is once.
 */
Route::get('/book/{slug}', [\App\Http\Controllers\Api\V1\PublicBookingController::class, 'page'])
    ->middleware('throttle:60,1');
Route::get('/book/{slug}/slots', [\App\Http\Controllers\Api\V1\PublicBookingController::class, 'slots'])
    ->middleware('throttle:60,1');
Route::post('/book/{slug}', [\App\Http\Controllers\Api\V1\PublicBookingController::class, 'book'])
    ->middleware('throttle:10,1');

// Managing a booking already made. The token is the credential.
Route::get('/bookings/{token}', [\App\Http\Controllers\Api\V1\PublicBookingController::class, 'show'])
    ->middleware('throttle:30,1')->where('token', '[A-Za-z0-9]{64}');
Route::post('/bookings/{token}/cancel', [\App\Http\Controllers\Api\V1\PublicBookingController::class, 'cancel'])
    ->middleware('throttle:10,1')->where('token', '[A-Za-z0-9]{64}');
Route::post('/bookings/{token}/reschedule', [\App\Http\Controllers\Api\V1\PublicBookingController::class, 'reschedule'])
    ->middleware('throttle:10,1')->where('token', '[A-Za-z0-9]{64}');

    /*
     * What a guest may do, and nothing else.
     *
     * Everything a participant needs to be in a room — join, leave, keep
     * presence, signal, rename themselves, react, read the room, say something
     * — and none of what a host needs. Ending the meeting, admitting people,
     * host actions, approval settings and the file endpoints are all absent on
     * purpose, so a guest cannot reach them even if they know the URL.
     */
    Route::middleware('guest.meeting')->group(function () {
        Route::get('/guest/meetings/{meeting}', [\App\Http\Controllers\Api\V1\MeetingController::class, 'show']);
        Route::post('/guest/meetings/{meeting}/join', [\App\Http\Controllers\Api\V1\MeetingController::class, 'join']);
        Route::post('/guest/meetings/{meeting}/leave', [\App\Http\Controllers\Api\V1\MeetingController::class, 'leave']);
        Route::post('/guest/meetings/{meeting}/heartbeat', [\App\Http\Controllers\Api\V1\MeetingController::class, 'heartbeat']);
        Route::post('/guest/meetings/{meeting}/signal', [\App\Http\Controllers\Api\V1\MeetingController::class, 'signal'])
            ->middleware('throttle:240,1');
        Route::post('/guest/meetings/{meeting}/name', [\App\Http\Controllers\Api\V1\MeetingController::class, 'rename']);
        Route::post('/guest/meetings/{meeting}/react', [\App\Http\Controllers\Api\V1\MeetingController::class, 'react']);
        /*
         * Saying something is part of being in the room, and the panel is
         * already there for a guest — without this every message they sent
         * failed on a route that did not exist. The handler is the members'
         * one unchanged: it lets nobody chat who has not joined this meeting,
         * and a pass is only ever good for one meeting.
         *
         * Sharing a file stays out, as the note above says: that is the one
         * part of the panel a guest does not get.
         */
        Route::post('/guest/meetings/{meeting}/chat', [\App\Http\Controllers\Api\V1\MeetingController::class, 'chat'])
            ->middleware('throttle:60,1');
        // A guest in the room needs the same token a member does — they are
        // in the same meeting, and the pass they hold is only good for it.
        Route::post('/guest/meetings/{meeting}/realtime-token', [\App\Http\Controllers\Api\V1\MeetingController::class, 'realtimeToken']);
        Route::get('/guest/meetings/{meeting}/participants', [\App\Http\Controllers\Api\V1\MeetingController::class, 'participants']);
    });

    // --- Public auth (strictly throttled) --------------------------------
    // On a developer's machine every tab, test and retry comes from one
    // address, and ten a minute locks the door after one bad session.
    // Production keeps the strict numbers.
    $authRate = app()->isLocal() ? 'throttle:120,1' : 'throttle:10,1';
    $signupRate = app()->isLocal() ? 'throttle:60,1' : 'throttle:3,1';
    Route::middleware($authRate)->group(function () use ($signupRate) {
        /*
         * Sign-up is throttled harder than the rest of this group.
         *
         * Ten a minute is a reasonable allowance for somebody mistyping a
         * password; it is 14,000 accounts a day from one address. Nobody
         * signs up three times in a minute, and an office behind one NAT
         * still has three tries a minute between them.
         */
        Route::post('/auth/register', [AuthController::class, 'register'])
            ->middleware($signupRate);
        Route::post('/auth/login', [AuthController::class, 'login']);
        Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
        Route::post('/auth/otp/request', [AuthController::class, 'requestLoginOtp']);
        // The second step of a password login, when a code was asked for.
        Route::post('/auth/login/verify', [AuthController::class, 'verifySignInCode']);
        Route::post('/auth/otp/login', [AuthController::class, 'loginWithOtp']);
    });

    /*
     * Who is behind an invite link. Public, because the person opening it has
     * no account yet — that is the entire point of the link.
     */
    /*
     * Netvork Trade, read by anybody.
     *
     * A business page is a shop window: its whole point is to be seen by
     * people who are not members yet. Reading needs nothing; a visitor may
     * also ask a page one thing with a name and an email, behind the same
     * guard as sign-up. Everything that acts — follow, connect, message —
     * is inside the authed group with the rest of the app.
     */
    Route::prefix('trade')->middleware('throttle:120,1')->group(function () {
        Route::get('/directory', [\App\Http\Controllers\Api\V1\Business\PublicController::class, 'directory']);
        Route::get('/search', [\App\Http\Controllers\Api\V1\Business\PublicController::class, 'search']);
        Route::get('/pages/{slug}', [\App\Http\Controllers\Api\V1\Business\PublicController::class, 'page']);
        Route::get('/products/{uuid}', [\App\Http\Controllers\Api\V1\Business\PublicController::class, 'product']);
        Route::get('/enquiries/{token}', [\App\Http\Controllers\Api\V1\Business\PublicController::class, 'thread'])
            ->where('token', '[A-Za-z0-9]{64}');
    });
    // The code from the email, typed back: a few tries, then wait.
    Route::post('/trade/enquiries/{uuid}/confirm', [\App\Http\Controllers\Api\V1\Business\PublicController::class, 'confirm'])
        ->middleware('throttle:' . (app()->isLocal() ? '60,1' : '8,10'));
    Route::post('/trade/enquiries', [\App\Http\Controllers\Api\V1\Business\PublicController::class, 'enquire'])
        ->middleware(app()->isLocal() ? 'throttle:60,1' : 'throttle:6,1');
    // The link at the foot of every outreach email. One click, no sign-in.
    Route::get('/trade/outreach/unsubscribe/{uuid}', [\App\Http\Controllers\Api\V1\Business\OutreachController::class, 'unsubscribe']);
    // What an email reports back: opened (the pixel) and clicked (every button passes through).
    Route::get('/trade/outreach/o/{uuid}.gif', [\App\Http\Controllers\Api\V1\Business\OutreachController::class, 'opened']);
    Route::get('/trade/outreach/c/{uuid}', [\App\Http\Controllers\Api\V1\Business\OutreachController::class, 'clicked']);
    // The blank sheet for a product import: a plain link, so it opens without a token.
    Route::get('/trade/product-import-template', [\App\Http\Controllers\Api\V1\Business\ProductController::class, 'importTemplate']);
    Route::get('/trade/seed-template', [\App\Http\Controllers\Api\V1\Business\SeedController::class, 'template']);
    Route::get('/trade/hs', [\App\Http\Controllers\Api\V1\Business\HsController::class, 'lookup'])
        ->middleware('throttle:120,1');
    Route::get('/trade/invites/{token}', [\App\Http\Controllers\Api\V1\Business\TeamController::class, 'peek'])
        ->middleware('throttle:30,1')->where('token', '[A-Za-z0-9]{64}');
    Route::get('/trade/config', [\App\Http\Controllers\Api\V1\Business\TranslateController::class, 'config'])
        ->middleware('throttle:120,1');
    Route::post('/trade/translate', [\App\Http\Controllers\Api\V1\Business\TranslateController::class, 'translate'])
        ->middleware('throttle:40,1');
    Route::get('/trade/requirements', [\App\Http\Controllers\Api\V1\Business\PublicController::class, 'requirements'])
        ->middleware('throttle:120,1');
    Route::get('/trade/pages/{slug}/posts', [\App\Http\Controllers\Api\V1\Business\PublicController::class, 'posts'])
        ->middleware('throttle:120,1');
    Route::get('/trade/pages/{slug}/catalogue.pdf', [\App\Http\Controllers\Api\V1\Business\PublicController::class, 'catalogue'])
        ->middleware('throttle:12,1');
    Route::get('/trade/events', [\App\Http\Controllers\Api\V1\Business\TradeEventController::class, 'index'])
        ->middleware('throttle:120,1');
    Route::get('/trade/events/{uuid}', [\App\Http\Controllers\Api\V1\Business\TradeEventController::class, 'show'])
        ->middleware('throttle:120,1');

    Route::get('/invite/{code}', [\App\Http\Controllers\Api\V1\InviteController::class, 'show'])
        ->middleware('throttle:30,1')->where('code', '[A-Za-z0-9]{8,24}');

    Route::get('/auth/suggest-username', [AuthController::class, 'suggestUsername'])
        ->middleware('throttle:30,1');

    Route::get('/auth/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    // --- Authenticated ----------------------------------------------------
    // 60/min proved too tight for a realtime SPA: chat, badge and meeting
    // polling alone can approach it before the user does anything.
    //
    // 'verified.email' covers the whole group. Registration must hand back a
    // token (confirming the address is an authenticated call), and that token
    // used to unlock the entire app before the address was ever proven — so
    // the handful of routes an unverified account legitimately needs opt out
    // of it explicitly below, and nothing else does.
    Route::middleware(['auth:sanctum', 'active', 'verified.email', 'throttle:180,1'])->group(function () {

        // Session & account
        Route::post('/auth/logout', [AuthController::class, 'logout'])
            ->withoutMiddleware('verified.email');
        Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
        Route::post('/auth/email/verification-notification', [AuthController::class, 'resendVerification'])
            ->withoutMiddleware('verified.email')
            ->middleware('throttle:6,1');
        Route::post('/auth/mobile/verify', [AuthController::class, 'verifyMobile'])
            ->withoutMiddleware('verified.email')
            ->middleware('throttle:10,1');
        Route::post('/auth/mobile/resend-otp', [AuthController::class, 'resendMobileOtp'])
            ->withoutMiddleware('verified.email')
            ->middleware('throttle:5,1');
        Route::post('/auth/email/resend-otp', [AuthController::class, 'resendEmailOtp'])
            ->withoutMiddleware('verified.email')
            ->middleware('throttle:5,1');
        Route::post('/auth/email/verify-otp', [AuthController::class, 'verifyEmailOtp'])
            ->withoutMiddleware('verified.email')
            ->middleware('throttle:10,1');
        Route::get('/auth/sessions', [AuthController::class, 'sessions']);
        Route::delete('/auth/sessions/{tokenId}', [AuthController::class, 'revokeSession']);
        Route::get('/auth/login-history', [AuthController::class, 'loginHistory']);

        // Me — readable while unverified so the client knows whose account it
        // is holding and can show the right address on the OTP screen.
        Route::get('/me', [ProfileController::class, 'me'])
            ->withoutMiddleware('verified.email');
        Route::put('/me/profile', [ProfileController::class, 'updateProfile']);
        Route::put('/me/settings', [ProfileController::class, 'updateSettings']);
        Route::post('/me/photo', [ProfileController::class, 'uploadPhoto']);
        // Closing an account for good. Throttled because it is irreversible
        // and there is no reason to attempt it more than once a minute.
        Route::delete('/me', [\App\Http\Controllers\Api\V1\AccountController::class, 'destroy'])
            ->middleware('throttle:5,1');
        Route::get('/me/app-id/qr', [AppIdController::class, 'myQr']);

        /*
         * The service panel: an application administering itself.
         *
         * Its own group rather than a corner of the authenticated one, because
         * the rule is the opposite of everything in there — these routes exist
         * only for accounts that are not people, and are invisible to the rest.
         */
        Route::prefix('service')->middleware('service.account')->group(function () {
            Route::get('/overview', [\App\Http\Controllers\Api\V1\ServiceAccountController::class, 'overview']);
            Route::get('/tokens', [\App\Http\Controllers\Api\V1\ServiceAccountController::class, 'tokens']);
            Route::post('/tokens', [\App\Http\Controllers\Api\V1\ServiceAccountController::class, 'issueToken']);
            Route::get('/tokens/{id}/reveal', [\App\Http\Controllers\Api\V1\ServiceAccountController::class, 'revealToken'])->whereNumber('id');
            Route::delete('/tokens/{id}', [\App\Http\Controllers\Api\V1\ServiceAccountController::class, 'revokeToken'])
                ->whereNumber('id');
            Route::get('/connections', [\App\Http\Controllers\Api\V1\ServiceAccountController::class, 'connections']);
            Route::delete('/connections/{uuid}', [\App\Http\Controllers\Api\V1\ServiceAccountController::class, 'disconnect']);
        });

        // App ID & connections
        Route::get('/app-id/search', [AppIdController::class, 'search']);
        Route::get('/connections', [ConnectionController::class, 'index']);
        Route::post('/connections', [ConnectionController::class, 'store']);
        Route::put('/connections/{connection}', [ConnectionController::class, 'respond']);
        Route::delete('/connections/{connection}', [ConnectionController::class, 'destroy']);
        Route::get('/blocks', [BlockController::class, 'index']);
        Route::post('/blocks', [BlockController::class, 'store']);
        Route::delete('/blocks/{appId}', [BlockController::class, 'destroy']);

        // Dashboard & sidebar badges
        Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
        Route::get('/badges', [\App\Http\Controllers\Api\V1\BadgeController::class, 'index']);
        Route::post('/calls/seen', [\App\Http\Controllers\Api\V1\BadgeController::class, 'markCallsSeen']);

        // Web push subscriptions (system notifications on this device)
        // Your booking link: the page itself, and what people have booked.
        Route::get('/booking-page', [BookingPageController::class, 'show']);
        Route::put('/booking-page', [BookingPageController::class, 'update']);
        Route::get('/booking-page/bookings', [BookingPageController::class, 'bookings']);
        Route::post('/booking-page/bookings/{booking}/cancel', [BookingPageController::class, 'cancelBooking']);

        Route::get('/push/public-key', [\App\Http\Controllers\Api\V1\PushSubscriptionController::class, 'publicKey']);
        Route::post('/push/subscribe', [\App\Http\Controllers\Api\V1\PushSubscriptionController::class, 'subscribe']);
        Route::post('/push/unsubscribe', [\App\Http\Controllers\Api\V1\PushSubscriptionController::class, 'unsubscribe']);
        // The Android app's ring channel — see registerFcm for why it exists.
        Route::post('/push/fcm-token', [\App\Http\Controllers\Api\V1\PushSubscriptionController::class, 'registerFcm']);
        Route::delete('/push/fcm-token', [\App\Http\Controllers\Api\V1\PushSubscriptionController::class, 'unregisterFcm']);

        // Identity change requests (approval-based)
        Route::get('/me/change-requests', [\App\Http\Controllers\Api\V1\ChangeRequestController::class, 'index']);
        Route::post('/me/change-requests', [\App\Http\Controllers\Api\V1\ChangeRequestController::class, 'store'])
            ->middleware('throttle:10,1');

        // Categories
        Route::apiResource('categories', CategoryController::class);
        Route::post('/categories/{category}/share', [CategoryController::class, 'share']);

        // Tasks
        Route::apiResource('tasks', TaskController::class);
        Route::post('/tasks/{task}/status', [TaskController::class, 'updateStatus']);
        Route::post('/tasks/{task}/progress', [TaskController::class, 'updateProgress']);
        Route::post('/tasks/{task}/duplicate', [TaskController::class, 'duplicate']);
        Route::post('/tasks/{task}/toggle/{flag}', [TaskController::class, 'toggle']);
        Route::post('/tasks/{task}/assign', [TaskController::class, 'assign']);
        Route::get('/tasks/{task}/activity', [TaskController::class, 'activity']);
        Route::post('/tasks/{task}/checklist', [TaskController::class, 'addChecklistItem']);
        Route::put('/tasks/{task}/checklist/{itemId}', [TaskController::class, 'updateChecklistItem']);
        Route::delete('/tasks/{task}/checklist/{itemId}', [TaskController::class, 'deleteChecklistItem']);
        Route::post('/tasks/{task}/comments', [TaskController::class, 'addComment']);

        // Reminders
        Route::get('/reminders/upcoming', [ReminderController::class, 'upcoming']);
        Route::post('/reminders/{reminder}/snooze', [ReminderController::class, 'snooze']);
        Route::post('/reminders/{reminder}/acknowledge', [ReminderController::class, 'acknowledge']);

        // Notifications
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('/notifications/read-kinds', [NotificationController::class, 'markKindsRead']);
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
        Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);

        // Events & calendar
        Route::get('/calendar/feed', [EventController::class, 'feed']);
        Route::get('/calendar/export.ics', [EventController::class, 'exportIcs']);
        Route::apiResource('events', EventController::class);
        Route::post('/events/{event}/respond', [EventController::class, 'respond']);

        // Notes
        Route::apiResource('notes', NoteController::class);
        Route::post('/notes/{note}/share', [NoteController::class, 'share']);
        Route::get('/notes/{note}/versions', [NoteController::class, 'versions']);

        Route::get('/connections/suggest', [\App\Http\Controllers\Api\V1\ConnectionController::class, 'suggest']);
        /*
         * Somebody else's profile. What it shows narrows with the
         * relationship; the rules live in the controller.
         */
        Route::get('/people/{uuid}', [\App\Http\Controllers\Api\V1\PersonController::class, 'show'])
            ->middleware('throttle:60,1');

        // My own invite link, for somebody who is not on Netvork yet.
        Route::get('/invite-link', [\App\Http\Controllers\Api\V1\InviteController::class, 'mine']);

        /*
         * A group's invite link, and the people it put in the queue.
         *
         * Following a link needs an account - joining a group is something a
         * member does - so these sit inside auth rather than beside the
         * public invite above.
         */
        // The same group again, with the same people or a subset of them.
        Route::post('/groups/{group}/replicate', [GroupController::class, 'replicate']);
        Route::get('/groups/{group}/invite', [\App\Http\Controllers\Api\V1\GroupInviteController::class, 'show']);
        Route::put('/groups/{group}/invite', [\App\Http\Controllers\Api\V1\GroupInviteController::class, 'update']);
        Route::post('/groups/{group}/invite/rotate', [\App\Http\Controllers\Api\V1\GroupInviteController::class, 'rotate']);
        Route::get('/groups/{group}/join-requests', [\App\Http\Controllers\Api\V1\GroupInviteController::class, 'pending']);
        Route::post('/groups/{group}/join-requests/{joinRequest}', [\App\Http\Controllers\Api\V1\GroupInviteController::class, 'decide']);
        Route::get('/join-group/{token}', [\App\Http\Controllers\Api\V1\GroupInviteController::class, 'preview'])
            ->where('token', '[A-Za-z0-9]{16,32}');
        Route::post('/join-group/{token}', [\App\Http\Controllers\Api\V1\GroupInviteController::class, 'join'])
            ->middleware('throttle:20,1')->where('token', '[A-Za-z0-9]{16,32}');

        // Meetings (Meet-style link rooms)
        Route::get('/meetings', [\App\Http\Controllers\Api\V1\MeetingController::class, 'index']);
        Route::post('/meetings', [\App\Http\Controllers\Api\V1\MeetingController::class, 'store']);
        Route::get('/meetings/{meeting}', [\App\Http\Controllers\Api\V1\MeetingController::class, 'show']);
        Route::post('/meetings/{meeting}/join', [\App\Http\Controllers\Api\V1\MeetingController::class, 'join']);
        Route::post('/meetings/{meeting}/invite', [\App\Http\Controllers\Api\V1\MeetingController::class, 'invite']);
        Route::post('/meetings/{meeting}/leave', [\App\Http\Controllers\Api\V1\MeetingController::class, 'leave']);
        Route::post('/meetings/{meeting}/heartbeat', [\App\Http\Controllers\Api\V1\MeetingController::class, 'heartbeat']);
        Route::post('/meetings/{meeting}/host-action', [\App\Http\Controllers\Api\V1\MeetingController::class, 'hostAction']);
        Route::post('/meetings/{meeting}/end', [\App\Http\Controllers\Api\V1\MeetingController::class, 'end']);
        Route::delete('/meetings/{meeting}', [\App\Http\Controllers\Api\V1\MeetingController::class, 'destroy']);
        // A join token for the SFU. Only ever issued to somebody the room has
        // already admitted — see the controller.
        Route::post('/meetings/{meeting}/realtime-token', [\App\Http\Controllers\Api\V1\MeetingController::class, 'realtimeToken']);
        // WebRTC signalling posts one request per ICE candidate - with a TURN
        // server in play that is dozens per peer, so the ordinary per-minute
        // limit would drop candidates and strand the connection in "checking".
        Route::post('/meetings/{meeting}/signal', [\App\Http\Controllers\Api\V1\MeetingController::class, 'signal'])
            ->withoutMiddleware('throttle:180,1')
            ->middleware('throttle:1200,1');
        Route::post('/meetings/{meeting}/name', [\App\Http\Controllers\Api\V1\MeetingController::class, 'rename']);
        Route::post('/meetings/{meeting}/react', [\App\Http\Controllers\Api\V1\MeetingController::class, 'react']);
        Route::post('/meetings/{meeting}/admit', [\App\Http\Controllers\Api\V1\MeetingController::class, 'admit']);
        Route::put('/meetings/{meeting}/approval', [\App\Http\Controllers\Api\V1\MeetingController::class, 'setApproval']);
        Route::put('/meetings/{meeting}/passcode', [\App\Http\Controllers\Api\V1\MeetingController::class, 'setPasscode']);
        Route::post('/meetings/{meeting}/chat', [\App\Http\Controllers\Api\V1\MeetingController::class, 'chat']);
        Route::post('/meetings/{meeting}/chat-file', [\App\Http\Controllers\Api\V1\MeetingController::class, 'chatFile']);
        Route::get('/meetings/{meeting}/chat-file/{file}', [\App\Http\Controllers\Api\V1\MeetingController::class, 'chatFileDownload']);

        // Projects (money ledgers)
        Route::get('/projects', [\App\Http\Controllers\Api\V1\ProjectController::class, 'index']);
        Route::post('/projects', [\App\Http\Controllers\Api\V1\ProjectController::class, 'store']);
        Route::put('/projects/{project}', [\App\Http\Controllers\Api\V1\ProjectController::class, 'update']);
        Route::delete('/projects/{project}', [\App\Http\Controllers\Api\V1\ProjectController::class, 'destroy']);
        Route::get('/projects/{project}/entries', [\App\Http\Controllers\Api\V1\ProjectController::class, 'entries']);
        Route::post('/projects/{project}/entries', [\App\Http\Controllers\Api\V1\ProjectController::class, 'storeEntry']);
        Route::put('/projects/{project}/entries/{entry}', [\App\Http\Controllers\Api\V1\ProjectController::class, 'updateEntry']);
        Route::delete('/projects/{project}/entries/{entry}', [\App\Http\Controllers\Api\V1\ProjectController::class, 'destroyEntry']);
        Route::post('/projects/{project}/share', [\App\Http\Controllers\Api\V1\ProjectController::class, 'share']);
        Route::post('/projects/{project}/unshare', [\App\Http\Controllers\Api\V1\ProjectController::class, 'unshare']);
        Route::get('/projects/{project}/summary', [\App\Http\Controllers\Api\V1\ProjectController::class, 'summary']);
        Route::get('/projects/{project}/export', [\App\Http\Controllers\Api\V1\ProjectController::class, 'export']);
        Route::post('/projects/{project}/request-password-reset', [\App\Http\Controllers\Api\V1\ProjectController::class, 'requestPasswordReset']);
        Route::post('/projects/{project}/reset-password', [\App\Http\Controllers\Api\V1\ProjectController::class, 'resetPassword']);

        // Files & folders
        Route::get('/files/browse', [FileController::class, 'browse']);
        Route::get('/files/shared-with-me', [FileController::class, 'sharedWithMe']);
        Route::get('/files/usage', [FileController::class, 'usage']);
        Route::get('/files/trash', [FileController::class, 'trash']);
        Route::post('/files/upload', [FileController::class, 'upload']);
        Route::get('/files/{file}/download', [FileController::class, 'download']);
        Route::post('/files/{file}/share-link', [FileController::class, 'shareLink']);
        Route::delete('/files/{file}/share-link', [FileController::class, 'revokeShareLink']);
        Route::put('/files/{file}', [FileController::class, 'update']);
        Route::delete('/files/{file}', [FileController::class, 'destroy']);
        Route::post('/files/{uuid}/restore', [FileController::class, 'restore']);
        Route::delete('/files/{uuid}/force', [FileController::class, 'forceDelete']);
        Route::post('/files/{file}/share', [FileController::class, 'share']);
        Route::post('/folders', [FileController::class, 'storeFolder']);
        Route::post('/folders/{folder}/share', [FileController::class, 'shareFolder']);
        Route::get('/files/shared-by-me', [FileController::class, 'sharedByMe']);
        Route::post('/files/{file}/unshare', [FileController::class, 'unshare']);
        Route::post('/folders/{folder}/unshare', [FileController::class, 'unshareFolder']);
        Route::get('/folders/{folder}/shared-files', [FileController::class, 'sharedFolderFiles']);
        Route::put('/folders/{folder}', [FileController::class, 'updateFolder']);
        Route::delete('/folders/{folder}', [FileController::class, 'destroyFolder']);

        // Groups
        Route::apiResource('groups', GroupController::class);
        Route::post('/groups/{group}/members', [GroupController::class, 'addMember']);
        Route::put('/groups/{group}/members/{userUuid}', [GroupController::class, 'updateMember']);
        Route::delete('/groups/{group}/members/{userUuid}', [GroupController::class, 'removeMember']);
        Route::get('/groups/{group}/tasks', [GroupController::class, 'tasks']);

        /*
         * Netvork Trade — the business layer on a personal account.
         *
         * No separate shell and no separate login: a person opens a page
         * beside their profile, puts products on it, posts as it, and is
         * found by what they do. Everything here rides the same session as
         * the rest of the personal app.
         */
        Route::get('/business/me', [\App\Http\Controllers\Api\V1\Business\PageController::class, 'me']);
        Route::post('/business/page', [\App\Http\Controllers\Api\V1\Business\PageController::class, 'store']);
        Route::put('/business/page', [\App\Http\Controllers\Api\V1\Business\PageController::class, 'update']);
        Route::delete('/business/page', [\App\Http\Controllers\Api\V1\Business\PageController::class, 'destroy']);
        // The company's team, and what it buys and sells.
        Route::get('/business/page/team', [\App\Http\Controllers\Api\V1\Business\TeamController::class, 'index']);
        Route::post('/business/page/team/invite', [\App\Http\Controllers\Api\V1\Business\TeamController::class, 'invite']);
        Route::post('/business/page/team/accept', [\App\Http\Controllers\Api\V1\Business\TeamController::class, 'accept']);
        Route::post('/business/page/team/transfer', [\App\Http\Controllers\Api\V1\Business\TeamController::class, 'transfer']);
        Route::put('/business/page/team/{id}', [\App\Http\Controllers\Api\V1\Business\TeamController::class, 'update'])->whereNumber('id');
        Route::delete('/business/page/team/{id}', [\App\Http\Controllers\Api\V1\Business\TeamController::class, 'destroy'])->whereNumber('id');
        Route::post('/business/pages/{slug}/join', [\App\Http\Controllers\Api\V1\Business\TeamController::class, 'requestToJoin']);
        Route::post('/business/pages/{slug}/claim', [\App\Http\Controllers\Api\V1\Business\ClaimController::class, 'claim']);
        Route::get('/business/page/trade-lines', [\App\Http\Controllers\Api\V1\Business\PageController::class, 'tradeLines']);
        Route::put('/business/page/trade-lines', [\App\Http\Controllers\Api\V1\Business\PageController::class, 'putTradeLines']);
        // A visitor's enquiry: answered by email, then carried into an account.
        Route::post('/business/enquiries/claim', [\App\Http\Controllers\Api\V1\Business\EnquiryController::class, 'claim']);
        Route::post('/business/enquiries/{uuid}/reply', [\App\Http\Controllers\Api\V1\Business\EnquiryController::class, 'reply']);
        Route::post('/business/enquiries/{uuid}/quote', [\App\Http\Controllers\Api\V1\Business\QuoteController::class, 'forEnquiry']);
        // From conversation to deal: a meeting on it, the outcome after, the pipeline of all of them.
        Route::get('/business/pipeline', [\App\Http\Controllers\Api\V1\Business\EnquiryController::class, 'pipeline']);
        Route::post('/business/enquiries/{uuid}/meeting', [\App\Http\Controllers\Api\V1\Business\EnquiryController::class, 'meeting']);
        Route::post('/business/enquiries/{uuid}/outcome', [\App\Http\Controllers\Api\V1\Business\EnquiryController::class, 'outcome']);
        // Why two companies should talk, and who to talk to.
        Route::get('/business/matches', [\App\Http\Controllers\Api\V1\Business\MatchController::class, 'mine']);
        Route::get('/requirements/{uuid}/matches', [\App\Http\Controllers\Api\V1\Business\MatchController::class, 'forIntent']);

        // The other direction: what buyers need, and the offers that answer.
        Route::get('/requirements', [\App\Http\Controllers\Api\V1\Business\RequirementController::class, 'index']);
        Route::post('/requirements', [\App\Http\Controllers\Api\V1\Business\RequirementController::class, 'store']);
        Route::get('/requirements/{uuid}', [\App\Http\Controllers\Api\V1\Business\RequirementController::class, 'show']);
        Route::put('/requirements/{uuid}', [\App\Http\Controllers\Api\V1\Business\RequirementController::class, 'update']);
        Route::post('/requirements/{uuid}/quotes', [\App\Http\Controllers\Api\V1\Business\QuoteController::class, 'forRequirement']);
        Route::get('/business/quotes', [\App\Http\Controllers\Api\V1\Business\QuoteController::class, 'index']);
        Route::put('/business/quotes/{uuid}', [\App\Http\Controllers\Api\V1\Business\QuoteController::class, 'update']);

        // Trade shows: post, attend (and it lands in your calendar).
        Route::post('/trade/events', [\App\Http\Controllers\Api\V1\Business\TradeEventController::class, 'store']);
        Route::put('/trade/events/{uuid}', [\App\Http\Controllers\Api\V1\Business\TradeEventController::class, 'update']);
        Route::post('/trade/events/{uuid}/attend', [\App\Http\Controllers\Api\V1\Business\TradeEventController::class, 'attend']);

        // Saved searches: the standing question, answered daily.
        Route::get('/trade/alerts', [\App\Http\Controllers\Api\V1\Business\SavedSearchController::class, 'index']);
        Route::post('/trade/alerts', [\App\Http\Controllers\Api\V1\Business\SavedSearchController::class, 'store']);
        Route::put('/trade/alerts/{uuid}', [\App\Http\Controllers\Api\V1\Business\SavedSearchController::class, 'update']);
        Route::delete('/trade/alerts/{uuid}', [\App\Http\Controllers\Api\V1\Business\SavedSearchController::class, 'destroy']);

        // Many products from one sheet.
        Route::get('/business/products/import/template', [\App\Http\Controllers\Api\V1\Business\ProductController::class, 'importTemplate']);
        Route::post('/business/products/import', [\App\Http\Controllers\Api\V1\Business\ProductController::class, 'import']);

        // The badge: the owner asks with a document.
        Route::post('/business/page/verification', [\App\Http\Controllers\Api\V1\Business\VerificationController::class, 'request']);

        /*
         * Hot Leads — GrapOut's own buyers and suppliers.
         *
         * Searching is one endpoint for both kinds; the lists are the old
         * My Contacts folders. Everything is the person's own, so nothing
         * here is scoped to a company page: a salesperson keeps their
         * leads whether or not their employer has a page yet.
         */
        Route::prefix('grap')->group(function () {
            Route::get('/search', [\App\Http\Controllers\Api\V1\Grap\GrapController::class, 'search']);
            Route::get('/allowance', [\App\Http\Controllers\Api\V1\Grap\GrapController::class, 'allowance']);
            Route::get('/leads/{uuid}', [\App\Http\Controllers\Api\V1\Grap\GrapController::class, 'show']);
            /*
             * The third throttle argument is a counter name, and it is not
             * optional here. Without one, Laravel keys every throttle on the
             * user id alone — so this limit and the group's 180/min share one
             * counter, every request anywhere in the app counts against it,
             * and a search was refused as "Too Many Attempts" after a few page
             * loads with no search made at all.
             */
            // Throttled harder than the rest: this is the one that spends money.
            Route::post('/leads/{uuid}/reveal', [\App\Http\Controllers\Api\V1\Grap\GrapController::class, 'reveal'])
                ->middleware('throttle:60,1,grap-reveal');

            /*
             * Grap Company. Not a directory search: it goes out to the web on
             * demand and pays a vendor, so it is throttled harder than
             * anything else here and metered before it runs.
             */
            Route::get('/company/status', [\App\Http\Controllers\Api\V1\Grap\GrapCompanyController::class, 'status']);
            Route::get('/company/roles', [\App\Http\Controllers\Api\V1\Grap\GrapCompanyController::class, 'roles']);
            Route::post('/company/search', [\App\Http\Controllers\Api\V1\Grap\GrapCompanyController::class, 'search'])
                ->middleware('throttle:20,1,grap-company');

            /*
             * Cohorts — the emails a person writes once — and Book meeting,
             * which sends one to a contact Hot Leads found. It goes from
             * GrapOut's own address, so it has its own tight limit.
             */
            Route::get('/cohorts', [\App\Http\Controllers\Api\V1\Grap\GrapCohortController::class, 'index']);
            Route::post('/cohorts', [\App\Http\Controllers\Api\V1\Grap\GrapCohortController::class, 'store']);
            Route::put('/cohorts/{uuid}', [\App\Http\Controllers\Api\V1\Grap\GrapCohortController::class, 'update']);
            Route::delete('/cohorts/{uuid}', [\App\Http\Controllers\Api\V1\Grap\GrapCohortController::class, 'destroy']);
            Route::post('/meeting-email', [\App\Http\Controllers\Api\V1\Grap\GrapCohortController::class, 'send'])
                ->middleware('throttle:30,60,grap-meeting-email');

            Route::get('/lists', [\App\Http\Controllers\Api\V1\Grap\GrapListController::class, 'index']);
            Route::post('/lists', [\App\Http\Controllers\Api\V1\Grap\GrapListController::class, 'store']);
            Route::get('/lists/{uuid}', [\App\Http\Controllers\Api\V1\Grap\GrapListController::class, 'show']);
            Route::put('/lists/{uuid}', [\App\Http\Controllers\Api\V1\Grap\GrapListController::class, 'update']);
            Route::delete('/lists/{uuid}', [\App\Http\Controllers\Api\V1\Grap\GrapListController::class, 'destroy']);
            Route::post('/lists/{uuid}/leads', [\App\Http\Controllers\Api\V1\Grap\GrapListController::class, 'assign']);
            Route::get('/lists/{uuid}/export', [\App\Http\Controllers\Api\V1\Grap\GrapListController::class, 'export']);
        });

        /*
         * The company's own email list, and what it sends to it.
         *
         * Left registered on purpose while its screens are off the menu —
         * see `frontend/src/lib/features.ts`. The mailing system works; it
         * is only hidden, and hiding it in one place rather than deleting
         * it is what makes turning it back on a one-line change.
         */
        Route::get('/business/outreach', [\App\Http\Controllers\Api\V1\Business\OutreachController::class, 'status']);
        Route::get('/business/outreach/contacts', [\App\Http\Controllers\Api\V1\Business\OutreachController::class, 'contacts']);
        Route::post('/business/outreach/contacts', [\App\Http\Controllers\Api\V1\Business\OutreachController::class, 'store']);
        Route::post('/business/outreach/contacts/import', [\App\Http\Controllers\Api\V1\Business\OutreachController::class, 'import']);
        Route::put('/business/outreach/contacts/{uuid}', [\App\Http\Controllers\Api\V1\Business\OutreachController::class, 'update']);
        Route::delete('/business/outreach/contacts/{uuid}', [\App\Http\Controllers\Api\V1\Business\OutreachController::class, 'destroy']);
        Route::post('/business/outreach/send', [\App\Http\Controllers\Api\V1\Business\OutreachController::class, 'send']);
        Route::get('/business/outreach/sends', [\App\Http\Controllers\Api\V1\Business\OutreachController::class, 'sends']);
        // Templates, campaigns and figures for the page's own list.
        Route::get('/business/outreach/templates', [\App\Http\Controllers\Api\V1\Business\OutreachCampaignController::class, 'templates']);
        Route::post('/business/outreach/templates', [\App\Http\Controllers\Api\V1\Business\OutreachCampaignController::class, 'storeTemplate']);
        Route::put('/business/outreach/templates/{uuid}', [\App\Http\Controllers\Api\V1\Business\OutreachCampaignController::class, 'updateTemplate']);
        Route::delete('/business/outreach/templates/{uuid}', [\App\Http\Controllers\Api\V1\Business\OutreachCampaignController::class, 'destroyTemplate']);
        Route::get('/business/outreach/templates/{uuid}/preview', [\App\Http\Controllers\Api\V1\Business\OutreachCampaignController::class, 'preview']);
        Route::get('/business/outreach/campaigns', [\App\Http\Controllers\Api\V1\Business\OutreachCampaignController::class, 'campaigns']);
        Route::post('/business/outreach/campaigns', [\App\Http\Controllers\Api\V1\Business\OutreachCampaignController::class, 'storeCampaign']);
        Route::put('/business/outreach/campaigns/{uuid}', [\App\Http\Controllers\Api\V1\Business\OutreachCampaignController::class, 'updateCampaign']);
        Route::delete('/business/outreach/campaigns/{uuid}', [\App\Http\Controllers\Api\V1\Business\OutreachCampaignController::class, 'destroyCampaign']);
        Route::post('/business/outreach/campaigns/{uuid}/launch', [\App\Http\Controllers\Api\V1\Business\OutreachCampaignController::class, 'launch']);
        Route::post('/business/outreach/campaigns/{uuid}/pause', [\App\Http\Controllers\Api\V1\Business\OutreachCampaignController::class, 'pause']);
        Route::get('/business/outreach/stats', [\App\Http\Controllers\Api\V1\Business\OutreachCampaignController::class, 'stats']);
        // Lists, cohorts, sequences and the inbox of what came back.
        Route::get('/business/outreach/lists', [\App\Http\Controllers\Api\V1\Business\OutreachListController::class, 'lists']);
        Route::post('/business/outreach/lists', [\App\Http\Controllers\Api\V1\Business\OutreachListController::class, 'storeList']);
        Route::put('/business/outreach/lists/{uuid}', [\App\Http\Controllers\Api\V1\Business\OutreachListController::class, 'updateList']);
        Route::delete('/business/outreach/lists/{uuid}', [\App\Http\Controllers\Api\V1\Business\OutreachListController::class, 'destroyList']);
        Route::post('/business/outreach/lists/{uuid}/contacts', [\App\Http\Controllers\Api\V1\Business\OutreachListController::class, 'assign']);
        Route::get('/business/outreach/cohorts', [\App\Http\Controllers\Api\V1\Business\OutreachListController::class, 'cohorts']);
        Route::post('/business/outreach/cohorts', [\App\Http\Controllers\Api\V1\Business\OutreachListController::class, 'storeCohort']);
        Route::put('/business/outreach/cohorts/{uuid}', [\App\Http\Controllers\Api\V1\Business\OutreachListController::class, 'updateCohort']);
        Route::delete('/business/outreach/cohorts/{uuid}', [\App\Http\Controllers\Api\V1\Business\OutreachListController::class, 'destroyCohort']);
        Route::get('/business/outreach/cohorts/{uuid}/preview', [\App\Http\Controllers\Api\V1\Business\OutreachListController::class, 'previewCohort']);
        Route::get('/business/outreach/sequences', [\App\Http\Controllers\Api\V1\Business\OutreachListController::class, 'sequences']);
        Route::post('/business/outreach/sequences', [\App\Http\Controllers\Api\V1\Business\OutreachListController::class, 'storeSequence']);
        Route::put('/business/outreach/sequences/{uuid}', [\App\Http\Controllers\Api\V1\Business\OutreachListController::class, 'updateSequence']);
        Route::delete('/business/outreach/sequences/{uuid}', [\App\Http\Controllers\Api\V1\Business\OutreachListController::class, 'destroySequence']);
        Route::get('/business/outreach/inbox', [\App\Http\Controllers\Api\V1\Business\OutreachListController::class, 'inbox']);
        Route::put('/business/outreach/inbox/{uuid}/read', [\App\Http\Controllers\Api\V1\Business\OutreachListController::class, 'readInbox']);
        // The document folder behind the badge.
        Route::get('/business/page/documents', [\App\Http\Controllers\Api\V1\Business\DocumentController::class, 'index']);
        Route::post('/business/page/documents', [\App\Http\Controllers\Api\V1\Business\DocumentController::class, 'store']);
        Route::delete('/business/page/documents/{uuid}', [\App\Http\Controllers\Api\V1\Business\DocumentController::class, 'destroy']);
        Route::post('/business/page/image/{which}', [\App\Http\Controllers\Api\V1\Business\PageController::class, 'uploadImage']);
        Route::post('/business/page/hero', [\App\Http\Controllers\Api\V1\Business\PageController::class, 'setHero']);
        Route::get('/business/page/followers', [\App\Http\Controllers\Api\V1\Business\PageController::class, 'followers']);
        Route::get('/business/pages/{slug}', [\App\Http\Controllers\Api\V1\Business\PageController::class, 'show']);
        Route::post('/business/pages/{slug}/follow', [\App\Http\Controllers\Api\V1\Business\PageController::class, 'follow']);
        Route::delete('/business/pages/{slug}/follow', [\App\Http\Controllers\Api\V1\Business\PageController::class, 'unfollow']);

        Route::get('/business/products', [\App\Http\Controllers\Api\V1\Business\ProductController::class, 'index']);
        Route::post('/business/products', [\App\Http\Controllers\Api\V1\Business\ProductController::class, 'store']);
        Route::get('/business/products/{uuid}', [\App\Http\Controllers\Api\V1\Business\ProductController::class, 'show']);
        Route::put('/business/products/{uuid}', [\App\Http\Controllers\Api\V1\Business\ProductController::class, 'update']);
        Route::delete('/business/products/{uuid}', [\App\Http\Controllers\Api\V1\Business\ProductController::class, 'destroy']);
        Route::post('/business/products/{uuid}/images', [\App\Http\Controllers\Api\V1\Business\ProductController::class, 'uploadImages']);
        Route::delete('/business/products/{uuid}/images/{imageId}', [\App\Http\Controllers\Api\V1\Business\ProductController::class, 'deleteImage']);
        Route::put('/business/products/{uuid}/images/order', [\App\Http\Controllers\Api\V1\Business\ProductController::class, 'reorderImages']);
        Route::post('/business/products/{uuid}/interested', [\App\Http\Controllers\Api\V1\Business\ProductController::class, 'interested']);

        Route::post('/business/enquiries', [\App\Http\Controllers\Api\V1\Business\EnquiryController::class, 'store'])
            ->middleware('throttle:20,1');
        Route::get('/business/enquiries', [\App\Http\Controllers\Api\V1\Business\EnquiryController::class, 'received']);
        Route::get('/business/enquiries/sent', [\App\Http\Controllers\Api\V1\Business\EnquiryController::class, 'sent']);
        Route::put('/business/enquiries/{uuid}', [\App\Http\Controllers\Api\V1\Business\EnquiryController::class, 'update']);
        Route::post('/business/enquiries/{uuid}/chat', [\App\Http\Controllers\Api\V1\Business\EnquiryController::class, 'chat']);

        Route::get('/posts', [\App\Http\Controllers\Api\V1\Business\PostController::class, 'feed']);
        Route::post('/posts', [\App\Http\Controllers\Api\V1\Business\PostController::class, 'store'])
            ->middleware('throttle:30,1');
        Route::get('/posts/{uuid}', [\App\Http\Controllers\Api\V1\Business\PostController::class, 'show']);
        Route::delete('/posts/{uuid}', [\App\Http\Controllers\Api\V1\Business\PostController::class, 'destroy']);
        Route::post('/posts/{uuid}/like', [\App\Http\Controllers\Api\V1\Business\PostController::class, 'like']);
        Route::get('/posts/{uuid}/comments', [\App\Http\Controllers\Api\V1\Business\PostController::class, 'comments']);
        Route::post('/posts/{uuid}/comments', [\App\Http\Controllers\Api\V1\Business\PostController::class, 'comment']);
        Route::post('/posts/{uuid}/forward', [\App\Http\Controllers\Api\V1\Business\PostController::class, 'forward']);


        // The network search: by name, by what somebody does, by what their
        // page sells. Not under /people — that prefix already has a {uuid}
        // route, which would have read "search" as somebody's id.
        Route::get('/network/search', [\App\Http\Controllers\Api\V1\Business\PeopleController::class, 'search']);

        // Handing a borrowed account back: the way out must not depend on where the admin is standing.
        Route::post('/impersonation/stop', [\App\Http\Controllers\Api\V1\Admin\ImpersonationController::class, 'stop']);

        // Presence. The heartbeat is frequent by design — every 45 seconds
        // from every open tab — so it gets its own bucket rather than eating
        // the shared allowance, and is exempt from TrackActivity: it reports
        // whether anybody is there, so it must not itself count as somebody
        // being there.
        Route::post('/presence', [PresenceController::class, 'beat'])
            ->withoutMiddleware('throttle:180,1')
            ->middleware('throttle:120,1');
        Route::post('/presence/leaving', [PresenceController::class, 'leaving'])
            ->withoutMiddleware('throttle:180,1')
            ->middleware('throttle:120,1');

        /*
         * One message to a list of people, arriving as private ones.
         *
         * Its own small bucket. Every other chat route is one message to one
         * room; this is up to fifty in a request, so the shared allowance is
         * the wrong unit — a handful of sends a minute is well past what a
         * person does on purpose and well short of what a script wants.
         */
        Route::post('/broadcasts', [BroadcastController::class, 'store'])
            ->middleware('throttle:6,1');

        // Chat
        Route::get('/conversations', [ConversationController::class, 'index']);
        Route::post('/conversations', [ConversationController::class, 'store']);
        Route::get('/groups/{group}/conversation', [ConversationController::class, 'forGroup']);
        Route::post('/conversations/{conversation}/read', [ConversationController::class, 'markRead']);
        // Fires on every few keystrokes, so it gets its own generous bucket
        // rather than eating the shared per-minute allowance.
        Route::post('/conversations/{conversation}/typing', [ConversationController::class, 'typing'])
            ->withoutMiddleware('throttle:180,1')
            ->middleware('throttle:600,1');
        // Disappearing messages: off unless somebody in the room says so.
        Route::post('/conversations/{conversation}/retention', [ConversationController::class, 'setRetention']);
        Route::post('/conversations/{conversation}/mute', [ConversationController::class, 'toggleMute']);
        Route::post('/conversations/{conversation}/archive', [ConversationController::class, 'toggleArchive']);
        Route::get('/conversations/{conversation}/members', [ConversationController::class, 'members']);
        // Removing somebody from a group chat is removing them from the
        // group — the chat is the group's, not a guest list of its own.
        Route::delete('/conversations/{conversation}/members/{userUuid}', [ConversationController::class, 'removeMember']);
        Route::get('/conversations/{conversation}/messages', [MessageController::class, 'index']);
        Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store']);
        Route::put('/conversations/{conversation}/messages/{message}', [MessageController::class, 'update']);
        Route::delete('/conversations/{conversation}/messages/{messageUuid}', [MessageController::class, 'destroy']);
        // The same message again, in somebody else's thread.
        Route::post('/conversations/{conversation}/messages/{messageUuid}/forward', [MessageController::class, 'forward']);
        // Kept privately, or held up for everyone.
        Route::post('/conversations/{conversation}/messages/{messageUuid}/star', [MessageController::class, 'star']);
        Route::post('/conversations/{conversation}/messages/{messageUuid}/pin', [MessageController::class, 'pin']);
        Route::get('/conversations/{conversation}/pinned', [MessageController::class, 'pinned']);
        Route::post('/conversations/{conversation}/messages/{message}/react', [MessageController::class, 'react']);
        Route::get('/conversations/{conversation}/attachments/{attachmentId}', [MessageController::class, 'downloadAttachment']);

        // Calls
        /*
         * The ICE servers, which a meeting guest needs as much as a member —
         * a peer connection is built from them, so without this a guest who
         * had just been admitted got "Unauthenticated." the moment there was
         * somebody to connect to, and never saw the room. It only showed up
         * on admission because that is when the first peer appears.
         *
         * Resolved, not required: a signed-in member carries on to Sanctum
         * untouched, and anyone with neither is still turned away.
         */
        Route::get('/calls/config', [CallController::class, 'config'])
            ->middleware(\App\Http\Middleware\ResolveMeetingGuest::class);
        Route::get('/calls/history', [CallController::class, 'history']);
        // The recovery path for a ring whose websocket event never landed.
        Route::get('/calls/incoming', [CallController::class, 'incoming']);
        Route::post('/conversations/{conversation}/calls', [CallController::class, 'initiate']);
        Route::post('/calls/{call}/respond', [CallController::class, 'respond']);
        Route::post('/calls/{call}/end', [CallController::class, 'end']);
        Route::post('/calls/{call}/heartbeat', [CallController::class, 'heartbeat']);
        Route::post('/calls/{call}/signal', [CallController::class, 'signal'])
            ->withoutMiddleware('throttle:180,1')
            ->middleware('throttle:1200,1');
        Route::post('/calls/{call}/invite', [CallController::class, 'invite']);

        // Habits
        Route::get('/habits', [\App\Http\Controllers\Api\V1\HabitController::class, 'index']);
        Route::post('/habits', [\App\Http\Controllers\Api\V1\HabitController::class, 'store']);
        Route::put('/habits/{habit}', [\App\Http\Controllers\Api\V1\HabitController::class, 'update']);
        Route::delete('/habits/{habit}', [\App\Http\Controllers\Api\V1\HabitController::class, 'destroy']);
        Route::post('/habits/{habit}/log', [\App\Http\Controllers\Api\V1\HabitController::class, 'log']);

        // Goals
        Route::get('/goals', [\App\Http\Controllers\Api\V1\GoalController::class, 'index']);
        Route::post('/goals', [\App\Http\Controllers\Api\V1\GoalController::class, 'store']);
        Route::put('/goals/{goal}', [\App\Http\Controllers\Api\V1\GoalController::class, 'update']);
        Route::delete('/goals/{goal}', [\App\Http\Controllers\Api\V1\GoalController::class, 'destroy']);
        Route::post('/goals/{goal}/milestones', [\App\Http\Controllers\Api\V1\GoalController::class, 'addMilestone']);
        Route::post('/goals/{goal}/milestones/{milestoneId}/toggle', [\App\Http\Controllers\Api\V1\GoalController::class, 'toggleMilestone']);
        Route::delete('/goals/{goal}/milestones/{milestoneId}', [\App\Http\Controllers\Api\V1\GoalController::class, 'deleteMilestone']);

        // Bills
        Route::get('/bills', [\App\Http\Controllers\Api\V1\BillController::class, 'index']);
        Route::post('/bills', [\App\Http\Controllers\Api\V1\BillController::class, 'store']);
        Route::put('/bills/{bill}', [\App\Http\Controllers\Api\V1\BillController::class, 'update']);
        Route::delete('/bills/{bill}', [\App\Http\Controllers\Api\V1\BillController::class, 'destroy']);
        Route::post('/bills/{bill}/pay', [\App\Http\Controllers\Api\V1\BillController::class, 'markPaid']);

        // Subscription & billing
        Route::get('/subscription', [\App\Http\Controllers\Api\V1\SubscriptionController::class, 'mySubscription']);
        Route::post('/subscription/quote', [\App\Http\Controllers\Api\V1\BillingController::class, 'quote']);
        Route::post('/subscription/checkout', [\App\Http\Controllers\Api\V1\BillingController::class, 'checkout'])
            ->middleware('throttle:10,1');
        Route::post('/subscription/cancel', [\App\Http\Controllers\Api\V1\BillingController::class, 'cancelSubscription']);
        Route::post('/payments/{order}/verify', [\App\Http\Controllers\Api\V1\BillingController::class, 'verifyOrder'])
            ->middleware('throttle:30,1');
        Route::get('/payments', [\App\Http\Controllers\Api\V1\BillingController::class, 'payments']);
        Route::get('/invoices', [\App\Http\Controllers\Api\V1\BillingController::class, 'invoices']);
        Route::get('/invoices/{invoice}', [\App\Http\Controllers\Api\V1\BillingController::class, 'invoiceView']);

        // Voice assistant
        Route::post('/voice/interpret', [\App\Http\Controllers\Api\V1\VoiceController::class, 'interpret']);
        Route::post('/voice/transcribe', [\App\Http\Controllers\Api\V1\VoiceController::class, 'transcribe']);

        // Reports
        Route::get('/reports/summary', [ReportController::class, 'summary']);
        Route::get('/reports/productivity', [ReportController::class, 'productivity']);
        Route::get('/reports/export.csv', [ReportController::class, 'exportCsv']);

        // Report a user or message (moderation intake)
        Route::post('/reports', [\App\Http\Controllers\Api\V1\ReportUserController::class, 'store'])
            ->middleware('throttle:10,1');

        // --- Internal Work (Admin / Subadmin / Salesperson) ----------------
        Route::prefix('admin/internal')->middleware(['role:admin,super_admin,subadmin,salesperson', 'module:internal,view'])->group(function () {
            Route::post('lookup', [\App\Http\Controllers\Api\V1\Admin\InternalNoteController::class, 'lookup']);
            Route::delete('notes/{uuid}', [\App\Http\Controllers\Api\V1\Admin\InternalNoteController::class, 'destroy']);
            Route::get('/threads', [\App\Http\Controllers\Api\V1\Admin\InternalNoteController::class, 'threads']);
            Route::get('/users/{user}/notes', [\App\Http\Controllers\Api\V1\Admin\InternalNoteController::class, 'index']);
            Route::post('/users/{user}/notes', [\App\Http\Controllers\Api\V1\Admin\InternalNoteController::class, 'store']);
        });

        // --- Admin + Subadmin (module-gated) ------------------------------
        Route::prefix('admin')->middleware('role:admin,super_admin,subadmin')->group(function () {
            // Approvals (subadmin default-granted)
            Route::get('/change-requests', [\App\Http\Controllers\Api\V1\ChangeRequestController::class, 'pending'])
                ->middleware('module:approvals,view');
            Route::post('/change-requests/{changeRequest}', [\App\Http\Controllers\Api\V1\ChangeRequestController::class, 'review'])
                ->middleware('module:approvals,edit');

            // Users (subadmins need a grant)
            Route::get('/users', [AdminUserController::class, 'index'])->middleware('module:users,view');
            Route::get('/users/{user}/summary', [AdminUserController::class, 'summary'])->middleware('module:users,view');
            Route::get('/users/{user}/call-records', [AdminUserController::class, 'callRecords'])->middleware('module:users,view');
            Route::get('/users/{user}/message-records', [AdminUserController::class, 'messageRecords'])->middleware('module:users,view');
            Route::post('/users/{user}/suspend', [AdminUserController::class, 'suspend'])->middleware('module:users,delete');
            Route::put('/users/{user}/features', [AdminUserController::class, 'features'])->middleware('module:users,edit');
            // "Login as": an admin steps into a member's account; admins only, never into another admin.
            Route::post('/users/{user}/impersonate', [\App\Http\Controllers\Api\V1\Admin\ImpersonationController::class, 'start'])->middleware('role:admin,super_admin');
            Route::post('/users/{user}/activate', [AdminUserController::class, 'activate'])->middleware('module:users,edit');

            // Moderation
            Route::get('/reports', [\App\Http\Controllers\Api\V1\Admin\ModerationController::class, 'index'])
                ->middleware('module:moderation,view');
            Route::post('/reports/{report}/act', [\App\Http\Controllers\Api\V1\Admin\ModerationController::class, 'act'])
                ->middleware('module:moderation,edit'); // delete-level actions re-checked in controller

            // Activity & logins
            Route::get('/active-members', [AdminUserController::class, 'activeMembers'])->middleware('module:activity,view');
            Route::get('/login-histories', [RoleController::class, 'loginHistories'])->middleware('module:activity,view');
            Route::get('/audit-logs', [RoleController::class, 'auditLogs'])->middleware('module:activity,view');
        });

        // --- Salesperson workspace (also open to admins) ------------------
        Route::prefix('admin/sales')->middleware('role:salesperson,admin,super_admin')->group(function () {
            Route::get('/my-users', [\App\Http\Controllers\Api\V1\Admin\SalespersonController::class, 'myUsers']);
            Route::get('/users/{user}/summary', [\App\Http\Controllers\Api\V1\Admin\SalespersonController::class, 'summary']);
        });

        // --- Admin only ---------------------------------------------------
        Route::prefix('admin')->middleware('role:admin,super_admin')->group(function () {
            Route::get('/stats', [StatsController::class, 'index']);

            /*
             * Service accounts, seen from outside.
             *
             * Admin-only rather than shared with subadmins: a token issued here
             * can send as an account everybody trusts, and revoking one cuts an
             * integration off mid-flight. Neither is a moderation decision.
             */
            Route::get('/service-accounts', [\App\Http\Controllers\Api\V1\Admin\ServiceAccountAdminController::class, 'index']);
            Route::post('/service-accounts', [\App\Http\Controllers\Api\V1\Admin\ServiceAccountAdminController::class, 'store']);
            Route::post('/service-accounts/{uuid}/tokens', [\App\Http\Controllers\Api\V1\Admin\ServiceAccountAdminController::class, 'issueToken']);
            Route::get('/service-accounts/{uuid}/tokens', [\App\Http\Controllers\Api\V1\Admin\ServiceAccountAdminController::class, 'tokens']);
            Route::get('/service-accounts/{uuid}/tokens/{id}/reveal', [\App\Http\Controllers\Api\V1\Admin\ServiceAccountAdminController::class, 'revealToken'])->whereNumber('id');
            Route::delete('/service-accounts/{uuid}/tokens/{id}', [\App\Http\Controllers\Api\V1\Admin\ServiceAccountAdminController::class, 'revokeToken'])->whereNumber('id');
            Route::post('/service-accounts/{uuid}/revoke-tokens', [\App\Http\Controllers\Api\V1\Admin\ServiceAccountAdminController::class, 'revokeTokens']);
            Route::post('/users', [AdminUserController::class, 'store']);
            Route::get('/users/{user}', [AdminUserController::class, 'show']);
            Route::put('/users/{user}', [AdminUserController::class, 'update']);
            Route::post('/users/{user}/roles', [AdminUserController::class, 'syncRoles']);
            Route::get('/users/{user}/module-permissions', [AdminUserController::class, 'modulePermissions']);
            Route::put('/users/{user}/module-permissions', [AdminUserController::class, 'updateModulePermissions']);
            Route::post('/users/{user}/app-id/regenerate', [AdminUserController::class, 'regenerateAppId']);
            Route::get('/users/{user}/otp', [AdminUserController::class, 'activeOtp']);
            Route::post('/users/{user}/otp/resend', [AdminUserController::class, 'resendOtp']);
            Route::post('/users/{user}/verify-email', [AdminUserController::class, 'verifyEmail']);
            Route::get('/salespeople', [AdminUserController::class, 'salespeople']);
            Route::post('/users/{user}/salesperson', [AdminUserController::class, 'assignSalesperson']);
            Route::get('/users/{user}/locked-projects', [AdminUserController::class, 'lockedProjects']);
            Route::post('/projects/{uuid}/send-password-reset', [AdminUserController::class, 'sendProjectPasswordReset']);
            // Live meetings: what is running right now, and stopping it.
            // What is breaking in people's browsers.
            Route::get('/client-errors', [\App\Http\Controllers\Api\V1\ClientErrorController::class, 'index']);
            Route::post('/client-errors/{clientError}/resolve', [\App\Http\Controllers\Api\V1\ClientErrorController::class, 'resolve']);
            Route::get('/live-meetings', [\App\Http\Controllers\Api\V1\Admin\LiveMeetingController::class, 'index']);
            Route::delete('/live-meetings/{meeting}', [\App\Http\Controllers\Api\V1\Admin\LiveMeetingController::class, 'destroy']);
            Route::get('/settings', [AdminUserController::class, 'settings']);
            Route::put('/settings', [AdminUserController::class, 'updateSettings']);
            Route::get('/plans', [\App\Http\Controllers\Api\V1\Admin\PlanController::class, 'index']);
            Route::put('/plans/{plan}', [\App\Http\Controllers\Api\V1\Admin\PlanController::class, 'update']);
            Route::post('/users/{user}/plan', [\App\Http\Controllers\Api\V1\Admin\PlanController::class, 'assign']);
            Route::get('/roles', [RoleController::class, 'roles']);
            Route::get('/permissions', [RoleController::class, 'permissions']);

            // Billing administration
            Route::get('/billing/payments', [\App\Http\Controllers\Api\V1\Admin\BillingAdminController::class, 'payments']);
            Route::get('/billing/webhooks', [\App\Http\Controllers\Api\V1\Admin\BillingAdminController::class, 'webhooks']);
            Route::get('/billing/coupons', [\App\Http\Controllers\Api\V1\Admin\BillingAdminController::class, 'coupons']);
            Route::post('/billing/coupons', [\App\Http\Controllers\Api\V1\Admin\BillingAdminController::class, 'storeCoupon']);
            Route::put('/billing/coupons/{coupon:code}', [\App\Http\Controllers\Api\V1\Admin\BillingAdminController::class, 'updateCoupon']);
            Route::get('/billing/refunds', [\App\Http\Controllers\Api\V1\Admin\BillingAdminController::class, 'refunds']);
            Route::post('/billing/payments/{payment}/refund', [\App\Http\Controllers\Api\V1\Admin\BillingAdminController::class, 'createRefund']);
        });

        /*
         * --- CRM addon -----------------------------------------------------
         *
         * A separate product living behind its own door. Nothing here touches
         * the personal Netvork surface: access requires an active crm_members
         * row (crm.member middleware), and per-module rights ride on it.
         * Super admins manage which organizations have the addon at all.
         */

        // The addon switch itself: super admin only.
        // Verified badges: a super admin looks at the document and decides.
        Route::prefix('admin/business')->middleware('role:admin,super_admin')->group(function () {
            // Outreach: the mailboxes, and what each page may send.
            Route::get('/outreach/mailboxes', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'mailboxes']);
            Route::post('/outreach/mailboxes', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'storeMailbox']);
            Route::put('/outreach/mailboxes/{uuid}', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'updateMailbox']);
            Route::delete('/outreach/mailboxes/{uuid}', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'destroyMailbox']);
            Route::post('/outreach/mailboxes/{uuid}/test', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'testMailbox']);
            Route::get('/outreach/pages', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'pages']);
            Route::put('/outreach/pages/bulk', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'bulkPages']);
            Route::put('/outreach/pages/{uuid}', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'updatePage']);
            Route::get('/outreach/sends', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'sends']);
            Route::get('/outreach/templates', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'templates']);
            Route::post('/outreach/templates', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'storeTemplate']);
            Route::put('/outreach/templates/{uuid}', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'updateTemplate']);
            Route::delete('/outreach/templates/{uuid}', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'destroyTemplate']);
            Route::get('/outreach/campaigns', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'campaigns']);
            Route::post('/outreach/campaigns/{uuid}/pause', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'pauseCampaign']);
            Route::get('/outreach/contacts', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'contacts']);
            Route::put('/outreach/contacts/{uuid}', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'updateContact']);
            Route::get('/outreach/stats', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'stats']);
            Route::post('/outreach/mailboxes/{uuid}/dns', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'dnsMailbox']);
            Route::post('/outreach/mailboxes/{uuid}/smtp-test', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'smtpTestMailbox']);
            Route::post('/outreach/mailboxes/{uuid}/imap-test', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'imapTestMailbox']);
            Route::post('/outreach/mailboxes/{uuid}/sync', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'syncMailbox']);
            Route::post('/outreach/mailboxes/{uuid}/replicate', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'replicateMailbox']);
            Route::get('/outreach/groups', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'groups']);
            Route::post('/outreach/groups', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'storeGroup']);
            Route::put('/outreach/groups/{uuid}', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'updateGroup']);
            Route::delete('/outreach/groups/{uuid}', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'destroyGroup']);
            Route::get('/outreach/sequences', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'sequences']);
            Route::post('/outreach/sequences', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'storeSequence']);
            Route::put('/outreach/sequences/{uuid}', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'updateSequence']);
            Route::delete('/outreach/sequences/{uuid}', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'destroySequence']);
            Route::get('/outreach/cohorts', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'cohorts']);
            Route::post('/outreach/cohorts', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'storeCohort']);
            Route::put('/outreach/cohorts/{uuid}', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'updateCohort']);
            Route::delete('/outreach/cohorts/{uuid}', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'destroyCohort']);
            Route::get('/outreach/lists', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'lists']);
            Route::get('/outreach/inbox', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'inbox']);
            Route::put('/outreach/inbox/{uuid}/read', [\App\Http\Controllers\Api\V1\Admin\OutreachController::class, 'readInbox']);
            Route::get('/verifications', [\App\Http\Controllers\Api\V1\Business\VerificationController::class, 'pending']);
            Route::get('/verifications/{uuid}/document', [\App\Http\Controllers\Api\V1\Business\VerificationController::class, 'document']);
            Route::get('/verifications/{uuid}/documents/{doc}', [\App\Http\Controllers\Api\V1\Business\VerificationController::class, 'document']);
            Route::put('/verifications/{uuid}', [\App\Http\Controllers\Api\V1\Business\VerificationController::class, 'decide']);
            Route::get('/hs', [\App\Http\Controllers\Api\V1\Business\HsController::class, 'status']);
            Route::post('/hs/import', [\App\Http\Controllers\Api\V1\Business\HsController::class, 'import']);
            // The research as pages, and the claims on them.
            Route::get('/seed', [\App\Http\Controllers\Api\V1\Business\SeedController::class, 'status']);
            Route::get('/seed/template', [\App\Http\Controllers\Api\V1\Business\SeedController::class, 'template']);
            Route::post('/seed', [\App\Http\Controllers\Api\V1\Business\SeedController::class, 'import']);
            Route::get('/claims', [\App\Http\Controllers\Api\V1\Business\SeedController::class, 'claims']);
            Route::put('/claims/{id}', [\App\Http\Controllers\Api\V1\Business\SeedController::class, 'decideClaim'])->whereNumber('id');
        });

    });
});
