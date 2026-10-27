<?php
namespace App\Services\Mail;

/** Owner hold: provider selection/configuration and progress percentages never activate delivery. */
final class MailDeliveryGate
{
    public static function enabled(): bool
    {
        return config('waoo.mail_delivery_enabled') === true;
    }
}
