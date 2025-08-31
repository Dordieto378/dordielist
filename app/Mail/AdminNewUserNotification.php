<?php
// app/Mail/AdminNewUserNotification.php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AdminNewUserNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $confirmUrl;

    /**
     * @param  \App\Models\User  $user
     * @param  string            $confirmUrl
     */
    public function __construct(User $user, string $confirmUrl)
    {
        $this->user       = $user;
        $this->confirmUrl = $confirmUrl;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this
            ->subject('New User Awaiting Approval')
            ->view('emails.admin_new_user')
            ->with([
                'username'   => $this->user->username,
                'emailAddr'  => $this->user->email,
                'confirmUrl' => $this->confirmUrl,
            ]);
    }
}
