<?php

namespace App\Privacy\Persistence;

use Illuminate\Database\Eloquent\Model;

/** One suppressed identifier: a keyed hash of an email or a phone (privacy_suppressions). Never deleted; lifted by `lifted_at`. */
class PrivacySuppressionModel extends Model
{
    public const KIND_EMAIL = 'email';

    public const KIND_PHONE = 'phone';

    protected $table = 'privacy_suppressions';

    protected $guarded = [];

    protected $casts = [
        'lifted_at' => 'datetime',
    ];
}
