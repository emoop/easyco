<?php

namespace App\Privacy\Persistence;

use Illuminate\Database\Eloquent\Model;

/** The staff note about a customer, one per lower-cased email (customer_notes). Deleted when the person is forgotten. */
class CustomerNoteModel extends Model
{
    protected $table = 'customer_notes';

    protected $guarded = [];
}
