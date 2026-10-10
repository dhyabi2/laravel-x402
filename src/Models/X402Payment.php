<?php

declare(strict_types=1);

namespace HydraLabs\X402\Models;

use DateTimeInterface;
use HydraLabs\X402\Core\Money\UsdConverter;
use HydraLabs\X402\Enums\Mode;
use HydraLabs\X402\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A recorded x402 payment attempt.
 *
 * @property int $id
 * @property string|null $route
 * @property string $method
 * @property string $resource
 * @property string|null $amount_atomic
 * @property string|null $amount_usd
 * @property string|null $asset
 * @property int|null $asset_decimals
 * @property string|null $network
 * @property string|null $payer
 * @property string|null $pay_to
 * @property string|null $transaction_hash
 * @property PaymentStatus $status
 * @property string|null $failure_reason
 * @property string|null $facilitator
 * @property Mode $mode
 * @property string|null $request_id
 * @property array<string, mixed>|null $meta
 *
 * @method static Builder<X402Payment> query()
 */
class X402Payment extends Model
{
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'status' => PaymentStatus::class,
        'mode' => Mode::class,
        'meta' => 'array',
        'asset_decimals' => 'integer',
    ];

    public function getTable(): string
    {
        return $this->table ?? (string) config('x402.database.table', 'x402_payments');
    }

    public function getConnectionName(): ?string
    {
        $connection = config('x402.database.connection');

        return is_string($connection) && $connection !== '' ? $connection : parent::getConnectionName();
    }

    /**
     * @param Builder<X402Payment> $query
     * @return Builder<X402Payment>
     */
    public function scopeSettled(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::Settled);
    }

    /**
     * @param Builder<X402Payment> $query
     * @return Builder<X402Payment>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::Failed);
    }

    /**
     * @param Builder<X402Payment> $query
     * @return Builder<X402Payment>
     */
    public function scopeObserved(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::Observed);
    }

    /**
     * Payments for a route, matched on its name or its "METHOD /uri" label.
     *
     * @param Builder<X402Payment> $query
     * @return Builder<X402Payment>
     */
    public function scopeForRoute(Builder $query, string $route): Builder
    {
        return $query->where('route', $route);
    }

    /**
     * @param Builder<X402Payment> $query
     * @return Builder<X402Payment>
     */
    public function scopeForPayer(Builder $query, string $payer): Builder
    {
        return $query->whereRaw('lower(payer) = ?', [strtolower($payer)]);
    }

    /**
     * @param Builder<X402Payment> $query
     * @return Builder<X402Payment>
     */
    public function scopeBetween(Builder $query, DateTimeInterface|string $from, DateTimeInterface|string $to): Builder
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }

    /**
     * The amount as a human USD string, from the atomic amount actually charged.
     */
    public function formattedAmount(): string
    {
        if ($this->amount_atomic === null) {
            return $this->amount_usd === null ? '-' : '$'.$this->amount_usd;
        }

        return UsdConverter::format($this->amount_atomic, $this->asset_decimals ?? 6);
    }
}
