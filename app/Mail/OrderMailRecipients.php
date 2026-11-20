<?php

namespace App\Mail;

use EasyCo\Order\Order;

/**
 * ACC-3c: who receives which ORDER mail. The one place that decides it.
 *
 *  - the BUYER (the order's email: the account's own for a logged-in order) receives EVERY order mail;
 *  - the optional RECIPIENT (`orders.recipient_email`, "Изпращам до друг човек") receives ONLY the delivery mails (shipped, waybill/tracking),
 *    in addition to the buyer. Never the confirmation, never anything about money, never an account mail.
 *
 * The list is deduplicated (case-insensitive) and the buyer is always first. NOTHING calls this for a delivery mail yet: no delivery mail
 * exists (M3 will add them and must send to every address returned here); the order confirmation keeps going to the buyer only, and
 * asking for it through this class returns the buyer alone, so wiring it later cannot widen who gets it.
 */
final class OrderMailRecipients
{
    /** The template keys that are DELIVERY mails. Reserved ahead of M3: none of them is in MailTemplates yet. */
    public const DELIVERY_MAILS = ['order.shipped', 'order.tracking'];

    /** @return list<string> */
    public static function for(string $templateKey, Order $order): array
    {
        $recipients = [$order->email()];

        if (in_array($templateKey, self::DELIVERY_MAILS, true) && $order->recipientEmail() !== null) {
            $recipients[] = $order->recipientEmail();
        }

        $seen = [];
        $unique = [];

        foreach ($recipients as $address) {
            $key = mb_strtolower(trim($address));

            if ($key !== '' && ! isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $address;
            }
        }

        return $unique;
    }
}
