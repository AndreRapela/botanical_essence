<?php

declare(strict_types=1);

/** Local shipping-price estimate based on a public bpost reference; it is not a live carrier quote. */
final class ShippingTariffCalculator
{
    private const PACKAGING_WEIGHT_GRAMS = 250;
    private const MAX_PARCEL_WEIGHT_GRAMS = 30_000;
    private const TARIFF_YEAR = 2026;
    private const TARIFF_REVIEWED_AT = '2026-10-04';
    private const TARIFF_VALID_THROUGH = '2026-12-31';
    private const TARIFF_SOURCE = 'bpost-public-business-online-2026-reviewed-2026-10-04-v1';
    private const TARIFF_REFERENCE = 'https://parcel.bpost.be/en/pricing/business';

    public function __construct(private readonly ?DateTimeImmutable $today = null)
    {
    }

    private const EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU',
        'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
    ];

    private const NEIGHBOUR_COUNTRIES = ['DE', 'FR', 'LU', 'NL'];

    public function quote(string $country, string $postalCode, int $netWeightGrams): array
    {
        $today = ($this->today ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
        if ($today > self::TARIFF_VALID_THROUGH) {
            throw new HttpException(
                'A referência pública de frete expirou e precisa ser revisada antes de novas compras.',
                503,
                ['reviewDueAt' => self::TARIFF_VALID_THROUGH],
                'shipping_tariff_review_required'
            );
        }

        $countryCode = $this->countryCode($country);
        $normalizedPostalCode = $this->postalCode($countryCode, $postalCode);
        $weightGrams = $netWeightGrams + self::PACKAGING_WEIGHT_GRAMS;
        if ($netWeightGrams < 50 || $weightGrams > self::MAX_PARCEL_WEIGHT_GRAMS) {
            throw new HttpException('O pacote excede o limite de 30 kg aceito por esta modalidade da bpost.', 422, [
                'items' => 'Reduza a quantidade ou divida a compra em mais de um pedido.',
            ]);
        }

        $zone = $countryCode === 'BE'
            ? 'belgium'
            : (in_array($countryCode, self::NEIGHBOUR_COUNTRIES, true) ? 'neighbour' : 'eu');

        return [
            'carrier' => 'bpost',
            'quoteType' => 'estimate',
            'referenceYear' => self::TARIFF_YEAR,
            'source' => self::TARIFF_SOURCE,
            'tariffVersion' => self::TARIFF_SOURCE,
            'tariffReviewedAt' => self::TARIFF_REVIEWED_AT,
            'tariffValidThrough' => self::TARIFF_VALID_THROUGH,
            'tariffBasis' => 'Published online business rates exclude VAT and shipping credits; merchant contract and actual booking may differ.',
            'tariffReference' => self::TARIFF_REFERENCE,
            'liveCarrierQuote' => false,
            'contractualRate' => false,
            'labelCreationAvailable' => false,
            'trackingAvailable' => false,
            'currency' => 'EUR',
            'destination' => [
                'countryCode' => $countryCode,
                'postalCode' => $normalizedPostalCode,
                'zone' => $zone,
            ],
            'parcel' => [
                'weightGrams' => $weightGrams,
                'weightKilograms' => round($weightGrams / 1000, 2),
                'packagingWeightGrams' => self::PACKAGING_WEIGHT_GRAMS,
            ],
            'quotes' => $this->rates($zone, $weightGrams),
        ];
    }

    public function selectedQuote(array $quoteResponse, string $method): array
    {
        foreach ($quoteResponse['quotes'] ?? [] as $quote) {
            if (is_array($quote) && ($quote['id'] ?? null) === $method) {
                return $quote;
            }
        }
        throw new HttpException('Selecione uma modalidade de entrega válida para este endereço.', 422, [
            'delivery' => 'Calcule o frete novamente e escolha uma das opções disponíveis.',
        ]);
    }

    private function countryCode(string $country): string
    {
        $value = mb_strtoupper(trim($country), 'UTF-8');
        $aliases = [
            'BELGIUM' => 'BE', 'BELGIQUE' => 'BE', 'BELGIE' => 'BE', 'BELGIË' => 'BE',
            'NETHERLANDS' => 'NL', 'PAYS-BAS' => 'NL', 'NEDERLAND' => 'NL',
            'FRANCE' => 'FR', 'GERMANY' => 'DE', 'ALLEMAGNE' => 'DE', 'DEUTSCHLAND' => 'DE',
            'LUXEMBOURG' => 'LU',
        ];
        $countryCode = $aliases[$value] ?? $value;
        if (!in_array($countryCode, self::EU_COUNTRIES, true)) {
            throw new HttpException('No momento, a estimativa local de frete está disponível para destinos da União Europeia.', 422, [
                'country' => 'Escolha um país da União Europeia.',
            ]);
        }
        return $countryCode;
    }

    private function postalCode(string $countryCode, string $postalCode): string
    {
        $value = mb_strtoupper(trim((string) preg_replace('/\s+/', ' ', $postalCode)), 'UTF-8');
        $patterns = [
            'BE' => '/^\d{4}$/D',
            'NL' => '/^\d{4}\s?[A-Z]{2}$/D',
            'FR' => '/^\d{5}$/D',
            'DE' => '/^\d{5}$/D',
            'LU' => '/^\d{4}$/D',
        ];
        $pattern = $patterns[$countryCode] ?? '/^[A-Z0-9][A-Z0-9 -]{2,9}$/D';
        if (!preg_match($pattern, $value)) {
            throw new HttpException('Código postal inválido para o país selecionado.', 422, [
                'postalCode' => 'Confira o formato do código postal.',
            ]);
        }
        return $value;
    }

    private function rates(string $zone, int $weightGrams): array
    {
        if ($zone === 'belgium') {
            $pickup = $this->band($weightGrams, [2_000 => 540, 5_000 => 560, 10_000 => 600, 20_000 => 839, 30_000 => 1058]);
            $home = $this->band($weightGrams, [2_000 => 710, 5_000 => 785, 10_000 => 865, 20_000 => 1107, 30_000 => 1405]);
            $guaranteed = $this->band($weightGrams, [2_000 => 865, 5_000 => 940, 10_000 => 1020, 20_000 => 1262, 30_000 => 1560]);
            return [
                $this->rate('bpost_pickup', 'Estimated bpost Pick-up Point or Parcel Locker', $pickup, 'pickup', '1 business day (estimate)', 'Local estimate only. No collection point is selected or reserved; shipment must be arranged separately with bpost.'),
                $this->rate('bpost_home', 'Estimated bpost delivery to your address', $home, 'home', '1 business day (estimate)', 'Local price and transit-time estimate based on parcel weight; this is not a bpost booking.'),
                $this->rate('bpost_home_guaranteed', 'Estimated bpost delivery with warranty option', $guaranteed, 'home-guaranteed', '1 business day (estimate)', 'Indicative price only. The warranty option is not purchased or reserved by this checkout.'),
            ];
        }

        if ($zone === 'neighbour') {
            $amount = $this->band($weightGrams, [10_000 => 1650, 30_000 => 4220]);
            return [
                $this->rate('bpost_eu_standard', 'Estimated bpost international delivery', $amount, 'international', '2–5 business days (estimate)', 'Local estimate only; no bpost shipment or tracking is created.'),
            ];
        }

        $amount = $this->band($weightGrams, [5_000 => 3460, 10_000 => 5270, 20_000 => 8600, 30_000 => 10790]);
        return [
            $this->rate('bpost_eu_standard', 'Estimated bpost international delivery', $amount, 'international', '3–7 business days (estimate)', 'Local estimate only; no bpost shipment or tracking is created.'),
        ];
    }

    private function band(int $weightGrams, array $bands): int
    {
        foreach ($bands as $maximum => $amountCents) {
            if ($weightGrams <= $maximum) {
                return $amountCents;
            }
        }
        throw new HttpException('Não há tarifa bpost disponível para o peso deste pacote.', 422);
    }

    private function rate(string $id, string $label, int $amountCents, string $kind, string $estimate, string $description): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'amount' => $amountCents / 100,
            'amountCents' => $amountCents,
            'currency' => 'EUR',
            'estimatedDays' => $estimate,
            'description' => $description,
            'kind' => $kind,
        ];
    }
}
