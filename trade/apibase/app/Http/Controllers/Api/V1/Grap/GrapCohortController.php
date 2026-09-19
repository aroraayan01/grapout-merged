<?php

namespace App\Http\Controllers\Api\V1\Grap;

use App\Http\Controllers\Controller;
use App\Mail\OutreachMail;
use App\Models\Grap\Cohort;
use App\Models\Grap\MeetingEmail;
use App\Models\Grap\Reveal;
use App\Models\User;
use App\Services\SubscriptionEntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * Cohorts, and the Book meeting email they are sent as.
 *
 * The email goes from GrapOut's own address with Reply-To set to the sender,
 * so an answer reaches them without anybody having to set up a mailbox. That
 * makes GrapOut's sending reputation everybody's, which is why it is only
 * ever written to an address Hot Leads actually found for them — never to
 * whatever somebody types.
 */
class GrapCohortController extends Controller
{
    public function __construct(private SubscriptionEntitlementService $plans) {}

    public function index(Request $request): JsonResponse
    {
        $cohorts = Cohort::where('user_id', $request->user()->id)->orderBy('name')->get();

        return response()->json([
            'data' => $cohorts->map(fn (Cohort $c) => $c->toApi())->values(),
            'placeholders' => Cohort::PLACEHOLDERS,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $me = $request->user();
        abort_if(
            Cohort::where('user_id', $me->id)->count() >= Cohort::MAX_PER_USER,
            422,
            'That is as many cohorts as one person can keep. Delete one you no longer use.',
        );

        $cohort = Cohort::create(['user_id' => $me->id] + $this->validated($request, $me));

        return response()->json(['message' => 'Cohort saved.', 'data' => $cohort->toApi()], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();
        $cohort = Cohort::where('user_id', $me->id)->where('uuid', $uuid)->firstOrFail();
        $cohort->update($this->validated($request, $me, $cohort));

        return response()->json(['message' => 'Cohort saved.', 'data' => $cohort->fresh()->toApi()]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        Cohort::where('user_id', $request->user()->id)->where('uuid', $uuid)->firstOrFail()->delete();

        return response()->json(['message' => 'Cohort deleted.']);
    }

    /**
     * Send one Book meeting email.
     *
     * The subject and body arrive already filled in: the person saw them in
     * the preview and may have changed them, and what they approved is what
     * goes. Nothing is re-rendered here.
     */
    public function send(Request $request): JsonResponse
    {
        $me = $request->user();

        $data = $request->validate([
            'to_email' => ['required', 'email', 'max:191'],
            'contact_name' => ['nullable', 'string', 'max:191'],
            'company_name' => ['nullable', 'string', 'max:191'],
            'subject' => ['required', 'string', 'max:191'],
            'body' => ['required', 'string', 'max:10000'],
            'cohort' => ['nullable', 'string', 'max:64'],
        ]);

        abort_if(! $this->plans->hasFeature($me, 'grap_leads'), 403, 'Hot Leads is not on your plan.');

        $to = mb_strtolower(trim($data['to_email']));
        abort_if(
            ! $this->foundFor($me, $to),
            403,
            'You can only book a meeting with a contact Hot Leads found for you.',
        );

        $cohort = $data['cohort'] ?? null
            ? Cohort::where('user_id', $me->id)->where('uuid', $data['cohort'])->first()
            : null;

        $record = MeetingEmail::create([
            'user_id' => $me->id,
            'cohort_id' => $cohort?->id,
            'to_email' => $to,
            'contact_name' => $data['contact_name'] ?? null,
            'company_name' => $data['company_name'] ?? null,
            'subject' => $data['subject'],
            'body' => $data['body'],
            'status' => MeetingEmail::STATUS_FAILED,
        ]);

        // Plain words in, plain words out: escaped, with the line breaks kept.
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.55;color:#1e293b">'
            . nl2br(e($data['body']))
            . '</div>';

        try {
            Mail::to($to, $data['contact_name'] ?? null)->send(new OutreachMail(
                subjectLine: $data['subject'],
                body: $html,
                fromAddress: (string) config('mail.from.address'),
                fromName: trim($me->name . ' via GrapOut'),
                replyToAddress: $me->email,
            ));
        } catch (\Throwable $e) {
            $record->update(['error' => mb_substr($e->getMessage(), 0, 1000)]);
            Log::warning('grap: meeting email failed', ['to' => $to, 'error' => $e->getMessage()]);

            abort(502, 'The email could not be sent just now. Try again in a moment.');
        }

        $record->update(['status' => MeetingEmail::STATUS_SENT, 'sent_at' => now()]);

        return response()->json(['message' => "Email sent to {$to}."]);
    }

    /**
     * Did Hot Leads find this address for this person?
     *
     * Three ways it can have: a Grap Buyer or Supplier lead they unlocked, a
     * Grap Company contact charged to them, or a Grap Company search that
     * found it — a cached answer charges nobody, so it leaves no receipt, and
     * the search cache is the only record that the address came from us.
     */
    private function foundFor(User $me, string $email): bool
    {
        $unlocked = Reveal::where('user_id', $me->id)
            ->where(function ($q) use ($email) {
                $q->whereRaw('LOWER(email) = ?', [$email])
                    ->orWhereHas('lead', fn ($lead) => $lead
                        ->whereRaw('LOWER(email) = ?', [$email])
                        ->orWhereRaw('LOWER(email_2) = ?', [$email]));
            })
            ->exists();

        if ($unlocked) {
            return true;
        }

        // The payload is JSON, where an address appears quoted and verbatim.
        return DB::table('grap_search_cache')
            ->where('payload', 'like', '%"' . addcslashes($email, '%_\\') . '"%')
            ->exists();
    }

    /** @return array{name: string, subject: string, body: string} */
    private function validated(Request $request, User $me, ?Cohort $cohort = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:80',
                Rule::unique('grap_cohorts', 'name')->where('user_id', $me->id)->ignore($cohort?->id),
            ],
            'subject' => ['required', 'string', 'max:191'],
            'body' => ['required', 'string', 'max:10000'],
        ], [
            'name.unique' => 'You already have a cohort with that name.',
        ]);
    }
}
