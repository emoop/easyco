<?php

namespace App\Mail\Dns;

use Illuminate\Container\Attributes\Bind;

/** The one door to real DNS, so tests never touch the network. */
#[Bind(PhpDnsLookup::class)]
interface DnsLookup
{
    /**
     * TXT strings of a host (each record's parts joined); with $includeCname, CNAME targets too, prefixed `CNAME:`.
     * Empty when nothing was found.
     *
     * @return list<string>
     */
    public function lookup(string $host, bool $includeCname = false): array;
}
