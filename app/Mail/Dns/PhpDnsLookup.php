<?php

namespace App\Mail\Dns;

final class PhpDnsLookup implements DnsLookup
{
    public function lookup(string $host, bool $includeCname = false): array
    {
        // dns_get_record() warns on a failed lookup: that is "not found", not an error of ours.
        $records = @dns_get_record($host, DNS_TXT | ($includeCname ? DNS_CNAME : 0));

        if (! is_array($records)) {
            return [];
        }

        $found = [];

        foreach ($records as $record) {
            if (isset($record['txt']) && is_string($record['txt'])) {
                $found[] = $record['txt'];
            } elseif (isset($record['entries']) && is_array($record['entries'])) {
                $found[] = implode('', $record['entries']);
            } elseif (isset($record['target']) && is_string($record['target'])) {
                $found[] = 'CNAME:'.$record['target'];
            }
        }

        return $found;
    }
}
