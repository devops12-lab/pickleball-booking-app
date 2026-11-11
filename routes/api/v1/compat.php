<?php

use App\Http\Controllers\Api\V1\Compat\AuthController;
use App\Http\Controllers\Api\V1\Compat\BookingController;
use App\Http\Controllers\Api\V1\Compat\CalendarController;
use App\Http\Controllers\Api\V1\Compat\ClubController;
use App\Http\Controllers\Api\V1\Compat\CourtController;
use App\Http\Controllers\Api\V1\Compat\CourtExceptionController;
use App\Http\Controllers\Api\V1\Compat\CommunityController;
use App\Http\Controllers\Api\V1\Compat\DiscoveryController;
use App\Http\Controllers\Api\V1\Compat\GeoController;
use App\Http\Controllers\Api\V1\Compat\GuestBookingController;
use App\Http\Controllers\Api\V1\Compat\MatchController;
use App\Http\Controllers\Api\V1\Compat\MembershipController;
use App\Http\Controllers\Api\V1\Compat\NotificationController;
use App\Http\Controllers\Api\V1\Compat\OpenPlayController;
use App\Http\Controllers\Api\V1\Compat\OpenPlayQueueController;
use App\Http\Controllers\Api\V1\Compat\OrganizerMoneyController;
use App\Http\Controllers\Api\V1\Compat\OwnerSignupController;
use App\Http\Controllers\Api\V1\Compat\PlayerAuthController;
use App\Http\Controllers\Api\V1\Compat\PlayerBookingController;
use App\Http\Controllers\Api\V1\Compat\PlayerDirectoryController;
use App\Http\Controllers\Api\V1\Compat\PlayerHomeController;
use App\Http\Controllers\Api\V1\Compat\PlayerProfileController;
use App\Http\Controllers\Api\V1\Compat\PlayerClubController;
use App\Http\Controllers\Api\V1\Compat\PlayerSearchController;
use App\Http\Controllers\Api\V1\Compat\PlatformController;
use App\Http\Controllers\Api\V1\Compat\RankingController;
use App\Http\Controllers\Api\V1\Compat\ReportController;
use App\Http\Controllers\Api\V1\Compat\StaffController;
use App\Http\Controllers\Api\V1\Compat\SubscriptionController;
use App\Http\Controllers\Api\V1\Compat\TournamentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Frontend compatibility routes (/api/v1/*)
|--------------------------------------------------------------------------
|
| The SPA in packages/api calls resource-first URLs (/auth/login, /me,
| /clubs/{club}/courts). These routes serve exactly that contract on top of
| the same models and session as the role-scoped API, so the frontend can run
| against the real backend without any page changing.
|
| A "club" here is a venue tenant: its courts, staff, bookings and
| subscription are all tenant-scoped.
|
*/

Route::post('/auth/login', [AuthController::class, 'login'])->name('compat.auth.login');
Route::post('/auth/register', [PlayerAuthController::class, 'register'])->name('compat.auth.register');
Route::post('/auth/register/check-email', [PlayerAuthController::class, 'checkEmail'])->name('compat.auth.register.check_email');
Route::post('/auth/register/start', [PlayerAuthController::class, 'startRegistration'])->name('compat.auth.register.start');
Route::post('/auth/register/verify', [PlayerAuthController::class, 'verifyRegistration'])->name('compat.auth.register.verify');
Route::post('/auth/register/resend', [PlayerAuthController::class, 'resendRegistration'])->name('compat.auth.register.resend');
Route::post('/auth/register/complete', [PlayerAuthController::class, 'completeRegistration'])->name('compat.auth.register.complete');
Route::post('/owner-signup/code', [OwnerSignupController::class, 'code'])->name('compat.owner_signup.code');
Route::post('/owner-signup/verify', [OwnerSignupController::class, 'verify'])->name('compat.owner_signup.verify');
Route::post('/owner-signup', [OwnerSignupController::class, 'store'])->name('compat.owner_signup.store');
Route::post('/auth/forgot-password', [PlayerAuthController::class, 'forgotPassword'])->name('compat.auth.forgot_password');
Route::post('/auth/reset-password', [PlayerAuthController::class, 'resetPassword'])->name('compat.auth.reset_password');

// Open to visitors: browsing venues and checking what is free needs no account.
Route::get('/platform/settings', [PlatformController::class, 'settings'])->name('compat.platform.settings');
Route::get('/announcements', [PlatformController::class, 'publicAnnouncements'])->name('compat.announcements');
Route::get('/tournaments', [TournamentController::class, 'index'])->name('compat.tournaments.index');
// Open play is browsable by visitors; joining still runs through the signed-in action routes.
Route::get('/open-play', [OpenPlayController::class, 'index'])->name('compat.open_play.index');
Route::get('/open-play/{session}', [OpenPlayController::class, 'show'])->name('compat.open_play.show');
Route::get('/rankings', [RankingController::class, 'index'])->name('compat.rankings.index');
Route::get('/rankings/areas', [RankingController::class, 'areas'])->name('compat.rankings.areas');
Route::get('/geo/{level}', [GeoController::class, 'index'])->name('compat.geo.index');
Route::get('/discovery/amenities', [DiscoveryController::class, 'amenities'])->name('compat.discovery.amenities');
Route::get('/discovery/courts', [DiscoveryController::class, 'courts'])->name('compat.discovery.courts');
Route::get('/courts/{court}', [DiscoveryController::class, 'court'])->name('compat.courts.public');
Route::get('/courts/{court}/availability', [DiscoveryController::class, 'availability'])->name('compat.courts.public_availability');
Route::get('/venues/{slug}/booking-day', [PlayerBookingController::class, 'day'])->name('compat.venues.booking_day');
Route::get('/organizers/{type}/{id}/sessions', [OpenPlayController::class, 'organizerSessions'])->name('compat.organizers.sessions');
Route::get('/bookings/quote', [PlayerBookingController::class, 'quote'])->name('compat.bookings.quote');

// Guest booking: no account, a code sent to the email proves it.
Route::post('/guest/code', [GuestBookingController::class, 'code'])->name('compat.guest.code');
Route::post('/guest/bookings', [GuestBookingController::class, 'store'])->name('compat.guest.store');
Route::post('/guest/booking', [GuestBookingController::class, 'find'])->name('compat.guest.show');
Route::post('/guest/booking/receipt', [GuestBookingController::class, 'sendReceipt'])->name('compat.guest.receipt');
Route::post('/guest/booking/cancel', [GuestBookingController::class, 'cancelBooking'])->name('compat.guest.cancel');

Route::middleware(['auth:sanctum', 'compat.active'])->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('compat.auth.logout');
    Route::post('/auth/verify', [PlayerAuthController::class, 'verify'])->name('compat.auth.verify');
    Route::post('/auth/verify/resend', [PlayerAuthController::class, 'resend'])->name('compat.auth.verify_resend');
    Route::get('/me', [AuthController::class, 'me'])->name('compat.me');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('compat.notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('compat.notifications.read_all');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])->name('compat.notifications.read');
    Route::get('/notification-preferences', [NotificationController::class, 'preferences'])->name('compat.notification_preferences.show');
    Route::put('/notification-preferences', [NotificationController::class, 'updatePreferences'])->name('compat.notification_preferences.update');

    Route::get('/player/clubs', [PlayerClubController::class, 'index'])->name('compat.player.clubs');
    Route::get('/players', [PlayerSearchController::class, 'index'])->name('compat.players.search');
    Route::get('/players/{player}/stats', [MatchController::class, 'stats'])->name('compat.players.stats');

    Route::get('/player/matches', [MatchController::class, 'playerIndex'])->name('compat.matches.mine');
    Route::post('/matches', [MatchController::class, 'store'])->name('compat.matches.store');
    Route::post('/matches/{match}/start', [MatchController::class, 'start'])->name('compat.matches.start');
    Route::post('/matches/{match}/score-events', [MatchController::class, 'score'])->name('compat.matches.score');
    Route::post('/matches/{match}/undo', [MatchController::class, 'undo'])->name('compat.matches.undo');
    Route::post('/matches/{match}/submit-result', [MatchController::class, 'submit'])->name('compat.matches.submit');
    Route::post('/matches/{match}/confirm-result', [MatchController::class, 'confirm'])->name('compat.matches.confirm');
    Route::post('/matches/{match}/dispute', [MatchController::class, 'dispute'])->name('compat.matches.dispute');
    Route::post('/matches/{match}/verify', [MatchController::class, 'verify'])->name('compat.matches.verify');
    Route::post('/matches/{match}/scorer', [MatchController::class, 'changeScorer'])->name('compat.matches.scorer');
    Route::post('/matches/{match}/void', [MatchController::class, 'void'])->name('compat.matches.void');

    Route::get('/tournaments/hosting-options', [TournamentController::class, 'hostingOptions'])->name('compat.tournaments.hosting_options');
    Route::post('/tournaments', [TournamentController::class, 'store'])->name('compat.tournaments.store');
    Route::post('/tournaments/{tournament}/register', [TournamentController::class, 'register'])->name('compat.tournaments.register');
    Route::post('/tournaments/{tournament}/withdraw', [TournamentController::class, 'withdraw'])->name('compat.tournaments.withdraw');
    Route::post('/tournaments/{tournament}/start', [TournamentController::class, 'start'])->name('compat.tournaments.start');
    Route::post('/tournaments/{tournament}/cancel', [TournamentController::class, 'cancel'])->name('compat.tournaments.cancel');

    Route::get('/player/profile', [PlayerProfileController::class, 'show'])->name('compat.player.profile.show');
    Route::patch('/player/profile', [PlayerProfileController::class, 'update'])->name('compat.player.profile.update');
    Route::patch('/player/location', [PlayerProfileController::class, 'updateLocation'])->name('compat.player.location');
    Route::get('/player/home', [PlayerHomeController::class, 'show'])->name('compat.player.home');

    Route::get('/discovery/clubs', [CommunityController::class, 'index'])->name('compat.community.index');
    Route::get('/discovery/clubs/{type}/{slug}', [CommunityController::class, 'show'])->name('compat.community.show');
    Route::post('/communities', [CommunityController::class, 'store'])->name('compat.community.store');
    Route::patch('/communities/{club}', [CommunityController::class, 'update'])->name('compat.community.update');
    Route::get('/venues/{venue}/court-options', [CommunityController::class, 'courtOptions'])->name('compat.venues.court_options');

    Route::post('/organizers/{type}/{id}/join', [MembershipController::class, 'join'])->name('compat.membership.join');
    Route::post('/organizers/community/{id}/membership/receipt', [MembershipController::class, 'receipt'])->name('compat.membership.receipt');
    Route::post('/organizers/{type}/{id}/leave', [MembershipController::class, 'leave'])->name('compat.membership.leave');
    Route::get('/organizers/{type}/{id}/members', [MembershipController::class, 'members'])->name('compat.membership.members');
    Route::get('/organizers/{type}/{id}/members/{member}', [MembershipController::class, 'member'])->name('compat.membership.member');
    Route::post('/organizers/{type}/{id}/members/{member}/dues', [MembershipController::class, 'dues'])->name('compat.membership.dues');
    Route::post('/organizers/{type}/{id}/members/{member}/{action}', [MembershipController::class, 'action'])->name('compat.membership.action');
    Route::get('/organizers/{type}/{id}/plans', [MembershipController::class, 'plans'])->name('compat.membership.plans');
    Route::post('/organizers/{type}/{id}/plans', [MembershipController::class, 'storePlan'])->name('compat.membership.plans.store');
    Route::patch('/organizers/{type}/{id}/plans/{plan}', [MembershipController::class, 'updatePlan'])->name('compat.membership.plans.update');
    Route::get('/organizers/{type}/{id}/payments', [OrganizerMoneyController::class, 'payments'])->name('compat.organizers.payments');
    Route::get('/organizers/{type}/{id}/reports/revenue', [OrganizerMoneyController::class, 'revenue'])->name('compat.organizers.revenue');

    Route::get('/open-play/{session}/queue', [OpenPlayQueueController::class, 'show'])->name('compat.open_play.queue');
    Route::post('/open-play/{session}/queue/{command}', [OpenPlayQueueController::class, 'command'])->name('compat.open_play.queue.command');
    Route::post('/open-play', [OpenPlayController::class, 'store'])->name('compat.open_play.store');
    Route::patch('/open-play/{session}', [OpenPlayController::class, 'update'])->name('compat.open_play.update');
    Route::post('/open-play/{session}/participants', [OpenPlayController::class, 'addParticipant'])->name('compat.open_play.add_participant');
    Route::post('/open-play/{session}/participants/{participant}/payments', [OpenPlayController::class, 'participantPayment'])->name('compat.open_play.participant_payments');
    Route::post('/open-play/{session}/participants/{participant}/refund', [OpenPlayController::class, 'participantRefund'])->name('compat.open_play.participant_refund');
    Route::post('/open-play/{session}/participants/{participant}/{action}', [OpenPlayController::class, 'participantAction'])->name('compat.open_play.participant_action');
    Route::post('/open-play/{session}/court-fee', [OpenPlayController::class, 'courtFee'])->name('compat.open_play.court_fee');
    Route::post('/open-play/{session}/{action}', [OpenPlayController::class, 'act'])->name('compat.open_play.action');

    Route::get('/player/bookings', [PlayerBookingController::class, 'index'])->name('compat.player.bookings.index');
    Route::post('/bookings', [PlayerBookingController::class, 'store'])->name('compat.bookings.store');
    Route::get('/bookings/{booking}', [PlayerBookingController::class, 'show'])->name('compat.bookings.show');
    Route::post('/bookings/{booking}/receipt', [PlayerBookingController::class, 'receipt'])->name('compat.bookings.receipt');
    Route::post('/bookings/{booking}/cancel', [PlayerBookingController::class, 'cancel'])->name('compat.bookings.cancel');

    Route::prefix('clubs/{club}')->group(function (): void {
        Route::get('/', [ClubController::class, 'show'])->name('compat.clubs.show');
        Route::patch('/', [ClubController::class, 'update'])->name('compat.clubs.update');

        Route::get('/courts', [CourtController::class, 'index'])->name('compat.courts.index');
        Route::post('/courts', [CourtController::class, 'store'])->name('compat.courts.store');
        Route::get('/courts/{court}', [CourtController::class, 'show'])->name('compat.courts.show');
        Route::patch('/courts/{court}', [CourtController::class, 'update'])->name('compat.courts.update');
        Route::put('/courts/{court}/hours', [CourtController::class, 'updateHours'])->name('compat.courts.hours');
        Route::put('/courts/{court}/price-rules', [CourtController::class, 'updatePriceRules'])->name('compat.courts.price_rules');
        Route::get('/courts/{court}/availability', [CourtController::class, 'availability'])->name('compat.courts.availability');

        Route::get('/court-exceptions', [CourtExceptionController::class, 'index'])->name('compat.court_exceptions.index');
        Route::post('/court-exceptions', [CourtExceptionController::class, 'store'])->name('compat.court_exceptions.store');
        Route::delete('/court-exceptions/{exception}', [CourtExceptionController::class, 'destroy'])->name('compat.court_exceptions.destroy');

        Route::get('/staff', [StaffController::class, 'index'])->name('compat.staff.index');
        Route::post('/staff', [StaffController::class, 'store'])->name('compat.staff.store');
        Route::patch('/staff/{membership}', [StaffController::class, 'update'])->name('compat.staff.update');
        Route::delete('/staff/{membership}', [StaffController::class, 'destroy'])->name('compat.staff.destroy');

        Route::get('/players', [PlayerDirectoryController::class, 'index'])->name('compat.players.index');

        Route::get('/subscription', [SubscriptionController::class, 'show'])->name('compat.subscription.show');
        Route::get('/matches', [MatchController::class, 'clubIndex'])->name('compat.matches.club');
        Route::get('/tournaments', [TournamentController::class, 'clubIndex'])->name('compat.tournaments.club_index');

        Route::get('/calendar', [CalendarController::class, 'show'])->name('compat.calendar.show');
        Route::get('/reports/daily', [ReportController::class, 'show'])->name('compat.reports.daily');

        Route::get('/receipts', [BookingController::class, 'receipts'])->name('compat.bookings.receipts');
        Route::post('/bookings/walk-in', [BookingController::class, 'walkIn'])->name('compat.bookings.walk_in');
        Route::get('/bookings/{booking}', [BookingController::class, 'show'])->name('compat.bookings.show');
        Route::post('/bookings/{booking}/payments', [BookingController::class, 'recordPayment'])->name('compat.bookings.payments');
        Route::post('/bookings/{booking}/payments/{payment}/adjustments', [BookingController::class, 'adjustPayment'])->name('compat.bookings.payment_adjustments');
        Route::post('/bookings/{booking}/receipt/{action}', [BookingController::class, 'reviewReceipt'])
            ->whereIn('action', ['approve', 'reject'])
            ->name('compat.bookings.receipt');
        Route::post('/bookings/{booking}/pay-at-court', [BookingController::class, 'payAtCourt'])->name('compat.bookings.pay_at_court');
        Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel'])->name('compat.bookings.cancel');
    });
});

// Public: anyone can open a tournament's page and the hosting options keep the
// dynamic detail route from shadowing the authenticated hosting-options route.
Route::get('/tournaments/{tournament}', [TournamentController::class, 'show'])->name('compat.tournaments.show');

// Public: anyone with the link can watch a match and its point history.
Route::get('/matches/{match}/events', [MatchController::class, 'events'])->name('compat.matches.events');
Route::get('/matches/{match}', [MatchController::class, 'show'])->name('compat.matches.show');
