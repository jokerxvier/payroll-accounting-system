<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Pas\Invoice;
use App\Models\Pas\PaymentGatewaySetting;
use App\Models\Pas\School;
use App\Services\Accounting\InvoiceBalanceService;
use App\Services\Payments\Data\CheckoutUrls;
use App\Services\Payments\PaymentGatewayManager;
use App\ValueObjects\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Spatie\Multitenancy\Models\Tenant;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

/**
 * The one page in this application a customer can reach without a login.
 *
 * Everything here assumes the visitor is anonymous and possibly hostile:
 *
 * **The token is the credential, not the slug.** A guest bypasses
 * `ApplyTenantOverride`'s LMS-pinning, so `/schools/{slug}/` resolves whatever
 * it names. The lookup therefore matches on the resolved school AND the
 * invoice's own 40-character token, so a token minted by School A presented
 * under School B's slug finds nothing.
 *
 * **The global tenant scope is not trusted.** `BelongsToTenant` fails open
 * when no tenant is current — it skips the scope entirely and returns every
 * school's rows. Every query here names `school_id` explicitly.
 *
 * **A missing invoice is a 404, always.** Never "wrong token" versus "no such
 * invoice": the difference tells someone probing whether they guessed a real
 * document.
 *
 * **The return URL proves nothing.** A customer can type it. The invoice is
 * settled by the webhook, and this page only ever reports what the books
 * already say.
 */
final class InvoicePaymentController extends Controller
{
    public function __construct(
        private readonly InvoiceBalanceService $balances,
        private readonly PaymentGatewayManager $gateways,
    ) {}

    public function show(string $slug, string $token): Response
    {
        $invoice = $this->resolve($token);

        return Inertia::render('public/invoice-payment', [
            'invoice' => $this->present($invoice),
            'school' => $this->school(),
            'methods' => $this->availableMethods(),
            'paid' => $this->balances->remainingCentavosFor($invoice) <= 0,
        ]);
    }

    public function checkout(Request $request, string $slug, string $token): SymfonyRedirect
    {
        $invoice = $this->resolve($token);

        // A public POST that costs an outbound API call each time. Throttled
        // by token so one document cannot be used to hammer the gateway.
        $limiterKey = 'pay-checkout:'.$token;

        if (RateLimiter::tooManyAttempts($limiterKey, 10)) {
            abort(429, 'Too many attempts. Wait a minute and try again.');
        }

        RateLimiter::hit($limiterKey, 60);

        $provider = (string) $request->input('provider', PaymentGatewaySetting::PROVIDER_PAYMONGO);

        if (! in_array($provider, PaymentGatewaySetting::PROVIDERS, true)) {
            abort(404);
        }

        $outstanding = $this->balances->remainingCentavosFor($invoice);

        if ($outstanding <= 0) {
            return redirect()->route('public.pay.show', ['slug' => $slug, 'token' => $token]);
        }

        try {
            $setting = $this->gateways->requireSettingsFor($provider);

            $session = $this->gateways->driver($provider)->createCheckout(
                $setting,
                $invoice,
                Money::fromCentavos($outstanding),
                new CheckoutUrls(
                    success: route('public.pay.return', ['slug' => $slug, 'token' => $token]),
                    cancel: route('public.pay.show', ['slug' => $slug, 'token' => $token]),
                ),
            );
        } catch (RuntimeException $e) {
            report($e);

            return back()->with('error', 'That payment method is unavailable right now. Please try another, or contact the school.');
        }

        // Away to the gateway. Nothing is recorded here — the customer has
        // not paid yet, and a checkout opened is not money received.
        return redirect()->away($session->redirectUrl);
    }

    /**
     * Where the gateway sends the payer back to.
     *
     * Reports the ledger's view, not the gateway's. A webhook may not have
     * landed yet, in which case the honest answer is "we are waiting", not a
     * receipt for money we have no record of.
     */
    public function return(string $slug, string $token): Response
    {
        $invoice = $this->resolve($token);
        $settled = $this->balances->remainingCentavosFor($invoice) <= 0;

        return Inertia::render('public/invoice-payment', [
            'invoice' => $this->present($invoice),
            'school' => $this->school(),
            'methods' => $this->availableMethods(),
            'paid' => $settled,
            'justReturned' => true,
        ]);
    }

    /**
     * Explicit school scoping, plus the token. Never the global scope.
     */
    private function resolve(string $token): Invoice
    {
        $school = Tenant::current();

        abort_if($school === null, 404);

        $invoice = Invoice::query()
            ->withoutGlobalScopes()
            ->where('school_id', $school->getKey())
            ->where('pay_token', $token)
            ->where('type', Invoice::TYPE_SALES)
            ->with(['lines.account:id,code,name', 'contact:id,name'])
            ->first();

        // One 404 for every reason. Distinguishing "no such token" from "that
        // invoice is voided" would confirm a guess.
        abort_if($invoice === null || ! $invoice->isIssued(), 404);

        return $invoice;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Invoice $invoice): array
    {
        return [
            'number' => $invoice->number,
            'issue_date' => $invoice->issue_date->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'contact_name' => $invoice->contact?->name,
            'total_centavos' => $invoice->total_centavos,
            'amount_paid_centavos' => $invoice->amount_paid_centavos,
            'balance_due_centavos' => $this->balances->remainingCentavosFor($invoice),
            'terms' => $invoice->terms,
            // Net plus tax, so the figures a customer adds up reach the
            // total they are being asked to pay.
            'lines' => $invoice->lines->map(fn ($line): array => [
                'description' => $line->description,
                'quantity' => $line->quantity,
                'amount_centavos' => (int) $line->line_net_centavos + (int) $line->line_tax_centavos,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function school(): array
    {
        $school = $this->currentSchool();

        return [
            // The registered name is what belongs on a document a customer
            // sees; the short trading name is for our own screens.
            'name' => $school === null
                ? null
                : ($school->registered_name ?? $school->name),
            'tin' => $school?->tin,
            'address' => $school?->business_address,
        ];
    }

    /**
     * `Tenant::current()` is typed as Spatie's base model; the school-specific
     * columns live on ours.
     */
    private function currentSchool(): ?School
    {
        $tenant = Tenant::current();

        return $tenant instanceof School ? $tenant : null;
    }

    /**
     * Only providers this school can actually take money through.
     *
     * @return list<string>
     */
    private function availableMethods(): array
    {
        return array_map(
            fn (PaymentGatewaySetting $s): string => $s->provider,
            $this->gateways->usable(),
        );
    }
}
