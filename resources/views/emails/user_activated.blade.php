{{-- resources/views/emails/user_activated.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Account Activated</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f3f4f6; color: #1f2937;">

  <!-- NAVBAR -->
  <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background-color: #ab2328  ; text-align: center; padding: 16px 0;">
    <tr>
      <td>
        <span style="color: white; font-size: 24px; font-weight: bold;">DORDIELIST</span>
      </td>
    </tr>
  </table>

  <!-- MAIN CONTENT -->
  <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-top: 40px;">
    <tr>
      <td align="center">
        <table width="600" cellpadding="0" cellspacing="0" role="presentation" style="background-color: white; border-radius: 8px; padding: 32px; text-align: center; box-shadow: 0 4px 12px rgba(0,0,0,0.08);">
          <tr>
            <td>
              <h1 style="font-size: 20px; font-weight: bold; color: #dc2626; margin-bottom: 16px;">Account Activated</h1>

              <p style="margin-bottom: 12px;">
                Congratulations, <span style="font-weight: 600;">{{ $user->username }}</span>!  
                Your account (ID #{{ $user->user_id }}) has been activated.
              </p>

              <a href="{{ route('login') }}" style="
                display: inline-block;
                margin-top: 24px;
                padding: 12px 24px;
                background-color: #08875b;
                color: white;
                text-decoration: none;
                font-weight: bold;
                border-radius: 4px;
              ">
                Go to Login
              </a>

              <p style="margin-top: 32px; font-size: 14px; color: #6b7280;">
                If you did not expect this message, you may safely ignore it.
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  <!-- FOOTER -->
  <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-top: 40px; background-color: #f3f4f6;">
    <tr>
      <td align="center">
        <!-- Divider -->
        <hr style="border: none; border-top: 1px solid #d1d5db; width: 90%; margin: 0 auto;" />

        <!-- Footer Content -->
        <table width="600" cellpadding="0" cellspacing="0" role="presentation" style="margin: 24px auto; text-align: center; font-size: 14px; color: #6b7280; font-family: Arial, sans-serif;">
          <tr>
            <td style="padding-bottom: 12px;">
              &copy; DORDIELIST, LLC 2025 All rights reserved.
            </td>
          </tr>
          <tr>
            <td>
              <a href="#" style="color: #6b7280; text-decoration: none; margin: 0 8px;">Contact</a>
              <a href="#" style="color: #6b7280; text-decoration: none; margin: 0 8px;">Support</a>
              <a href="#" style="color: #6b7280; text-decoration: none; margin: 0 8px;">Jobs</a>
              <a href="#" style="color: #6b7280; text-decoration: none; margin: 0 8px;">Terms</a>
              <a href="#" style="color: #6b7280; text-decoration: none; margin: 0 8px;">Privacy</a>
              <a href="#" style="color: #6b7280; text-decoration: none; margin: 0 8px;">Merch</a>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

</body>
</html>
