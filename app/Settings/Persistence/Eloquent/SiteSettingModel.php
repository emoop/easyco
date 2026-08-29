<?php

namespace App\Settings\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent read/write model for site_settings — see
 * 2026_08_29_000001_create_site_settings_table.php for the
 * authoritative column list. Deliberately not paired with a domain
 * class (site-settings-design.md §6) — the "key" column is a plain
 * unique lookup identity, not a surrogate id the way every other
 * repository in this project tracks one.
 */
class SiteSettingModel extends Model
{
    protected $table = 'site_settings';

    protected $fillable = [
        'key',
        'value',
    ];
}
