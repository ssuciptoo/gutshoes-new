<?php

namespace App\Domain\Shipping;

use Illuminate\Support\Facades\Cache;

class ShippingRateResolver
{
    public function __construct(private readonly ShippingProvider $provider) {}

    /**
     * Quotes are cached briefly because one checkout asks for the same rate several
     * times (quote page, courier changes, order creation) and provider APIs such as
     * RajaOngkir enforce a daily request quota and can be slow.
     *
     * @param  array<string,mixed>  $origin
     * @param  array<string,mixed>  $destination
     * @return list<ShippingQuote>
     */
    public function quotes(array $origin, array $destination, int $weightGrams, int $quantity, ?string $courier = null): array
    {
        $minutes = (int) config('gutshoes.shipping_quote_cache_minutes', 15);
        if ($minutes <= 0) {
            return $this->provider->quote($origin, $destination, $weightGrams, $quantity, $courier);
        }

        $key = 'shipping-quote:'.sha1(json_encode([
            config('gutshoes.shipping_driver'),
            (string) ($origin['provider_area_id'] ?? ''),
            (string) ($destination['provider_area_id'] ?? ''),
            $weightGrams,
            $quantity,
            strtolower((string) $courier),
        ], JSON_THROW_ON_ERROR));

        $cached = Cache::get($key);
        if (is_array($cached)) {
            return array_map(fn (array $quote): ShippingQuote => new ShippingQuote(
                $quote['provider'], $quote['courier'], $quote['service'], $quote['description'], $quote['fee'], $quote['estimated_days'],
            ), $cached);
        }

        $quotes = $this->provider->quote($origin, $destination, $weightGrams, $quantity, $courier);
        // An empty answer may be a transient provider hiccup; ask again next time.
        if ($quotes !== []) {
            Cache::put($key, array_map(fn (ShippingQuote $quote): array => $quote->toArray(), $quotes), now()->addMinutes($minutes));
        }

        return $quotes;
    }
}
