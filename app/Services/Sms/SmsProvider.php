<?php

namespace App\Services\Sms;

interface SmsProvider
{
    /**
     * Send an SMS message.
     *
     * @param  string      $toE164   E.164 phone, e.g. +9665XXXXXXXX
     * @param  string      $message
     * @param  string|null $senderId
     */
    public function send(string $toE164, string $message, ?string $senderId = null): void;
}