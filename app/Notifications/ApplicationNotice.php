<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApplicationNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $applicationId, public string $message)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('AMS request #'.$this->applicationId)->line($this->message)->action('Open requests', config('ams.frontend_url').'/#requests')->line('Sign in to review. No decision is made by opening this email.');
    }
}
