<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Actions\Payments\RecordGatewayPayment;
use App\Http\Controllers\Controller;
use App\Models\Pas\GatewayWebhookEvent;
use App\Models\Pas\Invoice;
use App\Models\Pas\PaymentGatewaySetting;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Multitenancy\Models\Tenant;
use Throwable;

/**
 * Where PayMongo and Stripe tell us a customer paid.
 *
 * The first unauthenticated POST in this application, so the reasoning is
 * spelled out rather than assumed:
 *
 * **The URL carries the school.** `SchoolTenantFinder` resolves a tenant from
 * the host, a `/schools/{slug}/` path prefix, or a header — and a gateway
 * sends none of those unless we build them in. Registering the webhook per
 * school as `/schools/{slug}/webhooks/{provider}` reuses the existing path
 * strategy instead of inventing a fourth resolution mode, and means the
 * tenant is already current by the time this controller runs.
 *
 * **The signature is the only authentication.** Anyone can POST here. What
 * separates a real delivery from an attacker marking their own invoice paid
 * is an HMAC over the raw body with a secret only the gateway and this school
 * share. It is checked before the payload is looked at, and a failure is
 * recorded rather than silently dropped.
 *
 * **Everything returns JSON.** The exception handler renders Inertia HTML for
 * non-JSON requests, and a gateway retrying against an HTML error page learns
 * nothing. A 2xx means "we have this"; anything else asks for a retry.
 *
 * **A failure after acceptance is still a 200.** Once the signature is valid
 * and the event is recorded, retrying will not help — the row is already
 * there and the unique constraint would make the retry a no-op anyway. The
 * failure is stored on the event for a human to look at.
 */
final class GatewayWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $slug,
        string $provider,
        PaymentGatewayManager $gateways,
        RecordGatewayPayment $recorder,
    ): JsonResponse {
        if (! in_array($provider, PaymentGatewaySetting::PROVIDERS, true)) {
            return response()->json(['message' => 'Unknown provider.'], 404);
        }

        $school = Tenant::current();
        $payload = $request->getContent();

        $setting = $gateways->settingsFor($provider);

        if ($setting === null) {
            // Recorded, not dropped: a delivery to a school that has not
            // finished setting the gateway up is worth being able to see.
            $this->record($provider, $request, [
                'school_id' => $school?->getKey(),
                'status' => GatewayWebhookEvent::STATUS_FAILED,
                'message' => 'No active, fully configured settings for this provider.',
            ]);

            return response()->json(['message' => 'Gateway not configured.'], 404);
        }

        $driver = $gateways->driver($provider);
        $signature = $this->signatureHeader($request, $provider);

        if (! $driver->verifySignature($setting, $payload, $signature)) {
            $this->record($provider, $request, [
                'school_id' => $school?->getKey(),
                'status' => GatewayWebhookEvent::STATUS_FAILED,
                'message' => 'Signature verification failed.',
            ]);

            Log::warning('Rejected a gateway webhook with an invalid signature.', [
                'provider' => $provider,
                'school' => $slug,
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($payload, true) ?: [];
        $event = $driver->parseEvent($decoded);

        if ($event === null) {
            return response()->json(['message' => 'Unrecognised payload.'], 202);
        }

        // Idempotency. Both gateways retry until they get a 2xx, and both can
        // redeliver after one. The unique on (provider, external_event_id) is
        // what stops a retry booking a second payment.
        $existing = GatewayWebhookEvent::query()
            ->where('provider', $provider)
            ->where('external_event_id', $event->eventId)
            ->first();

        if ($existing !== null) {
            return response()->json([
                'message' => 'Already handled.',
                'status' => $existing->status,
            ]);
        }

        $record = $this->record($provider, $request, [
            'school_id' => $school?->getKey(),
            'external_event_id' => $event->eventId,
            'event_type' => $event->type,
            'invoice_id' => $event->invoiceId,
            'status' => GatewayWebhookEvent::STATUS_PENDING,
        ]);

        if (! $event->isPaid) {
            $record->forceFill([
                'status' => GatewayWebhookEvent::STATUS_IGNORED,
                'message' => 'Not a completed payment.',
                'processed_at' => now(),
            ])->save();

            return response()->json(['message' => 'Ignored.']);
        }

        // Explicit school scoping, never the global scope: BelongsToTenant
        // fails OPEN when no tenant is current, and a webhook is the one
        // request shape where that could happen.
        $invoice = $school === null || $event->invoiceId === null
            ? null
            : Invoice::query()
                ->where('school_id', $school->getKey())
                ->whereKey($event->invoiceId)
                ->first();

        if ($invoice === null) {
            $record->forceFill([
                'status' => GatewayWebhookEvent::STATUS_FAILED,
                'message' => 'No invoice for this event in this school.',
                'processed_at' => now(),
            ])->save();

            return response()->json(['message' => 'Invoice not found.'], 202);
        }

        try {
            $payment = $recorder->execute($setting, $event, $invoice);

            $record->forceFill([
                'status' => GatewayWebhookEvent::STATUS_HANDLED,
                'payment_id' => $payment->getKey(),
                'processed_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            $record->forceFill([
                'status' => GatewayWebhookEvent::STATUS_FAILED,
                'message' => mb_substr($e->getMessage(), 0, 1000),
                'processed_at' => now(),
            ])->save();

            Log::error('Failed to record a gateway payment.', [
                'provider' => $provider,
                'event' => $event->eventId,
                'error' => $e->getMessage(),
            ]);

            // Deliberately 200: the delivery was genuine and is recorded, so
            // a retry would hit the idempotency guard and change nothing.
            return response()->json(['message' => 'Recorded, not applied.']);
        }

        return response()->json(['message' => 'Handled.']);
    }

    /**
     * Each gateway names its signature header differently.
     */
    private function signatureHeader(Request $request, string $provider): string
    {
        return match ($provider) {
            PaymentGatewaySetting::PROVIDER_STRIPE => (string) $request->header('Stripe-Signature', ''),
            default => (string) $request->header('Paymongo-Signature', ''),
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function record(string $provider, Request $request, array $attributes): GatewayWebhookEvent
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($request->getContent(), true) ?: [];

        return DB::transaction(fn (): GatewayWebhookEvent => GatewayWebhookEvent::create([
            'provider' => $provider,
            // A rejected delivery has no trustworthy id of its own, so one is
            // synthesised — the row still has to be unique.
            'external_event_id' => $attributes['external_event_id']
                ?? sprintf('unverified-%s', (string) Str::uuid()),
            'payload' => $decoded,
            ...$attributes,
        ]));
    }
}
