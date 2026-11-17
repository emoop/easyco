<?php

namespace App\Support;

/**
 * ORD-PC: the 28 oblasti of Bulgaria, in Bulgarian, keyed by the GeoNames admin1 code (the key of the postcode data file). The names are FIXED here, never taken from
 * the data file (whose spelling has errors). SOF (София-град, the capital) and SFO (София област, the province around it) are two different regions.
 */
final class BgRegions
{
    /** @var array<string, string> */
    public const NAMES = [
        'BLG' => 'Благоевград',
        'BGS' => 'Бургас',
        'VAR' => 'Варна',
        'VTR' => 'Велико Търново',
        'VID' => 'Видин',
        'VRC' => 'Враца',
        'GAB' => 'Габрово',
        'DOB' => 'Добрич',
        'KRZ' => 'Кърджали',
        'KNL' => 'Кюстендил',
        'LOV' => 'Ловеч',
        'MON' => 'Монтана',
        'PAZ' => 'Пазарджик',
        'PER' => 'Перник',
        'PVN' => 'Плевен',
        'PDV' => 'Пловдив',
        'RAZ' => 'Разград',
        'RSE' => 'Русе',
        'SHU' => 'Шумен',
        'SLS' => 'Силистра',
        'SLV' => 'Сливен',
        'SML' => 'Смолян',
        'SOF' => 'София-град',
        'SFO' => 'София област',
        'SZR' => 'Стара Загора',
        'TGV' => 'Търговище',
        'HKV' => 'Хасково',
        'JAM' => 'Ямбол',
    ];

    public static function name(string $code): ?string
    {
        return self::NAMES[$code] ?? null;
    }
}
