<?php
// app/Mail/UserActivationNotification.php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class UserActivationNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $user;

    /**
     * @param \App\Models\User $user
     */
    public function __construct(User $user)
    {
        $this->user = $user;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this
            ->subject('Your Account Is Now Active')
            ->view('emails.user_activated')
            ->with([
                'username' => $this->user->username,
                'loginUrl' => route('login'),
            ]);
    }
}
