<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Accounting\PaymentGatewaySettingRequest;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\PaymentGatewaySetting;
use App\Models\Pas\School;
use App\Services\Payments\GatewayAccountResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Spatie\Multitenancy\Models\Tenant;

/**
 * Where a school enters its own gateway credentials.
 *
 * The rule this controller exists to enforce: **a secret never travels to the
 * browser.** Inertia props are serialised into the page, so sending the
 * stored key so the form could pre-fill it would publish it to anyone who
 * views source. The page gets four masked characters and a boolean, and a
 * blank submission leaves the stored value untouched.
 *
 * Rows are addressed by `(provider, mode)` rather than created and deleted,
 * because those four combinations are the entire space — a school has a
 * PayMongo test row whether or not it has filled it in.
 */
final class PaymentGatewaySettingController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', PaymentGatewaySetting::class);

        return Inertia::render('admin/accounting/payment-gateways/index', [
            'settings' => $this->present(),
            'cashAccountOptions' => ChartOfAccount::query()
                ->active()
                ->cashEquivalent()
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'expenseAccountOptions' => ChartOfAccount::query()
                ->active()
                ->ofType(ChartOfAccount::TYPE_EXPENSE)
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'webhookBaseUrl' => url('/schools/'.$this->currentSlug().'/webhooks'),
            // Named so the screen can say what it will do rather than asking.
            // Null when a default is missing, which the page reports as a
            // setup problem instead of silently rendering a blank.
            'defaults' => $this->resolvedDefaults(),
        ]);
    }

    public function store(PaymentGatewaySettingRequest $request): RedirectResponse
    {
        Gate::authorize('create', PaymentGatewaySetting::class);

        $data = $request->validated();

        $setting = PaymentGatewaySetting::query()->firstOrNew([
            'provider' => $data['provider'],
            'mode' => $data['mode'],
        ]);

        if ($setting->exists) {
            Gate::authorize('update', $setting);
        }

        $setting->fill([
            'publishable_key' => $data['publishable_key'] ?? null,
            'cash_account_id' => $data['cash_account_id'] ?? null,
            'fee_account_id' => $data['fee_account_id'] ?? null,
            'is_active' => (bool) $data['is_active'],
        ]);

        // Blank means "leave it alone". The form cannot echo back what it was
        // never sent, so treating an empty field as a clear would wipe a
        // working key every time someone edited the account pickers.
        if (filled($data['secret_key'] ?? null)) {
            $setting->secret_key = $data['secret_key'];
        }

        if (filled($data['webhook_secret'] ?? null)) {
            $setting->webhook_secret = $data['webhook_secret'];
        }

        // Only one row per provider may be live at a time — a school takes
        // money in test mode or in live mode, never both at once.
        if ($setting->is_active) {
            PaymentGatewaySetting::query()
                ->forProvider((string) $data['provider'])
                ->where('mode', '!=', $data['mode'])
                ->update(['is_active' => false]);
        }

        $setting->save();

        return back()->with('success', sprintf(
            '%s %s settings saved.',
            ucfirst((string) $data['provider']),
            $data['mode'],
        ));
    }

    /**
     * The accounts a gateway will use when nobody overrides them.
     *
     * @return array{cash: ?array<string, mixed>, fee: ?array<string, mixed>}
     */
    private function resolvedDefaults(): array
    {
        // A bare, unconfigured row: what the resolver falls back to when the
        // settings say nothing.
        $blank = new PaymentGatewaySetting;
        $resolver = app(GatewayAccountResolver::class);

        return [
            'cash' => $this->describeAccount(fn () => $resolver->resolveCash($blank)),
            'fee' => $this->describeAccount(fn () => $resolver->resolveFee($blank)),
        ];
    }

    /**
     * @param  callable(): ChartOfAccount  $resolve
     * @return array<string, mixed>|null
     */
    private function describeAccount(callable $resolve): ?array
    {
        try {
            $account = $resolve();
        } catch (RuntimeException) {
            return null;
        }

        return ['id' => $account->getKey(), 'code' => $account->code, 'name' => $account->name];
    }

    /**
     * `Tenant::current()` is typed as Spatie's base model; `slug` is ours.
     */
    private function currentSlug(): string
    {
        $tenant = Tenant::current();

        return $tenant instanceof School ? $tenant->slug : '';
    }

    /**
     * Every provider/mode combination, filled in or not.
     *
     * @return list<array<string, mixed>>
     */
    private function present(): array
    {
        $stored = PaymentGatewaySetting::query()->get()
            ->keyBy(fn (PaymentGatewaySetting $s): string => $s->provider.':'.$s->mode);

        $rows = [];

        foreach (PaymentGatewaySetting::PROVIDERS as $provider) {
            foreach (PaymentGatewaySetting::MODES as $mode) {
                $setting = $stored->get($provider.':'.$mode);

                $rows[] = [
                    'provider' => $provider,
                    'mode' => $mode,
                    'publishable_key' => $setting?->publishable_key,
                    // Never the value itself.
                    'secret_masked' => $setting?->maskedSecret(),
                    'has_secret' => filled($setting?->secret_key),
                    'has_webhook_secret' => filled($setting?->webhook_secret),
                    'cash_account_id' => $setting?->cash_account_id,
                    'fee_account_id' => $setting?->fee_account_id,
                    'is_active' => (bool) $setting?->is_active,
                    'is_usable' => $setting?->isUsable() ?? false,
                ];
            }
        }

        return $rows;
    }
}
